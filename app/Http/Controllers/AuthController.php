<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use App\Models\User;
use App\Services\ActivityLogger;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $validated = $request->validate([
            'name'              => 'required|string|max:100',
            'email'             => 'required|email|unique:users,email',
            'password'          => 'required|min:6|confirmed',
            'role'              => ['nullable', Rule::in(User::ROLES)],
            'store_location_id' => 'nullable|exists:store_locations,id',
        ]);

        $user = User::create([
            'name'              => $validated['name'],
            'email'             => $validated['email'],
            'password'          => Hash::make($validated['password']),
            'role'              => $validated['role'] ?? User::ROLE_KASIR,
            'store_location_id' => $validated['store_location_id'] ?? null,
        ]);

        return response()->json(['user' => $this->formatUser($user)], 201);
    }

    public function login(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $user->tokens()->delete();

        $token = $user->createToken('pos-token', ['staff'])->plainTextToken;

        ActivityLogger::forActor($request, $user, 'POST', '/login', 200);

        return response()->json([
            'user'  => $this->formatUser($user),
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
        return response()->json($this->formatUser($request->user()));
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
        $storeId = $data['store_location_id'] ?? null;

        if ($storeId !== null) {
            $this->authorizeStoreAccess($u, (int) $storeId);
        } elseif (! $u->isAdmin()) {
            abort(422, 'Store location is required');
        }

        $u->store_location_id = $storeId;
        $u->save();

        return response()->json([
            'message' => 'Store location updated',
            'user'    => $this->formatUser($u),
        ]);
    }

    private function formatUser(User $user): array
    {
        $user->load('storeLocation');
        $user->makeHidden(['password', 'remember_token']);

        $payload = $user->toArray();
        $allowed = $user->allowedStoreIds();
        $payload['allowed_store_ids'] = $allowed;
        $payload['can_switch_store'] = $user->canSwitchStore();

        return $payload;
    }
}

