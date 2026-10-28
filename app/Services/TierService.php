<?php

namespace App\Services;

use App\Models\LoyaltyTier;
use App\Models\Member;
use Illuminate\Support\Collection;

/**
 * Tier and progress for the member app (ARCHITECTURE.md "Tier Calculation").
 *
 * Tier is determined by LIFETIME earned points (`points_earned_total`), so
 * redeeming never demotes a member. Progress % = points ÷ next threshold,
 * rounded DOWN (24,850 / 30,000 → 82%). The client only displays these
 * values; it never recomputes them.
 */
class TierService
{
    /** @var Collection<int, LoyaltyTier>|null */
    private ?Collection $tiers = null;

    /**
     * @return array{
     *   points:int, tier: LoyaltyTier|null, next: LoyaltyTier|null,
     *   percent:int, points_to_next:int
     * }
     */
    public function resolve(Member $member): array
    {
        $points = max(0, (int) $member->points_earned_total);
        $tiers = $this->tiers();

        $current = $tiers->filter(fn (LoyaltyTier $t) => (int) $t->minimum_lifetime_points <= $points)->last()
            ?: $tiers->first();
        $next = $tiers->first(fn (LoyaltyTier $t) => (int) $t->minimum_lifetime_points > $points);

        if (! $next) {
            return ['points' => $points, 'tier' => $current, 'next' => null, 'percent' => 100, 'points_to_next' => 0];
        }

        $threshold = max(1, (int) $next->minimum_lifetime_points);

        return [
            'points' => $points,
            'tier' => $current,
            'next' => $next,
            'percent' => min(100, max(0, intdiv($points * 100, $threshold))),
            'points_to_next' => max(0, $threshold - $points),
        ];
    }

    /** Active tiers ordered by threshold, loaded once per request. */
    public function tiers(): Collection
    {
        return $this->tiers ??= LoyaltyTier::with('perks')
            ->where('is_active', true)
            ->orderBy('minimum_lifetime_points')
            ->orderBy('sort_order')
            ->get();
    }
}
