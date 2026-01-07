<?php

namespace App\Http\Controllers;

use App\Models\AdditionalCharge;
use App\Http\Requests\StoreAdditionalChargeRequest;
use App\Http\Requests\UpdateAdditionalChargeRequest;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AdditionalChargeController extends Controller
{
    /**
     * List additional charges untuk store user login
     */
    public function index(Request $request)
    {
        $storeId = Auth::user()->store_location_id;

        return AdditionalCharge::query()
            ->where('store_location_id', $storeId)
            ->orderBy('type')
            ->get();
    }

    /**
     * Create additional charge (PB1 / SERVICE)
     * store_location_id DIINJECT dari user login
     */
    public function store(StoreAdditionalChargeRequest $request)
    {
        $storeId = Auth::user()->store_location_id;

        try {
            return AdditionalCharge::create([
                ...$request->validated(),
                'store_location_id' => $storeId,
            ]);
        } catch (QueryException $e) {
            // unique constraint (store_location_id, type)
            if ($e->getCode() === '23000') {
                abort(422, 'PB1 / Service untuk store ini sudah ada.');
            }

            throw $e;
        }
    }

    /**
     * Show detail
     * (opsional: bisa ditambah policy kalau mau)
     */
    public function show(AdditionalCharge $additionalCharge)
    {
        $this->authorizeStore($additionalCharge);
        return $additionalCharge;
    }

    /**
     * Update additional charge
     * store_location_id TIDAK BOLEH diubah
     */
    public function update(
        UpdateAdditionalChargeRequest $request,
        AdditionalCharge $additionalCharge
    ) {
        $this->authorizeStore($additionalCharge);

        try {
            $additionalCharge->update($request->validated());
            return $additionalCharge;
        } catch (QueryException $e) {
            if ($e->getCode() === '23000') {
                abort(422, 'PB1 / Service untuk store ini sudah ada.');
            }

            throw $e;
        }
    }

    /**
     * Delete additional charge
     */
    public function destroy(AdditionalCharge $additionalCharge)
    {
        $this->authorizeStore($additionalCharge);

        $additionalCharge->delete();
        return response()->noContent();
    }

    /**
     * Pastikan data milik store user login
     */
    protected function authorizeStore(AdditionalCharge $additionalCharge): void
    {
        if ($additionalCharge->store_location_id !== Auth::user()->store_location_id) {
            abort(403, 'Tidak punya akses ke store ini.');
        }
    }
}
