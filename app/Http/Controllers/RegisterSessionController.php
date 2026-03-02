<?php

namespace App\Http\Controllers;

use App\Models\RegisterSession;
use App\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class RegisterSessionController extends Controller
{
    public function current(Request $r)
    {
        $user = $r->user();
        $storeId = optional($user)->store_location_id;

        if (!$storeId) {
            return response()->json(['message' => 'User does not have store location'], 422);
        }

        $session = RegisterSession::where('cashier_id', $user->id)
            ->where('store_location_id', $storeId)
            ->whereNull('closed_at')
            ->latest('opened_at')
            ->first();

        return response()->json($session);
    }

    public function open(Request $r)
    {
        $user = $r->user();
        $storeId = optional($user)->store_location_id;

        if (!$storeId) {
            return response()->json(['message' => 'User does not have store location'], 422);
        }

        $data = $r->validate([
            'opening_cash' => ['required', 'numeric', 'min:0'],
            'note'         => ['nullable', 'string'],
        ]);

        $exists = RegisterSession::where('cashier_id', $user->id)
            ->where('store_location_id', $storeId)
            ->whereNull('closed_at')
            ->exists();

        if ($exists) {
            return response()->json(
                ['message' => 'Register is already open for this cashier and store.'],
                422
            );
        }

        $now = Carbon::now();

        $session = RegisterSession::create([
            'store_location_id' => $storeId,
            'cashier_id'        => $user->id,
            'opening_cash'      => $data['opening_cash'],
            'note_open'         => $data['note'] ?? null,
            'opened_at'         => $now,
        ]);

        return response()->json($session, 201);
    }

    public function close(Request $r, $id)
    {
        $user = $r->user();
        $storeId = optional($user)->store_location_id;

        if (!$storeId) {
            return response()->json(['message' => 'User does not have store location'], 422);
        }

        $data = $r->validate([
            'closing_cash' => ['nullable', 'numeric', 'min:0'],
            'note'         => ['nullable', 'string'],
        ]);

        $session = RegisterSession::where('id', $id)
            ->where('cashier_id', $user->id)
            ->where('store_location_id', $storeId)
            ->whereNull('closed_at')
            ->first();

        if (!$session) {
            return response()->json(['message' => 'Open register session not found'], 404);
        }

        $closingAt = Carbon::now();

        $summary = $this->buildSummary($session, $closingAt, true);

        // if closing_cash is not provided, default to expected cash
        $closingCash = array_key_exists('closing_cash', $data) && $data['closing_cash'] !== null
            ? (float) $data['closing_cash']
            : (float) $summary['totals']['expected_cash'];

        // adjust totals with final closing cash & difference
        $totals = $summary['totals'];
        $totals['closing_cash'] = $closingCash;
        $totals['difference'] = $closingCash - (float) $totals['expected_cash'];

        $session->update([
            'closing_cash'       => $closingCash,
            'note_close'         => $data['note'] ?? null,
            'closed_at'          => $closingAt,
            'total_transactions' => $totals['total_transactions'],
            'total_sales'        => $totals['total_sales'],
            'cash_payments'      => $totals['cash_payments'],
            'non_cash_payments'  => $totals['non_cash_payments'],
            'expected_cash'      => $totals['expected_cash'],
            'difference'         => $totals['difference'],
        ]);

        $session->refresh();

        return response()->json([
            'session'  => $session,
            'summary'  => $summary['header'],
            'totals'   => $totals,
            'sales'    => $summary['sales'],
        ]);
    }

    public function index(Request $r)
    {
        $user = $r->user();
        $q = RegisterSession::with(['cashier', 'storeLocation'])->latest('opened_at');

        // Restrict by store: each store only sees its own sessions
        if ($user->store_location_id) {
            $q->where('store_location_id', $user->store_location_id);
        } elseif ($r->filled('store_location_id')) {
            // Only allow query filter when user has no store (e.g. super-admin)
            $q->where('store_location_id', $r->store_location_id);
        }

        if (!$user->isAdmin()) {
            $q->where('cashier_id', $user->id);
        } elseif ($r->filled('cashier_id')) {
            $q->where('cashier_id', $r->cashier_id);
        }

        if ($r->filled('from')) {
            $q->whereDate('opened_at', '>=', $r->from);
        }
        if ($r->filled('to')) {
            $q->whereDate('opened_at', '<=', $r->to);
        }

        $perPage = max(1, min(200, (int) ($r->per_page ?? 20)));

        return response()->json(
            $q->paginate($perPage)
        );
    }

    public function show(Request $r, $id)
    {
        $user = $r->user();

        $session = RegisterSession::with(['cashier', 'storeLocation'])->findOrFail($id);

        // Each store can only view sessions for their own store
        if ($user->store_location_id && (int) $session->store_location_id !== (int) $user->store_location_id) {
            abort(403, 'Not allowed to view this register session');
        }
        if (!$user->isAdmin() && $session->cashier_id !== $user->id) {
            abort(403, 'Not allowed to view this register session');
        }

        $closingAt = $session->closed_at ?? Carbon::now();
        $summary = $this->buildSummary($session, $closingAt, (bool) $session->closed_at);

        return response()->json([
            'session' => $session,
            'summary' => $summary['header'],
            'totals'  => $summary['totals'],
            'sales'   => $summary['sales'],
        ]);
    }

    protected function buildSummary(RegisterSession $session, Carbon $closingAt, bool $includeClosedAtInHeader = false): array
    {
        $userId = $session->cashier_id;
        $storeId = $session->store_location_id;

        // Include both completed and void so register summary shows full transaction history
        $allSales = Sale::with(['payments', 'items.product'])
            ->where('cashier_id', $userId)
            ->where('store_location_id', $storeId)
            ->whereIn('status', ['completed', 'void'])
            ->whereBetween('created_at', [$session->opened_at, $closingAt])
            ->orderBy('id', 'asc')
            ->get();

        $completedSales = $allSales->where('status', 'completed');

        $totalTransactions = $completedSales->count();
        $totalSales = $completedSales->sum(function (Sale $s) {
            return $s->final_total ?? $s->total ?? 0;
        });

        $cashPayments = 0.0;
        $nonCashPayments = 0.0;
        foreach ($completedSales as $sale) {
            foreach ($sale->payments as $p) {
                $method = strtoupper((string) $p->method);
                if ($method === 'CASH') {
                    $cashPayments += (float) $p->amount;
                } else {
                    $nonCashPayments += (float) $p->amount;
                }
            }
        }

        $voidCount = $allSales->where('status', 'void')->count();
        $voidAmount = $allSales->where('status', 'void')->sum(function (Sale $s) {
            return $s->final_total ?? $s->total ?? 0;
        });

        $openingCash = (float) $session->opening_cash;
        $expectedCash = $openingCash + $cashPayments;

        $closingCash = $session->closed_at ? (float) ($session->closing_cash ?? 0) : 0.0;
        $difference = $closingCash - $expectedCash;

        $salesRows = $allSales->map(function (Sale $s) {
            $items = $s->items->map(function ($it) {
                return [
                    'product_id'   => $it->product_id,
                    'product_name'  => optional($it->product)->name ?? '-',
                    'product_sku'   => optional($it->product)->sku ?? null,
                    'qty'           => (int) $it->qty,
                    'unit_price'    => (float) ($it->net_unit_price ?? $it->unit_price ?? 0),
                    'line_total'    => (float) ($it->line_total ?? 0),
                ];
            })->values()->all();

            return [
                'id'           => $s->id,
                'code'         => $s->code,
                'date'         => $s->created_at?->toDateTimeString(),
                'customer'     => $s->customer_name,
                'total'        => $s->final_total ?? $s->total ?? 0,
                'status'       => $s->status,
                'items'        => $items,
            ];
        })->values();

        return [
            'header' => [
                'session_id'   => $session->id,
                'opened_at'    => $session->opened_at?->toDateTimeString(),
                'closed_at'    => $includeClosedAtInHeader ? $closingAt->toDateTimeString() : null,
                'opening_cash' => $openingCash,
                'cashier_name' => optional($session->cashier)->name,
                'store_name'   => optional($session->storeLocation)->name,
            ],
            'totals' => [
                'total_transactions'   => $totalTransactions,
                'total_sales'          => $totalSales,
                'cash_payments'        => $cashPayments,
                'non_cash_payments'    => $nonCashPayments,
                'opening_cash'         => $openingCash,
                'expected_cash'        => $expectedCash,
                'closing_cash'         => $closingCash,
                'difference'           => $difference,
                'void_transactions'    => $voidCount,
                'void_amount'           => $voidAmount,
            ],
            'sales' => $salesRows,
        ];
    }
}

