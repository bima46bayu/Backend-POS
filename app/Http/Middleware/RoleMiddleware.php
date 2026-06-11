<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    public function handle(Request $request, Closure $next, string $rolesCsv)
    {
        $user = $request->user();
        if (! $user) {
            abort(401, 'Unauthenticated');
        }

        $allowed = array_values(array_filter(array_map(
            'trim',
            preg_split('/[|,]/', $rolesCsv) ?: []
        )));

        if ($allowed === [] || ! in_array($user->role, $allowed, true)) {
            abort(403, 'Forbidden');
        }

        return $next($request);
    }
}
