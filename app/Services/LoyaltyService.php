<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\Member;
use App\Models\MemberPointTransaction;
use App\Models\Sale;
use Illuminate\Support\Facades\DB;

/**
 * Loyalty points.
 *
 * Conversion is spend-based: every `rate_per_point` rupiah of a sale's final
 * total earns 1 point, floored (the remainder is discarded, not carried over).
 * With the default rate of 10.000: Rp 25.000 -> 2 points.
 *
 * Points are ALWAYS computed here from the persisted sale total. The client
 * never sends a point amount — same anti-tamper rule as option pricing.
 */
class LoyaltyService
{
    /** AppSetting key holding the rupiah-per-point rate. */
    public const KEY_RATE = 'loyalty.points_per_amount';

    /** AppSetting key for the on/off switch. */
    public const KEY_ENABLED = 'loyalty.enabled';

    public const DEFAULT_RATE = 10000;

    /**
     * Rupiah needed for 1 point. Guaranteed >= 1 so we can never divide by zero.
     */
    public static function rate(): int
    {
        $raw = AppSetting::getValue(self::KEY_RATE, self::DEFAULT_RATE);

        // Stored via a json cast, so it may come back as string/float.
        $rate = is_numeric($raw) ? (int) $raw : self::DEFAULT_RATE;

        return $rate > 0 ? $rate : self::DEFAULT_RATE;
    }

    public static function enabled(): bool
    {
        $raw = AppSetting::getValue(self::KEY_ENABLED, true);

        if (is_bool($raw)) {
            return $raw;
        }
        if (is_numeric($raw)) {
            return (int) $raw === 1;
        }
        if (is_string($raw)) {
            return ! in_array(strtolower($raw), ['0', 'false', 'no', 'off', ''], true);
        }

        return true;
    }

    /** Current settings, for the management UI. */
    public static function settings(): array
    {
        $rate = self::rate();

        return [
            'enabled'          => self::enabled(),
            'points_per_amount' => $rate,
            'rate_per_point'   => $rate,
            'default_rate'     => self::DEFAULT_RATE,
            'description'      => "Setiap belanja Rp " . number_format($rate, 0, ',', '.') . " mendapat 1 poin.",
        ];
    }

    public static function saveSettings(?int $rate, ?bool $enabled): array
    {
        if ($rate !== null) {
            AppSetting::setValue(self::KEY_RATE, max(1, $rate));
        }
        if ($enabled !== null) {
            AppSetting::setValue(self::KEY_ENABLED, $enabled);
        }

        return self::settings();
    }

    /**
     * How many points a given spend is worth. Floor, never negative.
     */
    public static function pointsFor(float $amount, ?int $rate = null): int
    {
        $rate = $rate ?: self::rate();
        if ($amount <= 0 || $rate <= 0) {
            return 0;
        }

        return (int) floor($amount / $rate);
    }

    /**
     * Award points for a completed sale.
     *
     * Idempotent: the ledger has a unique (sale_id, type) index, so a retried or
     * re-synced offline sale cannot grant points twice. Returns the points
     * granted (0 when disabled, no member, or already awarded).
     *
     * Must be called inside a transaction that also owns the sale row.
     */
    public static function awardForSale(Sale $sale, ?int $userId = null): int
    {
        if (! $sale->member_id || ! self::enabled()) {
            return 0;
        }

        // Voided sales never earn.
        if (strtolower((string) $sale->status) === 'void') {
            return 0;
        }

        $already = MemberPointTransaction::query()
            ->where('sale_id', $sale->id)
            ->where('type', MemberPointTransaction::TYPE_EARN)
            ->exists();
        if ($already) {
            return 0;
        }

        $member = Member::query()->lockForUpdate()->find($sale->member_id);
        if (! $member) {
            return 0;
        }

        $rate   = self::rate();
        $amount = (float) ($sale->final_total > 0 ? $sale->final_total : $sale->total);
        $points = self::pointsFor($amount, $rate);

        // Still record the visit/spend even when the basket was too small to
        // earn a point — otherwise visit history silently loses transactions.
        $member->total_spend         = (float) $member->total_spend + $amount;
        $member->visit_count         = (int) $member->visit_count + 1;
        $member->last_transaction_at = $sale->created_at ?? now();

        if ($points > 0) {
            $member->points_balance      = (int) $member->points_balance + $points;
            $member->points_earned_total = (int) $member->points_earned_total + $points;
        }

        $member->save();

        if ($points > 0) {
            MemberPointTransaction::create([
                'member_id'         => $member->id,
                'type'              => MemberPointTransaction::TYPE_EARN,
                'points'            => $points,
                'balance_after'     => (int) $member->points_balance,
                'sale_id'           => $sale->id,
                'amount'            => $amount,
                'rate_per_point'    => $rate,
                'store_location_id' => $sale->store_location_id,
                'user_id'           => $userId,
                'note'              => 'Poin dari transaksi ' . $sale->code,
            ]);
        }

        $sale->points_earned = $points;
        $sale->saveQuietly();

        return $points;
    }

