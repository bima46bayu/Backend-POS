<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use App\Models\User;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $validated = $request->validate([
            'name'          => 'required|string|max:100',
            'email'         => 'required|email|unique:users',
            'password'      => 'required|min:6|confirmed',
            'role'          => 'in:admin,kasir',
            // store fields (opsional)
            'store_name'    => 'nullable|string|max:150',
            'store_address' => 'nullable|string|max:255',
            'store_phone'   => 'nullable|string|max:50',
        ]);

        $user = User::create([
            'name'          => $validated['name'],
            'email'         => $validated['email'],
            'password'      => Hash::make($validated['password']),
            'role'          => $validated['role'] ?? 'kasir',
            'store_name'    => $validated['store_name']    ?? null,
            'store_address' => $validated['store_address'] ?? null,
            'store_phone'   => $validated['store_phone']   ?? null,
        ]);

        // jangan kirim password ke FE
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

        if (! $user || ! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $token = $user->createToken('api-token')->plainTextToken;

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
     * Profil user aktif (dipakai oleh FE / ReceiptTicket untuk ambil store).
     * GET /api/me (auth)
     */
    public function me(Request $request)
    {
        $u = $request->user();
        $u->makeHidden(['password', 'remember_token']);

        // Kamu bisa kirim langsung user atau bungkus dengan shape khusus.
        // Di bawah ini aku kirim bentuk yang enak untuk FE:
        return response()->json([
            'data' => [
                'id'    => $u->id,
                'name'  => $u->name,
                'email' => $u->email,
                'role'  => $u->role,
                'store' => [
                    'name'    => $u->store_name,
                    'address' => $u->store_address,
                    'phone'   => $u->store_phone,
                ],
            ],
        ]);
    }

    /**
     * Update store milik user aktif.
     * PUT /api/me/store (auth)
     * body: { name?, address?, phone? }
     */
    public function updateStore(Request $request)
    {
        $u = $request->user();

        $data = $request->validate([
            'name'    => ['nullable', 'string', 'max:150'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone'   => ['nullable', 'string', 'max:50'],
        ]);

        $u->store_name    = $data['name']    ?? $u->store_name;
        $u->store_address = $data['address'] ?? $u->store_address;
        $u->store_phone   = $data['phone']   ?? $u->store_phone;
        $u->save();

        return response()->json([
            'message' => 'Store profile updated',
            'data' => [
                'store' => [
                    'name'    => $u->store_name,
                    'address' => $u->store_address,
                    'phone'   => $u->store_phone,
                ],
            ],
        ]);
    }
}
