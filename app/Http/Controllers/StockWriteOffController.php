<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesStoreAccess;
use App\Models\StockWriteOff;
use App\Services\StockWriteOffService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class StockWriteOffController extends Controller
{
    use AuthorizesStoreAccess;

    public function __construct(private StockWriteOffService $service)
    {
    }

    /** GET /api/stock-write-offs */
    public function index(Request $r)
    {
        $q = StockWriteOff::query()
            ->with(['product:id,sku,name,unit_id', 'product.unit:id,name', 'user:id,name', 'storeLocation:id,code,name'])
            ->latest('id');

        $this->applySaleStoreScope($q, $r);

        if ($r->filled('reason')) {
            $q->where('reason', strtoupper((string) $r->input('reason')));
        }
        if ($r->filled('product_id')) {
            $q->where('product_id', (int) $r->input('product_id'));
        }
        if ($r->filled('from')) {
            $q->whereDate('created_at', '>=', $r->input('from'));
        }
        if ($r->filled('to')) {
            $q->whereDate('created_at', '<=', $r->input('to'));
        }
        if ($r->filled('search')) {
            $search = (string) $r->input('search');
            $q->whereHas('product', function ($qq) use ($search) {
                $qq->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%");
            });
        }

        $perPage = max(1, min(200, (int) ($r->per_page ?? 20)));
        $p = $q->paginate($perPage)->appends($r->query());

        return response()->json([
            'items' => $p->items(),
            'meta' => [
                'current_page' => $p->currentPage(),
                'per_page' => $p->perPage(),
                'last_page' => $p->lastPage(),
                'total' => $p->total(),
            ],
        ]);
    }

    /** GET /api/stock-write-offs/summary — totals grouped by reason */
    public function summary(Request $r)
    {
        $q = StockWriteOff::query();
        $this->applySaleStoreScope($q, $r);

        if ($r->filled('from')) {
            $q->whereDate('created_at', '>=', $r->input('from'));
        }
        if ($r->filled('to')) {
            $q->whereDate('created_at', '<=', $r->input('to'));
        }

        $rows = $q->selectRaw('reason, SUM(qty) AS qty, SUM(total_cost) AS cost')
            ->groupBy('reason')
            ->get();

        $byReason = [];
        foreach (StockWriteOff::REASONS as $reason) {
            $row = $rows->firstWhere('reason', $reason);
            $byReason[$reason] = [
                'reason' => $reason,
                'label' => StockWriteOff::reasonLabels()[$reason] ?? $reason,
                'qty' => (int) ($row->qty ?? 0),
                'cost' => (float) ($row->cost ?? 0),
            ];
        }

        return response()->json([
            'by_reason' => array_values($byReason),
            'total_qty' => (int) $rows->sum('qty'),
            'total_cost' => (float) $rows->sum('cost'),
        ]);
    }

    /** POST /api/stock-write-offs */
    public function store(Request $r)
    {
        $data = $r->validate([
            'store_location_id' => ['required', 'integer', 'exists:store_locations,id'],
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'qty' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', Rule::in(StockWriteOff::REASONS)],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $user = $r->user();
        $storeId = (int) $data['store_location_id'];
        $this->authorizeStoreAccess($user, $storeId);

        try {
            $writeOff = $this->service->record([
                'product_id' => (int) $data['product_id'],
                'qty' => (int) $data['qty'],
                'reason' => $data['reason'],
                'store_location_id' => $storeId,
                'user_id' => $user?->id,
                'note' => $data['note'] ?? null,
            ]);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages([
                'qty' => [$e->getMessage()],
            ]);
        }

        return response()->json(
            $writeOff->load(['product:id,sku,name', 'user:id,name']),
            201
        );
    }

    /** GET /api/stock-write-offs/reasons */
    public function reasons()
    {
        $out = [];
        foreach (StockWriteOff::reasonLabels() as $value => $label) {
            $out[] = ['value' => $value, 'label' => $label];
        }

        return response()->json(['items' => $out]);
    }
}
