<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RegisterSessionCart extends Model
{
    protected $fillable = [
        'register_session_id',
        'items',
        'checkout',
    ];

    protected $casts = [
        'items'    => 'array',
        'checkout' => 'array',
    ];

    public function registerSession(): BelongsTo
    {
        return $this->belongsTo(RegisterSession::class);
    }

    /** Shape expected by the POS frontend (`cart_data`). */
    public function toPayload(): array
    {
        return [
            'items'      => $this->items ?? [],
            'checkout'   => $this->checkout,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
