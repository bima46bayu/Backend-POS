<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Product;
use App\Models\Unit;
use App\Http\Requests\UpdateProductRequest;
use Illuminate\Support\Str;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\File;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;

class ProductController extends Controller
{
    /**
     * Simpan file ke public/uploads/products dan
     * KEMBALIKAN path relatif: /uploads/products/<uuid>.<ext>
     */
    private function putPublicProductImage(UploadedFile $file): string
    {
        $ext  = strtolower($file->getClientOriginalExtension() ?: 'png');
        $name = Str::uuid().'.'.$ext;

        $targetDir = public_path('uploads/products');

        if (! File::exists($targetDir)) {
            File::makeDirectory($targetDir, 0755, true);
        }

        if (! is_writable($targetDir)) {
            throw new \RuntimeException("Upload directory is not writable: {$targetDir}");
        }

        $file->move($targetDir, $name);

        // penting: tanpa "/public"
        return '/uploads/products/'.$name;
    }

    private function tryDeletePublicProductImage(?string $value): void
    {
        if (! $value) return;

        // kalau disimpan full URL, ambil path-nya saja
        $path = parse_url($value, PHP_URL_PATH) ?: $value;
        if (! $path) return;

        $candidate = public_path(ltrim($path, '/'));

        if (File::exists($candidate)) {
            @File::delete($candidate);
        }
    }

