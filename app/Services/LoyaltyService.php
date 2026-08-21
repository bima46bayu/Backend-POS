<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\LoyaltyReward;
use App\Models\Member;
use App\Models\MemberPointTransaction;
use App\Models\Product;
use App\Models\RewardReservation;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Loyalty points.
 *
 * Conversion is spend-based: every `rate_per_point` rupiah of a sale's final
 * total earns 1 point, floored (the remainder is discarded, not carried over).
 * With the default rate of 10.000: Rp 25.000 -> 2 points.
 *
 * Points are ALWAYS computed here from the persisted sale total. The client
 * never sends a point amount — same anti-tamper rule as option pricing.
 */
class LoyaltyService
{
    /** AppSetting key holding the rupiah-per-point rate. */
    public const KEY_RATE = 'loyalty.points_per_amount';

    /** AppSetting key for the on/off switch. */
    public const KEY_ENABLED = 'loyalty.enabled';

    public const DEFAULT_RATE = 10000;

    /**
     * Rupiah needed for 1 point. Guaranteed >= 1 so we can never divide by zero.
     */
    public static function rate(): int
    {
        $raw = AppSetting::getValue(self::KEY_RATE, self::DEFAULT_RATE);

        // Stored via a json cast, so it may come back as string/float.
        $rate = is_numeric($raw) ? (int) $raw : self::DEFAULT_RATE;

        return $rate > 0 ? $rate : self::DEFAULT_RATE;
    }

    public static function enabled(): bool
    {
        $raw = AppSetting::getValue(self::KEY_ENABLED, true);

        if (is_bool($raw)) {
            return $raw;
        }
        if (is_numeric($raw)) {
            return (int) $raw === 1;
        }
        if (is_string($raw)) {
            return ! in_array(strtolower($raw), ['0', 'false', 'no', 'off', ''], true);
        }

        return true;
    }

    /** Current settings, for the management UI. */
    public static function settings(): array
    {
        $rate = self::rate();

        return [
            'enabled' => self::enabled(),
            'points_per_amount' => $rate,
            'rate_per_point' => $rate,
            'default_rate' => self::DEFAULT_RATE,
            'description' => 'Setiap belanja Rp '.number_format($rate, 0, ',', '.').' mendapat 1 poin.',
        ];
    }

    public static function saveSettings(?int $rate, ?bool $enabled): array
    {
        if ($rate !== null) {
            AppSetting::setValue(self::KEY_RATE, max(1, $rate));
        }
        if ($enabled !== null) {
            AppSetting::setValue(self::KEY_ENABLED, $enabled);
        }

        return self::settings();
    }

    /**
     * How many points a given spend is worth. Floor, never negative.
     */
    public static function pointsFor(float $amount, ?int $rate = null): int
    {
        $rate = $rate ?: self::rate();
        if ($amount <= 0 || $rate <= 0) {
            return 0;
        }

        return (int) floor($amount / $rate);
    }

    /**
     * Award points for a completed sale.
     *
     * Idempotent: the ledger has a unique (sale_id, type) index, so a retried or
     * re-synced offline sale cannot grant points twice. Returns the points
     * granted (0 when disabled, no member, or already awarded).
     *
     * Must be called inside a transaction that also owns the sale row.
     */
    public static function awardForSale(Sale $sale, ?int $userId = null): int
    {
        if (! $sale->member_id || ! self::enabled()) {
            return 0;
        }

        // Voided sales never earn.
        if (strtolower((string) $sale->status) === 'void') {
            return 0;
        }

        $already = MemberPointTransaction::query()
            ->where('sale_id', $sale->id)
            ->where('type', MemberPointTransaction::TYPE_EARN)
            ->exists();
        if ($already) {
            return 0;
        }

        $member = Member::query()->lockForUpdate()->find($sale->member_id);
        if (! $member) {
            return 0;
        }

        $rate = self::rate();
        $amount = (float) ($sale->final_total > 0 ? $sale->final_total : $sale->total);
        $points = self::pointsFor($amount, $rate);

        // Still record the visit/spend even when the basket was too small to
        // earn a point — otherwise visit history silently loses transactions.
        $member->total_spend = (float) $member->total_spend + $amount;
        $member->visit_count = (int) $member->visit_count + 1;
        $member->last_transaction_at = $sale->created_at ?? now();

        if ($points > 0) {
            $member->points_balance = (int) $member->points_balance + $points;
            $member->points_earned_total = (int) $member->points_earned_total + $points;
        }

        $member->save();

        if ($points > 0) {
            MemberPointTransaction::create([
                'member_id' => $member->id,
                'type' => MemberPointTransaction::TYPE_EARN,
                'points' => $points,
                'balance_after' => (int) $member->points_balance,
                'sale_id' => $sale->id,
                'amount' => $amount,
                'rate_per_point' => $rate,
                'store_location_id' => $sale->store_location_id,
                'user_id' => $userId,
                'note' => 'Poin dari transaksi '.$sale->code,
            ]);
        }

        $sale->points_earned = $points;
        $sale->saveQuietly();

        return $points;
    }

