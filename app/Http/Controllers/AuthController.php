<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use App\Models\User;
use App\Models\StoreLocation;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $validated = $request->validate([
            'name'              => 'required|string|max:100',
            'email'             => 'required|email|unique:users,email',
            'password'          => 'required|min:6|confirmed',
            'role'              => 'in:admin,kasir',
            'store_location_id' => 'nullable|exists:store_locations,id', // relasi baru
        ]);

        $user = User::create([
            'name'              => $validated['name'],
            'email'             => $validated['email'],
            'password'          => Hash::make($validated['password']),
            'role'              => $validated['role'] ?? 'kasir',
            'store_location_id' => $validated['store_location_id'] ?? null,
        ]);

        $user->load('storeLocation');                 // ← include relasi untuk FE
        $user->makeHidden(['password', 'remember_token']);

        return response()->json(['user' => $user], 201);
    }

    public function login(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required'
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $token = $user->createToken('api-token')->plainTextToken;

        $user->load('storeLocation');                 // ← include relasi
        $user->makeHidden(['password', 'remember_token']);

        return response()->json([
            'user'  => $user,
            'token' => $token,
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->tokens()->delete();
        return response()->json(['message' => 'Logged out']);
    }

    /**
     * Profil user aktif (dipakai FE/ReceiptTicket).
     * GET /api/me (auth:sanctum)
     */
    public function me(Request $request)
    {
        $u = $request->user()->load('storeLocation');

        // Kembalikan shape yang ramah FE (langsung kirim objek relasi)
        $u->makeHidden(['password', 'remember_token']);
        return response()->json($u);
    }

    /**
     * Ganti store location milik user aktif (pindah cabang).
     * PUT /api/me/store (auth:sanctum)
     * body: { store_location_id: number|null }
     */
    public function updateStore(Request $request)
    {
        $data = $request->validate([
            'store_location_id' => ['nullable', 'exists:store_locations,id'],
        ]);

        $u = $request->user();
        $u->store_location_id = $data['store_location_id'] ?? null;
        $u->save();

        $u->load('storeLocation');
        $u->makeHidden(['password', 'remember_token']);

        return response()->json([
            'message' => 'Store location updated',
            'user'    => $u,
        ]);
    }
}
