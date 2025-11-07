<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\StreamedResponse;

use App\Models\Product;
use App\Models\Category;
use App\Models\SubCategory;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\NamedRange;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ProductImportController extends Controller
{
    /** ========== 1) Download template XLSX dengan dropdown Category/ID → Subcategory/ID ========== */
    public function template(Request $r)
    {
        $categories = \App\Models\Category::orderBy('name')->get(['id','name']);
        $subcats    = \App\Models\SubCategory::orderBy('name')->get(['id','name','category_id']);

        $ss = new \PhpOffice\PhpSpreadsheet\Spreadsheet();

        // ========== Sheet Products ==========
        $ws = $ss->getActiveSheet();
        $ws->setTitle('Products');
        $ws->fromArray([['SKU','Name','Price','Stock','Category','Subcategory','Description']], null, 'A1');
        foreach (range('A','G') as $c) $ws->getColumnDimension($c)->setAutoSize(true);
        $ws->freezePane('A2');

        // ========== Sheet Categories (helper) ==========
        $helper = new \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet($ss, 'Categories');
        $ss->addSheet($helper);

        // A: Category Name, B: CategoryKey (untuk INDIRECT), mulai baris 2
        $helper->fromArray([['Category','CategoryKey']], null, 'A1');

        $sanitize = function(string $n){
            $k = preg_replace('/\s+/', '_', trim($n));
            $k = preg_replace('/[^A-Za-z0-9_]/', '', $k);
            if (!preg_match('/^[A-Za-z_]/', $k)) $k = '_'.$k;
            return strtoupper($k);
        };

        // Peta kategori -> subcategories
        $byCat = [];
        foreach ($categories as $c) {
            $byCat[$c->id] = ['name'=>$c->name, 'key'=>$sanitize($c->name), 'subs'=>[]];
        }
        foreach ($subcats as $s) {
            if (isset($byCat[$s->category_id])) $byCat[$s->category_id]['subs'][] = $s->name;
        }

        // Tulis kategori (kolom A & B)
        $r = 2;
        foreach ($byCat as $info) {
            $helper->fromArray([[$info['name'], $info['key']]], null, "A{$r}");
            $r++;
        }
        $catCount = max(0, count($byCat));
        $lastRow  = 1 + $catCount; // karena mulai dari baris 2

        // Subcategory per kategori → NamedRange horizontal (mulai C)
        $startColIdx = 3; // C
        $r = 2;
        foreach ($byCat as $info) {
            $names = $info['subs'];
            $key   = $info['key'];

            if (!empty($names)) {
                $helper->fromArray([$names], null, $this->col($startColIdx).$r);
                $lastColIdx = $startColIdx + count($names) - 1;
                $range = "Categories!".$this->col($startColIdx).$r.":".$this->col($lastColIdx).$r;
            } else {
                // minimal 1 sel kosong biar INDIRECT tidak error
                $helper->setCellValue($this->col($startColIdx).$r, '');
                $range = "Categories!".$this->col($startColIdx).$r.":".$this->col($startColIdx).$r;
            }
            $ss->addNamedRange(new \PhpOffice\PhpSpreadsheet\NamedRange($key, $helper, $range));
            $r++;
        }

        // ========== Data Validation ==========
        $startRow = 2; $endRow = 1000;

        // Range ABSOLUT untuk kategori: Categories!A2:A{lastRow}
        $catRange = "=Categories!\$A\$2:\$A\$".$lastRow;
        // Range ABSOLUT untuk MATCH (nama & key)
        $namesAbs = "\$A\$2:\$A\$".$lastRow;
        $keysAbs  = "\$B\$2:\$B\$".$lastRow;

        for ($row = $startRow; $row <= $endRow; $row++) {
            // Dropdown Category (E)
            $dvCat = $ws->getCell("E{$row}")->getDataValidation();
            $dvCat->setType(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::TYPE_LIST)
                ->setAllowBlank(true)->setShowDropDown(true)
                ->setShowErrorMessage(true)->setErrorTitle('Invalid Category')->setError('Select from the list.')
                ->setFormula1($catRange);

            // Dropdown Subcategory (F) — cascading via key
            $dvSub = $ws->getCell("F{$row}")->getDataValidation();
            $dvSub->setType(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::TYPE_LIST)
                ->setAllowBlank(true)->setShowDropDown(true)
                ->setShowErrorMessage(true)->setErrorTitle('Invalid Subcategory')->setError('Select based on Category.')
                ->setFormula1('=INDIRECT(INDEX(Categories!'.$keysAbs.', MATCH($E'.$row.', Categories!'.$namesAbs.', 0)))');
        }

        // (Opsional) Tooltip
        $ws->getComment('E1')->getText()->createTextRun('Pilih dari daftar.');
        $ws->getComment('F1')->getText()->createTextRun('Mengikuti kategori kolom E.');

        // NOTE: biarkan sheet helper terlihat dulu untuk memastikan datanya terisi.
        // Setelah kamu cek OK, boleh disembunyikan:
        // $helper->setSheetState(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet::SHEETSTATE_HIDDEN);

        // Pastikan sheet Products aktif saat disave
        $ss->setActiveSheetIndex(0);

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($ss);
        return new \Symfony\Component\HttpFoundation\StreamedResponse(function () use ($writer) {
            $writer->save('php://output');
        }, 200, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="product_import_template.xlsx"',
        ]);
    }

    private function col(int $idx): string {
        $s = '';
        while ($idx > 0) { $idx--; $s = chr(65 + ($idx % 26)).$s; $idx = intdiv($idx, 26); }
        return $s;
    }


        /** ========== 2) Proses import XLSX: parse (prioritas ID), create/upsert, store dari /me ========== */
