<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\MemberResource;
use App\Http\Resources\Api\V1\PerkResource;
use App\Http\Resources\Api\V1\TierResource;
use App\Services\MemberCardTokenService;
use App\Services\TierService;
use Illuminate\Http\Request;

class MeController extends Controller
{
    public function show(Request $request)
    {
        return new MemberResource($request->user()->member);
    }

    /**
     * Digital card. The QR payload is a short-lived encrypted token resolved
     * by the staff app; it never contains the raw member id.
     */
    public function card(Request $request, MemberCardTokenService $tokens, TierService $tiers)
    {
        $account = $request->user();
        $member = $account->member;
        $standing = $tiers->resolve($member);
        $qr = $tokens->issue($account);

        return response()->json([
            'data' => [
                'member_number' => $member->code,
                'name' => $member->name,
                'member_since' => $member->created_at?->toIso8601String(),
                'tier' => $standing['tier'] ? new TierResource($standing['tier']) : null,
                'qr' => [
                    'payload' => $qr['token'],
                    'expires_at' => $qr['expires_at'],
                ],
            ],
        ]);
    }

    public function perks(Request $request, TierService $tiers)
    {
        $standing = $tiers->resolve($request->user()->member);

        return PerkResource::collection($standing['tier']?->perks ?? collect());
    }
}
