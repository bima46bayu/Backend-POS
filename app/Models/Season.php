<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A named stats period ("Year of the Alchemist"). Seasons never reset
 * points; they only scope the "earned this season" summary.
 */
class Season extends Model
{
    protected $fillable = ['name', 'starts_at', 'ends_at', 'is_active'];

    protected $casts = ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'is_active' => 'boolean'];

    public function scopeCurrent(Builder $q): Builder
    {
        return $q->where('is_active', true)
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>=', now())
            ->orderByDesc('starts_at');
    }
}
