<?php

namespace App\Http\Resources\Api\V1;

use App\Models\MemberPointTransaction;
use App\Services\PointsService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One ledger row for the History screen: title, source, signed points.
 */
class TransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'type' => PointsService::kind($this->resource),
            'points' => (int) $this->points,
            'title' => $this->title(),
            'source' => $this->source(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    private function title(): string
    {
        $reward = $this->whenLoaded('reward', fn () => $this->reward?->name, null);

        return match ($this->type) {
            MemberPointTransaction::TYPE_EARN => 'Points earned',
            MemberPointTransaction::TYPE_REVOKE => 'Purchase voided',
            MemberPointTransaction::TYPE_REDEEM,
            MemberPointTransaction::TYPE_RESERVE => $reward ?: 'Reward redeemed',
            MemberPointTransaction::TYPE_REDEEM_VOID,
            MemberPointTransaction::TYPE_RESERVE_VOID => $reward ? "Refund: {$reward}" : 'Redemption refunded',
            MemberPointTransaction::TYPE_ADJUST => (int) $this->points >= 0 ? 'Bonus points' : 'Points adjustment',
            default => 'Points activity',
        };
    }

    private function source(): string
    {
        $store = $this->whenLoaded('storeLocation', fn () => $this->storeLocation?->name, null);
        $sale = $this->whenLoaded('sale', fn () => $this->sale?->code, null);

        return match ($this->type) {
            MemberPointTransaction::TYPE_EARN,
            MemberPointTransaction::TYPE_REVOKE => $store ?: ($sale ? "Purchase {$sale}" : 'Purchase'),
            MemberPointTransaction::TYPE_ADJUST => 'Member services',
            default => $store ?: 'Rewards Vault',
        };
    }
}
