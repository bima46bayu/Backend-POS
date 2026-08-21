<?php

namespace App\Http\Controllers;

use App\Models\LoyaltyReward;
use App\Models\Member;
use App\Models\Product;
use App\Services\InventoryService;
use App\Services\RewardReservationService;
use Illuminate\Http\Request;

class LoyaltyRewardController extends Controller
{
    /**
     * GET /api/loyalty-rewards?store_location_id=&active_only=&search=
     */
    public function index(Request $request)
    {
        $storeId = $this->resolveStoreIdFromRequest($request);

        $q = LoyaltyReward::query()->with([
            'storeLocation:id,code,name',
            'product:id,name,sku,image_url,description,inventory_type,price,store_location_id',
        ]);

        if ($request->boolean('active_only')) {
            $q->where('is_active', true)->whereNotNull('product_id');
        }

        $search = trim((string) $request->input('search', $request->input('q', '')));
        if ($search !== '') {
            $q->where(function ($qq) use ($search) {
                $qq->where('name', 'like', '%' . $search . '%')
                    ->orWhereHas('product', function ($pq) use ($search) {
                        $pq->where('name', 'like', '%' . $search . '%')
                            ->orWhere('sku', 'like', '%' . $search . '%');
                    });
            });
        }

        $perPage = (int) $request->input('per_page', 50);
        $perPage = max(1, min($perPage, 200));

        $page = $q->orderBy('sort_order')->orderBy('points_cost')->paginate($perPage);
        $page->getCollection()->transform(function (LoyaltyReward $reward) use ($storeId) {
            if ($reward->product) {
                $reward->product->setAttribute(
                    'stock',
                    InventoryService::displayStock(
                        (int) $reward->product->id,
                        $storeId,
                        $reward->product
                    )
                );
            }

            return $reward;
        });

        return $page;
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $storeId = $this->resolveStoreIdFromRequest($request, $data['store_location_id'] ?? null);
        if ($storeId === null && isset($data['product_id'])) {
            $storeId = Product::query()->whereKey((int) $data['product_id'])->value('store_location_id');
            $storeId = $storeId ? (int) $storeId : null;
        }
        if ($storeId === null) {
            abort(422, 'Store wajib dipilih.');
        }

        $data = $this->applyProductSnapshot($request, $data, null);
        $data['store_location_id'] = $storeId;
        $reward = LoyaltyReward::create($data);

        return response()->json(
            $reward->load(['storeLocation:id,code,name', 'product:id,name,sku,image_url,description,inventory_type,price']),
            201
        );
    }

    public function update(Request $request, LoyaltyReward $loyalty_reward)
    {
        $data = $this->validated($request, updating: true);
        unset($data['store_location_id']);
        $data = $this->applyProductSnapshot(
            $request,
            $data,
            (int) $loyalty_reward->id
        );
        $loyalty_reward->update($data);

        return response()->json(
            $loyalty_reward->fresh()->load(['storeLocation:id,code,name', 'product:id,name,sku,image_url,description,inventory_type,price'])
        );
    }

    public function destroy(Request $request, LoyaltyReward $loyalty_reward)
    {
        $loyalty_reward->delete();

        return response()->json(['message' => 'deleted']);
    }

    /**
     * POST /api/loyalty-rewards/{reward}/redeem
     * body: { member_id, store_location_id? }
     */
    /**
     * Over-the-counter redemption.
     *
     * Delegates to RewardReservationService so this shares one implementation
     * with the member app: same eligibility rules (tier minimum, daily limit,
     * balance) and the same stock/sale side effects. The response shape is kept
     * for the existing Member Store UI.
     */
    public function redeem(Request $request, LoyaltyReward $loyalty_reward, RewardReservationService $reservations)
    {
        $data = $request->validate([
            'member_id'          => 'required|integer|exists:members,id',
            'store_location_id'  => 'nullable|integer|exists:store_locations,id',
        ]);

        $branchId = $this->resolveStoreIdFromRequest(
            $request,
            isset($data['store_location_id']) ? (int) $data['store_location_id'] : null
        );
        if ($branchId === null) {
            abort(422, 'Cabang wajib dipilih.');
        }
        $this->authorizeStoreAccess($request->user(), $branchId);

        $member = Member::findOrFail((int) $data['member_id']);

        $reservation = $reservations->redeemOverCounter(
            $member,
            $loyalty_reward,
            $branchId,
            (int) $request->user()?->id
        );

        $tx = $reservation->pointTransaction;

        return response()->json([
            'transaction' => $tx,
            'member'      => $member->fresh(),
            'reward'      => $loyalty_reward->fresh()->load('product:id,name,sku,image_url'),
            'sale'        => $reservation->sale,
            'reservation' => $reservation->only(['id', 'public_id', 'status', 'sale_id']),
        ]);
    }

    private function applyProductSnapshot(
        Request $request,
        array $data,
        ?int $ignoreRewardId = null
    ): array {
        if (! isset($data['product_id'])) {
            return $data;
        }

        $product = Product::findOrFail((int) $data['product_id']);
        if ($product->store_location_id) {
            $this->authorizeStoreAccess($request->user(), (int) $product->store_location_id);
        }

        $dup = LoyaltyReward::query()
            ->where('product_id', $product->id)
            ->when($ignoreRewardId, fn ($q) => $q->where('id', '!=', $ignoreRewardId))
            ->exists();
        if ($dup) {
            abort(422, 'Produk ini sudah ada di Member Store.');
        }

        $data['product_id'] = $product->id;
        $data['name'] = $product->name;
        if (! array_key_exists('description', $data) || $data['description'] === null || trim((string) $data['description']) === '') {
            $desc = trim((string) ($product->description ?? ''));
            $data['description'] = $desc === '' ? null : mb_substr($desc, 0, 255);
        }

        return $data;
    }

    private function validated(Request $request, bool $updating = false): array
    {
        return $request->validate([
            'store_location_id' => 'nullable|integer|exists:store_locations,id',
            'product_id'        => ($updating ? 'sometimes|' : '') . 'required|integer|exists:products,id',
            'name'              => 'nullable|string|max:120',
            'description'       => 'nullable|string|max:255',
            'points_cost'       => ($updating ? 'sometimes|' : '') . 'required|integer|min:1|max:1000000',
            'is_active'         => 'nullable|boolean',
            'sort_order'        => 'nullable|integer|min:0',
        ]);
    }
}
