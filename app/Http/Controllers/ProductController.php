<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Product;
use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Arr;

class ProductController extends Controller
{
    /**
     * GET /api/products
     * Query params (opsional):
     * - search: string (cari di name/sku)
     * - sku: string (exact)
     * - category_id: int|array
     * - sub_category_id: int|array
     * - min_price, max_price: numeric
     * - sort: one of [id,name,sku,price,updated_at,created_at]
     * - dir: asc|desc
     * - page, per_page (default 10, max 100)
     *
     * Response (dinormalisasi):
     * { items: [...], meta: { current_page, per_page, last_page, total }, links: { next, prev } }
     */
    public function index(Request $request)
    {
        $perPage = (int) ($request->input('per_page', 10));
        $perPage = $perPage > 100 ? 100 : ($perPage < 1 ? 10 : $perPage);

        $sort     = (string) $request->input('sort', 'id');
        $dir      = strtolower((string) $request->input('dir', 'asc')) === 'desc' ? 'desc' : 'asc';
        $allowedSorts = ['id','name','sku','price','updated_at','created_at'];
        if (!in_array($sort, $allowedSorts, true)) {
            $sort = 'id';
        }

        // siapkan nilai filter
        $search        = trim((string) $request->input('search', ''));
        $skuExact      = trim((string) $request->input('sku', ''));
        $categoryId    = $request->input('category_id');     // int | array
        $subCategoryId = $request->input('sub_category_id'); // int | array
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
            // exact SKU cepat
            ->when($skuExact !== '', fn($qq) => $qq->where('sku', '=', $skuExact))
            // search di name/sku
            ->when($search !== '', function ($qq) use ($search) {
                $qq->where(function ($w) use ($search) {
                    $w->where('name', 'like', "%{$search}%")
                      ->orWhere('sku',  'like', "%{$search}%");
                });
            })
            // category filter (single/array)
            ->when($categoryId, function ($qq) use ($categoryId) {
                is_array($categoryId)
                    ? $qq->whereIn('category_id', array_filter($categoryId))
                    : $qq->where('category_id', $categoryId);
            })
            // sub category filter (single/array)
            ->when($subCategoryId, function ($qq) use ($subCategoryId) {
                is_array($subCategoryId)
                    ? $qq->whereIn('sub_category_id', array_filter($subCategoryId))
                    : $qq->where('sub_category_id', $subCategoryId);
            })
            // price range
            ->when($minPrice !== null && $minPrice !== '', fn($qq) => $qq->where('price', '>=', (float) $minPrice))
            ->when($maxPrice !== null && $maxPrice !== '', fn($qq) => $qq->where('price', '<=', (float) $maxPrice))
            // sorting
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

    public function store(StoreProductRequest $request)
    {
        $data = $request->validated();

        if ($request->hasFile('image')) {
            // pakai Storage biar konsisten
            $data['image'] = $request->file('image')->store('products','public');
        }

        $product = Product::create($data);
        return response()->json($product, 201);
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
            if (!Arr::has($data, $k) || $data[$k] === '') {
                $data[$k] = null;
            }
        }

        if ($request->hasFile('image')) {
            $data['image'] = $request->file('image')->store('products','public');
        }

        $product->update($data);

        return response()->json(['data' => $product]);
    }

    public function destroy(Product $product)
    {
        $product->delete();
        return response()->json(['message' => 'Product deleted']);
    }

    public function upload(Product $product, Request $request)
    {
        $request->validate([
            'image' => ['required','image','mimes:jpg,jpeg,png,svg','max:5120'],
        ]);

        $file = $request->file('image');

        $uploadPath = public_path('uploads/products');
        if (!file_exists($uploadPath)) {
            mkdir($uploadPath, 0755, true);
        }

        $filename = Str::uuid().'.'.$file->getClientOriginalExtension();
        $file->move($uploadPath, $filename);

        if (file_exists($uploadPath . '/' . $filename)) {
            chmod($uploadPath . '/' . $filename, 0644);
        }

        $imageUrl = "/public/uploads/products/{$filename}";
        $product->image_url = $imageUrl;
        $product->save();

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $product->id,
                'image_url' => $imageUrl,
                'full_url' => url($imageUrl),
            ]
        ]);
    }

    public function search(Request $request)
    {
        $request->validate(['sku' => 'required|string']);

        $product = Product::where('sku', $request->sku)->first();

        if (! $product) {
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
