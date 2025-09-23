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
        $request->validate([
            'image' => ['required','image','mimes:jpg,jpeg,png,svg','max:5120'],
        ]);

        $file = $request->file('image');

        $path = $file->storeAs(
            'products', // -> public/uploads/products/...
            Str::uuid().'.'.$file->getClientOriginalExtension(),
            'public_uploads'
        );

        $relative = '/uploads/'.$path; // simpan path relatif
        $product->image_url = $relative;
        $product->save();

        return response()->json(['data' => [
            'id' => $product->id,
            'image_url' => $relative,
        ]]);
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
