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
        $q = User::with('storeLocation')
            ->search($r->input('search'))
            ->role($r->input('role'))
            ->orderByDesc('id');

        $perPage = min((int)($r->per_page ?? 10), 100);
        $res = $q->paginate($perPage);

        // hide sensitive
        $res->getCollection()->transform(function ($u) {
            $u->makeHidden(['password','remember_token']);
            return $u;
        });

        return $res;
    }

    /**
     * GET /api/users/{user}
     */
    public function show(User $user)
    {
        $user->load('storeLocation')->makeHidden(['password','remember_token']);
        return $user;
    }

    /**
     * POST /api/users
     * body: { name, email, password, role: 'admin'|'kasir', store_location_id?: number|null }
     */
    public function store(Request $r)
    {
        $data = $r->validate([
            'name'              => ['required','string','max:150'],
            'email'             => ['required','email','max:150','unique:users,email'],
            'password'          => ['required','string','min:8'],
            'role'              => ['required', Rule::in(['admin','kasir'])],
            'store_location_id' => ['nullable','exists:store_locations,id'],
        ]);

        // password akan di-hash oleh mutator di model
        $user = User::create($data);

        $user->load('storeLocation')->makeHidden(['password','remember_token']);
        return response()->json($user, 201);
    }

    /**
     * PUT/PATCH /api/users/{user}
     * body: { name?, email?, store_location_id? }
     */
    public function update(Request $r, User $user)
    {
        $data = $r->validate([
            'name'              => ['sometimes','required','string','max:150'],
            'email'             => ['sometimes','required','email','max:150', Rule::unique('users','email')->ignore($user->id)],
            'store_location_id' => ['sometimes','nullable','exists:store_locations,id'],
        ]);

        $user->update($data);
        $user->load('storeLocation')->makeHidden(['password','remember_token']);
        return $user;
    }

    /**
     * DELETE /api/users/{user}
     * - Cegah self-delete
     * - Revoke semua token user target
     */
    public function destroy(Request $r, User $user)
    {
        if ($r->user()->id === $user->id) {
            return response()->json(['message' => 'You cannot delete your own account'], 422);
        }

        $user->tokens()->delete(); // revoke sessions
        $user->delete();
        return response()->json(['message' => 'deleted']);
    }

    /**
     * PATCH /api/users/{user}/role
     * body: { role: 'admin'|'kasir' }
     * - Cegah turunkan role admin terakhir
     */
    public function updateRole(Request $r, User $user)
    {
        $data = $r->validate([
            'role' => ['required', Rule::in(['admin','kasir'])],
        ]);

        // Jika mengubah role diri sendiri jadi bukan admin, pastikan masih ada admin lain
        if ($r->user()->id === $user->id && $data['role'] !== 'admin') {
            if (User::where('role','admin')->where('id','!=',$user->id)->count() === 0) {
                return response()->json(['message'=>'At least one admin is required'], 422);
            }
        }

        $user->update(['role' => $data['role']]);
        $user->makeHidden(['password','remember_token']);
        return $user;
    }

    /**
     * POST /api/users/{user}/reset-password
     * body: { password: string (min 8) }
     * - Mutator akan hash
     * - Revoke semua sesi
     */
    public function resetPassword(Request $r, User $user)
    {
        $data = $r->validate([
            'password' => ['required','string','min:8'],
        ]);

        $user->update(['password' => $data['password']]);
        $user->tokens()->delete(); // force logout semua sesi
        return response()->json(['message' => 'password reset']);
    }

    /**
     * GET /api/users/roles/options
     * - Helper untuk dropdown FE
     */
    public function roleOptions()
    {
        return [
            ['value'=>'admin','label'=>'Admin'],
            ['value'=>'kasir','label'=>'Kasir'],
        ];
    }
}
