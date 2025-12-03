<?php

// app/Http/Controllers/StoreLocationController.php
namespace App\Http\Controllers;

use App\Models\StoreLocation;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class StoreLocationController extends Controller
{
    public function index(Request $request)
    {
        $perPage = max(1, min((int) $request->get('per_page', 20), 100));
        $q = StoreLocation::query();

        if ($search = $request->query('search')) {
            $q->where(function ($qq) use ($search) {
                $qq->where('name', 'like', "%{$search}%")
                   ->orWhere('code', 'like', "%{$search}%")
                   ->orWhere('address', 'like', "%{$search}%");
            });
        }

        return $q->orderBy('created_at')->paginate($perPage);
    }

    public function show($id)
    {
        return response()->json(StoreLocation::findOrFail($id));
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

    public function update(Request $request, $id)
    {
        $store = StoreLocation::findOrFail($id);

        $data = $request->validate([
            'code'    => 'sometimes|string|max:32|unique:store_locations,code,' . $store->id,
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

        if (method_exists($store, 'users') && $store->users()->exists()) {
            return response()->json(['message' => 'Tidak bisa menghapus: masih dipakai user'], 422);
        }

        $store->delete();
        return response()->json(['message' => 'Deleted']);
    }

    public function uploadLogo(Request $request, $id)
    {
        $store = StoreLocation::findOrFail($id);

        $data = $request->validate([
            'logo' => 'required|image|mimes:jpeg,png,jpg,svg,webp|max:2048',
        ]);

        $file = $request->file('logo');

        // pastikan foldernya ada
        $uploadDir = public_path('uploads/storeLogo');
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        // bikin nama file unik
        $ext = $file->getClientOriginalExtension();
        $base = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $slug = Str::slug($base);
        $filename = $slug . '-' . time() . '.' . $ext;

        // pindah ke public/uploads/storeLogo
        $file->move($uploadDir, $filename);

        // optional: hapus logo lama kalau masih ada & di folder yang sama
        if ($store->logo_url) {
            $oldPath = public_path(ltrim($store->logo_url, '/'));
            if (file_exists($oldPath)) {
                @unlink($oldPath);
            }
        }

        // simpan URL relatif di DB, misal: /uploads/storeLogo/xx.png
        $relativeUrl = '/uploads/storeLogo/' . $filename;
        $store->logo_url = $relativeUrl;
        $store->save();

        return response()->json([
            'data' => $store,
        ]);
    }
}
