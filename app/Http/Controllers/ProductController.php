<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Product;
use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $query = Product::query();


        if ($request->has('search')) {
        $query->where('name','like','%'.$request->search.'%')
        ->orWhere('sku','like','%'.$request->search.'%');
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


        return response()->json($query->paginate(10)); 
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
}
