<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class DailySessionCheck
{
    public function handle(Request $request, Closure $next)
{
    $user = $request->user();
    if (!$user) return $next($request);

    $token = $user->currentAccessToken();
    if (!$token) return $next($request);

    $tz = config('app.timezone');

    $now = now()->setTimezone($tz);
    $tokenCreated = $token->created_at->setTimezone($tz);

    $cutoffHour = 02;
    $cutoffMinute = 00;

    $cutoff = $now->copy()
        ->startOfDay()
        ->addHours($cutoffHour)
        ->addMinutes($cutoffMinute);

    if ($tokenCreated->greaterThan($cutoff)) {
        $cutoff->addDay();
    }

    if ($now->greaterThanOrEqualTo($cutoff)) {

        $token->delete();

        return response()->json([
            'message' => 'Session expired (daily cutoff)',
            'now' => $now->toDateTimeString(),
            'cutoff' => $cutoff->toDateTimeString(),
            'token_created' => $tokenCreated->toDateTimeString(),
        ], 401);
    }

    return $next($request);
}

}
