<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoyaltyTier extends Model
{
    protected $fillable = ['name', 'minimum_lifetime_points', 'color', 'sort_order', 'is_active'];

    protected $casts = ['minimum_lifetime_points' => 'integer', 'sort_order' => 'integer', 'is_active' => 'boolean'];

    public function perks()
    {
        return $this->hasMany(LoyaltyTierPerk::class)->orderBy('sort_order');
    }
}
