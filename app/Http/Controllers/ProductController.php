<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Product;
use App\Http\Requests\UpdateProductRequest;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Arr;
use App\Support\InventoryQuick;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\File;
use Illuminate\Database\QueryException;

class ProductController extends Controller
{
    /**
     * GET /api/products
     * Query params:
     * - search, sku, category_id, sub_category_id, min_price, max_price
     * - sort=[id,name,sku,price,updated_at,created_at], dir=asc|desc
     * - page, per_page
     */
    public function index(Request $request)
    {
        $perPage = (int) $request->input('per_page', 10);
        $perPage = $perPage > 100 ? 100 : ($perPage < 1 ? 10 : $perPage);

        $sort     = (string) $request->input('sort', 'id');
        $dir      = strtolower((string) $request->input('dir', 'asc')) === 'desc' ? 'desc' : 'asc';
        $allowedSorts = ['id','name','sku','price','updated_at','created_at'];
        if (!in_array($sort, $allowedSorts, true)) $sort = 'id';

        $search        = trim((string) $request->input('search', ''));
        $skuExact      = trim((string) $request->input('sku', ''));
        $categoryId    = $request->input('category_id');
        $subCategoryId = $request->input('sub_category_id');
        $minPrice      = $request->input('min_price');
        $maxPrice      = $request->input('max_price');

        $q = Product::query()
            ->select([
                'id',
                'category_id',
                'sub_category_id',
                'sku',
                'name',
                'description',
                'price',
                'stock',
                'image_url',
                'created_at',
                'updated_at',
            ])
            ->when($skuExact !== '', fn($qq) => $qq->where('sku', $skuExact))
            ->when($search !== '', function ($qq) use ($search) {
                $qq->where(function ($w) use ($search) {
                    $w->where('name', 'like', "%{$search}%")
                      ->orWhere('sku',  'like', "%{$search}%");
                });
            })
            ->when($categoryId, function ($qq) use ($categoryId) {
                is_array($categoryId)
                    ? $qq->whereIn('category_id', array_filter($categoryId))
                    : $qq->where('category_id', $categoryId);
            })
            ->when($subCategoryId, function ($qq) use ($subCategoryId) {
                is_array($subCategoryId)
                    ? $qq->whereIn('sub_category_id', array_filter($subCategoryId))
                    : $qq->where('sub_category_id', $subCategoryId);
            })
            ->when($minPrice !== null && $minPrice !== '', fn($qq) => $qq->where('price', '>=', (float) $minPrice))
            ->when($maxPrice !== null && $maxPrice !== '', fn($qq) => $qq->where('price', '<=', (float) $maxPrice))
            ->orderBy($sort, $dir);

        $p = $q->paginate($perPage)->appends($request->query());

        return response()->json([
            'items' => $p->items(),
            'meta'  => [
                'current_page' => $p->currentPage(),
                'per_page'     => $p->perPage(),
                'last_page'    => $p->lastPage(),
                'total'        => $p->total(),
            ],
            'links' => [
                'next' => $p->nextPageUrl(),
                'prev' => $p->previousPageUrl(),
            ],
        ]);
    }

