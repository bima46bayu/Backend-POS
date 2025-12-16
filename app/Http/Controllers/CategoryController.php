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

        // Tentukan store_location_id yang akan dipakai
        $storeId = $request->input('store_location_id');

        if (!$storeId && $user) {
            $storeId = $user->store_location_id
                ?? optional($user->storeLocation)->id
                ?? null;
        }

        $data = $request->validate([
            'name'        => [
                'required',
                'string',
                'max:100',
                // unik per store
                Rule::unique('categories')->where(function ($q) use ($storeId) {
                    return $q->where('store_location_id', $storeId);
                }),
            ],
            'description'      => 'nullable|string',
            'store_location_id'=> 'nullable|exists:store_locations,id',
        ]);

        // Paksa store_location_id yang dipakai (kalau FE nggak kirim)
        $data['store_location_id'] = $storeId;

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
        // store yang akan dipakai untuk cek unique:
        // kalau user ganti store_location_id di request, pakai itu,
        // kalau tidak, pakai store_location_id existing di row
        $storeIdForUnique = $request->input('store_location_id', $category->store_location_id);

        $data = $request->validate([
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:100',
                Rule::unique('categories')
                    ->ignore($category->id) // abaikan diri sendiri
                    ->where(function ($q) use ($storeIdForUnique) {
                        return $q->where('store_location_id', $storeIdForUnique);
                    }),
            ],
            'description'       => 'sometimes|nullable|string',
            'store_location_id' => 'sometimes|nullable|exists:store_locations,id',
        ]);

        // Kalau store_location_id tidak dikirim, biarkan nilai lama
        if (!array_key_exists('store_location_id', $data)) {
            $data['store_location_id'] = $category->store_location_id;
        }

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
