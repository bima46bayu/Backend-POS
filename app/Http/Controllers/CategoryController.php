<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    // GET /api/categories?search=&per_page=&store_location_id=
    public function index(Request $request)
    {
        $q = Category::query();
        $user = $request->user();

        // 1) Tentukan store_location_id (prioritas: query param → user)
        $storeId = $request->query('store_location_id');

        if (!$storeId && $user) {
            $storeId = $user->store_location_id
                ?? optional($user->storeLocation)->id
                ?? null;
        }

        // 2) Filter berdasarkan store_location_id
        if ($storeId) {
            $q->where('store_location_id', $storeId);
        }

        // 3) Search
        if ($s = $request->query('search')) {
            $q->where(function ($qq) use ($s) {
                $qq->where('name', 'like', "%{$s}%")
                   ->orWhere('description', 'like', "%{$s}%");
            });
        }

        // 4) Pagination
        $perPage = (int) $request->query('per_page', 15);
        $perPage = $perPage > 100 ? 100 : ($perPage < 1 ? 15 : $perPage);

        return $q->orderBy('created_at')->paginate($perPage);
    }

    // POST /api/categories
    public function store(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'name'        => 'required|string|max:100|unique:categories,name',
            'description' => 'nullable|string',
            'store_location_id' => 'nullable|exists:store_locations,id',
        ]);

        // Kalau FE nggak kirim store_location_id → pakai store user
        if (empty($data['store_location_id']) && $user) {
            $data['store_location_id'] = $user->store_location_id
                ?? optional($user->storeLocation)->id
                ?? null;
        }

        $category = Category::create($data);

        return response()->json($category, 201);
    }

    // GET /api/categories/{category}
    public function show(Category $category)
    {
        return response()->json($category);
    }

    // PATCH/PUT /api/categories/{category}
    public function update(Request $request, Category $category)
    {
        $data = $request->validate([
            'name'        => 'sometimes|required|string|max:100|unique:categories,name,' . $category->id,
            'description' => 'sometimes|nullable|string',
            'store_location_id' => 'sometimes|nullable|exists:store_locations,id',
        ]);

        $category->update($data);

        return response()->json($category);
    }

    // DELETE /api/categories/{category}
    public function destroy(Category $category)
    {
        $category->delete();

        return response()->json(['message' => 'Category deleted']);
    }
}
