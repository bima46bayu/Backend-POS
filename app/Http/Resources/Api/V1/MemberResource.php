<?php

namespace App\Http\Resources\Api\V1;

use App\Services\TierService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * GET /me — profile, tier, balance and progress to the next tier.
 * Wraps a Member model.
 */
class MemberResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $standing = app(TierService::class)->resolve($this->resource);

        return [
            'id' => (int) $this->id,
            'member_number' => $this->code,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'member_since' => $this->created_at?->toIso8601String(),
            'balance' => (int) $this->points_balance,
            'lifetime_points' => (int) $this->points_earned_total,
            'tier' => $standing['tier'] ? new TierResource($standing['tier']) : null,
            'tier_progress' => [
                'percent' => $standing['percent'],
                'points_to_next' => $standing['points_to_next'],
                'next_tier' => $standing['next'] ? new TierResource($standing['next']) : null,
            ],
        ];
    }
}
