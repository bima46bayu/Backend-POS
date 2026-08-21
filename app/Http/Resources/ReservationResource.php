<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReservationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'public_id' => $this->public_id,
            'redemption_id' => $this->public_id,
            'status' => $this->status,
            'points_cost' => (int) $this->points_cost,
            'store_location_id' => (int) $this->store_location_id,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'rejection_reason' => $this->rejection_reason,
            // Set once fulfilled: the Rp 0 sale that took the reward out of
            // stock, so staff can reconcile a pickup against a receipt.
            'sale_id' => $this->sale_id ? (int) $this->sale_id : null,
            'sale_code' => $this->whenLoaded('sale', fn () => $this->sale?->code),
            'member' => $this->whenLoaded('member', fn () => [
                'id' => (int) $this->member->id,
                'code' => $this->member->code,
                'name' => $this->member->name,
                'points_balance' => (int) $this->member->points_balance,
            ]),
            'reward' => new RewardResource($this->whenLoaded('reward')),
            'store' => $this->whenLoaded('storeLocation'),
        ];
    }
}
