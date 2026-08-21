<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoyaltyReward extends Model
{
    protected $fillable = [
        'store_location_id',
        'product_id',
        'reward_category_id',
        'minimum_tier_id',
        'name',
        'description',
        'image_url',
        'points_cost',
        'reservation_ttl_minutes',
        'daily_limit_per_member',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'points_cost' => 'integer',
        'reservation_ttl_minutes' => 'integer',
        'daily_limit_per_member' => 'integer',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function storeLocation()
    {
        return $this->belongsTo(StoreLocation::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function category()
    {
        return $this->belongsTo(RewardCategory::class, 'reward_category_id');
    }

    public function minimumTier()
    {
        return $this->belongsTo(LoyaltyTier::class, 'minimum_tier_id');
    }
}
