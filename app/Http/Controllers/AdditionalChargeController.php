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
     * List additional charges for a specific store (query: store_location_id).
     */
    public function index(Request $request)
    {
        $storeId = $this->resolveStoreIdFromRequest($request);

        if ($storeId === null) {
            return collect([]);
        }

        return AdditionalCharge::query()
            ->where('store_location_id', $storeId)
            ->orderBy('type')
            ->get();
    }

    /**
     * Create additional charge (PB1 / SERVICE) for a store.
     */
    public function store(StoreAdditionalChargeRequest $request)
    {
        $data = $request->validated();
        $requested = isset($data['store_location_id'])
            ? (int) $data['store_location_id']
            : null;
        unset($data['store_location_id']);

        $storeId = $this->resolveStoreIdFromRequest($request, $requested);

        if ($storeId === null) {
            abort(422, 'Store wajib dipilih.');
        }

        try {
            return AdditionalCharge::create([
                ...$data,
                'store_location_id' => $storeId,
            ]);
        } catch (QueryException $e) {
            if ($e->getCode() === '23000') {
                abort(422, 'PB1 / Service untuk store ini sudah ada.');
            }

            throw $e;
        }
    }

    public function show(AdditionalCharge $additionalCharge)
    {
        $this->authorizeStore($additionalCharge);

        return $additionalCharge;
    }

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

    public function destroy(AdditionalCharge $additionalCharge)
    {
        $this->authorizeStore($additionalCharge);

        $additionalCharge->delete();

        return response()->noContent();
    }

    protected function authorizeStore(AdditionalCharge $additionalCharge): void
    {
        $this->authorizeStoreAccess(
            Auth::user(),
            (int) $additionalCharge->store_location_id
        );
    }
}