public function import(Request $req)
{
    // --- inisialisasi awal agar tidak undefined ---
    $rows = [];
    $sheet = null;

    try {
        $req->validate([
            'file' => 'required|file|mimes:xlsx,xls|max:10240',
            'mode' => 'nullable|string|in:upsert,create-only',
        ]);
        $mode = $req->input('mode', 'upsert');

        // store dari user
        $user = $req->user();
        $storeId = (int)($user?->store_location_id ?? optional($user?->store_location)->id ?? 0);
        if ($storeId <= 0) {
            return response()->json(['status'=>'error','message'=>'Akun belum memiliki store_location_id.'], 422);
        }

        $uploaded = $req->file('file');
        if (!$uploaded || !$uploaded->isValid()) {
            return response()->json(['status'=>'error','message'=>'File upload invalid'], 422);
        }

        // Reader sesuai ekstensi
        $ext = strtolower($uploaded->getClientOriginalExtension());
        $reader = $ext === 'xlsx'
            ? new \PhpOffice\PhpSpreadsheet\Reader\Xlsx()
            : \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($uploaded->getRealPath());
        $reader->setReadDataOnly(true);

        $spreadsheet = $reader->load($uploaded->getRealPath());

        // --- pastikan $sheet valid sebelum dipakai ---
        $sheet = $spreadsheet->getSheetByName('Products') ?: $spreadsheet->getActiveSheet();
        if (!$sheet) {
            return response()->json(['status'=>'error','message'=>'Worksheet "Products" tidak ditemukan'], 422);
        }

        // --- set $rows SEKARANG (sebelum ada proses lain) ---
        $rows = $sheet->toArray(null, true, true, true);

        // header wajib ada
        if (!isset($rows[1]) || !is_array($rows[1])) {
            return response()->json(['status'=>'error','message'=>'Header tidak ditemukan di baris pertama'], 422);
        }

        // ------- header map (case-insensitive, aman) -------
        $findCol = function($want, $fallback) use ($rows) {
            $want = strtolower($want);
            foreach ($rows[1] as $col => $val) {
                if (strtolower(trim((string)$val)) === $want) return $col;
            }
            return $fallback; // fallback untuk template standar
        };
        $col = [
            'sku'         => $findCol('sku', 'A'),
            'name'        => $findCol('name', 'B'),
            'price'       => $findCol('price', 'C'),
            'stock'       => $findCol('stock', 'D'),
            'cat_name'    => $findCol('category', 'E'),
            'sub_name'    => $findCol('subcategory', 'F'),
            'description' => $findCol('description', 'G'),
        ];

        // ------- cache master: Category & SubCategory -------
        $catByName = \App\Models\Category::all()->keyBy(fn($c) => mb_strtolower(trim($c->name)));
        $subs = \App\Models\SubCategory::select(['id','name','category_id'])->get();
        $subByCatAndName = $subs->keyBy(fn($s) => ((int)$s->category_id).'::'.mb_strtolower(trim($s->name)));

        // ------- flags untuk inventory -------
        $hasLedger = \Illuminate\Support\Facades\Schema::hasTable('stock_ledger');
        $hasLayers = \Illuminate\Support\Facades\Schema::hasTable('inventory_layers');
        $hasInventory = $hasLedger || $hasLayers;

        // helper angka
        $num = function($v) {
            if ($v === null || $v === '') return null;
            if (is_numeric($v)) return (float)$v;
            $s = str_replace([' ', "\u{00A0}"], '', (string)$v);
            $s = preg_replace('/\.(?=\d{3}(\D|$))/', '', $s); // buang thousand dot
            $s = str_replace(',', '.', $s);
            return is_numeric($s) ? (float)$s : null;
        };

        $created=0; $updated=0; $errors=[];

        \DB::beginTransaction();
        try {
            $maxRow = count($rows);
            for ($r = 2; $r <= $maxRow; $r++) {
                try {
                    $line = $rows[$r] ?? null; if (!$line) continue;

                    $sku   = trim((string)($line[$col['sku']] ?? ''));
                    $name  = trim((string)($line[$col['name']] ?? ''));
                    $price = $num($line[$col['price']] ?? null);
                    $stock = $num($line[$col['stock']] ?? null);
                    $catNm = trim((string)($line[$col['cat_name']] ?? ''));
                    $subNm = trim((string)($line[$col['sub_name']] ?? ''));
                    $desc  = trim((string)($line[$col['description']] ?? ''));

                    // skip baris kosong
                    if ($sku==='' && $name==='' && $catNm==='' && $subNm==='' && $price===null && $stock===null && $desc==='') {
                        continue;
                    }
                    if ($name==='') { $errors[]=['row'=>$r,'message'=>'Name is required']; continue; }

                    // resolve category & subcategory by name
                    $categoryId = null; $subcategoryId = null;
                    if ($catNm !== '') {
                        $cat = $catByName[mb_strtolower($catNm)] ?? null;
                        if (!$cat) { $errors[]=['row'=>$r,'message'=>"Category '{$catNm}' not found"]; continue; }
                        $categoryId = (int)$cat->id;

                        if ($subNm !== '') {
                            $sub = $subByCatAndName[$categoryId.'::'.mb_strtolower($subNm)] ?? null;
                            if (!$sub) { $errors[]=['row'=>$r,'message'=>"Subcategory '{$subNm}' (Category '{$cat->name}') not found"]; continue; }
                            $subcategoryId = (int)$sub->id;
                        }
                    } elseif ($subNm !== '') {
                        $errors[]=['row'=>$r,'message'=>"Subcategory '{$subNm}' given but Category empty"]; continue;
                    }

                    // angka default
                    $price = $price ?? 0.0;
                    $stock = $stock ?? 0.0;

                    if ($mode === 'create-only' || $sku==='') {
                        if ($sku !== '' && \App\Models\Product::where('sku', $sku)->exists()) {
                            $errors[] = ['row'=>$r,'message'=>"SKU '{$sku}' already exists"];
                            continue;
                        }

                        $p = new \App\Models\Product();
                        if ($sku!=='') $p->sku = $sku;
                        $p->name            = $name;
                        $p->price           = $price;
                        if (!$hasInventory) $p->stock = $stock;
                        $p->category_id     = $categoryId;
                        $p->sub_category_id = $subcategoryId;
                        $p->description     = $desc ?: null;
                        if (\Illuminate\Support\Facades\Schema::hasColumn($p->getTable(), 'store_location_id')) {
                            $p->store_location_id = $storeId;
                        }
                        $p->save();
                        $created++;

                        if ($hasInventory && $stock > 0) {
                            $this->recordOpeningStock($p->id, (float)$stock, $storeId, $price, false);
                        }
                    } else {
                        $p = $sku !== '' ? \App\Models\Product::where('sku', $sku)->first() : null;
                        if ($p) {
                            $p->name            = $name ?: $p->name;
                            $p->price           = $price;
                            if (!$hasInventory) $p->stock = $stock;
                            $p->category_id     = $categoryId ?? $p->category_id;
                            $p->sub_category_id = $subcategoryId ?? $p->sub_category_id;
                            $p->description     = ($desc !== '') ? $desc : $p->description;
                            $p->save();
                            $updated++;

                            if ($hasInventory && $stock > 0) {
                                $this->recordOpeningStock($p->id, (float)$stock, $storeId, $price, true);
                            }
                        } else {
                            $p = new \App\Models\Product();
                            if ($sku!=='') $p->sku = $sku;
                            $p->name            = $name;
                            $p->price           = $price;
                            if (!$hasInventory) $p->stock = $stock;
                            $p->category_id     = $categoryId;
                            $p->sub_category_id = $subcategoryId;
                            $p->description     = $desc ?: null;
                            if (\Illuminate\Support\Facades\Schema::hasColumn($p->getTable(), 'store_location_id')) {
                                $p->store_location_id = $storeId;
                            }
                            $p->save();
                            $created++;

                            if ($hasInventory && $stock > 0) {
                                $this->recordOpeningStock($p->id, (float)$stock, $storeId, $price, false);
                            }
                        }
                    }
                } catch (\Throwable $rowEx) {
                    $errors[] = ['row'=>$r, 'message'=>$rowEx->getMessage()];
                }
            }
            \DB::commit();
        } catch (\Throwable $e) {
            \DB::rollBack();
            \Log::error('Import failed (DB): '.$e->getMessage(), ['trace'=>$e->getTraceAsString()]);
            return response()->json(['status'=>'error','message'=>'Import failed: '.$e->getMessage()], 500);
        }

        return response()->json([
            'status'=>'ok',
            'summary'=>[
                'created'=>$created,
                'updated'=>$updated,
                'errors'=>$errors,
                'total_rows_processed'=>($created+$updated+count($errors)),
            ]
        ]);
    } catch (\Throwable $e) {
        // --- JANGAN referensi $rows di sini; cukup kirim pesan umum ---
        \Log::error('Import failed (IO): '.$e->getMessage(), ['trace'=>$e->getTraceAsString()]);
        return response()->json(['status'=>'error','message'=>'Import failed: '.$e->getMessage()], 500);
    }
}