    public function store(Request $req)
    {
        $data = $req->validate([
            'sku'             => 'nullable|string|unique:products,sku',
            'name'            => 'required|string',
            'price'           => 'required|numeric',
            'stock'           => 'nullable|numeric|min:0',
            'category_id'     => 'nullable|integer',
            'sub_category_id' => 'nullable|integer',
            'description'     => 'nullable|string',
            'image'           => 'sometimes|file|image|mimes:jpg,jpeg,png,webp,svg|max:5120',
        ]);

        try {
            return DB::transaction(function () use ($req, $data) {
                // simpan foto (opsional)
                $imageUrl = null;
                if ($req->hasFile('image')) {
                    $file = $req->file('image');
                    $filename = \Illuminate\Support\Str::uuid().'.'.$file->getClientOriginalExtension();
                    $path = $file->storeAs('products', $filename, 'public');
                    $imageUrl = \Illuminate\Support\Facades\Storage::disk('public')->url($path);
                }

                // buat produk
                $productId = DB::table('products')->insertGetId([
                    'sku'             => $data['sku'] ?? null,
                    'name'            => $data['name'],
                    'price'           => $data['price'],
                    'category_id'     => $data['category_id'] ?? null,
                    'sub_category_id' => $data['sub_category_id'] ?? null,
                    'description'     => $data['description'] ?? null,
                    'image_url'       => $imageUrl,
                    'stock'           => 0,
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ]);

                // stok awal → bikin layer minimal
                $initQty = (float)($data['stock'] ?? 0);
                if ($initQty > 0) {
                    \App\Support\InventoryQuick::addInboundLayer([
                        'product_id' => $productId,
                        'qty'        => $initQty,
                        // tidak kirim unit_cost & store_location_id
                        'note'       => 'Stok awal (product store)',
                        // sesuai aturan kamu: layer ADD = GR tanpa source_id
                        // biarkan helper men-set defaultnya; kita hanya membaca sebagai GR+NULL di bawah
                    ]);

                    // === LEDGER IN (ADD) dari layer GR tanpa source_id ===
                    if (Schema::hasTable('stock_ledger')) {
                        // Coba cari layer terakhir: source_type='gr' AND source_id IS NULL
                        $layerId = DB::table('inventory_layers')
                            ->where('product_id', $productId)
                            ->where(function($q){
                                $q->where(function($w){
                                    $w->where('source_type','gr')->whereNull('source_id');
                                })
                                // fallback kalau helper tidak set source_type sama sekali
                                ->orWhere(function($w){
                                    $w->whereNull('source_type')->whereNull('source_id');
                                });
                            })
                            ->orderByDesc('id')
                            ->value('id');

                        if ($layerId) {
                            // fallback nama kolom qty & cost
                            $qtyCol  = Schema::hasColumn('inventory_layers','qty') ? 'qty'
                                     : (Schema::hasColumn('inventory_layers','qty_initial') ? 'qty_initial'
                                     : (Schema::hasColumn('inventory_layers','qty_remaining') ? 'qty_remaining' : null));

                            $costCol = Schema::hasColumn('inventory_layers','unit_landed_cost') ? 'unit_landed_cost'
                                     : (Schema::hasColumn('inventory_layers','unit_cost')       ? 'unit_cost'
                                     : (Schema::hasColumn('inventory_layers','unit_price')      ? 'unit_price' : null));

                            $select = 'id, product_id, store_location_id';
                            $select .= $qtyCol  ? ", {$qtyCol} as q" : ", 0 as q";
                            $select .= $costCol ? ", {$costCol} as c" : ", 0 as c";

                            $L = DB::table('inventory_layers')->selectRaw($select)->where('id', $layerId)->first();

                            if ($L) {
                                $qVal = (float)$L->q ?: (float)$initQty;
                                $cVal = (float)$L->c; // kalau 0 pun dicatat apa adanya

                                DB::table('stock_ledger')->insert([
                                    'product_id'        => (int)$L->product_id,
                                    'store_location_id' => $L->store_location_id ? (int)$L->store_location_id : null,
                                    'layer_id'          => (int)$layerId,
                                    'user_id'           => auth()->id(),
                                    'ref_type'          => 'ADD',      // catat sebagai ADD
                                    'ref_id'            => null,       // ADD tidak punya dokumen sumber
                                    'direction'         => +1,         // IN
                                    'qty'               => $qVal,
                                    'unit_cost'         => $cVal,
                                    'unit_price'        => null,
                                    'subtotal_cost'     => $qVal * $cVal,
                                    'note'              => 'Stok awal (product store)',
                                    'created_at'        => now(),
                                    'updated_at'        => now(),
                                ]);
                            }
                        }
                    }
                    // === END LEDGER IN (ADD) ===
                }

                // recalc dari layers (extra safety)
                $sumRemain = (float) DB::table('inventory_layers')
                    ->where('product_id', $productId)
                    ->sum('qty_remaining');

                DB::table('products')->where('id', $productId)->update([
                    'stock'      => $sumRemain,
                    'updated_at' => now(),
                ]);

                $product = DB::table('products')->where('id', $productId)->first();
                return response()->json(['message' => 'Product created', 'product' => $product], 201);
            });
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Create product failed',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function show(Product $product)
    {
        return response()->json($product);
    }

    public function update(UpdateProductRequest $request, Product $product)
    {
        $data = $request->validated();

        // normalisasi nullable
        foreach (['description','category_id','sub_category_id'] as $k) {
            if (!Arr::has($data, $k) || $data[$k] === '') $data[$k] = null;
        }

        // upload file (gunakan storage 'public' agar url bisa diakses via /storage)
        if ($request->hasFile('image')) {
            $request->validate(['image' => ['image','mimes:jpg,jpeg,png,svg','max:5120']]);

            $file = $request->file('image');
            $filename = Str::uuid().'.'.$file->getClientOriginalExtension();

            // simpan ke storage/app/public/products/{filename}
            $path = $file->storeAs('products', $filename, 'public');
            $imageUrl = Storage::disk('public')->url($path); // biasanya /storage/products/{filename}

            $data['image_url'] = $imageUrl;
        }

        $product->update($data);

        return response()->json(['data' => $product->fresh()]);
    }

    public function destroy(Product $product)
    {
        try {
            return DB::transaction(function () use ($product) {

                // ====== NOLKAN STOK SEBELUM DELETE ======
                if (Schema::hasTable('inventory_layers')) {
                    // Ambil semua layer yang masih ada qty_remaining
                    $layers = DB::table('inventory_layers')
                        ->where('product_id', $product->id)
                        ->where('qty_remaining', '>', 0)
                        ->lockForUpdate()
                        ->get(['id','qty_remaining',
                            // cost fallback columns (ambil jika ada)
                            (Schema::hasColumn('inventory_layers','unit_landed_cost') ? 'unit_landed_cost' : DB::raw('0 as unit_landed_cost')),
                            (Schema::hasColumn('inventory_layers','unit_cost')        ? 'unit_cost'        : DB::raw('0 as unit_cost')),
                            (Schema::hasColumn('inventory_layers','unit_price')       ? 'unit_price'       : DB::raw('0 as unit_price')),
                            (Schema::hasColumn('inventory_layers','store_location_id')? 'store_location_id': DB::raw('NULL as store_location_id'))
                        ]);

                    $totalOut = 0.0;
                    $ledgerRows = [];

                    foreach ($layers as $L) {
                        $qtyOut = (float)$L->qty_remaining;
                        if ($qtyOut <= 0) continue;

                        // Hitung unit_cost fallback dari layer (tanpa konsumptions)
                        $unitCost = 0.0;
                        if (Schema::hasColumn('inventory_layers','unit_landed_cost')) $unitCost = (float)$L->unit_landed_cost;
                        if ($unitCost == 0.0 && Schema::hasColumn('inventory_layers','unit_cost'))  $unitCost = (float)$L->unit_cost;
                        if ($unitCost == 0.0 && Schema::hasColumn('inventory_layers','unit_price')) $unitCost = (float)$L->unit_price;

                        // Catat untuk ledger OUT
                        $ledgerRows[] = (object)[
                            'layer_id'          => (int)$L->id,
                            'qty'               => $qtyOut,
                            'unit_cost'         => $unitCost,
                            'store_location_id' => property_exists($L,'store_location_id') ? ($L->store_location_id ?? null) : null,
                        ];

                        $totalOut += $qtyOut;

                        // Set qty_remaining layer -> 0
                        DB::table('inventory_layers')->where('id', $L->id)->update([
                            'qty_remaining' => 0,
                            'updated_at'    => now(),
                        ]);
                    }

                    // Turunkan counter stok produk ke 0
                    if ($totalOut > 0) {
                        DB::table('products')->where('id', $product->id)->update([
                            'stock'      => 0,
                            'updated_at' => now(),
                        ]);

                        // StockLog (legacy) sekali saja
                        DB::table('stock_logs')->insert([
                            'product_id'  => $product->id,
                            'user_id'     => auth()->id(),
                            'change_type' => 'out',
                            'quantity'    => $totalOut,
                            'note'        => 'destroy: product deletion (zeroed)',
                            'created_at'  => now(),
                            'updated_at'  => now(),
                        ]);

                        // Ledger OUT per layer (jika tabel ada)
                        if (Schema::hasTable('stock_ledger')) {
                            foreach ($ledgerRows as $row) {
                                DB::table('stock_ledger')->insert([
                                    'product_id'        => $product->id,
                                    'store_location_id' => $row->store_location_id ? (int)$row->store_location_id : null,
                                    'layer_id'          => $row->layer_id,
                                    'user_id'           => auth()->id(),
                                    'ref_type'          => 'DESTROY',
                                    'ref_id'            => null,
                                    'direction'         => -1, // OUT
                                    'qty'               => $row->qty,
                                    'unit_cost'         => $row->unit_cost,
                                    'unit_price'        => null,
                                    'subtotal_cost'     => $row->qty * $row->unit_cost,
                                    'note'              => 'destroy: product deletion (zeroed)',
                                    'created_at'        => now(),
                                    'updated_at'        => now(),
                                ]);
                            }
                        }
                    }
                } else {
                    // Kalau tabel layers tidak ada, minimal set counter product ke 0
                    DB::table('products')->where('id', $product->id)->update([
                        'stock'      => 0,
                        'updated_at' => now(),
                    ]);

                    DB::table('stock_logs')->insert([
                        'product_id'  => $product->id,
                        'user_id'     => auth()->id(),
                        'change_type' => 'out',
                        'quantity'    => 0,
                        'note'        => 'destroy: product deletion (no layers table)',
                        'created_at'  => now(),
                        'updated_at'  => now(),
                    ]);
                }
                // ====== END NOLKAN STOK ======

                // Hard delete seperti semula
                $product->forceDelete();

                return response()->json(['message' => 'Product permanently deleted'], 200);
            });

        } catch (QueryException $e) {
            $isFk = ($e->getCode() === '23000') || str_contains($e->getMessage(), '1451');

            if ($isFk) {
                if (Schema::hasColumn('products', 'deleted_at')) {
                    DB::table('products')
                        ->where('id', $product->id)
                        ->update(['deleted_at' => now()]);

                    return response()->json(['message' => 'Product archived'], 200);
                }

                return response()->json([
                    'message' => 'Produk dipakai transaksi sehingga tidak bisa dihapus permanen. ' .
                                'Aktifkan soft delete (tambah kolom deleted_at) untuk mengarsipkan.',
                ], 409);
            }

            return response()->json([
                'message' => 'Delete failed',
                'error'   => $e->getMessage(),
            ], 500);

        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Delete failed',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }


    public function upload(Product $product, Request $request)
    {
        $request->validate([
            'image' => ['required','image','mimes:jpg,jpeg,png,svg','max:5120'],
        ]);

        $file = $request->file('image');
        $filename = Str::uuid().'.'.$file->getClientOriginalExtension();

        // simpan via Storage public
        $path = $file->storeAs('products', $filename, 'public'); // storage/app/public/products/...
        $imageUrl = Storage::disk('public')->url($path);         // /storage/products/...

        $product->image_url = $imageUrl;
        $product->save();

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $product->id,
                'image_url' => $imageUrl,
                'full_url' => $imageUrl, // sudah absolute oleh url generator disk
            ]
        ]);
    }

    public function search(Request $request)
    {
        $request->validate(['sku' => 'required|string']);

        $product = Product::where('sku', $request->sku)->first();

        if (!$product) {
            return response()->json([
                'success' => false,
                'message' => 'Produk tidak ditemukan'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Produk ditemukan',
            'data' => $product
        ]);
    }
}
