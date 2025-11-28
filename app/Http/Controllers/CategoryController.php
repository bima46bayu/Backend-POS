<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Http\Request;
use App\Http\Requests\StoreCategoryRequest;
use App\Http\Requests\UpdateCategoryRequest;

class CategoryController extends Controller
{
    // GET /api/categories?search=&per_page=
    public function index(Request $request)
    {
        $q = Category::query();

        if ($s = $request->query('search')) {
            $q->where('name', 'like', "%{$s}%")
              ->orWhere('description', 'like', "%{$s}%");
        }

        $perPage = (int) $request->query('per_page', 15);
        $perPage = $perPage > 100 ? 100 : ($perPage < 1 ? 15 : $perPage);

        return $q->orderBy('created_at')->paginate($perPage);
    }

    // POST /api/categories
    public function store(Request $request)
    {
        $data = $request->validate([
            'name'        => 'required|string|max:100|unique:categories,name',
            'description' => 'nullable|string',
        ]);

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
        ]);

        $category->update($data);
        return response()->json($category);
    }

    // DELETE /api/categories/{category}
    public function destroy(Category $category)
    {
        // jika di migration sub_categories pakai cascadeOnDelete, ini aman
        $category->delete();
        return response()->json(['message' => 'Category deleted']);
    }
}

