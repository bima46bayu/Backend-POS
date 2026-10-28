<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\SeasonService;
use Illuminate\Http\Request;

class SeasonController extends Controller
{
    public function currentSummary(Request $request, SeasonService $seasons)
    {
        $summary = $seasons->currentSummary($request->user()->member);
        $season = $summary['season'];

        return response()->json([
            'data' => [
                'season' => $season ? [
                    'id' => (int) $season->id,
                    'name' => $season->name,
                    'starts_at' => $season->starts_at->toIso8601String(),
                    'ends_at' => $season->ends_at->toIso8601String(),
                ] : null,
                'points_earned' => $summary['points_earned'],
            ],
        ]);
    }
}
