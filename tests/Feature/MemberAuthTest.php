<?php

namespace Tests\Feature;

use App\Contracts\OtpProvider;
use App\Models\StoreLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MemberAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_can_verify_phone_and_login(): void
    {
        $sent = [];
        $this->app->instance(OtpProvider::class, new class($sent) implements OtpProvider
        {
            public static array $sent = [];

            public function __construct(&$x) {}

            public function deliver(string $phone, string $code, string $purpose): void
            {
                self::$sent = compact('phone', 'code', 'purpose');
            }
        });
        $store = StoreLocation::create(['code' => 'MAIN', 'name' => 'Main']);
        \App\Models\Member::create(['store_location_id' => $store->id, 'code' => 'MBR-TEST', 'name' => 'Member Test', 'phone' => '+6281234567890']);
        $otp = $this->postJson('/api/v1/member/auth/otp', ['phone' => '081234567890', 'purpose' => 'register'])->assertAccepted();
        $provider = $this->app->make(OtpProvider::class);
        $verify = $this->postJson('/api/v1/member/auth/verify', ['challenge_id' => $otp->json('challenge_id'), 'phone' => '081234567890', 'otp' => $provider::$sent['code'], 'name' => 'Member Test', 'password' => 'Member123', 'password_confirmation' => 'Member123'])->assertCreated()->assertJsonStructure(['token', 'member' => ['id', 'phone']]);
        $this->assertDatabaseHas('member_accounts', ['phone' => '+6281234567890']);
        $this->postJson('/api/v1/member/auth/login', ['phone' => '081234567890', 'password' => 'Member123'])->assertOk()->assertJsonStructure(['token']);
    }

    public function test_public_staff_registration_is_not_available(): void
    {
        $this->postJson('/api/register', [])->assertStatus(405);
    }
}
