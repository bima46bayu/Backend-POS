<?php

namespace App\Http\Controllers;

use App\Models\Member;
use App\Models\MemberPointTransaction;
use App\Models\StoreLocation;
use App\Services\LoyaltyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Member / customer database + loyalty point settings.
 *
 * Members are owned by a PARENT store group, so every read/write resolves the
 * caller's branch to its region root before touching the table.
 */
class MemberController extends Controller
{
    /**
     * GET /api/members
     * ?store_location_id=&search=&active_only=&per_page=&page=
     */
    public function index(Request $request)
    {
        $storeId = $this->resolveStoreIdFromRequest($request);

        $q = Member::query();

        if ($storeId !== null) {
            $q->where('store_location_id', Member::ownerStoreId($storeId));
        } else {
            // HQ admin with no branch filter: limit to groups they may see.
            $this->scopeQueryToAllowedStores($q, $request->user());
        }

        $q->search($request->input('search'));

        if ($request->boolean('active_only')) {
            $q->where('is_active', true);
        }

        $perPage = (int) $request->input('per_page', 25);
        $perPage = max(1, min($perPage, 200));

        $items = $q->orderBy('name')->paginate($perPage);

        return response()->json($items);
    }

    /**
     * GET /api/members/lookup?search=&store_location_id=
     *
     * Lightweight search for the POS picker. Cashiers may call this (unlike the
     * management CRUD routes), because selecting a member is part of checkout.
     */
    public function lookup(Request $request)
    {
        $storeId = $this->resolveStoreIdFromRequest($request);
        if ($storeId === null) {
            return response()->json(['data' => []]);
        }

        $term = trim((string) $request->input('search', ''));

        $q = Member::query()
            ->where('store_location_id', Member::ownerStoreId($storeId))
            ->where('is_active', true);

        // Empty search returns the most recent customers, which is what a
        // cashier usually wants (regulars) instead of an empty list.
        if ($term !== '') {
            $q->search($term);
        }

        $items = $q
            ->orderByDesc('last_transaction_at')
            ->orderBy('name')
            ->limit(25)
            ->get()
            ->map(fn (Member $m) => $m->toPickerArray())
            ->values();

        return response()->json([
            'data'  => $items,
            'rate'  => LoyaltyService::rate(),
            'enabled' => LoyaltyService::enabled(),
        ]);
    }

    /** GET /api/members/next-code?store_location_id= */
    public function nextCode(Request $request)
    {
        $storeId = $this->resolveStoreIdFromRequest($request);
        if ($storeId === null) {
            abort(422, 'Store wajib dipilih.');
        }

        return response()->json([
            'code' => Member::nextCode(Member::ownerStoreId($storeId)),
        ]);
    }

    public function show(Request $request, Member $member)
    {
        $this->authorizeStoreAccess($request->user(), (int) $member->store_location_id);

        return response()->json([
            'data' => $member->load([
                'storeLocation:id,code,name',
            ]),
            'point_transactions' => $member->pointTransactions()
                ->with('sale:id,code,created_at')
                ->limit(50)
                ->get(),
        ]);
    }

    /**
     * POST /api/members
     */
    public function store(Request $request)
    {
        $storeId = $this->resolveStoreIdFromRequest($request);
        if ($storeId === null) {
            abort(422, 'Store wajib dipilih.');
        }

        $ownerId = Member::ownerStoreId($storeId);
        $data = $this->validatePayload($request, $ownerId);

        $member = Member::create([
            'store_location_id' => $ownerId,
            'code'              => $data['code'] ?? Member::nextCode($ownerId),
            'name'              => trim($data['name']),
            'phone'             => $this->normalizePhone($data['phone'] ?? null),
            'email'             => $data['email'] ?? null,
            'birth_date'        => $data['birth_date'] ?? null,
            'address'           => $data['address'] ?? null,
            'note'              => $data['note'] ?? null,
            'is_active'         => $data['is_active'] ?? true,
        ]);

        // Optional opening balance, e.g. migrating from a paper card system.
        if (! empty($data['initial_points'])) {
            LoyaltyService::adjust(
                $member,
                (int) $data['initial_points'],
                'Saldo poin awal',
                $request->user()?->id,
                $storeId
            );
            $member->refresh();
        }

        return response()->json(['data' => $member], 201);
    }

