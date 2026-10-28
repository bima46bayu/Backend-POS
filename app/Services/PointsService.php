<?php

namespace App\Services;

use App\Models\Member;
use App\Models\MemberPointTransaction;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read side of the point ledger for the member app.
 *
 * The ledger (`member_point_transactions`) is append-only and written only by
 * LoyaltyService / RewardReservationService. This class never writes to it.
 */
class PointsService
{
    public const FILTER_EARN = 'earn';

    public const FILTER_REDEEM = 'redeem';

    /**
     * Member-facing kind for a ledger row. Signs decide the earn/redeem
     * filter; `adjust` covers admin corrections, voids and refunds.
     */
    public static function kind(MemberPointTransaction $tx): string
    {
        return match ($tx->type) {
            MemberPointTransaction::TYPE_EARN => 'earn',
            MemberPointTransaction::TYPE_REDEEM,
            MemberPointTransaction::TYPE_RESERVE => 'redeem',
            default => 'adjust',
        };
    }

    public function ledger(Member $member, ?string $type, ?string $from, ?string $to, int $perPage): LengthAwarePaginator
    {
        return $this->query($member, $type, $from, $to)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /** Points earned (positive rows) between two instants. */
    public function earnedBetween(Member $member, \DateTimeInterface $from, \DateTimeInterface $to): int
    {
        return (int) MemberPointTransaction::query()
            ->where('member_id', $member->id)
            ->where('points', '>', 0)
            ->whereBetween('created_at', [$from, $to])
            ->sum('points');
    }

    private function query(Member $member, ?string $type, ?string $from, ?string $to): Builder
    {
        $q = MemberPointTransaction::query()
            ->with(['reward:id,name', 'sale:id,code', 'storeLocation:id,name'])
            ->where('member_id', $member->id);

        if ($type === self::FILTER_EARN) {
            $q->where('points', '>', 0);
        } elseif ($type === self::FILTER_REDEEM) {
            $q->where('points', '<', 0);
        }

        if ($from) {
            $q->where('created_at', '>=', $from.' 00:00:00');
        }
        if ($to) {
            $q->where('created_at', '<=', $to.' 23:59:59');
        }

        return $q;
    }
}