    /**
     * Take back the points a sale granted, because it was voided.
     *
     * Reverses the exact amount recorded in the ledger rather than recomputing
     * from the current rate — otherwise changing the rate would corrupt
     * historical voids. Balance is floored at 0 so a member can never go
     * negative if they already spent the points.
     */
    public static function revokeForSale(Sale $sale, ?int $userId = null): int
    {
        if (! $sale->member_id) {
            return 0;
        }

        $earn = MemberPointTransaction::query()
            ->where('sale_id', $sale->id)
            ->where('type', MemberPointTransaction::TYPE_EARN)
            ->first();

        if (! $earn) {
            return 0;
        }

        // Already revoked?
        $revoked = MemberPointTransaction::query()
            ->where('sale_id', $sale->id)
            ->where('type', MemberPointTransaction::TYPE_REVOKE)
            ->exists();
        if ($revoked) {
            return 0;
        }

        $member = Member::query()->lockForUpdate()->find($sale->member_id);
        if (! $member) {
            return 0;
        }

        $take = (int) $earn->points;
        $newBalance = max(0, (int) $member->points_balance - $take);

        // What we could actually take back (member may have spent some).
        $actual = (int) $member->points_balance - $newBalance;

        $member->points_balance = $newBalance;
        $member->points_earned_total = max(0, (int) $member->points_earned_total - $take);
        $member->total_spend = max(0, (float) $member->total_spend - (float) $earn->amount);
        $member->visit_count = max(0, (int) $member->visit_count - 1);
        $member->save();

        MemberPointTransaction::create([
            'member_id' => $member->id,
            'type' => MemberPointTransaction::TYPE_REVOKE,
            'points' => -$actual,
            'balance_after' => $newBalance,
            'sale_id' => $sale->id,
            'amount' => $earn->amount,
            'rate_per_point' => $earn->rate_per_point,
            'store_location_id' => $sale->store_location_id,
            'user_id' => $userId,
            'note' => 'Void transaksi '.$sale->code,
        ]);

        $sale->points_earned = 0;
        $sale->saveQuietly();

        return $actual;
    }

    /**
     * Manual correction by an admin (positive or negative).
     */
    public static function adjust(
        Member $member,
        int $points,
        ?string $note = null,
        ?int $userId = null,
        ?int $storeLocationId = null
    ): MemberPointTransaction {
        return DB::transaction(function () use ($member, $points, $note, $userId, $storeLocationId) {
            $locked = Member::query()->lockForUpdate()->findOrFail($member->id);

            $newBalance = max(0, (int) $locked->points_balance + $points);
            $applied = $newBalance - (int) $locked->points_balance;

            $locked->points_balance = $newBalance;
            if ($applied > 0) {
                $locked->points_earned_total = (int) $locked->points_earned_total + $applied;
            } else {
                $locked->points_spent_total = (int) $locked->points_spent_total + abs($applied);
            }
            $locked->save();

            return MemberPointTransaction::create([
                'member_id' => $locked->id,
                'type' => MemberPointTransaction::TYPE_ADJUST,
                'points' => $applied,
                'balance_after' => $newBalance,
                'amount' => 0,
                'rate_per_point' => null,
                'store_location_id' => $storeLocationId,
                'user_id' => $userId,
                'note' => $note,
            ]);
        });
    }

