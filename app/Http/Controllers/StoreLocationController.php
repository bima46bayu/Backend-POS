<?php

namespace App\Http\Controllers;

use App\Models\StoreLocation;
use Illuminate\Http\Request;

class StoreLocationController extends Controller
{
    public function __construct()
    {
        // pastikan hanya admin yang bisa akses semua endpoint
        $this->middleware(function ($request, $next) {
            if ($request->user()->role !== 'admin') {
                return response()->json(['message' => 'Unauthorized'], 403);
            }
            return $next($request);
        });
    }

    public function index(Request $request)
    {
        $q = StoreLocation::query();

        if ($search = $request->query('search')) {
            $q->where('name', 'like', "%$search%")
              ->orWhere('code', 'like', "%$search%")
              ->orWhere('address', 'like', "%$search%");
        }

        return $q->orderBy('name')->paginate($request->get('per_page', 20));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'code'    => 'required|string|max:32|unique:store_locations,code',
            'name'    => 'required|string|max:255',
            'address' => 'nullable|string|max:255',
            'phone'   => 'nullable|string|max:32',
        ]);

        $store = StoreLocation::create($data);
        return response()->json($store, 201);
    }

    public function show($id)
    {
        $store = StoreLocation::findOrFail($id);
        return response()->json($store);
    }

    public function update(Request $request, $id)
    {
        $store = StoreLocation::findOrFail($id);

        $data = $request->validate([
            'code'    => 'sometimes|string|max:32|unique:store_locations,code,'.$store->id,
            'name'    => 'sometimes|string|max:255',
            'address' => 'nullable|string|max:255',
            'phone'   => 'nullable|string|max:32',
        ]);

        $store->update($data);
        return response()->json($store);
    }

    public function destroy($id)
    {
        $store = StoreLocation::findOrFail($id);

        // lindungi kalau masih dipakai user
        if ($store->users()->exists()) {
            return response()->json(['message' => 'Tidak bisa menghapus: masih dipakai user'], 422);
        }

        $store->delete();
        return response()->json(['message' => 'Deleted']);
    }
}
