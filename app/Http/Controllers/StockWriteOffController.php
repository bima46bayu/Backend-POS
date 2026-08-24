<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesStoreAccess;
use App\Models\StockWriteOff;
use App\Services\StockWriteOffService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
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
            ->with([
                'product:id,sku,name,unit_id',
                'product.unit:id,name',
                'qtyUnit:id,name',
                'user:id,name',
                'storeLocation:id,code,name',
            ])
            ->latest('id');

        $this->applySaleStoreScope($q, $r);
        $this->applyListFilters($q, $r);

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

    /** GET /api/stock-write-offs/summary — totals grouped by reason (submitted only) */
    public function summary(Request $r)
    {
        $q = StockWriteOff::query()
            ->where('status', StockWriteOff::STATUS_SUBMITTED);
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
                'qty' => (float) ($row->qty ?? 0),
                'cost' => (float) ($row->cost ?? 0),
            ];
        }

        return response()->json([
            'by_reason' => array_values($byReason),
            'total_qty' => (float) $rows->sum('qty'),
            'total_cost' => (float) $rows->sum('cost'),
        ]);
    }

    /**
     * GET /api/stock-write-offs/batches — one row per "Catat Waste" document.
     *
     * Filters narrow which documents show up; each document is returned with all its lines.
     */
    public function batches(Request $r)
    {
        $filtered = StockWriteOff::query();
        $this->applySaleStoreScope($filtered, $r);
        $this->applyListFilters($filtered, $r);

        $perPage = max(1, min(100, (int) ($r->per_page ?? 20)));
        $page = max(1, (int) ($r->page ?? 1));

        $total = (clone $filtered)->distinct()->count('batch_uid');
        $lastPage = max(1, (int) ceil($total / $perPage));

        $uids = (clone $filtered)
            ->select('batch_uid')
            ->selectRaw('MAX(id) AS last_id')
            ->groupBy('batch_uid')
            ->orderByDesc('last_id')
            ->forPage($page, $perPage)
            ->pluck('batch_uid')
            ->all();

        $rows = StockWriteOff::query()
            ->whereIn('batch_uid', $uids)
            ->with([
                'product:id,sku,name,unit_id',
                'product.unit:id,name',
                'qtyUnit:id,name',
                'user:id,name',
                'storeLocation:id,code,name',
            ])
            ->orderBy('id')
            ->get()
            ->groupBy('batch_uid');

        $items = [];
        foreach ($uids as $uid) {
            $group = $rows->get($uid);
            if ($group && $group->isNotEmpty()) {
                $items[] = $this->batchPayload($uid, $group);
            }
        }

        return response()->json([
            'items' => $items,
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'last_page' => $lastPage,
                'total' => $total,
            ],
        ]);
    }

    /** POST /api/stock-write-offs — creates DRAFT rows (stock untouched); `items` saves them as one document */
    public function store(Request $r)
    {
        if ($r->has('items')) {
            return $this->storeBatch($r);
        }

        $data = $r->validate([
            'store_location_id' => ['required', 'integer', 'exists:store_locations,id'],
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'qty' => ['required', 'numeric', 'gt:0'],
            'qty_unit_id' => ['nullable', 'integer', 'exists:units,id'],
            'reason' => ['required', 'string', Rule::in(StockWriteOff::REASONS)],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $user = $r->user();
        $storeId = (int) $data['store_location_id'];
        $this->authorizeStoreAccess($user, $storeId);

        try {
            $writeOff = $this->service->createDraft([
                'product_id' => (int) $data['product_id'],
                'qty' => (float) $data['qty'],
                'qty_unit_id' => $data['qty_unit_id'] ?? null,
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
            $writeOff->load(['product:id,sku,name,unit_id', 'product.unit:id,name', 'qtyUnit:id,name', 'user:id,name']),
            201
        );
    }

    /** PUT /api/stock-write-offs/{id} — edit draft only */
    public function update(Request $r, StockWriteOff $stockWriteOff)
    {
        $this->authorizeStoreAccess($r->user(), (int) $stockWriteOff->store_location_id);

        $data = $r->validate([
            'product_id' => ['sometimes', 'integer', 'exists:products,id'],
            'qty' => ['sometimes', 'numeric', 'gt:0'],
            'qty_unit_id' => ['nullable', 'integer', 'exists:units,id'],
            'reason' => ['sometimes', 'string', Rule::in(StockWriteOff::REASONS)],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $writeOff = $this->service->updateDraft($stockWriteOff, $data);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages([
                'qty' => [$e->getMessage()],
            ]);
        }

        return response()->json(
            $writeOff->load(['product:id,sku,name,unit_id', 'product.unit:id,name', 'qtyUnit:id,name', 'user:id,name'])
        );
    }

    /** POST /api/stock-write-offs/{id}/submit — consume FIFO + lock */
    public function submit(Request $r, StockWriteOff $stockWriteOff)
    {
        $this->authorizeStoreAccess($r->user(), (int) $stockWriteOff->store_location_id);

        try {
            $writeOff = $this->service->submit($stockWriteOff, $r->user()?->id);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages([
                'qty' => [$e->getMessage()],
            ]);
        }

        return response()->json(
            $writeOff->load(['product:id,sku,name', 'user:id,name'])
        );
    }

    /** DELETE /api/stock-write-offs/{id} — draft only */
    public function destroy(Request $r, StockWriteOff $stockWriteOff)
    {
        $this->authorizeStoreAccess($r->user(), (int) $stockWriteOff->store_location_id);

        try {
            $this->service->deleteDraft($stockWriteOff);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages([
                'status' => [$e->getMessage()],
            ]);
        }

        return response()->json(['ok' => true]);
    }

    /** PUT /api/stock-write-offs/batches/{uid} — replace the lines of a draft document */
    public function updateBatch(Request $r, string $batchUid)
    {
        $first = $this->firstBatchRow($batchUid);
        $this->authorizeStoreAccess($r->user(), (int) $first->store_location_id);

        $data = $r->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.qty' => ['required', 'numeric', 'gt:0'],
            'items.*.qty_unit_id' => ['nullable', 'integer', 'exists:units,id'],
            'items.*.reason' => ['required', 'string', Rule::in(StockWriteOff::REASONS)],
            'items.*.note' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $rows = $this->service->updateBatch($batchUid, $data['items'], $r->user()?->id);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages([
                'qty' => [$e->getMessage()],
            ]);
        }

        return response()->json($this->batchPayload($batchUid, collect($rows)));
    }

    /** POST /api/stock-write-offs/batches/{uid}/submit — consume FIFO for every line at once */
    public function submitBatch(Request $r, string $batchUid)
    {
        $first = $this->firstBatchRow($batchUid);
        $this->authorizeStoreAccess($r->user(), (int) $first->store_location_id);

        try {
            $rows = $this->service->submitBatch($batchUid, $r->user()?->id);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages([
                'qty' => [$e->getMessage()],
            ]);
        }

        return response()->json($this->batchPayload($batchUid, collect($rows)));
    }

    /** DELETE /api/stock-write-offs/batches/{uid} — draft document only */
    public function destroyBatch(Request $r, string $batchUid)
    {
        $first = $this->firstBatchRow($batchUid);
        $this->authorizeStoreAccess($r->user(), (int) $first->store_location_id);

        try {
            $this->service->deleteBatch($batchUid);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages([
                'status' => [$e->getMessage()],
            ]);
        }

        return response()->json(['ok' => true]);
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

    /** Multi-line create: every row lands in the same document. */
    protected function storeBatch(Request $r)
    {
        $data = $r->validate([
            'store_location_id' => ['required', 'integer', 'exists:store_locations,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.qty' => ['required', 'numeric', 'gt:0'],
            'items.*.qty_unit_id' => ['nullable', 'integer', 'exists:units,id'],
            'items.*.reason' => ['required', 'string', Rule::in(StockWriteOff::REASONS)],
            'items.*.note' => ['nullable', 'string', 'max:255'],
        ]);

        $user = $r->user();
        $storeId = (int) $data['store_location_id'];
        $this->authorizeStoreAccess($user, $storeId);

        try {
            $rows = $this->service->createBatch($data['items'], $storeId, $user?->id);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages([
                'qty' => [$e->getMessage()],
            ]);
        }

        $batchUid = (string) $rows[0]->batch_uid;

        return response()->json($this->batchPayload($batchUid, collect($rows)), 201);
    }

    protected function applyListFilters($q, Request $r): void
    {
        if ($r->filled('status')) {
            $q->where('status', strtolower((string) $r->input('status')));
        }
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
    }

    protected function firstBatchRow(string $batchUid): StockWriteOff
    {
        $row = StockWriteOff::query()
            ->where('batch_uid', $batchUid)
            ->orderBy('id')
            ->first();

        abort_if(! $row, 404, 'Write-off tidak ditemukan.');

        return $row;
    }

    /** @param \Illuminate\Support\Collection<int, StockWriteOff> $rows */
    protected function batchPayload(string $batchUid, $rows): array
    {
        $rows = EloquentCollection::make($rows->all())->sortBy('id')->values();
        $rows->loadMissing([
            'product:id,sku,name,unit_id',
            'product.unit:id,name',
            'qtyUnit:id,name',
            'user:id,name',
            'storeLocation:id,code,name',
        ]);

        $drafts = $rows->filter(fn (StockWriteOff $row) => $row->isDraft())->count();
        $status = $drafts === $rows->count()
            ? StockWriteOff::STATUS_DRAFT
            : ($drafts === 0 ? StockWriteOff::STATUS_SUBMITTED : 'partial');

        $first = $rows->first();

        return [
            'batch_uid' => $batchUid,
            'store_location_id' => $first->store_location_id,
            'store_location' => $first->storeLocation,
            'user' => $first->user,
            'status' => $status,
            'created_at' => $rows->min('created_at'),
            'submitted_at' => $rows->max('submitted_at'),
            'items_count' => $rows->count(),
            'total_qty' => (float) $rows->sum('qty'),
            'total_cost' => (float) $rows->sum('total_cost'),
            'reasons' => $rows->pluck('reason')->unique()->values()->all(),
            'items' => $rows->all(),
        ];
    }
}
