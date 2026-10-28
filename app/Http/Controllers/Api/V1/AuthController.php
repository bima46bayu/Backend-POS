<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Requests\Api\V1\RegisterRequest;
use App\Http\Resources\Api\V1\MemberResource;
use App\Models\MemberAccount;
use App\Services\ActivityLogger;
use App\Services\MemberRegistrationService;
use App\Services\PhoneNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Member app auth. Sign-up is email + password; sign-in accepts either an
 * email or a phone number, so accounts created by the OTP flow
 * (/api/v1/member/auth/*) can use the same screen.
 */
class AuthController extends Controller
{
    private const TOKEN_NAME = 'member-app';

    public function register(RegisterRequest $request, MemberRegistrationService $registration)
    {
        $data = $request->validated();
        $account = $registration->register($data['name'], $data['email'], $data['password']);

        ActivityLogger::forActor($request, $account, 'POST', '/v1/auth/register', 201);

        return $this->tokenResponse($account, 201);
    }

    public function login(LoginRequest $request)
    {
        $password = (string) $request->validated('password');
        $account = $this->findAccount($request);

        // Phone accounts must have completed OTP; email accounts are not
        // verified yet (email verification is a later step).
        $verified = $account && ($request->isEmail() || $account->phone_verified_at !== null);

        if (! $account || ! $verified || ! Hash::check($password, $account->password)) {
            throw ValidationException::withMessages(['identifier' => ['Email, phone number or password is incorrect.']]);
        }

        if (! $account->is_active || ! $account->member?->is_active) {
            abort(403, 'This membership is inactive.');
        }

        $account->update(['last_login_at' => now()]);

        ActivityLogger::forActor($request, $account, 'POST', '/v1/auth/login', 200);

        return $this->tokenResponse($account);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Signed out.']);
    }

    private function findAccount(LoginRequest $request): ?MemberAccount
    {
        $query = MemberAccount::with('member');

        if ($request->isEmail()) {
            return $query->where('email', mb_strtolower($request->identifier()))->first();
        }

        try {
            $phone = PhoneNormalizer::normalize($request->identifier());
        } catch (HttpException) {
            // Not a valid phone either: fall through to the generic error so
            // the response does not reveal which identifiers exist.
            return null;
        }

        return $query->where('phone', $phone)->first();
    }

    private function tokenResponse(MemberAccount $account, int $status = 200): JsonResponse
    {
        $token = $account->createToken(self::TOKEN_NAME, ['member'])->plainTextToken;

        return response()->json([
            'token' => $token,
            'member' => new MemberResource($account->member),
        ], $status);
    }
}
