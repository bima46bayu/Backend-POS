<?php

namespace App\Http\Controllers\Member;

use App\Http\Controllers\Controller;
use App\Services\MemberCardTokenService;
use Illuminate\Http\Request;

class CardController extends Controller
{
    public function show(Request $request)
    {
        $account = $request->user()->load('member');

        return response()->json([
            'member' => [
                'id' => (int) $account->member->id,
                'code' => $account->member->code,
                'name' => $account->member->name,
                'points' => (int) $account->member->points_balance,
                'access_label' => 'Aurum Member',
            ],
        ]);
    }

    public function qr(Request $request, MemberCardTokenService $tokens)
    {
        return response()->json($tokens->issue($request->user()));
    }
}
