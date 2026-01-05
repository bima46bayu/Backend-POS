<?php

namespace App\Http\Controllers;

use App\Models\Discount;
use Illuminate\Http\Request;

class DiscountController extends Controller
{
    public function index(Request $r)
    {
        $r->validate([
            'scope' => 'nullable|in:GLOBAL,ITEM,global,item',
            'active' => 'nullable|in:0,1',
            'store_location_id' => 'nullable|integer',
            'q' => 'nullable|string|max:100',
            'per_page' => 'nullable|integer|min:1|max:200',
        ]);

        $q = Discount::query();

        // search by name
        if ($r->filled('q')) {
            $keyword = trim($r->q);
            $q->where('name', 'like', "%{$keyword}%");
        }

        // filter scope
        if ($r->filled('scope')) {
            $q->where('scope', strtoupper($r->scope));
        }

        // filter active
        if ($r->filled('active')) {
            $q->where('active', (int)$r->active);
        }

        // filter store: include NULL (global) + store spesifik (untuk dropdown POS biasanya begini)
        if ($r->filled('store_location_id')) {
            $sid = (int)$r->store_location_id;
            $q->where(function ($qq) use ($sid) {
                $qq->whereNull('store_location_id')->orWhere('store_location_id', $sid);
            });
        }

        $q->orderBy('active', 'desc')->orderBy('name');

        // FE admin biasanya enak pakai pagination
        $perPage = (int)($r->per_page ?? 50);

        // jika kamu mau non-paginated, bisa return get()
        return $q->paginate($perPage);
    }

    public function store(Request $r)
    {
        $data = $this->validatePayload($r);

        // normalize
        $data['scope'] = strtoupper($data['scope']);
        $data['kind']  = strtoupper($data['kind']);

        // rules tambahan
        $this->validateLogic($data);

        $disc = Discount::create($data);

        return response()->json($disc, 201);
    }

    public function show(Discount $discount)
    {
        return $discount;
    }

    public function update(Request $r, Discount $discount)
    {
        $data = $this->validatePayload($r, isUpdate: true);

        if (isset($data['scope'])) $data['scope'] = strtoupper($data['scope']);
        if (isset($data['kind']))  $data['kind']  = strtoupper($data['kind']);

        $merged = array_merge($discount->toArray(), $data);
        $this->validateLogic($merged);

        $discount->fill($data);
        $discount->save();

        return $discount;
    }

    public function destroy(Discount $discount)
    {
        // aman: hard delete. Kalau mau soft delete, tambahin SoftDeletes di model & migration.
        $discount->delete();

        return response()->json(['message' => 'Deleted']);
    }

    public function toggle(Discount $discount)
    {
        $discount->active = !$discount->active;
        $discount->save();

        return $discount;
    }

    /* ========================= HELPERS ========================= */

    private function validatePayload(Request $r, bool $isUpdate = false): array
    {
        // Catatan: value disimpan decimal, tapi input bisa integer.
        $rules = [
            'name' => ($isUpdate ? 'sometimes' : 'required') . '|string|max:120',
            'scope' => ($isUpdate ? 'sometimes' : 'required') . '|in:GLOBAL,ITEM,global,item',
            'kind'  => ($isUpdate ? 'sometimes' : 'required') . '|in:PERCENT,FIXED,percent,fixed',
            'value' => ($isUpdate ? 'sometimes' : 'required') . '|numeric|min:0',

            'max_amount' => 'nullable|numeric|min:0',
            'min_subtotal' => 'nullable|numeric|min:0',

            'active' => 'sometimes|boolean',
            'store_location_id' => 'nullable|integer',
        ];

        return $r->validate($rules);
    }

    private function validateLogic(array $data): void
    {
        $scope = strtoupper((string)($data['scope'] ?? ''));
        $kind  = strtoupper((string)($data['kind'] ?? ''));

        // scope harus GLOBAL/ITEM
        if (!in_array($scope, ['GLOBAL', 'ITEM'], true)) {
            abort(422, 'scope invalid');
        }

        // kind harus PERCENT/FIXED
        if (!in_array($kind, ['PERCENT', 'FIXED'], true)) {
            abort(422, 'kind invalid');
        }

        $value = (float)($data['value'] ?? 0);

        if ($kind === 'PERCENT') {
            // 0-100 biar masuk akal
            if ($value < 0 || $value > 100) {
                abort(422, 'Percent value must be between 0 and 100.');
            }
        }

        if ($kind === 'FIXED') {
            // max_amount tidak relevan utk FIXED (boleh, tapi tidak dipakai)
            // min_subtotal boleh untuk kedua kind.
        }
    }
}
