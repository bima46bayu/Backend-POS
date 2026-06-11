<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    /**
     * GET /api/users?search=&role=&per_page=
     */
    public function index(Request $r)
    {
        $actor = $r->user();
        $q = User::query()->with('storeLocation');

        $q->visibleToActor($actor);

        if ($r->filled('store_location_id')) {
            $val = $r->input('store_location_id');
            if ($val === 'null') {
                $q->whereNull('store_location_id');
            } else {
                $storeId = (int) $val;
                $this->authorizeStoreAccess($actor, $storeId);
                $q->where('store_location_id', $storeId);
            }
        } elseif ($r->filled('store_id')) {
            $val = $r->input('store_id');
            if ($val === 'null') {
                $q->whereNull('store_location_id');
            } else {
                $storeId = (int) $val;
                $this->authorizeStoreAccess($actor, $storeId);
                $q->where('store_location_id', $storeId);
            }
        }

        if ($r->filled('role')) {
            $q->where('role', strtolower($r->input('role')));
        }

        if ($r->filled('search')) {
            $s = $r->input('search');
            $q->where(function ($qq) use ($s) {
                $qq->where('name', 'like', "%{$s}%")
                    ->orWhere('email', 'like', "%{$s}%");
            });
        }

        $per = max(1, min((int) $r->input('per_page', 10), 100));
        $users = $q->orderByDesc('id')->paginate($per)->appends($r->query());

        return response()->json($users);
    }

    public function show(Request $r, User $user)
    {
        if (! $r->user()->canManageUser($user) && $r->user()->id !== $user->id) {
            abort(403, 'Forbidden');
        }

        $user->load('storeLocation')->makeHidden(['password', 'remember_token']);

        return $user;
    }

    public function store(Request $r)
    {
        $actor = $r->user();

        $data = $r->validate([
            'name'              => ['required', 'string', 'max:150'],
            'email'             => ['required', 'email', 'max:150', 'unique:users,email'],
            'password'          => ['required', 'string', 'min:8'],
            'role'              => ['required', Rule::in($actor->assignableRoles())],
            'store_location_id' => ['nullable', 'exists:store_locations,id'],
        ]);

        $this->validateUserStoreAssignment($actor, $data['role'], $data['store_location_id'] ?? null);

        $user = User::create($data);
        $user->load('storeLocation')->makeHidden(['password', 'remember_token']);

        return response()->json($user, 201);
    }

    public function update(Request $r, User $user)
    {
        $actor = $r->user();

        if (! $actor->canManageUser($user) && $actor->id !== $user->id) {
            abort(403, 'Forbidden');
        }

        $data = $r->validate([
            'name'              => ['sometimes', 'required', 'string', 'max:150'],
            'email'             => ['sometimes', 'required', 'email', 'max:150', Rule::unique('users', 'email')->ignore($user->id)],
            'store_location_id' => ['sometimes', 'nullable', 'exists:store_locations,id'],
            'role'              => ['sometimes', 'required', Rule::in($actor->assignableRoles())],
        ]);

        if (array_key_exists('role', $data) || array_key_exists('store_location_id', $data)) {
            $role = $data['role'] ?? $user->role;
            $storeId = array_key_exists('store_location_id', $data)
                ? $data['store_location_id']
                : $user->store_location_id;

            $this->validateUserStoreAssignment($actor, $role, $storeId);
        }

        if (array_key_exists('role', $data)) {
            if ($r->user()->id === $user->id && $data['role'] !== User::ROLE_ADMIN) {
                $hasOtherAdmin = User::where('role', User::ROLE_ADMIN)
                    ->where('id', '!=', $user->id)
                    ->exists();

                if (! $hasOtherAdmin) {
                    return response()->json(['message' => 'At least one admin is required'], 422);
                }
            }
        }

        $user->update($data);
        $user->load('storeLocation')->makeHidden(['password', 'remember_token']);

        return $user;
    }

    public function destroy(Request $r, User $user)
    {
        if (! $r->user()->canManageUser($user)) {
            abort(403, 'Forbidden');
        }

        if ($r->user()->id === $user->id) {
            return response()->json(['message' => 'You cannot delete your own account'], 422);
        }

        $user->tokens()->delete();
        $user->delete();

        return response()->json(['message' => 'deleted']);
    }

    public function updateRole(Request $r, User $user)
    {
        $actor = $r->user();

        if (! $actor->canManageUser($user)) {
            abort(403, 'Forbidden');
        }

        $data = $r->validate([
            'role' => ['required', Rule::in($actor->assignableRoles())],
        ]);

        $this->validateUserStoreAssignment($actor, $data['role'], $user->store_location_id);

        if ($r->user()->id === $user->id && $data['role'] !== User::ROLE_ADMIN) {
            if (User::where('role', User::ROLE_ADMIN)->where('id', '!=', $user->id)->count() === 0) {
                return response()->json(['message' => 'At least one admin is required'], 422);
            }
        }

        $user->update(['role' => $data['role']]);
        $user->makeHidden(['password', 'remember_token']);

        return $user;
    }

    public function resetPassword(Request $r, User $user)
    {
        if (! $r->user()->canManageUser($user)) {
            abort(403, 'Forbidden');
        }

        $data = $r->validate([
            'password' => ['required', 'string', 'min:8'],
        ]);

        $user->update(['password' => $data['password']]);
        $user->tokens()->delete();

        return response()->json(['message' => 'password reset']);
    }

    public function roleOptions(Request $r)
    {
        $actor = $r->user();
        $labels = [
            User::ROLE_ADMIN            => 'Admin (HQ)',
            User::ROLE_REGIONAL_MANAGER => 'Regional Manager',
            User::ROLE_STORE_ADMIN      => 'Store Admin',
            User::ROLE_KASIR            => 'Kasir',
        ];

        return collect($actor->assignableRoles())
            ->map(fn ($value) => ['value' => $value, 'label' => $labels[$value] ?? $value])
            ->values()
            ->all();
    }

    private function validateUserStoreAssignment(User $actor, string $role, $storeLocationId): void
    {
        if ($role === User::ROLE_ADMIN) {
            return;
        }

        if (! $storeLocationId) {
            abort(422, 'Store location is required for this role');
        }

        $this->authorizeStoreAccess($actor, (int) $storeLocationId);

        if ($role === User::ROLE_REGIONAL_MANAGER) {
            $store = \App\Models\StoreLocation::findOrFail((int) $storeLocationId);
            if (! $store->isRoot()) {
                abort(422, 'Regional manager must be assigned to a region root store');
            }
        }

        if ($actor->isRegionalManager() && $role === User::ROLE_REGIONAL_MANAGER) {
            abort(403, 'Forbidden');
        }
    }
}
