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
use App\Models\Unit;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\NamedRange;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ProductImportController extends Controller
{
    /** ========== 1) Download template XLSX dengan dropdown Category/ID → Subcategory/ID + Unit + Inventory Type ========== */
    public function template(Request $r)
    {
        // ============================
        // 0. Tentukan store_location_id
        // ============================
        $user = $r->user();

        // Prioritas: query ?store_location_id=... → lalu store dari user
        $storeId = $r->query('store_location_id');
        if (!$storeId && $user) {
            $storeId = $user->store_location_id
                ?? optional($user->storeLocation)->id
                ?? null;
        }

        // ============================
        // 1. Ambil master data per store
        // ============================

        // Category & SubCategory difilter sesuai store
        $categories = Category::query()
            ->when($storeId, function ($q) use ($storeId) {
                $q->where('store_location_id', $storeId);
            })
            ->orderBy('name')
            ->get(['id','name','store_location_id']);

        $subcats = SubCategory::query()
            ->when($storeId, function ($q) use ($storeId) {
                $q->where('store_location_id', $storeId);
            })
            ->orderBy('name')
            ->get(['id','name','category_id','store_location_id']);

        // Unit masih global (tidak per store)
        $units = Unit::orderBy('name')->get(['id','name']);

        $ss = new Spreadsheet();

        // ========== Sheet Products ========== //
        $ws = $ss->getActiveSheet();
        $ws->setTitle('Products');

        // A: SKU, B: Name, C: Price, D: Stock, E: Inventory Type, F: Unit, G: Category, H: Subcategory, I: Description
        $ws->fromArray(
            [['SKU','Name','Price','Stock','Inventory Type','Unit','Category','Subcategory','Description']],
            null,
            'A1'
        );
        foreach (range('A','I') as $c) {
            $ws->getColumnDimension($c)->setAutoSize(true);
        }
        $ws->freezePane('A2');

        // ========== Sheet Categories (helper) ========== //
        $helper = new Worksheet($ss, 'Categories');
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
            $byCat[$c->id] = [
                'name' => $c->name,
                'key'  => $sanitize($c->name),
                'subs' => [],
            ];
        }
        foreach ($subcats as $s) {
            if (isset($byCat[$s->category_id])) {
                $byCat[$s->category_id]['subs'][] = $s->name;
            }
        }

        // Tulis kategori (kolom A & B)
        $rIdx = 2;
        foreach ($byCat as $info) {
            $helper->fromArray([[$info['name'], $info['key']]], null, "A{$rIdx}");
            $rIdx++;
        }
        $catCount = max(0, count($byCat));
        $lastRow  = 1 + $catCount; // karena mulai dari baris 2

        // Subcategory per kategori → NamedRange horizontal (mulai C)
        $startColIdx = 3; // C
        $rIdx = 2;
        foreach ($byCat as $info) {
            $names = $info['subs'];
            $key   = $info['key'];

            if (!empty($names)) {
                $helper->fromArray([$names], null, $this->col($startColIdx).$rIdx);
                $lastColIdx = $startColIdx + count($names) - 1;
                $range = "Categories!".$this->col($startColIdx).$rIdx.":".$this->col($lastColIdx).$rIdx;
            } else {
                // minimal 1 sel kosong biar INDIRECT tidak error
                $helper->setCellValue($this->col($startColIdx).$rIdx, '');
                $range = "Categories!".$this->col($startColIdx).$rIdx.":".$this->col($startColIdx).$rIdx;
            }
            $ss->addNamedRange(new NamedRange($key, $helper, $range));
            $rIdx++;
        }

        // ========== Sheet Units (helper) ========== //
        $unitSheet = new Worksheet($ss, 'Units');
        $ss->addSheet($unitSheet);

        $unitSheet->fromArray([['Unit']], null, 'A1');
        $ur = 2;
        foreach ($units as $u) {
            $unitSheet->setCellValue("A{$ur}", $u->name);
            $ur++;
        }
        $unitLastRow = $ur - 1;
        // Range absolut untuk unit: Units!A2:A{unitLastRow}
        $unitRange = "=Units!\$A\$2:\$A\${$unitLastRow}";

        // ========== Data Validation ========== //
        $startRow = 2;
        $endRow   = 1000;

        // Range ABSOLUT untuk kategori: Categories!A2:A{lastRow}
        $catRange = "=Categories!\$A\$2:\$A\$".$lastRow;
        // Range ABSOLUT untuk MATCH (nama & key)
        $namesAbs = "\$A\$2:\$A\$".$lastRow;
        $keysAbs  = "\$B\$2:\$B\$".$lastRow;

        for ($row = $startRow; $row <= $endRow; $row++) {
            // Dropdown Inventory Type (E) → "stock" / "non_stock"
            $dvInv = $ws->getCell("E{$row}")->getDataValidation();
            $dvInv->setType(DataValidation::TYPE_LIST)
                ->setAllowBlank(true)
                ->setShowDropDown(true)
                ->setShowErrorMessage(true)
                ->setErrorTitle('Invalid Inventory Type')
                ->setError('Use: stock / non_stock')
                ->setFormula1('"stock,non_stock"');

            // Dropdown Unit (F)
            $dvUnit = $ws->getCell("F{$row}")->getDataValidation();
            $dvUnit->setType(DataValidation::TYPE_LIST)
                ->setAllowBlank(true)->setShowDropDown(true)
                ->setShowErrorMessage(true)->setErrorTitle('Invalid Unit')->setError('Select from the list.')
                ->setFormula1($unitRange);

            // Dropdown Category (G)
            $dvCat = $ws->getCell("G{$row}")->getDataValidation();
            $dvCat->setType(DataValidation::TYPE_LIST)
                ->setAllowBlank(true)->setShowDropDown(true)
                ->setShowErrorMessage(true)->setErrorTitle('Invalid Category')->setError('Select from the list.')
                ->setFormula1($catRange);

            // Dropdown Subcategory (H) — cascading via key
            $dvSub = $ws->getCell("H{$row}")->getDataValidation();
            $dvSub->setType(DataValidation::TYPE_LIST)
                ->setAllowBlank(true)->setShowDropDown(true)
                ->setShowErrorMessage(true)->setErrorTitle('Invalid Subcategory')->setError('Select based on Category.')
                ->setFormula1('=INDIRECT(INDEX(Categories!'.$keysAbs.', MATCH($G'.$row.', Categories!'.$namesAbs.', 0)))');
        }

        // Tooltip / komentar
        $ws->getComment('E1')->getText()->createTextRun('Inventory Type: stock / non_stock.');
        $ws->getComment('F1')->getText()->createTextRun('Pilih satuan dari daftar.');
        $ws->getComment('G1')->getText()->createTextRun('Pilih kategori dari daftar.');
        $ws->getComment('H1')->getText()->createTextRun('Subcategory mengikuti kategori di kolom G.');

        // Pastikan sheet Products aktif saat disave
        $ss->setActiveSheetIndex(0);

        $writer = new Xlsx($ss);
        return new StreamedResponse(function () use ($writer) {
            $writer->save('php://output');
        }, 200, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="product_import_template.xlsx"',
        ]);
    }

    // helper tetap sama
    private function col(int $idx): string {
        $s = '';
        while ($idx > 0) {
            $idx--;
            $s = chr(65 + ($idx % 26)).$s;
            $idx = intdiv($idx, 26);
        }
        return $s;
    }

    /** ========== 2) Proses import XLSX: pakai inventory_type (stock / non_stock) ========== */
    public function import(Request $req)
    {
        // supaya variabel ada untuk scope catch luar
        $rows  = [];
        $sheet = null;

        try {
            $req->validate([
                'file' => 'required|file|mimes:xlsx,xls|max:10240',
                'mode' => 'nullable|string|in:upsert,create-only',
            ]);
            $mode = $req->input('mode', 'upsert'); // hanya formalitas, tidak dipakai untuk logika

            // store dari user (wajib)
            $user    = $req->user();
            $storeId = (int)($user?->store_location_id ?? optional($user?->store_location)->id ?? 0);
            if ($storeId <= 0) {
                return response()->json(['status' => 'error', 'message' => 'Akun belum memiliki store_location_id.'], 422);
            }

            $uploaded = $req->file('file');
            if (!$uploaded || !$uploaded->isValid()) {
                return response()->json(['status' => 'error', 'message' => 'File upload invalid'], 422);
            }

            // baca spreadsheet
            $ext    = strtolower($uploaded->getClientOriginalExtension());
            $reader = $ext === 'xlsx'
                ? new XlsxReader()
                : IOFactory::createReaderForFile($uploaded->getRealPath());
            $reader->setReadDataOnly(true);

            $spreadsheet = $reader->load($uploaded->getRealPath());
            $sheet = $spreadsheet->getSheetByName('Products') ?: $spreadsheet->getActiveSheet();
            if (!$sheet) {
                return response()->json(['status' => 'error', 'message' => 'Worksheet "Products" tidak ditemukan'], 422);
            }

            $rows = $sheet->toArray(null, true, true, true);
            if (!isset($rows[1]) || !is_array($rows[1])) {
                return response()->json(['status' => 'error', 'message' => 'Header tidak ditemukan di baris pertama'], 422);
            }

            // peta header (case-insensitive)
            $findCol = function ($want, $fallback) use ($rows) {
                $want = strtolower($want);
                foreach ($rows[1] as $col => $val) {
                    if (strtolower(trim((string)$val)) === $want) return $col;
                }
                return $fallback;
            };

            // mapping kolom: A=SKU, B=Name, C=Price, D=Stock, E=Inventory Type, F:Unit, G:Category, H:Subcategory, I:Description
            $col = [
                'sku'            => $findCol('sku', 'A'),
                'name'           => $findCol('name', 'B'),
                'price'          => $findCol('price', 'C'),
                'stock'          => $findCol('stock', 'D'),
                'inventory_type' => $findCol('inventory_type', 'E'),
                'unit'           => $findCol('unit', 'F'),
                'cat_name'       => $findCol('category', 'G'),
                'sub_name'       => $findCol('subcategory', 'H'),
                'description'    => $findCol('description', 'I'),
            ];

            // cache master
            $catByName = Category::all()->keyBy(fn ($c) => mb_strtolower(trim($c->name)));
            $subs      = SubCategory::select(['id', 'name', 'category_id'])->get();
            $subByCatAndName = $subs->keyBy(fn ($s) => ((int)$s->category_id) . '::' . mb_strtolower(trim($s->name)));

            $units         = Unit::select(['id','name','is_system'])->get();
            $unitByName    = $units->keyBy(fn ($u) => mb_strtolower(trim($u->name)));
            $defaultUnit   = $units->firstWhere('is_system', true) ?? $units->first();

            // helper angka
            $num = function ($v) {
                if ($v === null || $v === '') return null;
                if (is_numeric($v)) return (float)$v;
                $s = str_replace([" ", "\u{00A0}"], '', (string)$v);
                $s = preg_replace('/\.(?=\d{3}(\D|$))/', '', $s); // hapus thousand dot
                $s = str_replace(',', '.', $s);                   // koma → titik
                return is_numeric($s) ? (float)$s : null;
            };

            $created = 0;
            $updated = 0; // tidak dipakai (create-only)
            $errors  = [];
            $touchedIds = []; // untuk resync stok absolut di akhir

            DB::beginTransaction();
            try {
                $maxRow = count($rows);
                for ($r = 2; $r <= $maxRow; $r++) {
                    try {
                        $line = $rows[$r] ?? null;
                        if (!$line) continue;

                        $sku    = trim((string)($line[$col['sku']] ?? ''));
                        $name   = trim((string)($line[$col['name']] ?? ''));
                        $price  = $num($line[$col['price']] ?? null);
                        $stock  = $num($line[$col['stock']] ?? null);
                        $invRaw = trim((string)($line[$col['inventory_type']] ?? ''));
                        $unitNm = trim((string)($line[$col['unit']] ?? ''));
                        $catNm  = trim((string)($line[$col['cat_name']] ?? ''));
                        $subNm  = trim((string)($line[$col['sub_name']] ?? ''));
                        $desc   = trim((string)($line[$col['description']] ?? ''));

                        // baris kosong?
                        if (
                            $sku==='' && $name==='' && $catNm==='' && $subNm==='' &&
                            $price===null && $stock===null && $desc==='' && $unitNm==='' && $invRaw===''
                        ) {
                            continue;
                        }
                        if ($name === '') {
                            $errors[] = ['row' => $r, 'message' => 'Name is required'];
                            continue;
                        }

                        // Normalisasi inventory_type
                        $inv = mb_strtolower($invRaw);
                        $inventoryType = 'stock'; // default
                        if ($inv !== '') {
                            if (in_array($inv, ['stock','stok','produk stok','product stock','produk_stock'], true)) {
                                $inventoryType = 'stock';
                            } elseif (in_array($inv, ['non_stock','non-stock','non stock','jasa','service','nonstock'], true)) {
                                $inventoryType = 'non_stock';
                            } else {
                                // nilai aneh → fallback ke 'stock'
                                $inventoryType = 'stock';
                            }
                        }

                        // resolve category/subcategory by name
                        $categoryId    = null;
                        $subcategoryId = null;
                        if ($catNm !== '') {
                            $cat = $catByName[mb_strtolower($catNm)] ?? null;
                            if (!$cat) {
                                $errors[] = ['row' => $r, 'message' => "Category '{$catNm}' not found"];
                                continue;
                            }
                            $categoryId = (int)$cat->id;

                            if ($subNm !== '') {
                                $sub = $subByCatAndName[$categoryId . '::' . mb_strtolower($subNm)] ?? null;
                                if (!$sub) {
                                    $errors[] = [
                                        'row' => $r,
                                        'message' => "Subcategory '{$subNm}' (Category '{$cat->name}') not found",
                                    ];
                                    continue;
                                }
                                $subcategoryId = (int)$sub->id;
                            }
                        } elseif ($subNm !== '') {
                            $errors[] = ['row' => $r, 'message' => "Subcategory '{$subNm}' given but Category empty"];
                            continue;
                        }

                        // resolve unit by name
                        $unitId = null;
                        if ($unitNm !== '') {
                            $key   = mb_strtolower($unitNm);
                            $unit  = $unitByName[$key] ?? null;

                            if (!$unit) {
                                // auto-create unit baru
                                $unit = Unit::create([
                                    'name'      => $unitNm,
                                    'is_system' => false,
                                ]);
                                $unitByName[$key] = $unit;
                            }

                            $unitId = (int)$unit->id;
                        } else {
                            // kalau kosong → pakai default unit (kalau ada)
                            if ($defaultUnit) {
                                $unitId = (int)$defaultUnit->id;
                            }
                        }

                        // default angka
                        $price = $price ?? 0.0;
                        $stock = $stock ?? 0.0;

                        // ===============================
                        // PURE CREATE-ONLY
                        // ===============================
                        if ($sku !== '' && Product::where('sku', $sku)->exists()) {
                            // SKU sudah ada → JANGAN UPDATE, tandai error & skip
                            $errors[] = ['row' => $r, 'message' => "SKU '{$sku}' already exists"];
                            continue;
                        }

                        $p = new Product();
                        if ($sku !== '') $p->sku = $sku;
                        $p->name            = $name;
                        $p->price           = $price;
                        $p->category_id     = $categoryId;
                        $p->sub_category_id = $subcategoryId;
                        $p->description     = $desc ?: null;
                        if (Schema::hasColumn($p->getTable(), 'store_location_id')) {
                            $p->store_location_id = $storeId;
                        }
                        if (Schema::hasColumn($p->getTable(), 'unit_id')) {
                            $p->unit_id = $unitId;
                        }
                        if (Schema::hasColumn($p->getTable(), 'inventory_type')) {
                            $p->inventory_type = $inventoryType; // stock / non_stock
                        }
                        // kolom stock diisi 0 karena kita pakai inventory tables
                        if (Schema::hasColumn($p->getTable(), 'stock')) $p->stock = 0;
                        $p->save();
                        $created++;

                        // ===============================
                        // STOK AWAL → INVENTORY + LEDGER (HANYA UNTUK inventory_type = stock)
                        // ===============================
                        if ($inventoryType === 'stock' && $stock > 0 && Schema::hasTable('inventory_layers')) {
                            // 1) Buat layer & ambil ID layer yang baru
                            $layerId = \App\Support\InventoryQuick::addInboundLayer([
                                'product_id'        => (int)$p->id,
                                'qty'               => (float)$stock,
                                'unit_cost'         => is_numeric($price) ? (float)$price : 0,
                                'note'              => 'Stok awal (import excel)',
                                'store_location_id' => $storeId,
                                'source_type'       => 'ADD_PRODUCT',
                                'with_ledger'       => false,
                            ]);

                            // 2) Ledger (kalau tabelnya ada)
                            if (Schema::hasTable('stock_ledger')) {
                                $lcols = Schema::getColumnListing('stock_ledger');
                                $has   = fn($n) => in_array($n, $lcols, true);
                                $first = function(array $cands) use ($lcols): ?string {
                                    foreach ($cands as $c) if (in_array($c, $lcols, true)) return $c;
                                    return null;
                                };

                                $storeCol = $first(['store_location_id','store_id']);
                                $refType  = $first(['ref_type','source_type']);
                                $refIdCol = $first(['ref_id','source_id']);
                                $hasInOut = $has('qty_in') && $has('qty_out');
                                $hasQty   = $has('qty');
                                $hasDir   = $has('direction');

                                // Cek apakah sudah ada ledger untuk layer ini (hindari duplikat)
                                $exists = false;
                                if ($refIdCol && $refType) {
                                    $exists = DB::table('stock_ledger')->where([
                                        ['product_id', '=', (int)$p->id],
                                        [$refType, '=', 'IMPORT_OPEN'],
                                        [$refIdCol, '=', $layerId],
                                    ])->exists();
                                }

                                if (!$exists) {
                                    $rowLd = ['product_id' => (int)$p->id];
                                    if ($storeCol) $rowLd[$storeCol] = $storeId;
                                    if ($refType)  $rowLd[$refType]  = 'IMPORT_OPEN';
                                    if ($refIdCol) $rowLd[$refIdCol] = $layerId;

                                    if ($hasInOut) {
                                        $rowLd['qty_in']  = (float)$stock;
                                        $rowLd['qty_out'] = 0;
                                    } elseif ($hasQty && $hasDir) {
                                        $rowLd['qty']      = (float)$stock;
                                        $rowLd['direction'] = 1;
                                    } elseif ($hasQty) {
                                        $rowLd['qty'] = (float)$stock;
                                    }

                                    if ($has('unit_cost'))      $rowLd['unit_cost']      = is_numeric($price) ? (float)$price : 0;
                                    if ($has('unit_price'))     $rowLd['unit_price']     = is_numeric($price) ? (float)$price : 0;
                                    if ($has('subtotal_cost'))  $rowLd['subtotal_cost']  = (float)$stock * (is_numeric($price) ? (float)$price : 0);
                                    if ($has('estimated_cost')) $rowLd['estimated_cost'] = (float)$stock * (is_numeric($price) ? (float)$price : 0);
                                    if ($has('note'))           $rowLd['note']           = 'Stok awal (import excel)';
                                    if ($has('user_id'))        $rowLd['user_id']        = auth()->id() ?: null;
                                    if ($has('layer_id'))       $rowLd['layer_id']       = $layerId;
                                    if ($has('created_at'))     $rowLd['created_at']     = now();
                                    if ($has('updated_at'))     $rowLd['updated_at']     = now();

                                    DB::table('stock_ledger')->insert($rowLd);
                                }
                            }
                        }

                        $touchedIds[] = (int)$p->id;
                    } catch (\Throwable $rowEx) {
                        $errors[] = ['row' => $r, 'message' => $rowEx->getMessage()];
                    }
                }

                // ===========================
                // RESYNC absolut products.stock
                // ===========================
                if (Schema::hasTable('inventory_layers') && !empty($touchedIds)) {
                    $hasRemain = Schema::hasColumn('inventory_layers','qty_remaining')
                            || Schema::hasColumn('inventory_layers','remaining_qty')
                            || Schema::hasColumn('inventory_layers','remaining_quantity');

                    if ($hasRemain && Schema::hasColumn('products','stock')) {
                        $remCol = Schema::hasColumn('inventory_layers','qty_remaining')      ? 'qty_remaining'
                                : (Schema::hasColumn('inventory_layers','remaining_qty')     ? 'remaining_qty'
                                : 'remaining_quantity');

                        foreach (array_unique($touchedIds) as $pid) {
                            $sumRemain = (float) DB::table('inventory_layers')->where('product_id', $pid)->sum($remCol);
                            DB::table('products')->where('id', $pid)->update([
                                'stock'      => $sumRemain,
                                'updated_at' => now(),
                            ]);
                        }
                    }
                }

                DB::commit();
            } catch (\Throwable $tx) {
                DB::rollBack();
                Log::error('Import failed (DB): '.$tx->getMessage(), ['trace' => $tx->getTraceAsString()]);
                return response()->json(['status' => 'error', 'message' => 'Import failed: '.$tx->getMessage()], 500);
            }

            return response()->json([
                'status'  => 'ok',
                'summary' => [
                    'created'               => $created,
                    'updated'               => $updated,  // selalu 0
                    'errors'                => $errors,
                    'total_rows_processed'  => ($created + $updated + count($errors)),
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('Import failed (IO): '.$e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['status' => 'error', 'message' => 'Import failed: '.$e->getMessage()], 500);
        }
    }

    /**
     * (Masih disimpan kalau nanti mau dipakai)
     * Catat stok awal/penyesuaian ke inventory tables bila tersedia.
     * Menggunakan store_location_id dari user yang mengupload.
     * Unit cost diisi dari price (jika ada) atau 0.
     */
    protected function recordOpeningStock(int $productId, float $qty, int $storeId, ?float $price, bool $asAdjustment = false): void
    {
        $now  = now();
        $cost = is_numeric($price) ? (float)$price : 0.0;

        // ===== INVENTORY LAYERS (adaptif) =====
        if (Schema::hasTable('inventory_layers')) {
            try {
                $cols = Schema::getColumnListing('inventory_layers');
                $has  = fn(string $n) => in_array($n, $cols, true);
                $first = function (array $cands) use ($cols): ?string {
                    foreach ($cands as $c) if (in_array($c, $cols, true)) return $c;
                    return null;
                };

                $storeCol = $first(['store_location_id','store_id']);
                $qtyCol   = $first(['qty','quantity','initial_qty','qty_initial','opening_qty','qty_opening']);
                $remCol   = $first(['remaining_qty','remaining_quantity','qty_remaining']);
                $srcCol   = $first(['source','source_type','ref_type']);

                $hasUnitPrice = $has('unit_price');
                $hasUnitCost  = $has('unit_cost');

                if ($storeCol && $qtyCol) {
                    $data = [
                        'product_id' => $productId,
                        $storeCol    => $storeId,
                        $qtyCol      => $qty,
                    ];

                    if ($remCol)   $data[$remCol]   = $qty;
                    if ($srcCol)   $data[$srcCol]   = $asAdjustment ? 'IMPORT_ADJUST' : 'IMPORT_OPEN';
                    if ($hasUnitPrice) $data['unit_price'] = $cost;
                    if ($hasUnitCost)  $data['unit_cost']  = $cost;

                    foreach (['unit_tax','unit_other_cost','unit_landed_cost','estimated_cost','subtotal_cost'] as $k) {
                        if ($has($k) && !array_key_exists($k, $data)) $data[$k] = 0;
                    }

                    if ($has('source_id')) $data['source_id'] = null;
                    if ($has('ref_id'))    $data['ref_id']    = null;
                    if ($has('note'))      $data['note']      = $asAdjustment ? 'Import excel (adjust)' : 'Import excel (opening)';

                    if (in_array('created_at', $cols, true)) $data['created_at'] = $now;
                    if (in_array('updated_at', $cols, true)) $data['updated_at'] = $now;

                    DB::table('inventory_layers')->insert($data);
                } else {
                    Log::error('inventory_layers: store/qty column not resolved', [
                        'storeCol' => $storeCol, 'qtyCol' => $qtyCol, 'cols' => $cols
                    ]);
                }
            } catch (\Throwable $e) {
                Log::error('inventory_layers insert failed', [
                    'product_id' => $productId,
                    'store_id'   => $storeId,
                    'qty'        => $qty,
                    'price'      => $cost,
                    'message'    => $e->getMessage(),
                ]);
            }
        }

        // ===== STOCK LEDGER (adaptif) =====
        if (Schema::hasTable('stock_ledger')) {
            try {
                $cols = Schema::getColumnListing('stock_ledger');
                $has  = fn($n) => in_array($n, $cols, true);
                $first = function (array $cands) use ($cols): ?string {
                    foreach ($cands as $c) if (in_array($c, $cols, true)) return $c;
                    return null;
                };

                $storeCol = $first(['store_location_id','store_id']);
                $refType  = $first(['ref_type','source_type']);
                $refIdCol = $first(['ref_id','source_id']);

                $hasInOut   = $has('qty_in') && $has('qty_out');
                $hasQtyOnly = $has('qty') && !$hasInOut && !$has('direction');
                $hasQtyDir  = $has('qty') && $has('direction');

                $data = ['product_id' => $productId];
                if ($storeCol) $data[$storeCol] = $storeId;
                if ($refType)  $data[$refType]  = $asAdjustment ? 'IMPORT_ADJUST' : 'IMPORT_OPEN';
                if ($refIdCol) $data[$refIdCol] = null;

                if ($hasInOut) {
                    $data['qty_in']  = $qty;
                    $data['qty_out'] = 0;
                } elseif ($hasQtyDir) {
                    $data['qty'] = $qty;
                    $data['direction'] = 1;
                } elseif ($hasQtyOnly) {
                    $data['qty'] = $qty;
                } else {
                    $data = null;
                }

                if ($data !== null) {
                    foreach (['unit_cost','unit_price'] as $k) if ($has($k)) $data[$k] = $cost;
                    if ($has('subtotal_cost'))  $data['subtotal_cost']  = $cost * $qty;
                    if ($has('estimated_cost')) $data['estimated_cost'] = $cost * $qty;
                    if ($has('note'))           $data['note'] = $asAdjustment ? 'Import excel (adjust)' : 'Import excel (opening)';
                    if ($has('created_at'))     $data['created_at'] = $now;
                    if ($has('updated_at'))     $data['updated_at'] = $now;

                    DB::table('stock_ledger')->insert($data);
                }
            } catch (\Throwable $e) {
                Log::warning('stock_ledger insert skipped: '.$e->getMessage());
            }
        }

        // ===== snapshot products.stock agar UI langsung terlihat =====
        if (Schema::hasColumn('products', 'stock')) {
            DB::table('products')
                ->where('id', $productId)
                ->update([
                    'stock'      => DB::raw('COALESCE(stock,0) + '.((float)$qty)),
                    'updated_at' => $now,
                ]);
        }
    }
}