    /**
     * Take back the points a sale granted, because it was voided.
     *
     * Reverses the exact amount recorded in the ledger rather than recomputing
     * from the current rate — otherwise changing the rate would corrupt
     * historical voids. Balance is floored at 0 so a member can never go
     * negative if they already spent the points.
     */
    public static function revokeForSale(Sale $sale, ?int $userId = null): int
    {
        if (! $sale->member_id) {
            return 0;
        }

        $earn = MemberPointTransaction::query()
            ->where('sale_id', $sale->id)
            ->where('type', MemberPointTransaction::TYPE_EARN)
            ->first();

        if (! $earn) {
            return 0;
        }

        // Already revoked?
        $revoked = MemberPointTransaction::query()
            ->where('sale_id', $sale->id)
            ->where('type', MemberPointTransaction::TYPE_REVOKE)
            ->exists();
        if ($revoked) {
            return 0;
        }

        $member = Member::query()->lockForUpdate()->find($sale->member_id);
        if (! $member) {
            return 0;
        }

        $take = (int) $earn->points;
        $newBalance = max(0, (int) $member->points_balance - $take);

        // What we could actually take back (member may have spent some).
        $actual = (int) $member->points_balance - $newBalance;

        $member->points_balance = $newBalance;
        $member->points_earned_total = max(0, (int) $member->points_earned_total - $take);
        $member->total_spend = max(0, (float) $member->total_spend - (float) $earn->amount);
        $member->visit_count = max(0, (int) $member->visit_count - 1);
        $member->save();

        MemberPointTransaction::create([
            'member_id'         => $member->id,
            'type'              => MemberPointTransaction::TYPE_REVOKE,
            'points'            => -$actual,
            'balance_after'     => $newBalance,
            'sale_id'           => $sale->id,
            'amount'            => $earn->amount,
            'rate_per_point'    => $earn->rate_per_point,
            'store_location_id' => $sale->store_location_id,
            'user_id'           => $userId,
            'note'              => 'Void transaksi ' . $sale->code,
        ]);

        $sale->points_earned = 0;
        $sale->saveQuietly();

        return $actual;
    }

    /**
     * Manual correction by an admin (positive or negative).
     */
    public static function adjust(
        Member $member,
        int $points,
        ?string $note = null,
        ?int $userId = null,
        ?int $storeLocationId = null
    ): MemberPointTransaction {
        return DB::transaction(function () use ($member, $points, $note, $userId, $storeLocationId) {
            $locked = Member::query()->lockForUpdate()->findOrFail($member->id);

            $newBalance = max(0, (int) $locked->points_balance + $points);
            $applied = $newBalance - (int) $locked->points_balance;

            $locked->points_balance = $newBalance;
            if ($applied > 0) {
                $locked->points_earned_total = (int) $locked->points_earned_total + $applied;
            } else {
                $locked->points_spent_total = (int) $locked->points_spent_total + abs($applied);
            }
            $locked->save();

            return MemberPointTransaction::create([
                'member_id'         => $locked->id,
                'type'              => MemberPointTransaction::TYPE_ADJUST,
                'points'            => $applied,
                'balance_after'     => $newBalance,
                'amount'            => 0,
                'rate_per_point'    => null,
                'store_location_id' => $storeLocationId,
                'user_id'           => $userId,
                'note'              => $note,
            ]);
        });
    }
}
