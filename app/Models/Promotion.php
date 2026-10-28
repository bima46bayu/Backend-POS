<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Promotion extends Model
{
    protected $fillable = ['title', 'badge', 'description', 'image_url', 'starts_at', 'ends_at', 'capacity', 'sort_order', 'is_active'];

    protected $casts = ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'capacity' => 'integer', 'sort_order' => 'integer', 'is_active' => 'boolean'];

    /** Hide promotions this member dismissed. */
    public function scopeNotDismissedBy(Builder $q, int $memberId): Builder
    {
        return $q->whereNotExists(function ($sub) use ($memberId) {
            $sub->selectRaw('1')
                ->from('promotion_dismissals')
                ->whereColumn('promotion_dismissals.promotion_id', 'promotions.id')
                ->where('promotion_dismissals.member_id', $memberId);
        });
    }

    public function scopeCurrent(Builder $q): Builder
    {
        return $q->where('is_active', true)->where(fn ($w) => $w->whereNull('starts_at')->orWhere('starts_at', '<=', now()))->where(fn ($w) => $w->whereNull('ends_at')->orWhere('ends_at', '>=', now()));
    }
}
