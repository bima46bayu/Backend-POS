<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ReportController extends Controller
{
    public function salesItems(Request $r)
    {
        $perPage  = max(1, (int) $r->input('per_page', 10));
        $page     = max(1, (int) $r->input('page', 1));
        $dateFrom = $r->input('date_from');
        $dateTo   = $r->input('date_to');
        $term     = trim((string) $r->input('q', ''));

        $storeIdReq = $r->filled('store_id') ? (int) $r->input('store_id') : null;
        $allStores  = $r->boolean('all'); // admin → All Stores

        $from = $dateFrom ? $dateFrom.' 00:00:00' : null;
        $to   = $dateTo   ? $dateTo.' 23:59:59' : null;

        $user = $r->user();
        $role = strtolower($user->role ?? '');
        $userStoreId = $user->store_location_id ?? ($user->store_location->id ?? null);

        // Admin: default = store user (kalau store_id kosong), bisa pilih ALL (all=1) atau store lain
        // Kasir: paksa store user
        if ($role === 'admin') {
            $effectiveStoreId = $allStores ? null : ($storeIdReq ?? $userStoreId);
        } else {
            if (!$userStoreId) {
                return response()->json(['message' => 'User has no store assigned.'], 422);
            }
            $effectiveStoreId = (int) $userStoreId;
        }

        try {
            $base = DB::table('sale_items as si')
                ->join('sales as s', 's.id', '=', 'si.sale_id')
                ->join('products as p', 'p.id', '=', 'si.product_id')
                ->leftJoin('users as u', 'u.id', '=', 's.cashier_id')
                ->when($from, fn($q) => $q->where('s.created_at', '>=', $from))
                ->when($to,   fn($q) => $q->where('s.created_at', '<=', $to))
                ->when($effectiveStoreId !== null, function ($q) use ($effectiveStoreId) {
                    $ors = [];
                    if (Schema::hasColumn('sales', 'store_location_id'))   $ors[] = ['s','store_location_id'];
                    if (Schema::hasColumn('users', 'store_location_id'))   $ors[] = ['u','store_location_id'];
                    if (Schema::hasColumn('products','store_location_id')) $ors[] = ['p','store_location_id'];
                    if (empty($ors)) return $q;
                    return $q->where(function ($w) use ($ors, $effectiveStoreId) {
                        foreach ($ors as [$alias, $col]) $w->orWhere("$alias.$col", '=', $effectiveStoreId);
                    });
                })
                ->when($term !== '', function ($qq) use ($term) {
                    $like = '%'.mb_strtolower($term).'%';
                    return $qq->where(function ($w) use ($like) {
                        $w->whereRaw('LOWER(p.sku)  LIKE ?', [$like])
                          ->orWhereRaw('LOWER(p.name) LIKE ?', [$like]);
                    });
                });

            $grouped = DB::query()
                ->fromSub(
                    $base->select([
                        'si.product_id',
                        'p.sku',
                        'p.name as product_name',
                        DB::raw('si.qty as qty_each'),
                        DB::raw('si.line_total as gross_each'),
                        's.created_at as sold_at',
                    ]),
                    't'
                )
                ->select([
                    'product_id',
                    'sku',
                    'product_name',
                    DB::raw('SUM(qty_each)   as qty'),
                    DB::raw('SUM(gross_each) as gross'),
                    DB::raw('MAX(sold_at)    as last_sold_at'),
                ])
                ->groupBy('product_id', 'sku', 'product_name')
                ->orderByDesc('gross');

            $p = $grouped->paginate($perPage, ['*'], 'page', $page);

            $items = collect($p->items())->map(fn($row) => [
                'product_id'   => (int) $row->product_id,
                'sku'          => $row->sku,
                'product_name' => $row->product_name,
                'qty'          => (int) $row->qty,
                'gross'        => (float) $row->gross,
                'last_sold_at' => $row->last_sold_at,
            ])->values();

            return response()->json([
                'items' => $items,
                'meta'  => [
                    'current_page' => $p->currentPage(),
                    'last_page'    => $p->lastPage(),
                    'per_page'     => $p->perPage(),
                    'total'        => $p->total(),
                ],
            ]);
        } catch (\Throwable $e) {
            \Log::error('reports.sales-items failed', ['err' => $e->getMessage()]);
            return response()->json(['message' => 'Failed generating sales-items report'], 500);
        }
    }
}
