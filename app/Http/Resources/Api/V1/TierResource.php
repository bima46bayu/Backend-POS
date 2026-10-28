<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TierResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'name' => $this->name,
            'min_points' => (int) $this->minimum_lifetime_points,
            'multiplier' => (float) ($this->multiplier ?? 1),
        ];
    }
}