/**
 * Catat stok awal/penyesuaian ke inventory tables bila tersedia.
 * Menggunakan store_location_id dari user yang mengupload.
 * Unit cost diisi dari price (jika ada) atau 0.
 */
protected function recordOpeningStock(int $productId, float $qty, int $storeId, ?float $price, bool $asAdjustment = false): void
{
    $now  = now();
    $cost = is_numeric($price) ? (float)$price : 0.0;

    // ===== INVENTORY LAYERS (adaptif) =====
    if (\Illuminate\Support\Facades\Schema::hasTable('inventory_layers')) {
        try {
            $cols = \Illuminate\Support\Facades\Schema::getColumnListing('inventory_layers');
            $has  = fn($n) => in_array($n, $cols, true);

            $storeCol = $has('store_location_id') ? 'store_location_id' : ($has('store_id') ? 'store_id' : null);
            $qtyCol   = $has('qty') ? 'qty' : ($has('quantity') ? 'quantity' : ($has('initial_qty') ? 'initial_qty' : ($has('opening_qty') ? 'opening_qty' : null)));
            $remCol   = $has('remaining_qty') ? 'remaining_qty' : ($has('remaining_quantity') ? 'remaining_quantity' : ($has('qty_remaining') ? 'qty_remaining' : null));
            $costCol  = $has('unit_cost') ? 'unit_cost' : ($has('unit_price') ? 'unit_price' : ($has('cost') ? 'cost' : null));
            $srcCol   = $has('source') ? 'source' : ($has('source_type') ? 'source_type' : ($has('ref_type') ? 'ref_type' : null));

            if ($storeCol && $qtyCol) {
                $data = [
                    'product_id' => $productId,
                    $storeCol    => $storeId,
                    $qtyCol      => $qty,
                ];
                if ($remCol)  $data[$remCol]  = $qty;
                if ($costCol) $data[$costCol] = $cost;
                if ($srcCol)  $data[$srcCol]  = $asAdjustment ? 'IMPORT_ADJUST' : 'IMPORT_OPEN';
                if (in_array('created_at', $cols, true)) $data['created_at'] = $now;
                if (in_array('updated_at', $cols, true)) $data['updated_at'] = $now;

                \DB::table('inventory_layers')->insert($data);
            }
        } catch (\Throwable $e) {
            \Log::warning('inventory_layers insert skipped: '.$e->getMessage());
        }
    }

    // ===== STOCK LEDGER (adaptif) =====
    if (\Illuminate\Support\Facades\Schema::hasTable('stock_ledger')) {
        try {
            $cols = \Illuminate\Support\Facades\Schema::getColumnListing('stock_ledger');
            $has  = fn($n) => in_array($n, $cols, true);

            $storeCol = $has('store_location_id') ? 'store_location_id' : ($has('store_id') ? 'store_id' : null);
            $refType  = $has('ref_type') ? 'ref_type' : ($has('source_type') ? 'source_type' : null);
            $refIdCol = $has('ref_id')   ? 'ref_id'   : ($has('source_id') ? 'source_id' : null);

            // skema kuantitas yang mungkin:
            $hasInOut   = $has('qty_in') && $has('qty_out');
            $hasQtyOnly = $has('qty') && !$hasInOut && !$has('direction');
            $hasQtyDir  = $has('qty') && $has('direction');

            $data = [
                'product_id' => $productId,
            ];
            if ($storeCol) $data[$storeCol] = $storeId;
            if ($refType)  $data[$refType]  = $asAdjustment ? 'IMPORT_ADJUST' : 'IMPORT_OPEN';
            if ($refIdCol) $data[$refIdCol] = null;

            if ($hasInOut) {
                $data['qty_in']  = $qty;
                $data['qty_out'] = 0;
            } elseif ($hasQtyDir) {
                $data['qty']      = $qty;
                $data['direction']= 1;    // 1 = masuk
            } elseif ($hasQtyOnly) {
                $data['qty'] = $qty;      // asumsi masuk
            } else {
                // tidak ada kolom qty yang cocok -> skip ledger
                $data = null;
            }

            // biaya/kolom opsional
            if ($data !== null) {
                if ($has('unit_cost'))         $data['unit_cost']         = $cost;
                if ($has('unit_price'))        $data['unit_price']        = $cost;
                if ($has('subtotal_cost'))     $data['subtotal_cost']     = $cost * $qty;
                if ($has('estimated_cost'))    $data['estimated_cost']    = $cost * $qty;
                if ($has('note'))              $data['note']              = $asAdjustment ? 'Import excel (adjust)' : 'Import excel (opening)';
                if ($has('created_at'))        $data['created_at']        = $now;
                if ($has('updated_at'))        $data['updated_at']        = $now;

                \DB::table('stock_ledger')->insert($data);
            }
        } catch (\Throwable $e) {
            \Log::warning('stock_ledger insert skipped: '.$e->getMessage());
        }
    }

    // ===== snapshot products.stock agar UI langsung terlihat =====
    if (\Illuminate\Support\Facades\Schema::hasColumn('products', 'stock')) {
        \DB::table('products')
            ->where('id', $productId)
            ->update([
                'stock'      => \DB::raw('COALESCE(stock,0) + '.((float)$qty)),
                'updated_at' => $now,
            ]);
    }
}

}