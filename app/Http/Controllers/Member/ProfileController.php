<?php

namespace App\Http\Controllers\Member;

use App\Http\Controllers\Controller;
use App\Http\Resources\MemberProfileResource;
use App\Http\Resources\RewardResource;
use App\Models\LoyaltyReward;
use App\Models\LoyaltyTier;
use App\Models\Promotion;
use App\Models\RewardCategory;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function show(Request $request)
    {
        return new MemberProfileResource($request->user()->load('member'));
    }

    public function update(Request $request)
    {
        $data = $request->validate(['name' => ['sometimes', 'string', 'max:120'], 'email' => ['nullable', 'email', 'max:120'], 'birth_date' => ['nullable', 'date', 'before:today'], 'address' => ['nullable', 'string', 'max:255']]);
        $request->user()->member->update($data);

        return new MemberProfileResource($request->user()->load('member'));
    }

    public function bootstrap(Request $request)
    {
        $account = $request->user()->load('member');
        $tiers = LoyaltyTier::with('perks')->where('is_active', true)->orderBy('minimum_lifetime_points')->orderBy('sort_order')->get();
        $lifetime = (int) $account->member->points_earned_total;
        $tier = $tiers->filter(fn ($item) => $item->minimum_lifetime_points <= $lifetime)->last() ?: $tiers->first();
        $nextTier = $tiers->first(fn ($item) => $item->minimum_lifetime_points > $lifetime);
        $start = (int) ($tier?->minimum_lifetime_points ?? 0);
        $end = (int) ($nextTier?->minimum_lifetime_points ?? $start);
        $progress = $nextTier ? min(1, max(0, ($lifetime - $start) / max(1, $end - $start))) : 1.0;

        $member = (new MemberProfileResource($account))->resolve();
        $member['tier'] = $tier ? ['id' => $tier->id, 'name' => $tier->name, 'color' => $tier->color] : null;
        $member['tier_progress'] = ['percentage' => $progress, 'points_remaining' => $nextTier ? max(0, $end - $lifetime) : 0, 'next_tier' => $nextTier ? ['id' => $nextTier->id, 'name' => $nextTier->name, 'minimum_lifetime_points' => $end] : null];

        $rewards = LoyaltyReward::with(['category', 'minimumTier', 'product:id,name,sku,image_url,description'])->where('is_active', true)->orderBy('sort_order')->get();
        $activities = $account->member->pointTransactions()->latest()->limit(10)->get()->map(fn ($activity) => [
            'id' => (int) $activity->id,
            'title' => $activity->note ?: $activity->type,
            'description' => $activity->note,
            'points' => (int) $activity->points,
            'type' => $activity->type,
            'created_at' => $activity->created_at?->toIso8601String(),
        ]);

        return response()->json([
            'member' => $member,
            'tier' => $member['tier'],
            'tier_progress' => $member['tier_progress'],
            'next_tier' => $member['tier_progress']['next_tier'],
            'perks' => $tier?->perks ?? [],
            'promotions' => Promotion::current()->orderBy('sort_order')->get(),
            'reward_categories' => RewardCategory::where('is_active', true)->orderBy('sort_order')->get(),
            'categories' => RewardCategory::where('is_active', true)->orderBy('sort_order')->get(),
            'rewards' => RewardResource::collection($rewards)->resolve(),
            'activities' => $activities,
            'recent_activities' => $activities,
            'membership_date' => $account->member->created_at?->toDateString(),
            'access_label' => 'Aurum Member',
        ]);
    }

    public function activity(Request $request)
    {
        return $request->user()->member->pointTransactions()->with(['reward:id,name', 'sale:id,code'])->paginate(min(100, max(1, (int) $request->input('per_page', 20))));
    }

    public function perks(Request $request)
    {
        $lifetime = $request->user()->member->points_earned_total;
        $tier = LoyaltyTier::with('perks')->where('is_active', true)->where('minimum_lifetime_points', '<=', $lifetime)->orderByDesc('minimum_lifetime_points')->first();

        return response()->json($tier?->perks ?? []);
    }

    public function promotions()
    {
        return response()->json(Promotion::current()->orderBy('sort_order')->get());
    }
}
