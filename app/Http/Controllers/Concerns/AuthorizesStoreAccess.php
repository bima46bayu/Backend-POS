<?php

namespace App\Http\Controllers\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

trait AuthorizesStoreAccess
{
    protected function authorizeStoreAccess(User $user, ?int $storeId): void
    {
        if (! $user->canAccessStore($storeId)) {
            abort(403, 'Store access denied');
        }
    }

    protected function resolveStoreIdFromRequest(Request $request, ?int $requested = null): ?int
    {
        $user = $request->user();
        $storeId = $requested;

        if ($storeId === null && $request->filled('store_location_id')) {
            $storeId = (int) $request->input('store_location_id');
        }

        if ($storeId === null && $request->filled('store_id')) {
            $storeId = (int) $request->input('store_id');
        }

        if ($storeId !== null) {
            $this->authorizeStoreAccess($user, $storeId);

            return $storeId;
        }

        if ($user->isAdmin()) {
            return null;
        }

        return $user->store_location_id ? (int) $user->store_location_id : null;
    }

    protected function scopeQueryToAllowedStores(Builder $query, User $user, string $column = 'store_location_id'): Builder
    {
        $allowed = $user->allowedStoreIds();

        if ($allowed === null) {
            return $query;
        }

        if ($allowed === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn($column, $allowed);
    }

    /**
     * Product catalog scope: optional store filter; HQ = all; RM = allowed + global; store staff = their branch + global.
     */
    protected function applyProductStoreScope(Builder $query, Request $request, string $column = 'products.store_location_id'): Builder
    {
        $user = $request->user();
        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        $storeId = null;
        if ($request->filled('store_location_id')) {
            $storeId = (int) $request->input('store_location_id');
        } elseif ($request->filled('store_id')) {
            $storeId = (int) $request->input('store_id');
        }

        if ($storeId !== null) {
            $this->authorizeStoreAccess($user, $storeId);

            return $query->where(function ($w) use ($column, $storeId) {
                $w->whereNull($column)->orWhere($column, $storeId);
            });
        }

        if ($user->isAdmin()) {
            return $query;
        }

        $allowed = $user->allowedStoreIds() ?? [];
        if ($allowed === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function ($w) use ($column, $allowed) {
            $w->whereNull($column)->orWhereIn($column, $allowed);
        });
    }

    /**
     * Apply list filters for sales/history: optional store query param, else role scope.
     * Supports store_location_id (single) or store_location_ids[] (parent + branches).
     */
    protected function applySaleStoreScope(Builder $query, Request $request, string $column = 'store_location_id'): Builder
    {
        $user = $request->user();
        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        $storeIds = [];
        if ($request->filled('store_location_ids')) {
            $raw = $request->input('store_location_ids');
            if (is_string($raw)) {
                $raw = preg_split('/\s*,\s*/', $raw, -1, PREG_SPLIT_NO_EMPTY);
            }
            if (is_array($raw)) {
                foreach ($raw as $id) {
                    $n = (int) $id;
                    if ($n > 0) {
                        $storeIds[] = $n;
                    }
                }
            }
        }

        if ($storeIds === []) {
            $storeId = null;
            if ($request->filled('store_location_id')) {
                $storeId = (int) $request->input('store_location_id');
            } elseif ($request->filled('store_id')) {
                $storeId = (int) $request->input('store_id');
            }
            if ($storeId !== null) {
                $storeIds = [$storeId];
            }
        }

        $storeIds = array_values(array_unique($storeIds));

        if ($storeIds !== []) {
            foreach ($storeIds as $sid) {
                $this->authorizeStoreAccess($user, $sid);
            }

            if (count($storeIds) === 1) {
                return $query->where($column, $storeIds[0]);
            }

            return $query->whereIn($column, $storeIds);
        }

        if ($user->isAdmin()) {
            return $query;
        }

        return $this->scopeQueryToAllowedStores($query, $user, $column);
    }
}
