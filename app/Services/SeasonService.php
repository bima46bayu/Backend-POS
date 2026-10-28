<?php

namespace App\Services;

use App\Models\Member;
use App\Models\Season;

class SeasonService
{
    public function __construct(private readonly PointsService $points) {}

    /** @return array{season: Season|null, points_earned: int} */
    public function currentSummary(Member $member): array
    {
        $season = Season::current()->first();

        if (! $season) {
            return ['season' => null, 'points_earned' => 0];
        }

        return [
            'season' => $season,
            'points_earned' => $this->points->earnedBetween($member, $season->starts_at, $season->ends_at),
        ];
    }
}
