<?php

namespace App\Http\Controllers;

use App\Models\StoreLocation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class StoreLocationController extends Controller
{
    public function index(Request $request)
    {
        $perPage = max(1, min((int) $request->get('per_page', 20), 100));
        $q = StoreLocation::query()->with('parent');

        $this->scopeQueryToAllowedStores($q, $request->user(), 'id');

        if ($search = $request->query('search')) {
            $q->where(function ($qq) use ($search) {
                $qq->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('address', 'like', "%{$search}%");
            });
        }

        return $q->orderBy('created_at')->paginate($perPage);
    }

    public function show(Request $request, $id)
    {
        $store = StoreLocation::with('parent')->findOrFail($id);
        $this->authorizeStoreAccess($request->user(), (int) $store->id);

        return response()->json([
            'data' => $store,
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'code'      => 'required|string|max:32|unique:store_locations,code',
            'name'      => 'required|string|max:255',
            'address'   => 'nullable|string|max:255',
            'phone'     => 'nullable|string|max:32',
            'parent_id' => 'nullable|exists:store_locations,id',
        ]);

        $this->validateParentAssignment($user, $data['parent_id'] ?? null);

        $store = StoreLocation::create($data);

        return response()->json([
            'data' => $store->load('parent'),
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $store = StoreLocation::findOrFail($id);
        $user = $request->user();

        $this->authorizeStoreAccess($user, (int) $store->id);

        $data = $request->validate([
            'code'      => 'sometimes|string|max:32|unique:store_locations,code,' . $store->id,
            'name'      => 'sometimes|string|max:255',
            'address'   => 'nullable|string|max:255',
            'phone'     => 'nullable|string|max:32',
            'parent_id' => 'nullable|exists:store_locations,id',
        ]);

        if (array_key_exists('parent_id', $data)) {
            if ((int) ($data['parent_id'] ?? 0) === (int) $store->id) {
                return response()->json(['message' => 'Store cannot be its own parent'], 422);
            }

            $this->validateParentAssignment($user, $data['parent_id'] ?? null, (int) $store->id);
        }

        $store->update($data);

        return response()->json([
            'data' => $store->fresh('parent'),
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $store = StoreLocation::findOrFail($id);
        $this->authorizeStoreAccess($request->user(), (int) $store->id);

        if ($user = $request->user()) {
            if ($user->isRegionalManager() && $store->isRoot()) {
                return response()->json(['message' => 'Regional manager cannot delete region root store'], 422);
            }
        }

        if (method_exists($store, 'users') && $store->users()->exists()) {
            return response()->json(['message' => 'Tidak bisa menghapus: masih dipakai user'], 422);
        }

        if ($store->children()->exists()) {
            return response()->json(['message' => 'Tidak bisa menghapus: masih punya cabang'], 422);
        }

        $store->delete();

        return response()->json(['message' => 'Deleted']);
    }

    public function uploadLogo(Request $request, $id)
    {
        $store = StoreLocation::findOrFail($id);
        $this->authorizeStoreAccess($request->user(), (int) $store->id);

        $request->validate([
            'logo' => 'required|image|mimes:jpeg,png,jpg,svg,webp|max:2048',
        ]);

        $file = $request->file('logo');

        $uploadDir = public_path('uploads/storeLogo');
        if (! is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $ext = $file->getClientOriginalExtension();
        $base = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $slug = Str::slug($base) ?: 'store-logo';
        $filename = $slug . '-' . time() . '.' . $ext;

        $file->move($uploadDir, $filename);

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

    public function logo($id)
    {
        try {
            $store = StoreLocation::findOrFail($id);

            if (! $store->logo_url) {
                return response()->json(['message' => 'Logo not set'], 404);
            }

            $path = public_path(ltrim($store->logo_url, '/'));
            if (! is_file($path)) {
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

    private function validateParentAssignment(User $user, ?int $parentId, ?int $storeId = null): void
    {
        if ($parentId === null) {
            if (! $user->isAdmin()) {
                abort(403, 'Only HQ admin can create or move region root stores');
            }

            return;
        }

        $parent = StoreLocation::findOrFail($parentId);

        if (! $parent->isRoot()) {
            abort(422, 'Parent store must be a region root (no parent)');
        }

        if ($user->isRegionalManager()) {
            $allowed = $user->allowedStoreIds() ?? [];
            $rootId = (int) $user->store_location_id;

            if (! in_array($rootId, $allowed, true) || (int) $parent->id !== $rootId) {
                abort(403, 'Regional manager can only manage branches under their assigned region');
            }
        } elseif (! $user->isAdmin()) {
            abort(403, 'Forbidden');
        }

        if ($storeId !== null && (int) $parentId === $storeId) {
            abort(422, 'Store cannot be its own parent');
        }
    }
}
