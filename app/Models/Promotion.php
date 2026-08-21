<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Promotion extends Model
{
    protected $fillable = ['title', 'description', 'image_url', 'starts_at', 'ends_at', 'sort_order', 'is_active'];

    protected $casts = ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'sort_order' => 'integer', 'is_active' => 'boolean'];

    public function scopeCurrent(Builder $q): Builder
    {
        return $q->where('is_active', true)->where(fn ($w) => $w->whereNull('starts_at')->orWhere('starts_at', '<=', now()))->where(fn ($w) => $w->whereNull('ends_at')->orWhere('ends_at', '>=', now()));
    }
}
