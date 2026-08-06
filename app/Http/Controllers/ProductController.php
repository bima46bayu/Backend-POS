<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Product;
use App\Models\StoreLocation;
use App\Models\Unit;
use App\Http\Requests\UpdateProductRequest;
use Illuminate\Support\Str;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\File;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use App\Services\InventoryService;
use App\Services\RecipeService;

class ProductController extends Controller
{
    /**
     * Next SKU for a store: SK-{storeCode}-001
     * GET /api/products/next-sku?store_location_id=
     */
    public function nextSku(Request $req)
    {
        $data = $req->validate([
            'store_location_id' => 'nullable|integer|exists:store_locations,id',
        ]);

        $user = $req->user();
        $storeId = $data['store_location_id'] ?? $user->store_location_id;
        $storeId = $storeId !== null ? (int) $storeId : null;

        if ($storeId !== null) {
            $this->authorizeStoreAccess($user, $storeId);
        } elseif (! $user->isAdmin()) {
            abort(422, 'store_location_id is required');
        }

        return response()->json([
            'sku' => $this->generateNextSku($storeId),
        ]);
    }

    /**
     * Format: SK-{CODE}-001 (3+ digit sequence per store code).
     */
    private function generateNextSku(?int $storeLocationId): string
    {
        $code = 'GEN';
        if ($storeLocationId) {
            $storeCode = StoreLocation::where('id', $storeLocationId)->value('code');
            if (is_string($storeCode) && trim($storeCode) !== '') {
                $code = trim($storeCode);
            }
        }

        $code = strtoupper($code);
        $prefix = 'SK-'.$code.'-';

        $existing = DB::table('products')
            ->where('sku', 'like', $prefix.'%')
            ->pluck('sku');

        $max = 0;
        $pattern = '/^'.preg_quote($prefix, '/').'(\d+)$/i';
        foreach ($existing as $sku) {
            if (preg_match($pattern, (string) $sku, $m)) {
                $max = max($max, (int) $m[1]);
            }
        }

        return $prefix.str_pad((string) ($max + 1), 3, '0', STR_PAD_LEFT);
    }

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
        $q = Product::query()->with([
            'storeLocation:id,name',
            'category:id,name',
            'subCategory:id,name',
            'unit:id,name',
        ]);

        // ===============================
        // 1) Role-based store scope
        // ===============================
        $user = $request->user();
        $this->applyProductStoreScope($q, $request);

        $storeId = null;
        if ($request->filled('store_location_id')) {
            $storeId = (int) $request->input('store_location_id');
        } elseif ($request->filled('store_id')) {
            $storeId = (int) $request->input('store_id');
        } elseif ($user && ! $user->isAdmin()) {
            $allowed = $user->allowedStoreIds() ?? [];
            if (count($allowed) === 1) {
                $storeId = $allowed[0];
            }
        }

        // ===============================
        // 2) Select fields (+ per-store stock when scoped to one store)
        // ===============================
        $select = [
            'products.id',
            'products.category_id',
            'products.sub_category_id',
            'products.sku',
            'products.name',
            'products.description',
            'products.price',
            'products.image_url',
            'products.store_location_id',
            'products.created_by',
            'products.created_at',
            'products.updated_at',
            'products.unit_id',
            'products.inventory_type',
            DB::raw('(SELECT name FROM units WHERE units.id = products.unit_id LIMIT 1) AS unit_name'),
        ];

        if ($storeId && Schema::hasTable('inventory_layers')) {
            $select[] = DB::raw(
                '(SELECT COALESCE(SUM(il.qty_remaining),0) FROM inventory_layers il '
                .'WHERE il.product_id = products.id AND il.store_location_id = '.(int) $storeId.') as stock'
            );
        } else {
            $select[] = 'products.stock';
        }

        $q->select($select);

        // ===============================
        // 4) Search
        // ===============================
        if ($s = trim($request->query('search', ''))) {
            $q->where(function ($qq) use ($s) {
                $qq->where('products.name', 'like', "%{$s}%")
                ->orWhere('products.sku', 'like', "%{$s}%");
            });
        }

        // ===============================
        // 5) Category filter
        // ===============================
        if ($categoryId = $request->query('category_id')) {
            if (is_array($categoryId)) {
                $q->whereIn('products.category_id', array_filter($categoryId));
            } else {
                $q->where('products.category_id', $categoryId);
            }
        }

