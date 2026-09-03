<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\StoreLocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActivityLogTest extends TestCase
{
    use RefreshDatabase;

    private StoreLocation $store;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = StoreLocation::create(['code' => 'MAIN', 'name' => 'Main']);
        $this->admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'store_location_id' => $this->store->id,
        ]);
    }

    public function test_mutating_staff_request_is_logged(): void
    {
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/products', [
            'name' => 'Kaos Log',
            'price' => 10000,
            'inventory_type' => 'non_stock',
            'store_location_id' => $this->store->id,
        ])->assertCreated();

        $this->assertDatabaseHas('activity_logs', [
            'actor_type' => 'staff',
            'actor_id' => $this->admin->id,
            'action' => 'product.create',
        ]);
    }

    public function test_hq_admin_can_list_activity_logs(): void
    {
        ActivityLog::create([
            'actor_type' => 'staff',
            'actor_id' => $this->admin->id,
            'actor_name' => $this->admin->name,
            'actor_role' => 'admin',
            'method' => 'POST',
            'path' => '/products',
            'action' => 'product.create',
            'description' => 'Membuat produk',
            'status_code' => 201,
            'created_at' => now(),
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/activity-logs')
            ->assertOk()
            ->assertJsonPath('data.0.action', 'product.create');
    }

    public function test_kasir_cannot_list_activity_logs(): void
    {
        $kasir = User::factory()->create([
            'role' => User::ROLE_KASIR,
            'store_location_id' => $this->store->id,
        ]);

        $this->actingAs($kasir, 'sanctum')
            ->getJson('/api/activity-logs')
            ->assertForbidden();
    }

    public function test_guest_cannot_list_activity_logs(): void
    {
        $this->getJson('/api/activity-logs')->assertUnauthorized();
    }

    public function test_staff_login_is_logged_against_the_user(): void
    {
        $this->postJson('/api/login', [
            'email' => $this->admin->email,
            'password' => 'password',
        ])->assertOk();

        $this->assertDatabaseHas('activity_logs', [
            'actor_type' => 'staff',
            'actor_id' => $this->admin->id,
            'action' => 'staff.login',
        ]);
    }
}
