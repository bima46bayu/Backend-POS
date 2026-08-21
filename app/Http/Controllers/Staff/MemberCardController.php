<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Services\MemberCardTokenService;
use Illuminate\Http\Request;

class MemberCardController extends Controller
{
    public function resolve(Request $request, MemberCardTokenService $tokens)
    {
        $data = $request->validate(['token' => ['required', 'string']]);
        $account = $tokens->resolve($data['token']);
        if (! $account || ! $account->is_active || ! $account->member?->is_active) {
            abort(422, 'Member card token is invalid or expired.');
        }

        return response()->json(['member' => [
            'id' => (int) $account->member->id,
            'code' => $account->member->code,
            'name' => $account->member->name,
        ]]);
    }
}
