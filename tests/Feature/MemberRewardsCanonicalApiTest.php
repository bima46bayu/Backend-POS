<?php

namespace Tests\Feature;

use App\Contracts\OtpProvider;
use App\Models\LoyaltyReward;
use App\Models\LoyaltyTier;
use App\Models\Member;
use App\Models\MemberAccount;
use App\Models\Product;
use App\Models\RewardCategory;
use App\Models\RewardReservation;
use App\Models\StoreLocation;
use App\Models\User;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MemberRewardsCanonicalApiTest extends TestCase
{
    use RefreshDatabase;

    private StoreLocation $store;

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = StoreLocation::create(['code' => 'MAIN', 'name' => 'Main Store']);
        $this->member = Member::create(['store_location_id' => $this->store->id, 'code' => 'MBR-CANON', 'name' => 'Canonical Member', 'phone' => '+6281234567890', 'is_active' => true]);
        $this->member->forceFill(['points_balance' => 1000, 'points_earned_total' => 600])->save();
    }

    public function test_existing_member_registers_through_challenge_and_canonical_profile_works(): void
    {
        $provider = new class implements OtpProvider
        {
            public string $code = '';

            public function deliver(string $phone, string $code, string $purpose): void
            {
                $this->code = $code;
            }
        };
        $this->app->instance(OtpProvider::class, $provider);
        $challenge = $this->postJson('/api/v1/member/auth/otp', ['phone' => '081234567890'])->assertAccepted()->assertJsonStructure(['challenge_id', 'expires_at']);
        $registered = $this->postJson('/api/v1/member/auth/verify', ['challenge_id' => $challenge->json('challenge_id'), 'phone' => '081234567890', 'otp' => $provider->code, 'name' => 'Canonical Member', 'email' => 'member@example.com', 'password' => 'Member123', 'password_confirmation' => 'Member123'])->assertCreated()->assertJsonStructure(['token', 'member' => ['id', 'points', 'joined_at', 'access_label']]);
        $this->withToken($registered->json('token'))->getJson('/api/v1/member/profile')->assertOk()->assertJsonPath('data.email', 'member@example.com');
    }

    public function test_registration_rejects_phone_without_existing_member(): void
    {
        $this->postJson('/api/v1/member/auth/otp', ['phone' => '081399999999'])->assertUnprocessable();
    }

    public function test_bootstrap_matches_flutter_four_tab_shape_and_card_qr_is_opaque(): void
    {
        $account = MemberAccount::create(['member_id' => $this->member->id, 'phone' => $this->member->phone, 'password' => 'Member123', 'phone_verified_at' => now()]);
        $bronze = LoyaltyTier::create(['name' => 'Bronze', 'minimum_lifetime_points' => 0, 'is_active' => true]);
        $bronze->perks()->create(['title' => 'Member pricing']);
        LoyaltyTier::create(['name' => 'Silver', 'minimum_lifetime_points' => 1000, 'is_active' => true]);
        $token = $account->createToken('test', ['member'])->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/member/bootstrap')->assertOk()->assertJsonStructure(['member' => ['tier', 'tier_progress', 'membership_date', 'access_label'], 'tier', 'next_tier', 'perks', 'promotions', 'reward_categories', 'categories', 'rewards', 'activities', 'recent_activities']);
        $qr = $this->withToken($token)->postJson('/api/v1/member/card/qr')->assertOk()->assertJsonStructure(['value', 'token', 'expires_at']);
        $this->assertNotSame((string) $this->member->id, $qr->json('token'));
    }

    public function test_redemption_idempotency_cancel_and_pos_resolve_fulfill_contract(): void
    {
        $account = MemberAccount::create(['member_id' => $this->member->id, 'phone' => $this->member->phone, 'password' => 'Member123', 'phone_verified_at' => now()]);
        $product = Product::create(['store_location_id' => $this->store->id, 'name' => 'Reward Product', 'sku' => 'RW-1', 'price' => 10000]);
        $category = RewardCategory::create(['name' => 'Drinks', 'slug' => 'drinks']);
        $reward = LoyaltyReward::create(['store_location_id' => $this->store->id, 'product_id' => $product->id, 'reward_category_id' => $category->id, 'name' => 'Reward', 'points_cost' => 200, 'is_active' => true]);
        $memberToken = $account->createToken('test', ['member'])->plainTextToken;
        $headers = ['Idempotency-Key' => 'canonical-redemption-1'];
        $first = $this->withToken($memberToken)->postJson('/api/v1/member/reservations', ['loyalty_reward_id' => $reward->id, 'store_location_id' => $this->store->id], $headers)->assertCreated()->assertJsonStructure(['id', 'public_id', 'pickup_code', 'member', 'reward', 'store']);
        $this->withToken($memberToken)->postJson('/api/v1/member/reservations', ['loyalty_reward_id' => $reward->id, 'store_location_id' => $this->store->id], $headers)->assertOk()->assertJsonPath('public_id', $first->json('public_id'));
        $this->withToken($memberToken)->postJson('/api/v1/member/reservations/'.$first->json('public_id').'/cancel')->assertOk()->assertJsonPath('data.status', 'cancelled');

        $second = $this->withToken($memberToken)->postJson('/api/v1/member/reservations', ['loyalty_reward_id' => $reward->id, 'store_location_id' => $this->store->id], ['Idempotency-Key' => 'canonical-redemption-2'])->assertCreated();
        $staff = User::factory()->create(['role' => User::ROLE_KASIR, 'store_location_id' => $this->store->id]);
        $this->actingAs($staff, 'sanctum');
        $resolved = $this->getJson('/api/v1/staff/reward-redemptions/resolve?pickup_code='.$second->json('pickup_code'))->assertOk()->assertJsonStructure(['id', 'public_id', 'pickup_code', 'member', 'reward', 'store']);

        // The reward product is stock-tracked, so pickup can only succeed if
        // there is inventory to hand over.
        app(InventoryService::class)->addInboundLayer([
            'product_id' => $product->id,
            'store_location_id' => $this->store->id,
            'qty' => 5,
            'unit_buy' => 4000,
            'source_type' => 'GR',
        ]);

        $this->postJson('/api/v1/staff/reward-redemptions/'.$resolved->json('public_id').'/fulfill', ['store_location_id' => $this->store->id])->assertOk()->assertJsonPath('data.status', 'fulfilled');

        // Fulfillment must issue the Rp 0 sale and consume stock, otherwise the
        // reward leaves the shelf without inventory or reporting noticing.
        $reservation = RewardReservation::where('public_id', $resolved->json('public_id'))->firstOrFail();
        $this->assertNotNull($reservation->sale_id, 'Fulfillment should issue a backing sale.');
        $this->assertSame(0.0, (float) $reservation->sale->final_total);
        $this->assertSame($this->member->id, (int) $reservation->sale->member_id);
        $this->assertSame(4.0, (float) InventoryService::sumQtyRemaining($product->id, $this->store->id));
        $this->assertDatabaseHas('sale_payments', ['sale_id' => $reservation->sale_id, 'method' => 'POINTS']);
    }

    /**
     * The cashier-side Member Store used to have its own redemption logic that
     * skipped tier eligibility and the daily limit. Both paths now share
     * RewardReservationService, so the counter enforces the same rules.
     */
    public function test_counter_redemption_enforces_tier_and_consumes_stock(): void
    {
        $product = Product::create(['store_location_id' => $this->store->id, 'name' => 'Tier Reward', 'sku' => 'RW-3', 'price' => 10000]);
        $goldOnly = LoyaltyTier::create(['name' => 'Gold', 'minimum_lifetime_points' => 5000, 'is_active' => true]);
        $reward = LoyaltyReward::create(['store_location_id' => $this->store->id, 'product_id' => $product->id, 'minimum_tier_id' => $goldOnly->id, 'name' => 'Gold Reward', 'points_cost' => 200, 'is_active' => true]);

        app(InventoryService::class)->addInboundLayer([
            'product_id' => $product->id,
            'store_location_id' => $this->store->id,
            'qty' => 3,
            'unit_buy' => 4000,
            'source_type' => 'GR',
        ]);

        $staff = User::factory()->create(['role' => User::ROLE_KASIR, 'store_location_id' => $this->store->id]);
        $this->actingAs($staff, 'sanctum');

        // Member has 600 lifetime points, well short of Gold's 5000.
        $this->postJson("/api/loyalty-rewards/{$reward->id}/redeem", ['member_id' => $this->member->id, 'store_location_id' => $this->store->id])->assertStatus(422);
        $this->assertSame(1000, (int) $this->member->fresh()->points_balance);

        // Once eligible, the counter redemption goes through and moves stock.
        $this->member->forceFill(['points_earned_total' => 6000])->save();
        $this->postJson("/api/loyalty-rewards/{$reward->id}/redeem", ['member_id' => $this->member->id, 'store_location_id' => $this->store->id])->assertOk();

        $this->assertSame(800, (int) $this->member->fresh()->points_balance);
        $this->assertSame(2.0, (float) InventoryService::sumQtyRemaining($product->id, $this->store->id));
        $this->assertDatabaseHas('reward_reservations', ['loyalty_reward_id' => $reward->id, 'status' => RewardReservation::FULFILLED]);
    }

    public function test_fulfillment_is_rejected_when_reward_stock_ran_out(): void
    {
        $account = MemberAccount::create(['member_id' => $this->member->id, 'phone' => $this->member->phone, 'password' => 'Member123', 'phone_verified_at' => now()]);
        $product = Product::create(['store_location_id' => $this->store->id, 'name' => 'Reward Product', 'sku' => 'RW-2', 'price' => 10000]);
        $reward = LoyaltyReward::create(['store_location_id' => $this->store->id, 'product_id' => $product->id, 'name' => 'Reward', 'points_cost' => 200, 'is_active' => true]);
        $memberToken = $account->createToken('test', ['member'])->plainTextToken;

        $created = $this->withToken($memberToken)->postJson('/api/v1/member/reservations', ['loyalty_reward_id' => $reward->id, 'store_location_id' => $this->store->id], ['Idempotency-Key' => 'out-of-stock'])->assertCreated();

        $staff = User::factory()->create(['role' => User::ROLE_KASIR, 'store_location_id' => $this->store->id]);
        $this->actingAs($staff, 'sanctum');

        // No inventory was ever received for this product.
        $this->postJson('/api/v1/staff/reward-redemptions/'.$created->json('public_id').'/fulfill', ['store_location_id' => $this->store->id])->assertStatus(422);

        // Roll back cleanly: still pending, no sale, points still held.
        $reservation = RewardReservation::where('public_id', $created->json('public_id'))->firstOrFail();
        $this->assertSame(RewardReservation::PENDING, $reservation->status);
        $this->assertNull($reservation->sale_id);
        $this->assertSame(800, (int) $this->member->fresh()->points_balance);
    }
}