        if ($subCategoryId = $request->query('sub_category_id')) {
            if (is_array($subCategoryId)) {
                $q->whereIn('products.sub_category_id', array_filter($subCategoryId));
            } else {
                $q->where('products.sub_category_id', $subCategoryId);
            }
        }

        // ===============================
        // 6) Price filter
        // ===============================
        if ($request->filled('min_price')) {
            $q->where('products.price', '>=', (float) $request->query('min_price'));
        }

        if ($request->filled('max_price')) {
            $q->where('products.price', '<=', (float) $request->query('max_price'));
        }

        // ===============================
        // 7) Inventory type filter
        // ===============================
        if ($invType = $request->query('inventory_type')) {
            $q->where('products.inventory_type', $invType);
        }

        // POS: hide raw materials that are only consumed via recipes at this branch
        if (
            $request->boolean('exclude_recipe_ingredients')
            && $storeId
            && Schema::hasTable('product_recipe_items')
            && Schema::hasTable('product_recipes')
        ) {
            $q->whereNotIn('products.id', function ($sub) use ($storeId) {
                $sub->select('pri.ingredient_product_id')
                    ->from('product_recipe_items as pri')
                    ->join('product_recipes as pr', 'pr.id', '=', 'pri.recipe_id')
                    ->where('pr.store_location_id', $storeId)
                    ->where('pr.is_active', true);
            });
        }

        // ===============================
        // 8) Sorting
        // ===============================
        $allowedSorts = ['id','name','sku','price','stock','updated_at','created_at'];
        $sort = $request->query('sort', 'id');
        $dir  = strtolower($request->query('dir', 'asc')) === 'desc' ? 'desc' : 'asc';

        if (!in_array($sort, $allowedSorts, true)) {
            $sort = 'id';
        }

        $q->orderBy($sort, $dir);

        // ===============================
        // 9) Pagination
        // ===============================
        // Hard cap at 200 to avoid OOM in LengthAwarePaginator JSON serialization.
        $perPage = max(1, min((int) $request->query('per_page', 50), 200));

        $p = $q->paginate($perPage)->appends($request->query());

        $items = $p->items();

        // POS / catalog: attach how many recipe products can be made from current ingredient stock
        $withMake =
            $storeId
            && (
                $request->boolean('exclude_recipe_ingredients')
                || $request->boolean('with_available_to_make')
            )
            && Schema::hasTable('product_recipes');

        if ($withMake && $items !== []) {
            $ids = array_map(
                static fn ($row) => (int) (is_array($row) ? ($row['id'] ?? 0) : $row->id),
                $items
            );
            $makeMap = app(RecipeService::class)->availableToMakeMap($ids, $storeId);

            foreach ($items as $row) {
                $id = (int) (is_array($row) ? ($row['id'] ?? 0) : $row->id);
                if (isset($makeMap[$id])) {
                    $row->has_recipe = true;
                    $row->available_to_make = $makeMap[$id]['qty'];
                    $row->recipe_bottleneck = $makeMap[$id]['bottleneck'];
                } else {
                    $row->has_recipe = false;
                    $row->available_to_make = null;
                    $row->recipe_bottleneck = null;
                }
            }
        }

