<?php

namespace App\Http\Controllers\Member;

use App\Http\Controllers\Controller;
use App\Http\Resources\RewardResource;
use App\Models\LoyaltyReward;
use App\Models\RewardCategory;
use Illuminate\Http\Request;

class RewardController extends Controller
{
    public function index(Request $request)
    {
        $q = LoyaltyReward::with(['category', 'minimumTier', 'product:id,name,sku,image_url,description'])->where('is_active', true)->whereNotNull('product_id');
        if ($request->filled('category')) {
            $q->whereHas('category', fn ($x) => $x->where('slug', $request->category));
        } if ($request->filled('search')) {
            $q->where('name', 'like', '%'.$request->search.'%');
        }

return RewardResource::collection($q->orderBy('sort_order')->paginate(min(100, max(1, (int) $request->input('per_page', 20)))));
    }

    public function show(LoyaltyReward $reward)
    {
        abort_unless($reward->is_active, 404);

        return new RewardResource($reward->load(['category', 'minimumTier', 'product']));
    }

    public function categories()
    {
        return RewardCategory::where('is_active', true)->orderBy('sort_order')->get();
    }
}
