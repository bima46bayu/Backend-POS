<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per point change. Append-only.
 */
class MemberPointTransaction extends Model
{
    public const TYPE_EARN = 'EARN';

    public const TYPE_REVOKE = 'REVOKE';

    public const TYPE_ADJUST = 'ADJUST';

    public const TYPE_REDEEM = 'REDEEM';

    public const TYPE_REDEEM_VOID = 'REDEEM_VOID';

    public const TYPE_RESERVE = 'RESERVE';

    public const TYPE_RESERVE_VOID = 'RESERVE_VOID';

    protected $fillable = [
        'member_id',
        'type',
        'points',
        'balance_after',
        'sale_id',
        'loyalty_reward_id',
        'amount',
        'rate_per_point',
        'store_location_id',
        'user_id',
        'note',
    ];

    protected $casts = [
        'points' => 'integer',
        'balance_after' => 'integer',
        'amount' => 'decimal:2',
        'rate_per_point' => 'integer',
    ];

    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    public function reward()
    {
        return $this->belongsTo(LoyaltyReward::class, 'loyalty_reward_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
