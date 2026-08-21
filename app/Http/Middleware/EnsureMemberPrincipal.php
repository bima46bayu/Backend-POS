<?php

namespace App\Http\Middleware;

use App\Models\MemberAccount;
use Closure;
use Illuminate\Http\Request;

class EnsureMemberPrincipal
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if (! $user instanceof MemberAccount || ($user->currentAccessToken() && ! $user->tokenCan('member'))) {
            return response()->json(['message' => 'Member authentication required.'], 403);
        } if (! $user->is_active || ! $user->member?->is_active) {
            return response()->json(['message' => 'Member account is inactive.'], 403);
        }

        return $next($request);
    }
}
