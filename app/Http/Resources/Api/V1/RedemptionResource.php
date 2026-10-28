<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A redemption as the member sees it. Wraps a RewardReservation; the pickup
 * code is only present on the response that created it.
 */
class RedemptionResource extends JsonResource
{
    public function __construct($resource, private readonly ?string $pickupCode = null)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'status' => $this->status,
            'points_spent' => (int) $this->points_cost,
            'pickup_code' => $this->pickupCode,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'reward' => new RewardResource($this->whenLoaded('reward')),
            'store' => $this->whenLoaded('storeLocation', fn () => [
                'id' => (int) $this->storeLocation->id,
                'name' => $this->storeLocation->name,
            ]),
        ];
    }
}