    /**
     * Give back points spent on a Member Store sale that was voided.
     *
     * Redemptions are recorded as RESERVE (both the member app and the
     * over-the-counter flow go through RewardReservationService). Older sales
     * still carry the legacy REDEEM type, so both are accepted here.
     */
    public static function restoreRedeemForSale(Sale $sale, ?int $userId = null): int
    {
        if (! $sale->member_id) {
            return 0;
        }

        $already = MemberPointTransaction::query()
            ->where('sale_id', $sale->id)
            ->whereIn('type', [
                MemberPointTransaction::TYPE_REDEEM_VOID,
                MemberPointTransaction::TYPE_RESERVE_VOID,
            ])
            ->exists();
        if ($already) {
            return 0;
        }

        $redeem = MemberPointTransaction::query()
            ->where('sale_id', $sale->id)
            ->whereIn('type', [
                MemberPointTransaction::TYPE_REDEEM,
                MemberPointTransaction::TYPE_RESERVE,
            ])
            ->first();
        if (! $redeem) {
            return 0;
        }

        // Keep the reservation consistent with the voided sale.
        RewardReservation::where('sale_id', $sale->id)
            ->where('status', RewardReservation::FULFILLED)
            ->update([
                'status' => RewardReservation::CANCELLED,
                'resolved_at' => now(),
                'resolved_by_user_id' => $userId,
                'rejection_reason' => 'Sale '.$sale->code.' voided',
            ]);

        $cost = abs((int) $redeem->points);
        if ($cost < 1) {
            return 0;
        }

        $member = Member::query()->lockForUpdate()->find($sale->member_id);
        if (! $member) {
            return 0;
        }

        $member->points_balance = (int) $member->points_balance + $cost;
        $member->points_spent_total = max(0, (int) $member->points_spent_total - $cost);
        $member->save();

        MemberPointTransaction::create([
            'member_id' => $member->id,
            'type' => MemberPointTransaction::TYPE_REDEEM_VOID,
            'points' => $cost,
            'balance_after' => (int) $member->points_balance,
            'sale_id' => $sale->id,
            'loyalty_reward_id' => $redeem->loyalty_reward_id,
            'amount' => 0,
            'rate_per_point' => null,
            'store_location_id' => $sale->store_location_id,
            'user_id' => $userId,
            'note' => 'Void tukar poin '.$sale->code,
        ]);

        return $cost;
    }

