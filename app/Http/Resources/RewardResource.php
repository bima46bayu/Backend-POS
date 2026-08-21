<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RewardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'image_url' => $this->image_url ?: $this->product?->image_url,
            'points' => (int) $this->points_cost,
            'points_cost' => (int) $this->points_cost,
            'available' => (bool) $this->is_active,
            'is_active' => (bool) $this->is_active,
            'reservation_ttl_minutes' => (int) $this->reservation_ttl_minutes,
            'daily_limit_per_member' => $this->daily_limit_per_member,
            'category' => $this->whenLoaded('category'),
            'minimum_tier' => $this->whenLoaded('minimumTier'),
            'product' => $this->whenLoaded('product'),
        ];
    }
}
