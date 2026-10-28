<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\PromotionResource;
use App\Models\Promotion;
use App\Models\PromotionDismissal;
use Illuminate\Http\Request;

class PromotionController extends Controller
{
    public function index(Request $request)
    {
        $memberId = (int) $request->user()->member_id;

        return PromotionResource::collection(
            Promotion::current()->notDismissedBy($memberId)->orderBy('sort_order')->orderByDesc('id')->get()
        );
    }

    public function dismiss(Request $request, Promotion $promotion)
    {
        PromotionDismissal::query()->upsert(
            [[
                'member_id' => (int) $request->user()->member_id,
                'promotion_id' => $promotion->id,
                'dismissed_at' => now(),
            ]],
            ['member_id', 'promotion_id'],
            ['dismissed_at'],
        );

        return response()->noContent();
    }
}
