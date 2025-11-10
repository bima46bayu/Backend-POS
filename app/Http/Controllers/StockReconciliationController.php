<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\StockReconciliation;
use App\Models\StockReconciliationItem;
use App\Services\StockReconciliationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StockReconciliationController extends Controller
{
    public function __construct(private StockReconciliationService $svc) {}

    /** GET /api/stock-reconciliation?store_id&status */
    public function index(Request $r)
    {
        $q = StockReconciliation::query()
            ->when($r->filled('store_id'), fn($qq)=>$qq->where('store_location_id',(int)$r->input('store_id')))
            ->when($r->filled('status'), fn($qq)=>$qq->where('status', strtoupper($r->input('status'))))
            ->with(['user:id,name'])              // ⬅️ tambah ini
            ->orderByDesc('id');

        return response()->json($q->paginate(25));
    }

    /** POST /api/stock-reconciliation { store_id, date_from, date_to } */
    public function store(Request $r) {
        $data = $r->validate([
            'store_id'  => 'required|integer',
            'date_from' => 'nullable|date',
            'date_to'   => 'nullable|date',
        ]);

        $storeId = (int) $data['store_id'];
        $from    = $data['date_from'] ?? null;
        $to      = $data['date_to']   ?? null;

        DB::beginTransaction();
        try {
            $ref = $this->svc->nextRef();
            $rec = StockReconciliation::create([
                'reference_code'   => $ref,
                'store_location_id'=> $storeId,
                'user_id'          => (int)auth()->id(),
                'status'           => 'DRAFT',
                'date_from'        => $from,
                'date_to'          => $to,
            ]);

            // generate draft item: system_stock & avg_cost
            $system = $this->svc->systemStocks($storeId);               // [product_id => qty]
            $avg    = $this->svc->avgCosts($storeId, $from, $to);       // [product_id => cost]

            $products = Product::select('id','sku','name')->get()->keyBy('id');

            $rows = [];
            foreach ($system as $pid => $qty) {
                $p = $products[$pid] ?? null;
                if (!$p) continue;
                $rows[] = [
                    'reconciliation_id'=> $rec->id,
                    'product_id'       => (int)$pid,
                    'sku'              => $p->sku,
                    'name'             => $p->name,
                    'system_stock'     => (float)$qty,
                    'real_stock'       => null,
                    'avg_cost'         => (float)($avg[$pid] ?? 0),
                    'diff_stock'       => 0,
                    'direction'        => 'NONE',
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ];
            }
            if (!empty($rows)) DB::table('stock_reconciliation_items')->insert($rows);

            $rec->update([
                'total_items' => count($rows),
                'total_value' => 0,
            ]);

            DB::commit();
            return response()->json([
                'id' => $rec->id,
                'reference_code' => $rec->reference_code,
                'status' => $rec->status,
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Create reconciliation failed: '.$e->getMessage());
            return response()->json(['message'=>'Create reconciliation failed'], 500);
        }
    }

    /** GET /api/stock-reconciliation/{id} */
    public function show($id)
    {
        $rec = StockReconciliation::with(['user:id,name'])->findOrFail($id); // ⬅️ load nama
        $items = StockReconciliationItem::where('reconciliation_id',$rec->id)
            ->orderBy('name')->get();

        return response()->json([
            'reconciliation' => $rec,
            'items'          => $items,
        ]);
    }

    /** GET /api/stock-reconciliation/template?store_id&date_from&date_to */
    public function template(Request $r)
    {
        $storeId  = (int) $r->input('store_id');
        $from     = $r->input('date_from'); // YYYY-MM-DD
        $to       = $r->input('date_to');   // YYYY-MM-DD

        // ambil daftar produk yang relevan (contoh sederhana: semua produk; bisa difilter by store kalau kamu punya relasi per store)
        $products = DB::table('products')
            ->select('id','sku','name','price','stock','store_location_id')
            ->when($storeId > 0 && Schema::hasColumn('products','store_location_id'),
                fn($q) => $q->where(function($qq) use ($storeId){
                    $qq->whereNull('store_location_id')->orWhere('store_location_id', $storeId);
                })
            )
            ->orderBy('name')
            ->get();

        // siapkan avg_cost per product (dari GR pada range, fallback ke GR terbaru di luar range, terakhir fallback 0)
        $avgCostByProduct = [];

        if (Schema::hasTable('stock_ledger')) {
            $ledger = DB::table('stock_ledger')->whereIn('product_id', $products->pluck('id'))
                ->when($storeId > 0 && Schema::hasColumn('stock_ledger','store_location_id'),
                    fn($q)=>$q->where('store_location_id',$storeId)
                );

            // prioritas: rata-rata cost GR pada periode
            $periodAvg = (clone $ledger)
                ->when($from, fn($q)=>$q->where('created_at','>=', $from.' 00:00:00'))
                ->when($to,   fn($q)=>$q->where('created_at','<=', $to.' 23:59:59'))
                ->where('ref_type','GR')
                ->selectRaw('product_id, COALESCE(NULLIF(SUM(COALESCE(subtotal_cost, qty * unit_cost)),0)/NULLIF(SUM(qty),0),0) as avg_cost')
                ->groupBy('product_id')
                ->pluck('avg_cost','product_id');

            foreach ($periodAvg as $pid => $c) $avgCostByProduct[(int)$pid] = (float)$c;

            // fallback: ambil unit_cost terakhir dari GR terbaru (tanpa batas periode)
            $latestGR = (clone $ledger)
                ->where('ref_type','GR')
                ->selectRaw('product_id, COALESCE(unit_cost,0) as unit_cost, created_at')
                ->orderBy('created_at','desc')
                ->get()
                ->unique('product_id');

            foreach ($latestGR as $row) {
                $pid = (int)$row->product_id;
                if (!array_key_exists($pid, $avgCostByProduct) || $avgCostByProduct[$pid] <= 0) {
                    $avgCostByProduct[$pid] = (float)$row->unit_cost;
                }
            }
        }

        // build spreadsheet
        $ss = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->setTitle('Reconciliation');

        // header
        $headers = ['product_id','sku','name','system_stock','real_stock','avg_cost','note'];
        $sheet->fromArray([$headers], null, 'A1');
        foreach (range('A','G') as $c) $sheet->getColumnDimension($c)->setAutoSize(true);
        $sheet->freezePane('A2');

        // rows
        $rows = [];
        foreach ($products as $p) {
            $pid     = (int)$p->id;
            $sysStok = (float)($p->stock ?? 0);
            $avgCost = isset($avgCostByProduct[$pid]) ? (float)$avgCostByProduct[$pid] : 0.0;

            $rows[] = [
                $pid,
                (string)($p->sku ?? ''),
                (string)$p->name,
                $sysStok,
                '',          // real_stock diisi user
                $avgCost,    // default cost untuk adjustment
                '',          // note opsional
            ];
        }

        if (!empty($rows)) {
            $sheet->fromArray($rows, null, 'A2');
        }

        // meta kecil di cell terpisah (opsional)
        $sheet->setCellValue('I1', 'Store ID');
        $sheet->setCellValue('J1', $storeId ?: '-');
        $sheet->setCellValue('I2', 'Period');
        $sheet->setCellValue('J2', ($from ?: '-') . ' s/d ' . ($to ?: '-'));

        // stream out
        $writer = new Xlsx($ss);
        $filename = 'template-stock-opname.xlsx';

        return new StreamedResponse(function() use ($writer) {
            $writer->save('php://output');
        }, 200, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /** POST /api/stock-reconciliation/{id}/upload  (file: xlsx/xls) */
    public function upload($id, Request $r) {
        $rec = StockReconciliation::findOrFail($id);
        $r->validate(['file'=>'required|file|mimes:xlsx,xls|max:10240']);

        // baca excel
        $ext = strtolower($r->file('file')->getClientOriginalExtension());
        $reader = $ext === 'xlsx'
            ? new \PhpOffice\PhpSpreadsheet\Reader\Xlsx()
            : \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($r->file('file')->getRealPath());
        $reader->setReadDataOnly(true);
        $sheet = $reader->load($r->file('file')->getRealPath())->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, true);

        // map header
        $map = function($want, $fallback) use ($rows) {
            $want = strtolower($want);
            foreach ($rows[1] as $col=>$val) if (strtolower((string)$val) === $want) return $col;
            return $fallback;
        };
        $colPid = $map('product_id','A');
        $colSku = $map('sku','B');
        $colReal= $map('real_stock','F');
        $colNote= $map('note','G');

        $errors = 0; $updated = 0; $items = [];
        DB::beginTransaction();
        try {
            for ($i=2; $i<=count($rows); $i++) {
                $pid  = (int)($rows[$i][$colPid] ?? 0);
                $sku  = trim((string)($rows[$i][$colSku] ?? ''));
                $real = $rows[$i][$colReal] ?? null;
                $note = trim((string)($rows[$i][$colNote] ?? ''));

                if ($pid<=0 && $sku==='') continue;

                $item = StockReconciliationItem::where('reconciliation_id',$rec->id)
                    ->when($pid>0, fn($q)=>$q->where('product_id',$pid))
                    ->when(!$pid && $sku!=='', fn($q)=>$q->where('sku',$sku))
                    ->first();

                if (!$item) { $errors++; continue; }

                $real = is_numeric($real) ? (float)$real : null;
                if ($real === null || $real < 0) { $errors++; continue; }

                $diff = $real - (float)$item->system_stock;
                $dir  = $diff > 0 ? 'IN' : ($diff < 0 ? 'OUT' : 'NONE');

                $item->real_stock = $real;
                $item->diff_stock = $diff;
                $item->direction  = $dir;
                if ($note !== '') $item->note = $note;
                $item->save();
                $updated++;

                $items[] = $item->toArray();
            }

            // update ringkasan header
            $sumVal = (float) StockReconciliationItem::where('reconciliation_id',$rec->id)
                ->selectRaw('COALESCE(SUM(ABS(diff_stock) * avg_cost),0) as v')->value('v');
            $rec->update(['total_value'=>$sumVal]);

            DB::commit();
            return response()->json(['updated'=>$updated,'errors'=>$errors,'items'=>$items]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message'=>'Upload gagal','error'=>$e->getMessage()], 500);
        }
    }

    /** POST /api/stock-reconciliation/{id}/apply */
    public function apply($id, Request $r) {
        $rec = StockReconciliation::findOrFail($id);
        if ($rec->status !== 'DRAFT') {
            return response()->json(['message'=>'Hanya DRAFT yang bisa di-apply'], 422);
        }

        $items = StockReconciliationItem::where('reconciliation_id',$rec->id)->get();

        DB::beginTransaction();
        try {
            $affected = 0;
            $pids = [];
            foreach ($items as $it) {
                if ((float)$it->diff_stock === 0.0) continue;
                $this->svc->applyItem($it->toArray(), (int)$rec->store_location_id, (int)$rec->id, (int)auth()->id());
                $affected++;
                $pids[] = (int)$it->product_id;
            }

            // re-sync products.stock
            $this->svc->resyncProductsStock($pids, (int)$rec->store_location_id);

            $rec->update([
                'status'        => 'APPLIED',
                'reconciled_at' => now(),
                'user_id'       => (int)auth()->id(),
            ]);

            DB::commit();
            return response()->json([
                'message'  => 'Rekonsiliasi diterapkan',
                'affected' => $affected,
                'reconciliation_id' => $rec->id,
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message'=>'Gagal apply rekonsiliasi','error'=>$e->getMessage()], 500);
        }
    }

    // DELETE /api/stock-reconciliation/{id}
    public function destroy($id)
    {
        $rec = StockReconciliation::findOrFail($id);

        if (strtoupper($rec->status) !== 'DRAFT') {
            return response()->json([
                'message' => 'Hanya rekonsiliasi dengan status DRAFT yang dapat dihapus.'
            ], 422);
        }

        DB::beginTransaction();
        StockReconciliationItem::where('reconciliation_id', $rec->id)->delete();
        $rec->delete();
        DB::commit();

        return response()->json(['message' => 'Rekonsiliasi berhasil dihapus.', 'id' => (int)$id], 200);
    }

}