    /**
     * Issue the Rp 0 sale that backs a point redemption.
     *
     * Shared by both redemption paths: the cashier-side Member Store
     * (`redeem()`, points and sale in one step) and the member-app reservation
     * flow (points taken at reserve time, sale issued at fulfillment). Keeping
     * one implementation is what guarantees stock, FIFO layers, the stock
     * ledger and sales reporting stay consistent between the two.
     *
     * Caller is responsible for the surrounding transaction and for locking the
     * member/reward rows. Aborts 422 on insufficient stock.
     */
    public static function issueRedeemSale(
        Member $member,
        LoyaltyReward $prize,
        Product $product,
        int $branchStoreId,
        ?int $userId
    ): Sale {
        $qty = 1.0;
        $recipeService = app(RecipeService::class);
        $recipes = $recipeService->loadActiveForProducts([$product->id], $branchStoreId);
        $recipe = $recipes->get($product->id);

        if ($recipe) {
            $needs = $recipeService->ingredientNeedsForSaleLine($recipe, $qty, []);
            $short = $recipeService->collectIngredientShortfall($needs, $branchStoreId);
            if ($short !== []) {
                $first = $short[0];
                $label = $first['product_name'] ?? 'bahan';
                abort(422, "Stok {$label} tidak cukup untuk menukar {$product->name}.");
            }
        } elseif ($product->isStockTracked()) {
            $available = InventoryService::sumQtyRemaining((int) $product->id, $branchStoreId);
            if ($available + 1e-9 < $qty) {
                abort(422, "Stok {$product->name} tidak cukup (tersisa {$available}).");
            }
        }

        $code = self::nextSaleCode();
        $sale = Sale::create([
            'code' => $code,
            'cashier_id' => $userId,
            'store_location_id' => $branchStoreId,
            'customer_name' => 'Member',
            'buyer_name' => $member->name,
            'member_id' => $member->id,
            'subtotal' => 0,
            'discount' => 0,
            'grand_total' => 0,
            'additional_charge_total' => 0,
            'final_total' => 0,
            'total' => 0,
            'paid' => 0,
            'change' => 0,
            'status' => 'completed',
            'points_earned' => 0,
        ]);

        $item = SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'qty' => $qty,
            'unit_price' => 0,
            'discount_nominal' => 0,
            'net_unit_price' => 0,
            'line_total' => 0,
        ]);

        SalePayment::create([
            'sale_id' => $sale->id,
            'method' => 'POINTS',
            'amount' => 0,
            'reference' => 'redeem:'.$prize->id,
        ]);

        $inv = app(InventoryService::class);
        $user = (object) ['id' => $userId];

        if ($recipe) {
            $needs = $recipeService->ingredientNeedsForSaleLine($recipe, $qty, []);
            foreach ($needs as $ingredientId => $ingredientQty) {
                self::consumeRedeemInventory(
                    $inv,
                    $sale,
                    $branchStoreId,
                    $user,
                    (int) $ingredientId,
                    (float) $ingredientQty,
                    (int) $item->id
                );
            }
        } elseif ($product->isStockTracked()) {
            self::consumeRedeemInventory(
                $inv,
                $sale,
                $branchStoreId,
                $user,
                (int) $product->id,
                $qty,
                (int) $item->id
            );
        }

        return $sale;
    }

    private static function nextSaleCode(): string
    {
        $soldAt = now();
        $seq = Sale::whereDate('created_at', $soldAt->toDateString())
            ->lockForUpdate()
            ->count() + 1;

        $code = 'POS-'.$soldAt->format('Ymd').'-'.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
        while (Sale::where('code', $code)->exists()) {
            $seq++;
            $code = 'POS-'.$soldAt->format('Ymd').'-'.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
        }

        return $code;
    }

    private static function consumeRedeemInventory(
        InventoryService $inv,
        Sale $sale,
        int $storeId,
        $user,
        int $productId,
        float $qty,
        int $saleItemId
    ): void {
        if ($qty <= 0) {
            return;
        }

        $inv->consumeFIFOWithPricing([
            'product_id' => $productId,
            'store_location_id' => $storeId,
            'qty' => $qty,
            'sale_id' => $sale->id,
            'sale_item_id' => $saleItemId,
            'sale_unit_price' => 0.0,
            'user_id' => $user->id ?? null,
            'allow_shortfall' => false,
        ]);

        if (Schema::hasTable('stock_ledger')) {
            $consQuery = DB::table('inventory_consumptions')->where('product_id', $productId);
            if (Schema::hasColumn('inventory_consumptions', 'sale_item_id')) {
                $consQuery->where('sale_item_id', $saleItemId);
            } else {
                $consQuery->where('sale_id', $sale->id);
            }

            foreach ($consQuery->orderBy('id')->get(['layer_id', 'qty', 'unit_cost']) as $c) {
                DB::table('stock_ledger')->insert([
                    'product_id' => $productId,
                    'store_location_id' => $storeId,
                    'layer_id' => $c->layer_id,
                    'user_id' => $user->id ?? null,
                    'ref_type' => 'SALE',
                    'ref_id' => $sale->id,
                    'direction' => -1,
                    'qty' => (float) $c->qty,
                    'unit_cost' => (float) $c->unit_cost,
                    'unit_price' => null,
                    'subtotal_cost' => (float) $c->qty * (float) $c->unit_cost,
                    'note' => "member store #{$sale->code}",
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        InventoryService::syncLegacyProductStock($productId, $storeId);
    }
}
