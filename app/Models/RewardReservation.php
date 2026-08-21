<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RewardReservation extends Model
{
    const PENDING = 'pending';

    const FULFILLED = 'fulfilled';

    const CANCELLED = 'cancelled';

    const REJECTED = 'rejected';

    const EXPIRED = 'expired';

    protected $fillable = ['public_id', 'member_id', 'loyalty_reward_id', 'store_location_id', 'status', 'points_cost', 'idempotency_key', 'pickup_code_hash', 'expires_at', 'resolved_at', 'resolved_by_user_id', 'rejection_reason', 'point_transaction_id', 'sale_id'];

    protected $hidden = ['pickup_code_hash', 'idempotency_key'];

    protected $casts = ['points_cost' => 'integer', 'expires_at' => 'datetime', 'resolved_at' => 'datetime'];

    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    public function reward()
    {
        return $this->belongsTo(LoyaltyReward::class, 'loyalty_reward_id');
    }

    public function storeLocation()
    {
        return $this->belongsTo(StoreLocation::class);
    }

    public function pointTransaction()
    {
        return $this->belongsTo(MemberPointTransaction::class);
    }

    /** The Rp 0 sale issued when the reward was handed over. */
    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    public function resolveRouteBinding($value, $field = null)
    {
        return $this->where('public_id', $value)->orWhere('id', is_numeric($value) ? (int) $value : -1)->first();
    }
}
