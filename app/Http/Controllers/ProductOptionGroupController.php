<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductOptionGroup;
use App\Models\ProductOptionValue;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ProductOptionGroupController extends Controller
{
    /**
     * GET /api/product-option-groups?store_location_id=&active_only=
     */
    public function index(Request $request)
    {
        $storeId = $this->resolveStoreIdFromRequest($request);

        $q = ProductOptionGroup::query()
            ->with([
                'values.qtyDeltaUnit:id,name',
                'ingredient:id,name,sku,unit_id',
                'ingredient.unit:id,name',
            ])
            ->withCount('products');

        if ($storeId !== null) {
            $q->where('store_location_id', $storeId);
        } else {
            $this->scopeQueryToAllowedStores($q, $request->user());
        }

        if ($request->boolean('active_only')) {
            $q->where('is_active', true);
        }

        $items = $q->orderBy('sort_order')->orderBy('id')->get();

        return response()->json(['data' => $items]);
    }

    public function show(Request $request, ProductOptionGroup $productOptionGroup)
    {
        $this->authorizeStoreAccess(
            Auth::user(),
            (int) $productOptionGroup->store_location_id
        );

        return response()->json([
            'data' => $productOptionGroup->load(['values.qtyDeltaUnit', 'ingredient.unit']),
        ]);
    }

    /**
     * POST /api/product-option-groups
     * body: { store_location_id, name, selection_type, is_required, is_active, sort_order, values: [{name, price_delta, is_active, sort_order}] }
     */
    public function store(Request $request)
    {
        $data = $this->validatePayload($request);

        $storeId = $this->resolveStoreIdFromRequest(
            $request,
            isset($data['store_location_id']) ? (int) $data['store_location_id'] : null
        );

        if ($storeId === null) {
            abort(422, 'Store wajib dipilih.');
        }

        return DB::transaction(function () use ($data, $storeId) {
            $group = ProductOptionGroup::create([
                'store_location_id'     => $storeId,
                'ingredient_product_id' => $data['ingredient_product_id'] ?? null,
                'name'                  => $data['name'],
                'selection_type'        => $data['selection_type'] ?? ProductOptionGroup::SELECTION_SINGLE,
                'is_required'           => $data['is_required'] ?? false,
                'is_active'             => $data['is_active'] ?? true,
                'sort_order'            => $data['sort_order'] ?? 0,
            ]);

            $this->syncValues($group, $data['values'] ?? []);

            return response()->json([
                'data' => $group->load(['values.qtyDeltaUnit', 'ingredient.unit']),
            ], 201);
        });
    }

    /**
     * PUT/PATCH /api/product-option-groups/{id}
     */
    public function update(Request $request, ProductOptionGroup $productOptionGroup)
    {
        $this->authorizeStoreAccess(
            Auth::user(),
            (int) $productOptionGroup->store_location_id
        );

        $data = $this->validatePayload($request, false);

        return DB::transaction(function () use ($data, $productOptionGroup, $request) {
            $productOptionGroup->fill(array_filter([
                'name'           => $data['name'] ?? null,
                'selection_type' => $data['selection_type'] ?? null,
                'sort_order'     => $data['sort_order'] ?? null,
            ], fn ($v) => $v !== null));

            if (array_key_exists('is_required', $data)) {
                $productOptionGroup->is_required = (bool) $data['is_required'];
            }
            if (array_key_exists('is_active', $data)) {
                $productOptionGroup->is_active = (bool) $data['is_active'];
            }
            // Explicit null clears the ingredient link (price-only group again).
            if (array_key_exists('ingredient_product_id', $data)) {
                $productOptionGroup->ingredient_product_id = $data['ingredient_product_id'];
            }

            $productOptionGroup->save();

            // values hanya disentuh kalau dikirim
            if ($request->has('values')) {
                $this->syncValues($productOptionGroup, $data['values'] ?? []);
            }

            return response()->json([
                'data' => $productOptionGroup->load(['values.qtyDeltaUnit', 'ingredient.unit']),
            ]);
        });
    }

    public function destroy(ProductOptionGroup $productOptionGroup)
    {
        $this->authorizeStoreAccess(
            Auth::user(),
            (int) $productOptionGroup->store_location_id
        );

        $productOptionGroup->delete();

        return response()->noContent();
    }

    /**
     * GET /api/product-option-groups/{id}/products
     *
     * Daftar produk yang bisa dipasangi grup ini (produk milik store grup +
     * produk global), plus flag apakah sudah terpasang. Dipakai halaman Master
     * untuk assign grup ke banyak produk sekaligus.
     */
    public function products(Request $request, ProductOptionGroup $productOptionGroup)
    {
        $storeId = (int) $productOptionGroup->store_location_id;

        $this->authorizeStoreAccess(Auth::user(), $storeId);

        $attachedIds = $productOptionGroup->products()
            ->pluck('products.id')
            ->all();

        $q = Product::query()
            ->select(['id', 'name', 'sku', 'price', 'store_location_id'])
            ->forStore($storeId, true);

        if ($search = trim((string) $request->input('search', ''))) {
            $q->where(function ($w) use ($search) {
                $w->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%");
            });
        }

        $items = $q->orderBy('name')->limit(500)->get();

        $attachedLookup = array_fill_keys($attachedIds, true);

        return response()->json([
            'data' => $items->map(fn ($p) => [
                'id'                => $p->id,
                'name'              => $p->name,
                'sku'               => $p->sku,
                'price'             => (float) $p->price,
                'store_location_id' => $p->store_location_id,
                'is_attached'       => isset($attachedLookup[$p->id]),
            ])->values(),
            'attached_ids' => array_values($attachedIds),
        ]);
    }

    /**
     * PUT /api/product-option-groups/{id}/products
     * body: { product_ids: [1,2,3] }
     *
     * Sync penuh: produk yang tidak ada di list akan dilepas dari grup ini.
     */
    public function syncProducts(Request $request, ProductOptionGroup $productOptionGroup)
    {
        $storeId = (int) $productOptionGroup->store_location_id;

        $this->authorizeStoreAccess(Auth::user(), $storeId);

        $data = $request->validate([
            'product_ids'   => 'present|array',
            'product_ids.*' => 'integer|exists:products,id',
        ]);

        $ids = array_values(array_unique(array_map('intval', $data['product_ids'])));

        // Cegah grup nyangkut ke produk milik store lain.
        if ($ids !== []) {
            $valid = Product::query()
                ->whereIn('id', $ids)
                ->forStore($storeId, true)
                ->pluck('id')
                ->all();

            $invalid = array_diff($ids, $valid);
            if ($invalid !== []) {
                abort(422, 'Ada produk yang bukan milik cabang grup opsi ini.');
            }

            $ids = $valid;
        }

        return DB::transaction(function () use ($productOptionGroup, $ids) {
            $productOptionGroup->products()->sync($ids);

            return response()->json([
                'data' => [
                    'id'           => $productOptionGroup->id,
                    'attached_ids' => $ids,
                    'count'        => count($ids),
                ],
            ]);
        });
    }

    /* ================= helpers ================= */

    private function validatePayload(Request $request, bool $creating = true): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'store_location_id'      => 'nullable|integer|exists:store_locations,id',
            'ingredient_product_id'  => 'nullable|integer|exists:products,id',
            'name'                   => $required . '|string|max:100',
            'selection_type'         => 'nullable|in:SINGLE,MULTI',
            'is_required'            => 'nullable|boolean',
            'is_active'              => 'nullable|boolean',
            'sort_order'             => 'nullable|integer|min:0',

            'values'                 => 'nullable|array',
            'values.*.id'            => 'nullable|integer',
            'values.*.name'          => 'required|string|max:100',
            'values.*.price_delta'   => 'nullable|numeric',
            'values.*.qty_delta'     => 'nullable|numeric',
            'values.*.qty_delta_unit_id' => 'nullable|integer|exists:units,id',
            'values.*.is_active'     => 'nullable|boolean',
            'values.*.sort_order'    => 'nullable|integer|min:0',
        ]);
    }

    /**
     * Sync values: update yang ada, buat yang baru, hapus yang hilang.
     */
    private function syncValues(ProductOptionGroup $group, array $values): void
    {
        $keepIds = [];

        foreach (array_values($values) as $i => $row) {
            $payload = [
                'name'              => $row['name'],
                'price_delta'       => round((float) ($row['price_delta'] ?? 0), 2),
                'qty_delta'         => round((float) ($row['qty_delta'] ?? 0), 4),
                'qty_delta_unit_id' => ! empty($row['qty_delta_unit_id'])
                    ? (int) $row['qty_delta_unit_id']
                    : null,
                'is_active'         => array_key_exists('is_active', $row) ? (bool) $row['is_active'] : true,
                'sort_order'        => $row['sort_order'] ?? $i,
            ];

            $existing = ! empty($row['id'])
                ? ProductOptionValue::where('product_option_group_id', $group->id)
                    ->where('id', (int) $row['id'])
                    ->first()
                : null;

            if ($existing) {
                $existing->update($payload);
                $keepIds[] = $existing->id;
                continue;
            }

            $created = $group->values()->create($payload);
            $keepIds[] = $created->id;
        }

        ProductOptionValue::where('product_option_group_id', $group->id)
            ->when($keepIds !== [], fn ($q) => $q->whereNotIn('id', $keepIds))
            ->delete();
    }
}
