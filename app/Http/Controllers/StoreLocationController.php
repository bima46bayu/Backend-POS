<?php

namespace App\Http\Controllers;

use App\Models\StoreLocation;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

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
        $store = StoreLocation::findOrFail($id);

        // supaya FE bisa handle bentuk { data: {...} } atau {...}
        return response()->json([
            'data' => $store,
        ]);
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

        return response()->json([
            'data' => $store,
        ], 201);
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

        return response()->json([
            'data' => $store,
        ]);
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

    /* =========================
       Upload logo (simpan URL)
       ========================= */
    public function uploadLogo(Request $request, $id)
    {
        $store = StoreLocation::findOrFail($id);

        $request->validate([
            'logo' => 'required|image|mimes:jpeg,png,jpg,svg,webp|max:2048',
        ]);

        $file = $request->file('logo');

        $uploadDir = public_path('uploads/storeLogo');
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $ext   = $file->getClientOriginalExtension();
        $base  = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $slug  = Str::slug($base) ?: 'store-logo';
        $filename = $slug . '-' . time() . '.' . $ext;

        $file->move($uploadDir, $filename);

        // Hapus logo lama (kalau masih di folder yang sama)
        if ($store->logo_url) {
            $oldPath = public_path(ltrim($store->logo_url, '/'));
            if (is_file($oldPath)) {
                @unlink($oldPath);
            }
        }

        $relativeUrl = '/uploads/storeLogo/' . $filename;
        $store->logo_url = $relativeUrl;
        $store->save();

        return response()->json([
            'data' => $store,
        ]);
    }

    /* =========================
       Serve logo sebagai image
       ========================= */
    public function logo($id)
    {
        try {
            $store = StoreLocation::findOrFail($id);

            if (!$store->logo_url) {
                return response()->json(['message' => 'Logo not set'], 404);
            }

            $path = public_path(ltrim($store->logo_url, '/'));
            if (!is_file($path)) {
                return response()->json(['message' => 'Logo file not found'], 404);
            }

            $mime = mime_content_type($path) ?: 'image/png';

            return response()->file($path, [
                'Content-Type'                 => $mime,
                'Access-Control-Allow-Origin'  => '*',
                'Access-Control-Allow-Methods' => 'GET, OPTIONS',
                'Access-Control-Allow-Headers' => 'Origin, Content-Type, Accept',
            ]);
        } catch (\Throwable $e) {
            Log::error('Error serving store logo', [
                'store_id' => $id,
                'error'    => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Failed to load logo'], 500);
        }
    }
}
