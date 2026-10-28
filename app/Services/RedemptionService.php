<?php

namespace App\Services;

use App\Models\LoyaltyReward;
use App\Models\Member;
use App\Models\RewardReservation;

/**
 * Member-app redemption (ARCHITECTURE.md "Redeem a Reward").
 *
 * Delegates to RewardReservationService so the app, the cashier Member Store
 * and the POS-Mobile pickup flow share one implementation: points are taken
 * atomically here (row lock, balance check, ledger row, idempotency key) and
 * the physical reward is handed over in-store against the pickup code / QR.
 */
class RedemptionService
{
    public function __construct(private readonly RewardReservationService $reservations) {}

    /**
     * @return array{redemption: RewardReservation, pickup_code: string|null, created: bool, balance: int}
     */
    public function redeem(Member $member, LoyaltyReward $reward, ?int $storeLocationId, string $idempotencyKey): array
    {
        $storeId = $storeLocationId ?: (int) $member->store_location_id;

        [$reservation, $pickup, $created] = $this->reservations->create($member, $reward, $storeId, $idempotencyKey);

        return [
            'redemption' => $reservation->load(['reward.category', 'storeLocation:id,name']),
            'pickup_code' => $pickup,
            'created' => $created,
            'balance' => (int) $member->fresh()->points_balance,
        ];
    }
}
