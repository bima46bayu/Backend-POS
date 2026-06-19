<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CategoryController extends Controller
{
    // GET /api/categories?search=&per_page=&store_location_id=
    public function index(Request $request)
    {
        $q = Category::query();

        $this->applySaleStoreScope($q, $request);

        if ($s = $request->query('search')) {
            $q->where(function ($qq) use ($s) {
                $qq->where('name', 'like', "%{$s}%")
                   ->orWhere('description', 'like', "%{$s}%");
            });
        }

        $perPage = (int) $request->query('per_page', 15);
        $perPage = $perPage > 100 ? 100 : ($perPage < 1 ? 15 : $perPage);

        return $q->orderBy('created_at')->paginate($perPage);
    }

    // POST /api/categories
    public function store(Request $request)
    {
        $requested = $request->filled('store_location_id')
            ? (int) $request->input('store_location_id')
            : null;

        $storeId = $this->resolveStoreIdFromRequest($request, $requested);

        if ($storeId === null) {
            abort(422, 'Store wajib dipilih.');
        }

        $data = $request->validate([
            'name'        => [
                'required',
                'string',
                'max:100',
                Rule::unique('categories')->where(function ($q) use ($storeId) {
                    return $q->where('store_location_id', $storeId);
                }),
            ],
            'description'       => 'nullable|string',
            'store_location_id' => 'nullable|exists:store_locations,id',
        ]);

        unset($data['store_location_id']);
        $data['store_location_id'] = $storeId;

        $category = Category::create($data);

        return response()->json($category, 201);
    }

    public function show(Category $category)
    {
        $this->authorizeCategoryStore($category);

        return response()->json($category);
    }

    public function update(Request $request, Category $category)
    {
        $this->authorizeCategoryStore($category);

        $storeIdForUnique = (int) $category->store_location_id;

        $data = $request->validate([
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:100',
                Rule::unique('categories')
                    ->ignore($category->id)
                    ->where(function ($q) use ($storeIdForUnique) {
                        return $q->where('store_location_id', $storeIdForUnique);
                    }),
            ],
            'description' => 'sometimes|nullable|string',
        ]);

        $category->update($data);

        return response()->json($category);
    }

    public function destroy(Category $category)
    {
        $this->authorizeCategoryStore($category);

        $category->delete();

        return response()->json(['message' => 'Category deleted']);
    }

    protected function authorizeCategoryStore(Category $category): void
    {
        $this->authorizeStoreAccess(
            request()->user(),
            (int) $category->store_location_id
        );
    }
}
