<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RedeemRewardRequest;
use App\Http\Requests\Api\V1\RewardIndexRequest;
use App\Http\Resources\Api\V1\RedemptionResource;
use App\Http\Resources\Api\V1\RewardResource;
use App\Models\LoyaltyReward;
use App\Models\RewardCategory;
use App\Services\RedemptionService;

class RewardController extends Controller
{
    public function index(RewardIndexRequest $request)
    {
        $q = LoyaltyReward::query()
            ->with(['category', 'minimumTier', 'product:id,name,image_url,description'])
            ->where('is_active', true)
            ->whereNotNull('product_id');

        if ($category = $request->validated('category')) {
            $q->whereHas('category', fn ($c) => $c->where('slug', $category));
        }
        if ($term = trim((string) $request->validated('q'))) {
            $q->where('name', 'like', '%'.$term.'%');
        }

        return RewardResource::collection(
            $q->orderBy('sort_order')->orderBy('name')->paginate($request->perPage())->withQueryString()
        );
    }

    public function categories()
    {
        return response()->json([
            'data' => RewardCategory::where('is_active', true)
                ->orderBy('sort_order')
                ->get(['id', 'name', 'slug']),
        ]);
    }

    public function redeem(RedeemRewardRequest $request, LoyaltyReward $reward, RedemptionService $redemptions)
    {
        abort_unless($reward->is_active, 404);

        $result = $redemptions->redeem(
            $request->user()->member,
            $reward,
            $request->validated('store_location_id'),
            $request->idempotencyKey(),
        );

        return response()->json([
            'data' => [
                'redemption' => new RedemptionResource($result['redemption'], $result['pickup_code']),
                'balance' => $result['balance'],
            ],
        ], $result['created'] ? 201 : 200);
    }
}
