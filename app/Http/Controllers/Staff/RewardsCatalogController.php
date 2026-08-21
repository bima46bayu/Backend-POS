<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\LoyaltyTier;
use App\Models\Promotion;
use App\Models\RewardCategory;
use Illuminate\Http\Request;

class RewardsCatalogController extends Controller
{
    public function tiers()
    {
        return LoyaltyTier::with('perks')->orderBy('sort_order')->get();
    }

    public function storeTier(Request $r)
    {
        $d = $r->validate(['name' => 'required|string|max:80|unique:loyalty_tiers,name', 'minimum_lifetime_points' => 'required|integer|min:0', 'color' => 'nullable|string|max:20', 'sort_order' => 'nullable|integer|min:0', 'is_active' => 'nullable|boolean', 'perks' => 'array', 'perks.*.title' => 'required|string|max:120', 'perks.*.description' => 'nullable|string|max:255']);
        $perks = $d['perks'] ?? [];
        unset($d['perks']);
        $tier = LoyaltyTier::create($d);
        foreach ($perks as $i => $perk) {
            $tier->perks()->create($perk + ['sort_order' => $i]);
        }

return response()->json($tier->load('perks'), 201);
    }

    public function categories()
    {
        return RewardCategory::orderBy('sort_order')->get();
    }

    public function storeCategory(Request $r)
    {
        $d = $r->validate(['name' => 'required|string|max:100|unique:reward_categories,name', 'slug' => 'required|string|max:120|unique:reward_categories,slug', 'sort_order' => 'nullable|integer|min:0', 'is_active' => 'nullable|boolean']);

        return response()->json(RewardCategory::create($d), 201);
    }

    public function promotions()
    {
        return Promotion::orderBy('sort_order')->get();
    }

    public function storePromotion(Request $r)
    {
        $d = $r->validate(['title' => 'required|string|max:140', 'description' => 'nullable|string|max:500', 'image_url' => 'nullable|url|max:500', 'starts_at' => 'nullable|date', 'ends_at' => 'nullable|date|after:starts_at', 'sort_order' => 'nullable|integer|min:0', 'is_active' => 'nullable|boolean']);

        return response()->json(Promotion::create($d), 201);
    }
}
