<?php

namespace App\Http\Controllers;

use App\Models\SubCategory;
use Illuminate\Http\Request;

class SubCategoryController extends Controller
{
    // GET /api/sub-categories?search=&category_id=&store_location_id=&per_page=
    public function index(Request $request)
    {
        $q = SubCategory::query()->with('category');
        $user = $request->user();

        // 1) Tentukan store_location_id
        $storeId = $request->query('store_location_id');

        if (!$storeId && $user) {
            $storeId = $user->store_location_id
                ?? optional($user->storeLocation)->id
                ?? null;
        }

        // 2) Filter berdasarkan store
        if ($storeId) {
            $q->where('store_location_id', $storeId);
        }

        // 3) Filter by category_id (untuk dependent dropdown)
        if ($request->filled('category_id')) {
            $q->where('category_id', $request->query('category_id'));
        }

        // 4) Search
        if ($s = $request->query('search')) {
            $q->where(function ($qq) use ($s) {
                $qq->where('name', 'like', "%{$s}%")
                   ->orWhere('description', 'like', "%{$s}%");
            });
        }

        // 5) Pagination
        $perPage = (int) $request->query('per_page', 15);
        $perPage = $perPage > 100 ? 100 : ($perPage < 1 ? 15 : $perPage);

        return $q->orderBy('created_at')->paginate($perPage);
    }

    // POST /api/sub-categories
    public function store(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'name'        => 'required|string|max:100',
            'description' => 'nullable|string',
            'category_id' => 'required|exists:categories,id',
            'store_location_id' => 'nullable|exists:store_locations,id',
        ]);

        // Kalau FE tidak kirim store_location_id → ikut store user
        if (empty($data['store_location_id']) && $user) {
            $data['store_location_id'] = $user->store_location_id
                ?? optional($user->storeLocation)->id
                ?? null;
        }

        $sub = SubCategory::create($data);

        return response()->json($sub, 201);
    }

    // GET /api/sub-categories/{subCategory}
    public function show(SubCategory $subCategory)
    {
        return response()->json($subCategory->load('category'));
    }

    // PATCH/PUT /api/sub-categories/{subCategory}
    public function update(Request $request, SubCategory $subCategory)
    {
        $data = $request->validate([
            'name'        => 'sometimes|required|string|max:100',
            'description' => 'sometimes|nullable|string',
            'category_id' => 'sometimes|required|exists:categories,id',
            'store_location_id' => 'sometimes|nullable|exists:store_locations,id',
        ]);

        $subCategory->update($data);

        return response()->json($subCategory);
    }

    // DELETE /api/sub-categories/{subCategory}
    public function destroy(SubCategory $subCategory)
    {
        $subCategory->delete();

        return response()->json(['message' => 'SubCategory deleted']);
    }
}