    /**
     * PUT/PATCH /api/members/{member}
     */
    public function update(Request $request, Member $member)
    {
        $this->authorizeStoreAccess($request->user(), (int) $member->store_location_id);

        $data = $this->validatePayload($request, (int) $member->store_location_id, $member->id, false);

        foreach (['name', 'email', 'birth_date', 'address', 'note', 'code'] as $field) {
            if (array_key_exists($field, $data)) {
                $member->{$field} = is_string($data[$field]) ? trim($data[$field]) : $data[$field];
            }
        }

        if (array_key_exists('phone', $data)) {
            $member->phone = $this->normalizePhone($data['phone']);
        }
        if (array_key_exists('is_active', $data)) {
            $member->is_active = (bool) $data['is_active'];
        }

        $member->save();

        return response()->json(['data' => $member]);
    }

    /**
     * DELETE /api/members/{member}
     *
     * Refused when the member has sales attached — deleting would orphan
     * transaction history. Deactivate instead.
     */
    public function destroy(Request $request, Member $member)
    {
        $this->authorizeStoreAccess($request->user(), (int) $member->store_location_id);

        if ($member->sales()->exists()) {
            abort(422, 'Member sudah punya transaksi. Non-aktifkan saja, jangan dihapus.');
        }

        $member->delete();

        return response()->noContent();
    }

    /**
     * POST /api/members/{member}/points
     * body: { points: int (may be negative), note?: string }
     */
    public function adjustPoints(Request $request, Member $member)
    {
        $this->authorizeStoreAccess($request->user(), (int) $member->store_location_id);

        $data = $request->validate([
            'points' => ['required', 'integer', 'not_in:0'],
            'note'   => ['nullable', 'string', 'max:255'],
        ]);

        $tx = LoyaltyService::adjust(
            $member,
            (int) $data['points'],
            $data['note'] ?? 'Penyesuaian manual',
            $request->user()?->id,
            (int) $member->store_location_id
        );

        return response()->json([
            'data'        => $member->refresh(),
            'transaction' => $tx,
        ]);
    }

    /** GET /api/members/{member}/points */
    public function pointHistory(Request $request, Member $member)
    {
        $this->authorizeStoreAccess($request->user(), (int) $member->store_location_id);

        $items = $member->pointTransactions()
            ->with('sale:id,code,created_at', 'user:id,name')
            ->paginate((int) $request->input('per_page', 25));

        return response()->json($items);
    }

    /* ===================== SETTINGS ===================== */

    /** GET /api/members/settings/points */
    public function settings()
    {
        return response()->json(LoyaltyService::settings());
    }

    /**
     * PUT /api/members/settings/points
     * body: { points_per_amount: int, enabled?: bool }
     */
    public function updateSettings(Request $request)
    {
        $data = $request->validate([
            // Rp per 1 point. Upper bound keeps a typo from making points
            // unreachable forever.
            'points_per_amount' => ['nullable', 'integer', 'min:1', 'max:100000000'],
            'enabled'           => ['nullable', 'boolean'],
        ]);

        $settings = LoyaltyService::saveSettings(
            isset($data['points_per_amount']) ? (int) $data['points_per_amount'] : null,
            array_key_exists('enabled', $data) ? (bool) $data['enabled'] : null
        );

        return response()->json([
            'message' => 'Pengaturan poin disimpan',
            ...$settings,
        ]);
    }

    /* ===================== helpers ===================== */

    protected function validatePayload(Request $request, int $ownerStoreId, ?int $ignoreId = null, bool $creating = true): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'name'  => [$req, 'string', 'max:120'],

            // Unique per store group, not globally.
            'code'  => [
                'nullable', 'string', 'max:40',
                Rule::unique('members', 'code')
                    ->where('store_location_id', $ownerStoreId)
                    ->ignore($ignoreId),
            ],
            'phone' => [
                'nullable', 'string', 'max:30',
                Rule::unique('members', 'phone')
                    ->where('store_location_id', $ownerStoreId)
                    ->ignore($ignoreId),
            ],

            'email'      => ['nullable', 'email', 'max:120'],
            'birth_date' => ['nullable', 'date'],
            'address'    => ['nullable', 'string', 'max:255'],
            'note'       => ['nullable', 'string', 'max:1000'],
            'is_active'  => ['nullable', 'boolean'],

            'initial_points' => ['nullable', 'integer', 'min:0'],

            'store_location_id' => ['nullable', 'integer', 'exists:store_locations,id'],
        ]);
    }

    /** Keep phone numbers comparable: digits only. */
    protected function normalizePhone(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $phone);

        return $digits === '' ? null : $digits;
    }
}