    /**
     * GET /api/products
     */
    public function index(Request $request)
    {
        $perPage = (int) $request->input('per_page', 10);
        $perPage = $perPage > 100 ? 100 : ($perPage < 1 ? 10 : $perPage);

        $sort = (string) $request->input('sort', 'id');
        $dir  = strtolower((string) $request->input('dir', 'asc')) === 'desc' ? 'desc' : 'asc';

        $allowedSorts = ['id','name','sku','price','updated_at','created_at'];
        if (! in_array($sort, $allowedSorts, true)) {
            $sort = 'id';
        }

        $search        = trim((string) $request->input('search', ''));
        $skuExact      = trim((string) $request->input('sku', ''));
        $categoryId    = $request->input('category_id');
        $subCategoryId = $request->input('sub_category_id');
        $minPrice      = $request->input('min_price');
        $maxPrice      = $request->input('max_price');

        $storeId   = $request->integer('store_id');
        $onlyStore = $request->boolean('only_store', false);

        $q = Product::query()
            ->select([
                'products.id',
                'products.category_id',
                'products.sub_category_id',
                'products.sku',
                'products.name',
                'products.description',
                'products.price',
                'products.stock',
                'products.image_url',
                'products.store_location_id',
                'products.created_by',
                'products.created_at',
                'products.updated_at',
                'products.unit_id',
                DB::raw('(SELECT name FROM units WHERE units.id = products.unit_id LIMIT 1) AS unit_name'),
            ])
            ->when($skuExact !== '', fn($qq) => $qq->where('sku', $skuExact))
            ->when($search !== '', function ($qq) use ($search) {
                $qq->where(function ($w) use ($search) {
                    $w->where('name', 'like', "%{$search}%")
                      ->orWhere('sku', 'like', "%{$search}%");
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
            ->when($maxPrice !== null && $maxPrice !== '', fn($qq) => $qq->where('price', '<=', (float) $maxPrice));

        if ($storeId) {
            if ($onlyStore) {
                $q->where('store_location_id', $storeId);        // hanya milik store
            } else {
                $q->forStore($storeId, true);                    // global + milik store
            }
        }

        $q->orderBy($sort, $dir);

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
        $user = $req->user();

        $data = $req->validate([
            'sku'               => 'nullable|string|unique:products,sku',
            'name'              => 'required|string',
            'price'             => 'required|numeric',
            'stock'             => 'nullable|numeric|min:0',
            'category_id'       => 'nullable|integer',
            'sub_category_id'   => 'nullable|integer',
            'description'       => 'nullable|string',
            'unit_id'           => 'nullable|exists:units,id', // ← unit dari master units
            // penting: nullable, bukan sometimes
            'image'             => 'nullable|file|mimes:jpg,jpeg,png,webp,svg,svg+xml|max:5120',
            'store_location_id' => 'nullable|integer|exists:store_locations,id',
            'scope'             => 'nullable|in:global,store',
        ]);

        try {
            return DB::transaction(function () use ($req, $data, $user) {
                // 1) Upload image (opsional)
                $imagePath = null;
                if ($req->hasFile('image')) {
                    // validasi ekstra (opsional tapi rapi)
                    $req->validate([
                        'image' => ['file','mimes:jpg,jpeg,png,webp,svg,svg+xml','max:5120'],
                    ]);

                    $imagePath = $this->putPublicProductImage($req->file('image'));
                }

                // 2) Tentukan store_location_id berdasarkan role
                $storeLocationId = null;
                if ($user->role !== 'admin') {
                    // staff/kasir → kunci ke tokonya
                    $storeLocationId = $user->store_location_id;
                } else {
                    // admin → boleh global/store
                    $scope = $data['scope'] ?? null;
                    if ($scope === 'global') {
                        $storeLocationId = null; // global
                    } else {
                        $storeLocationId = $data['store_location_id'] ?? null;
                    }
                }

                // 2b) Tentukan unit_id (default dari database jika tidak dikirim)
                if (!empty($data['unit_id'])) {
                    $unitId = $data['unit_id'];
                } else {
                    $defaultUnit = Unit::where('is_system', true)->orderBy('id')->first();
                    $unitId = $defaultUnit?->id;
                }

                // 3) Buat produk
                $productId = DB::table('products')->insertGetId([
                    'sku'               => $data['sku'] ?? null,
                    'name'              => $data['name'],
                    'price'             => $data['price'],
                    'category_id'       => $data['category_id'] ?? null,
                    'sub_category_id'   => $data['sub_category_id'] ?? null,
                    'description'       => $data['description'] ?? null,
                    'unit_id'           => $unitId,
                    'image_url'         => $imagePath,
                    'stock'             => 0,
                    'store_location_id' => $storeLocationId,
                    'created_by'        => $user->id,
                    'created_at'        => now(),
                    'updated_at'        => now(),
                ]);

                // 4) Stok awal (jika ada)
                $initQty = (float) ($data['stock'] ?? 0);
                if ($initQty > 0) {
                    \App\Support\InventoryQuick::addInboundLayer([
                        'product_id'        => $productId,
                        'qty'               => $initQty,
                        'note'              => 'Stok awal (product store)',
                        'store_location_id' => $storeLocationId,
                    ]);

                    if (Schema::hasTable('stock_ledger')) {
                        $layer = DB::table('inventory_layers')
                            ->where('product_id', $productId)
                            ->orderByDesc('id')
                            ->first();

                        $qtyCol  = Schema::hasColumn('inventory_layers','qty')               ? 'qty'
                                : (Schema::hasColumn('inventory_layers','qty_initial')      ? 'qty_initial'
                                : (Schema::hasColumn('inventory_layers','qty_remaining')    ? 'qty_remaining' : null));

                        $costCol = Schema::hasColumn('inventory_layers','unit_landed_cost')  ? 'unit_landed_cost'
                                : (Schema::hasColumn('inventory_layers','unit_cost')        ? 'unit_cost'
                                : (Schema::hasColumn('inventory_layers','unit_price')       ? 'unit_price' : null));

                        $qVal = $initQty;
                        $cVal = 0.0;
                        $storeLocForLedger = $storeLocationId;
                        $layerIdForLedger  = null;

                        if ($layer) {
                            $storeLocForLedger = $layer->store_location_id ?? $storeLocForLedger;
                            $layerIdForLedger  = $layer->id ?? null;

                            if ($qtyCol && isset($layer->{$qtyCol})) {
                                $qVal = (float) $layer->{$qtyCol} ?: $initQty;
                            }
                            if ($costCol && isset($layer->{$costCol})) {
                                $cVal = (float) $layer->{$costCol};
                            }
                        }

                        DB::table('stock_ledger')->insert([
                            'product_id'        => (int) $productId,
                            'store_location_id' => $storeLocForLedger ? (int) $storeLocForLedger : null,
                            'layer_id'          => $layerIdForLedger,
                            'user_id'           => auth()->id() ?: null,
                            'ref_type'          => 'ADD',
                            'ref_id'            => null,
                            'direction'         => +1,
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

                // 5) Sinkronkan kolom stock dari layers
                $sumRemain = (float) DB::table('inventory_layers')
                    ->where('product_id', $productId)
                    ->sum('qty_remaining');

                DB::table('products')->where('id', $productId)->update([
                    'stock'      => $sumRemain,
                    'updated_at' => now(),
                ]);

                $product = DB::table('products')->where('id', $productId)->first();

                return response()->json([
                    'message' => 'Product created',
                    'product' => $product,
                ], 201);
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
        // kalau mau sekaligus unit_name di FE:
        $product->load('unit');

        return response()->json($product);
    }

public function update(UpdateProductRequest $request, Product $product)
{
    $user = $request->user();
    $data = $request->validated(); // sudah termasuk unit_id

    // Normalisasi beberapa field ("" -> null)
    foreach (['description', 'category_id', 'sub_category_id', 'unit_id'] as $k) {
        if (!Arr::has($data, $k) || $data[$k] === '') {
            $data[$k] = null;
        }
    }

    // Upload image (opsional)
    if ($request->hasFile('image')) {
        $this->tryDeletePublicProductImage($product->image_url);
        $data['image_url'] = $this->putPublicProductImage($request->file('image'));

        // jangan kirim field 'image' (UploadedFile) ke update()
        unset($data['image']);
    }

    // Aturan update admin vs non-admin
    if ($user->role !== 'admin') {
        // non-admin hanya boleh edit produk dari store-nya sendiri
        if (empty($product->store_location_id) || $product->store_location_id !== $user->store_location_id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        // non-admin tidak boleh mengubah store/scope
        unset($data['store_location_id'], $data['scope']);
    } else {
        // admin: boleh pakai scope=global, kalau tidak → biarkan store_location_id sekarang
        $scope = $request->input('scope'); // 'global' | 'store' | null

        if ($scope === 'global') {
            $product->store_location_id = null;
        }

        unset($data['store_location_id'], $data['scope']);
    }

    // ❗ Di UPDATE kita TIDAK pakai default unit lagi
    //    Kalau user kosongkan unit di UI → akan tersimpan null (tanpa unit).
    //    Kalau user pilih unit lain → unit_id berisi ID sesuai pilihan dropdown.
    // Tinggal fill & save:
    $product->fill($data);
    $product->save();

    // load relasi buat FE
    $product->load(['category', 'subCategory', 'unit', 'storeLocation']);

    return response()->json([
        'message' => 'Product updated',
        'data'    => $product,
    ]);
}

    public function destroy(Product $product)
    {
        try {
            return DB::transaction(function () use ($product) {
                if (Schema::hasTable('inventory_layers')) {
                    $layers = DB::table('inventory_layers')
                        ->where('product_id', $product->id)
                        ->where('qty_remaining', '>', 0)
                        ->lockForUpdate()
                        ->get([
                            'id','qty_remaining',
                            (Schema::hasColumn('inventory_layers','unit_landed_cost') ? 'unit_landed_cost' : DB::raw('0 as unit_landed_cost')),
                            (Schema::hasColumn('inventory_layers','unit_cost')        ? 'unit_cost'        : DB::raw('0 as unit_cost')),
                            (Schema::hasColumn('inventory_layers','unit_price')       ? 'unit_price'       : DB::raw('0 as unit_price')),
                            (Schema::hasColumn('inventory_layers','store_location_id')? 'store_location_id': DB::raw('NULL as store_location_id')),
                        ]);

                    $totalOut   = 0.0;
                    $ledgerRows = [];

                    foreach ($layers as $L) {
                        $qtyOut = (float) $L->qty_remaining;
                        if ($qtyOut <= 0) continue;

                        $unitCost = 0.0;
                        if (Schema::hasColumn('inventory_layers','unit_landed_cost')) {
                            $unitCost = (float) $L->unit_landed_cost;
                        }
                        if ($unitCost == 0.0 && Schema::hasColumn('inventory_layers','unit_cost')) {
                            $unitCost = (float) $L->unit_cost;
                        }
                        if ($unitCost == 0.0 && Schema::hasColumn('inventory_layers','unit_price')) {
                            $unitCost = (float) $L->unit_price;
                        }

                        $ledgerRows[] = (object) [
                            'layer_id'          => (int) $L->id,
                            'qty'               => $qtyOut,
                            'unit_cost'         => $unitCost,
                            'store_location_id' => property_exists($L,'store_location_id') ? ($L->store_location_id ?? null) : null,
                        ];

                        $totalOut += $qtyOut;

                        DB::table('inventory_layers')->where('id', $L->id)->update([
                            'qty_remaining' => 0,
                            'updated_at'    => now(),
                        ]);
                    }

                    if ($totalOut > 0) {
                        DB::table('products')->where('id', $product->id)->update([
                            'stock'      => 0,
                            'updated_at' => now(),
                        ]);

                        DB::table('stock_logs')->insert([
                            'product_id'  => $product->id,
                            'user_id'     => auth()->id(),
                            'change_type' => 'out',
                            'quantity'    => $totalOut,
                            'note'        => 'destroy: product deletion (zeroed)',
                            'created_at'  => now(),
                            'updated_at'  => now(),
                        ]);

                        if (Schema::hasTable('stock_ledger')) {
                            foreach ($ledgerRows as $row) {
                                DB::table('stock_ledger')->insert([
                                    'product_id'        => $product->id,
                                    'store_location_id' => $row->store_location_id ? (int) $row->store_location_id : null,
                                    'layer_id'          => $row->layer_id,
                                    'user_id'           => auth()->id(),
                                    'ref_type'          => 'DESTROY',
                                    'ref_id'            => null,
                                    'direction'         => -1,
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

                $this->tryDeletePublicProductImage($product->image_url);
                $product->forceDelete();

                return response()->json(['message' => 'Product permanently deleted'], 200);
            });

        } catch (QueryException $e) {
            $isFk = ($e->getCode() === '23000') || str_contains($e->getMessage(), '1451');

            if ($isFk && Schema::hasColumn('products', 'deleted_at')) {
                DB::table('products')->where('id', $product->id)->update([
                    'deleted_at' => now(),
                ]);

                return response()->json(['message' => 'Product archived'], 200);
            }

            if ($isFk) {
                return response()->json([
                    'message' => 'Produk dipakai transaksi sehingga tidak bisa dihapus permanen. Aktifkan soft delete (tambah kolom deleted_at) untuk mengarsipkan.',
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
            'image' => ['required','file','mimes:jpg,jpeg,png,webp,svg,svg+xml','max:5120'],
        ]);

        $this->tryDeletePublicProductImage($product->image_url);

        $imagePath = $this->putPublicProductImage($request->file('image'));

        $product->image_url = $imagePath;
        $product->save();

        return response()->json([
            'success' => true,
            'data'    => [
                'id'        => $product->id,
                'image_url' => $imagePath,
            ],
        ]);
    }

    public function search(Request $request)
    {
        $request->validate([
            'sku' => 'required|string',
        ]);

        $product = Product::where('sku', $request->sku)->first();

        if (! $product) {
            return response()->json([
                'success' => false,
                'message' => 'Produk tidak ditemukan',
            ], 404);
        }

        $product->load('unit');

        return response()->json([
            'success' => true,
            'message' => 'Produk ditemukan',
            'data'    => $product,
        ]);
    }
}
