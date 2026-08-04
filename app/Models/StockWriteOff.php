<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockWriteOff extends Model
{
    public const REASON_WASTE = 'WASTE';
    public const REASON_SPOILED = 'SPOILED';
    public const REASON_EXPIRED = 'EXPIRED';
    public const REASON_DAMAGED = 'DAMAGED';
    public const REASON_OTHER = 'OTHER';

    public const REASONS = [
        self::REASON_WASTE,
        self::REASON_SPOILED,
        self::REASON_EXPIRED,
        self::REASON_DAMAGED,
        self::REASON_OTHER,
    ];

    protected $fillable = [
        'store_location_id',
        'product_id',
        'user_id',
        'register_session_id',
        'reason',
        'qty',
        'unit_cost',
        'total_cost',
        'note',
    ];

    protected $casts = [
        'qty' => 'integer',
        'unit_cost' => 'float',
        'total_cost' => 'float',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function storeLocation(): BelongsTo
    {
        return $this->belongsTo(StoreLocation::class, 'store_location_id');
    }

    public static function reasonLabels(): array
    {
        return [
            self::REASON_WASTE => 'Waste',
            self::REASON_SPOILED => 'Spoiled',
            self::REASON_EXPIRED => 'Expired',
            self::REASON_DAMAGED => 'Damaged',
            self::REASON_OTHER => 'Other',
        ];
    }
}
