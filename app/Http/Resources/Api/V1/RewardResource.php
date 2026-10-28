<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RewardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'name' => $this->name,
            'description' => $this->description ?: $this->product?->description,
            'points_cost' => (int) $this->points_cost,
            'image_url' => $this->image_url ?: $this->product?->image_url,
            'is_active' => (bool) $this->is_active,
            'category' => $this->whenLoaded('category', fn () => $this->category ? [
                'id' => (int) $this->category->id,
                'name' => $this->category->name,
                'slug' => $this->category->slug,
            ] : null),
            'minimum_tier' => $this->whenLoaded('minimumTier', fn () => $this->minimumTier ? new TierResource($this->minimumTier) : null),
        ];
    }
}
