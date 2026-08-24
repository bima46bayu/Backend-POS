<?php

namespace Database\Seeders;

use App\Models\LoyaltyReward;
use App\Models\LoyaltyTier;
use App\Models\Member;
use App\Models\Promotion;
use App\Models\RewardCategory;
use App\Models\StoreLocation;
use Illuminate\Database\Seeder;

class MemberRewardsDemoSeeder extends Seeder
{
    public function run(): void
    {
        $store = StoreLocation::firstOrCreate(['code' => 'MAIN'], ['name' => 'Main Store', 'address' => 'Demo address', 'phone' => '021000000']);
        $bronze = LoyaltyTier::updateOrCreate(['name' => 'Bronze'], ['minimum_lifetime_points' => 0, 'color' => '#CD7F32', 'sort_order' => 10, 'is_active' => true]);
        $silver = LoyaltyTier::updateOrCreate(['name' => 'Silver'], ['minimum_lifetime_points' => 500, 'color' => '#C0C0C0', 'sort_order' => 20, 'is_active' => true]);
        $gold = LoyaltyTier::updateOrCreate(['name' => 'Gold'], ['minimum_lifetime_points' => 2000, 'color' => '#FFD700', 'sort_order' => 30, 'is_active' => true]);
        foreach ([[$bronze, 'Member pricing'], [$silver, 'Priority promotions'], [$gold, 'Premium reward access']] as [$tier,$title]) {
            $tier->perks()->updateOrCreate(['title' => $title], ['description' => 'Deterministic demo perk', 'sort_order' => 10]);
        }
        $category = RewardCategory::updateOrCreate(['slug' => 'drinks'], ['name' => 'Drinks', 'sort_order' => 10, 'is_active' => true]);
        Promotion::updateOrCreate(['title' => 'Welcome Member'], ['description' => 'Earn and reserve rewards from the member app.', 'sort_order' => 10, 'is_active' => true]);
        $member = Member::updateOrCreate(['phone' => '+628111111111'], ['store_location_id' => $store->id, 'code' => 'MBR-DEMO', 'name' => 'Demo Member', 'points_balance' => 3000, 'points_earned_total' => 3000, 'is_active' => true]);
        LoyaltyReward::whereNull('reward_category_id')->update(['reward_category_id' => $category->id]);
    }
}
