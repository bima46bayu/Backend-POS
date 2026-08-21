<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MemberProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $member = $this->member;

        return [
            'id' => (int) $member->id,
            'member_id' => (int) $member->id,
            'code' => $member->code,
            'name' => $member->name,
            'phone' => $this->phone,
            'email' => $member->email,
            'birth_date' => $member->birth_date?->toDateString(),
            'address' => $member->address,
            'points' => (int) $member->points_balance,
            'points_balance' => (int) $member->points_balance,
            'lifetime_points' => (int) $member->points_earned_total,
            'visit_count' => (int) $member->visit_count,
            'joined_at' => $member->created_at?->toIso8601String(),
            'membership_date' => $member->created_at?->toDateString(),
            'access_label' => 'Aurum Member',
            'phone_verified_at' => $this->phone_verified_at?->toIso8601String(),
        ];
    }
}