        return response()->json([
            'items' => $items,
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
            'inventory_type'    => 'nullable|string|in:stock,service,non_stock', // ⬅️ pakai inventory_type
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
                if ($user->isAdmin()) {
                    $scope = $data['scope'] ?? null;
                    if ($scope === 'global') {
                        $storeLocationId = null;
                    } else {
                        $storeLocationId = $data['store_location_id'] ?? null;
                    }
                } else {
                    $requestedStore = $data['store_location_id'] ?? $user->store_location_id;
                    if (! $user->canAccessStore($requestedStore ? (int) $requestedStore : null)) {
                        abort(403, 'Store access denied');
                    }
                    $storeLocationId = $requestedStore ? (int) $requestedStore : null;
                }

                // 2b) Tentukan unit_id (default dari database jika tidak dikirim)
                if (!empty($data['unit_id'])) {
                    $unitId = $data['unit_id'];
                } else {
                    $defaultUnit = Unit::where('is_system', true)->orderBy('id')->first();
                    $unitId = $defaultUnit?->id;
                }

                // 2c) Tentukan inventory_type (default: stock)
                $inventoryType = strtolower((string)($data['inventory_type'] ?? 'stock'));
                if (! in_array($inventoryType, ['stock','service','non_stock'], true)) {
                    $inventoryType = 'stock';
                }

                // 2d) SKU: pakai input, atau auto SK-{storeCode}-001
                $sku = trim((string) ($data['sku'] ?? ''));
                if ($sku === '') {
                    $sku = $this->generateNextSku($storeLocationId ? (int) $storeLocationId : null);
                }

                // 3) Buat produk (retry singkat jika race unique SKU)
                $productId = null;
                for ($attempt = 0; $attempt < 5; $attempt++) {
                    try {
                        $productId = DB::table('products')->insertGetId([
                            'sku'               => $sku,
                            'name'              => $data['name'],
                            'price'             => $data['price'],
                            'category_id'       => $data['category_id'] ?? null,
                            'sub_category_id'   => $data['sub_category_id'] ?? null,
                            'description'       => $data['description'] ?? null,
                            'unit_id'           => $unitId,
                            'image_url'         => $imagePath,
                            'stock'             => 0, // ⬅️ kolom stock akan disinkron dari layers kalau tipe stock
                            'inventory_type'    => $inventoryType,
                            'store_location_id' => $storeLocationId,
                            'created_by'        => $user->id,
                            'created_at'        => now(),
                            'updated_at'        => now(),
                        ]);
                        break;
                    } catch (QueryException $e) {
                        $isUnique = ($e->errorInfo[1] ?? null) === 1062
                            || str_contains(strtolower($e->getMessage()), 'unique')
                            || str_contains(strtolower($e->getMessage()), 'duplicate');
                        if (! $isUnique || $attempt === 4) {
                            throw $e;
                        }
                        $sku = $this->generateNextSku($storeLocationId ? (int) $storeLocationId : null);
                    }
                }

                if ($productId === null) {
                    abort(500, 'Failed to create product');
                }

                // 4) Stok awal (jika ada) — HANYA untuk produk inventory_type = stock
                $initQty = (float) ($data['stock'] ?? 0);

                if ($initQty > 0 && $inventoryType === 'stock') {
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

                    // 5) Legacy column: mirror this branch only (layers = source of truth)
                    InventoryService::syncLegacyProductStock($productId, $storeLocationId);
                }

                // untuk produk non-stock, stock tetap 0. POS akan anggap unlimited dari inventory_type di FE.
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

    public function show(Request $request, Product $product)
    {
        $product->load('unit');

        $storeId = $this->resolveStoreIdFromRequest($request);
        $payload = $product->toArray();
        $payload['stock'] = (int) InventoryService::displayStock($product->id, $storeId, $product);

        return response()->json($payload);
    }

    public function update(UpdateProductRequest $request, Product $product)
    {
        $user = $request->user();
        $data = $request->validated(); // sudah termasuk unit_id & inventory_type kalau di-form-kan

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
        if (! $user->isAdmin()) {
            if (empty($product->store_location_id) || ! $user->canAccessStore((int) $product->store_location_id)) {
                return response()->json(['message' => 'Forbidden'], 403);
            }

            unset($data['store_location_id'], $data['scope']);
        } else {
            // admin: boleh pakai scope=global, kalau tidak → biarkan store_location_id sekarang
            $scope = $request->input('scope'); // 'global' | 'store' | null

            if ($scope === 'global') {
                $product->store_location_id = null;
            }

            unset($data['store_location_id'], $data['scope']);
        }

        // Di UPDATE:
        // - inventory_type boleh diubah (misal dari stock -> service) kalau kamu buka di form.
        // - kalau mau batasi non-admin tidak boleh ganti inventory_type, tinggal unset di blok di atas.

        // Stok fisik hanya lewat inventory_layers (GR / opname / import). Jangan tulis dari form edit.
        unset($data['stock']);

        if (
            isset($data['inventory_type'])
            && $data['inventory_type'] === Product::INVENTORY_TYPE_NON_STOCK
        ) {
            $data['stock'] = 0;
        }

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
