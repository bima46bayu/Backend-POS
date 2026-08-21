<?php

namespace App\Services;

use App\Models\LoyaltyReward;
use App\Models\Member;
use App\Models\MemberPointTransaction;
use App\Models\Product;
use App\Models\RewardReservation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class RewardReservationService
{
    public function create(Member $member, LoyaltyReward $reward, int $storeId, string $key): array
    {
        return DB::transaction(function () use ($member, $reward, $storeId, $key) {
            $existing = RewardReservation::where('member_id', $member->id)->where('idempotency_key', $key)->first();
            if ($existing) {
                return [$existing, null, false];
            }

            $m = Member::lockForUpdate()->findOrFail($member->id);
            $r = LoyaltyReward::lockForUpdate()->findOrFail($reward->id);

            $this->assertEligible($m, $r);

            $m->decrement('points_balance', $r->points_cost);
            $m->increment('points_spent_total', $r->points_cost);
            $tx = MemberPointTransaction::create(['member_id' => $m->id, 'type' => 'RESERVE', 'points' => -$r->points_cost, 'balance_after' => $m->fresh()->points_balance, 'loyalty_reward_id' => $r->id, 'store_location_id' => $storeId, 'note' => 'Reservasi reward: '.$r->name]);
            $pickup = (string) random_int(100000, 999999);
            $reservation = RewardReservation::create(['public_id' => (string) Str::uuid(), 'member_id' => $m->id, 'loyalty_reward_id' => $r->id, 'store_location_id' => $storeId, 'status' => RewardReservation::PENDING, 'points_cost' => $r->points_cost, 'idempotency_key' => $key, 'pickup_code_hash' => Hash::make($pickup), 'expires_at' => now()->addMinutes($r->reservation_ttl_minutes ?: 60), 'point_transaction_id' => $tx->id]);

            return [$reservation, $pickup, true];
        });
    }

    /**
     * Over-the-counter redemption: the member is already at the till, so the
     * reservation is created and handed over in one step.
     *
     * This exists so the cashier-side Member Store goes through the exact same
     * eligibility rules (tier minimum, daily limit, balance) and the same
     * stock/sale side effects as the member app. Previously it had its own
     * implementation that skipped the tier and daily-limit checks entirely.
     */
    public function redeemOverCounter(Member $member, LoyaltyReward $reward, int $storeId, int $staffId): RewardReservation
    {
        return DB::transaction(function () use ($member, $reward, $storeId, $staffId) {
            [$reservation] = $this->create(
                $member,
                $reward,
                $storeId,
                'counter-'.Str::uuid()->toString()
            );

            return $this->fulfill($reservation, $staffId);
        });
    }

    /**
     * Shared gate for both redemption paths. Assumes $member and $reward are
     * already locked by the caller.
     */
    private function assertEligible(Member $member, LoyaltyReward $reward): void
    {
        if (! $member->is_active) {
            abort(422, 'Member sudah tidak aktif.');
        }

        if (! $reward->is_active) {
            abort(422, 'Reward is not active.');
        }

        if (! $reward->product_id) {
            abort(422, 'Hadiah ini belum terhubung ke produk. Pilih produk di Point Rewards.');
        }

        if ((int) $reward->points_cost < 1) {
            abort(422, 'Hadiah tidak valid.');
        }

        if ($reward->minimum_tier_id) {
            $minimum = $reward->minimumTier()->value('minimum_lifetime_points');
            if ($member->points_earned_total < $minimum) {
                abort(422, 'Member tier is not eligible for this reward.');
            }
        }

        if ($reward->daily_limit_per_member && RewardReservation::where('member_id', $member->id)->where('loyalty_reward_id', $reward->id)->whereDate('created_at', today())->whereNotIn('status', [RewardReservation::CANCELLED, RewardReservation::REJECTED, RewardReservation::EXPIRED])->count() >= $reward->daily_limit_per_member) {
            abort(422, 'Daily reservation limit reached.');
        }

        if ($member->points_balance < $reward->points_cost) {
            abort(422, "Poin tidak cukup (punya {$member->points_balance}, butuh {$reward->points_cost}).");
        }
    }

    public function cancel(RewardReservation $reservation, ?int $staffId = null, string $status = RewardReservation::CANCELLED, ?string $reason = null): RewardReservation
    {
        return DB::transaction(function () use ($reservation, $staffId, $status, $reason) {
            $r = RewardReservation::lockForUpdate()->findOrFail($reservation->id);
            if ($r->status !== RewardReservation::PENDING) {
                return $r;
            } $m = Member::lockForUpdate()->findOrFail($r->member_id);
            $m->increment('points_balance', $r->points_cost);
            $m->decrement('points_spent_total', min($m->points_spent_total, $r->points_cost));
            MemberPointTransaction::create(['member_id' => $m->id, 'type' => 'RESERVE_VOID', 'points' => $r->points_cost, 'balance_after' => $m->fresh()->points_balance, 'loyalty_reward_id' => $r->loyalty_reward_id, 'store_location_id' => $r->store_location_id, 'user_id' => $staffId, 'note' => 'Pembatalan reservasi '.$r->public_id]);
            $r->update(['status' => $status, 'resolved_at' => now(), 'resolved_by_user_id' => $staffId, 'rejection_reason' => $reason]);

            return $r->fresh();
        });
    }

    /**
     * Hand the reward over to the member.
     *
     * Points were already taken at reserve time, so this step is what makes the
     * goods actually leave inventory: it issues the same Rp 0 sale the
     * cashier-side Member Store creates, which consumes FIFO layers, writes the
     * stock ledger and puts the redemption into sales reporting.
     *
     * If stock ran out between reserving and pickup, LoyaltyService aborts 422
     * and the transaction rolls back, leaving the reservation pending so staff
     * can retry or reject it (which refunds the points).
     */
    public function fulfill(RewardReservation $reservation, int $staffId): RewardReservation
    {
        return DB::transaction(function () use ($reservation, $staffId) {
            $r = RewardReservation::lockForUpdate()->findOrFail($reservation->id);

            if ($r->status !== RewardReservation::PENDING) {
                throw ValidationException::withMessages(['reservation' => ['Reservation is not pending.']]);
            }

            if ($r->expires_at->isPast()) {
                return $this->cancel($r, $staffId, RewardReservation::EXPIRED);
            }

            $member = Member::lockForUpdate()->findOrFail($r->member_id);
            $reward = LoyaltyReward::lockForUpdate()->findOrFail($r->loyalty_reward_id);

            if (! $reward->product_id) {
                throw ValidationException::withMessages([
                    'reservation' => ['Reward ini belum terhubung ke produk.'],
                ]);
            }

            $product = Product::lockForUpdate()->findOrFail((int) $reward->product_id);

            try {
                $sale = LoyaltyService::issueRedeemSale(
                    $member,
                    $reward,
                    $product,
                    (int) $r->store_location_id,
                    $staffId
                );
            } catch (RuntimeException $e) {
                // FIFO consumption failures are a stock problem, not a bug:
                // surface them as 422 instead of a 500.
                abort(422, $e->getMessage());
            }

            // Points were already deducted by create(); link the reserve
            // transaction to the sale so voiding the sale can find it.
            if ($r->point_transaction_id) {
                MemberPointTransaction::whereKey($r->point_transaction_id)
                    ->update(['sale_id' => $sale->id]);
            }

            $r->update([
                'status' => RewardReservation::FULFILLED,
                'resolved_at' => now(),
                'resolved_by_user_id' => $staffId,
                'sale_id' => $sale->id,
            ]);

            return $r->fresh();
        });
    }

    public function expireDue(): int
    {
        $count = 0;
        RewardReservation::where('status', RewardReservation::PENDING)->where('expires_at', '<=', now())->chunkById(100, function ($rows) use (&$count) {
            foreach ($rows as $row) {
                $this->cancel($row, null, RewardReservation::EXPIRED);
                $count++;
            }
        });

        return $count;
    }

    public function verifyPickup(RewardReservation $r,string $code): bool
    {
        return Hash::check($code,$r->pickup_code_hash);
    }
}
