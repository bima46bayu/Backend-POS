<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoyaltyTierPerk extends Model
{
    protected $fillable = ['loyalty_tier_id', 'title', 'description', 'sort_order'];

    public function tier()
    {
        return $this->belongsTo(LoyaltyTier::class, 'loyalty_tier_id');
    }
}
