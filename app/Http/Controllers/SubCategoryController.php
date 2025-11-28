<?php

namespace App\Http\Controllers;

use App\Models\SubCategory;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SubCategoryController extends Controller
{
    // GET /api/sub-categories?search=&category_id=&per_page=
    public function index(Request $request)
    {
        $q = SubCategory::query()->with('category:id,name');

        if ($request->filled('category_id')) {
            $q->where('category_id', $request->category_id);
        }
        if ($s = $request->query('search')) {
            $q->where('name', 'like', "%{$s}%");
        }

        $perPage = (int) $request->query('per_page', 15);
        $perPage = $perPage > 100 ? 100 : ($perPage < 1 ? 15 : $perPage);

        return $q->orderBy('created_at')->paginate($perPage);
    }

    // GET /api/categories/{category}/sub-categories
    public function indexByCategory(Category $category)
    {
        return $category->subCategories()->orderBy('name')->get();
    }

    // POST /api/sub-categories
    public function store(Request $request)
    {
        $data = $request->validate([
            'category_id' => 'required|exists:categories,id',
            'name'        => 'required|string|max:100',
        ]);

        // unik per kategori
        $exists = SubCategory::where('category_id', $data['category_id'])
                    ->where('name', $data['name'])
                    ->exists();
        if ($exists) {
            return response()->json(['message' => 'Sub category name already exists in this category'], 422);
        }

        $sub = SubCategory::create($data);
        return response()->json($sub->load('category:id,name'), 201);
    }

    // GET /api/sub-categories/{sub_category}
    public function show(SubCategory $sub_category)
    {
        return response()->json($sub_category->load('category:id,name'));
    }

    // PATCH/PUT /api/sub-categories/{sub_category}
    // PUT/PATCH /api/sub-categories/{subCategory}
    public function update(Request $request, SubCategory $subCategory)
    {
        $data = $request->validate([
            'category_id' => 'sometimes|required|exists:categories,id',
            'name'        => 'sometimes|required|string|max:100',
        ]);

        $newCatId = $data['category_id'] ?? $subCategory->category_id;
        $newName  = $data['name'] ?? $subCategory->name;

        $exists = SubCategory::where('category_id', $newCatId)
            ->where('name', $newName)
            ->where('id', '!=', $subCategory->id)
            ->exists();

        if ($exists) {
            return response()->json([
                'message' => 'Sub category name already exists in this category'
            ], 422);
        }

        $subCategory->update($data);

        return response()->json($subCategory->load('category:id,name'));
    }

    // DELETE /api/sub-categories/{subCategory}
    public function destroy(SubCategory $subCategory)
    {
        $subCategory->delete();
        return response()->json(['message' => 'Sub category deleted']);
    }


}


