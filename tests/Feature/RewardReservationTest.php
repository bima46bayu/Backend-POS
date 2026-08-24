<?php

namespace Tests\Feature;

use App\Models\LoyaltyReward;
use App\Models\Member;
use App\Models\MemberAccount;
use App\Models\Product;
use App\Models\StoreLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RewardReservationTest extends TestCase
{
    use RefreshDatabase;

    public function test_reservation_is_idempotent_and_cancel_refunds_points(): void
    {
        $store = StoreLocation::create(['code' => 'MAIN', 'name' => 'Main']);
        $member = Member::create(['store_location_id' => $store->id, 'code' => 'MBR-1', 'name' => 'M', 'phone' => '+628111111111', 'points_balance' => 1000]);
        $member->forceFill(['points_balance' => 1000])->save();
        $account = MemberAccount::create(['member_id' => $member->id, 'phone' => $member->phone, 'password' => 'Member123', 'phone_verified_at' => now()]);
        $product = Product::create(['store_location_id' => $store->id, 'name' => 'Reward Product', 'sku' => 'R-1', 'price' => 10000]);
        $reward = LoyaltyReward::create(['store_location_id' => $store->id, 'product_id' => $product->id, 'name' => 'Reward', 'points_cost' => 200, 'is_active' => true]);
        $headers = ['Authorization' => 'Bearer '.$account->createToken('test', ['member'])->plainTextToken, 'Idempotency-Key' => 'reservation-key-1'];
        $first = $this->postJson('/api/v1/member/reservations', ['loyalty_reward_id' => $reward->id, 'store_location_id' => $store->id], $headers)->assertCreated();
        $this->postJson('/api/v1/member/reservations', ['loyalty_reward_id' => $reward->id, 'store_location_id' => $store->id], $headers)->assertOk()->assertJsonPath('id', $first->json('id'));
        $this->assertSame(800, $member->fresh()->points_balance);
        $this->postJson('/api/v1/member/reservations/'.$first->json('id').'/cancel', [], $headers)->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame(1000, $member->fresh()->points_balance);
    }
}
