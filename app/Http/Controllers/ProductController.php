<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Product;
use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $query = Product::query();


        if ($request->has('search')) {
        $query->where(function ($q) use ($request) {
            $q->where('name','like','%'.$request->search.'%')
            ->orWhere('sku','like','%'.$request->search.'%');
        });
        }


        if ($request->has('category_id')) {
        $query->where('category_id', $request->category_id);
        }


        if ($request->has('min_price')) {
            $query->where('price','>=',$request->min_price);
        }

        
        if ($request->has('max_price')) {
            $query->where('price','<=',$request->max_price);
        }


        return $query->orderBy('id', 'asc')->get();
    }


    public function store(StoreProductRequest $request)
    {
        $data = $request->validated();


        if ($request->hasFile('image')) {
            $data['image'] = $request->file('image')->store('products','public');
        }


        $product = Product::create($data);
        return response()->json($product,201);
        }


        public function show(Product $product)
        {
        return response()->json($product);
    }


    public function update(UpdateProductRequest $request, Product $product)
    {
        $data = $request->validated();


        if ($request->hasFile('image')) {
            $data['image'] = $request->file('image')->store('products','public');
        }


        $product->update($data);
        return response()->json($product);
        }


        public function destroy(Product $product)
        {
        $product->delete();
        return response()->json(['message' => 'Product deleted']);
    }

    public function upload(Product $product, Request $request)
    {
        // Validasi file
        $request->validate([
            'image' => ['required','image','mimes:jpg,jpeg,png,svg','max:5120'],
        ]);
        
        $file = $request->file('image');
        
        // Path folder upload
        $uploadPath = public_path('uploads/products');
        if (!file_exists($uploadPath)) {
            mkdir($uploadPath, 0755, true);
        }
        
        // Generate filename unik
        $filename = Str::uuid().'.'.$file->getClientOriginalExtension();
        
        // Upload file ke folder
        $file->move($uploadPath, $filename);
        
        // Set permission file (opsional)
        if (file_exists($uploadPath . '/' . $filename)) {
            chmod($uploadPath . '/' . $filename, 0644);
        }
        
        // ✅ PENTING: URL harus include /public/
        $imageUrl = "/public/uploads/products/{$filename}";
        
        // Simpan ke database
        $product->image_url = $imageUrl;
        $product->save();
        
        return response()->json([
            'success' => true,
            'data' => [
                'id' => $product->id,
                'image_url' => $imageUrl,
                'full_url' => url($imageUrl), // Full URL dengan domain
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
