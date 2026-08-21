<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;

class EnsureStaffPrincipal
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if (! $user instanceof User || ($user->currentAccessToken() && ! $user->tokenCan('staff'))) {
            return response()->json(['message' => 'Staff authentication required.'], 403);
        }

        return $next($request);
    }
}
