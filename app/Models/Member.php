<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

/**
 * A member / registered customer.
 *
 * store_location_id always holds the PARENT (region root) store id, so one card
 * works across every branch of that group with a pooled point balance.
 */
class Member extends Model
{
    protected $fillable = [
        'store_location_id',
        'code',
        'name',
        'phone',
        'email',
        'birth_date',
        'address',
        'note',
        'is_active',
    ];

    /**
     * The totals default at the DB level, which means a freshly created model
     * returns null for them until it is re-fetched. Declaring them here makes a
     * new member serialise as 0 instead of null, so the UI never shows a blank
     * point balance right after signup.
     */
    protected $attributes = [
        'points_balance'      => 0,
        'points_earned_total' => 0,
        'points_spent_total'  => 0,
        'total_spend'         => 0,
        'visit_count'         => 0,
        'is_active'           => true,
    ];

    protected $casts = [
        'birth_date'          => 'date',
        'is_active'           => 'boolean',
        'points_balance'      => 'integer',
        'points_earned_total' => 'integer',
        'points_spent_total'  => 'integer',
        'total_spend'         => 'decimal:2',
        'visit_count'         => 'integer',
        'last_transaction_at' => 'datetime',
    ];

    public function storeLocation()
    {
        return $this->belongsTo(StoreLocation::class);
    }

    public function pointTransactions()
    {
        return $this->hasMany(MemberPointTransaction::class)->latest('id');
    }

    public function sales()
    {
        return $this->hasMany(Sale::class);
    }

    /**
     * Members are owned by the parent store group. Resolve any branch id to the
     * group root so lookups and creation always agree on the owner.
     */
    public static function ownerStoreId(int $storeLocationId): int
    {
        $store = StoreLocation::query()->find($storeLocationId);
        if (! $store) {
            return $storeLocationId;
        }

        return (int) ($store->parent_id ?: $store->id);
    }

    /** Restrict a query to the group that owns [$storeLocationId]. */
    public function scopeForStoreGroup(Builder $q, ?int $storeLocationId): Builder
    {
        if ($storeLocationId === null) {
            return $q;
        }

        return $q->where('store_location_id', static::ownerStoreId($storeLocationId));
    }

    /**
     * Free-text search over the fields a cashier would actually type:
     * phone, name, or member code.
     */
    public function scopeSearch(Builder $q, ?string $term): Builder
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $q;
        }

        // Digits-only input is almost certainly a phone number; match loosely so
        // "0812" and "812" both work regardless of how it was stored.
        $digits = preg_replace('/\D+/', '', $term);

        return $q->where(function ($w) use ($term, $digits) {
            $w->where('name', 'like', "%{$term}%")
              ->orWhere('code', 'like', "%{$term}%");

            if ($digits !== '') {
                $w->orWhere('phone', 'like', "%{$digits}%");
            } else {
                $w->orWhere('phone', 'like', "%{$term}%");
            }
        });
    }

    /**
     * Next sequential card code for a store group, e.g. MBR-0007.
     *
     * Derived from the highest existing numeric suffix rather than a count, so
     * deleting a member never causes a code collision.
     */
    public static function nextCode(int $ownerStoreId): string
    {
        $last = static::query()
            ->where('store_location_id', $ownerStoreId)
            ->where('code', 'like', 'MBR-%')
            ->orderByRaw('LENGTH(code) DESC')
            ->orderBy('code', 'desc')
            ->value('code');

        $n = 0;
        if ($last && preg_match('/(\d+)$/', $last, $m)) {
            $n = (int) $m[1];
        }

        return 'MBR-' . str_pad((string) ($n + 1), 4, '0', STR_PAD_LEFT);
    }

    /** Compact shape for the POS member picker. */
    public function toPickerArray(): array
    {
        return [
            'id'             => (int) $this->id,
            'code'           => (string) $this->code,
            'name'           => (string) $this->name,
            'phone'          => $this->phone,
            'points_balance' => (int) $this->points_balance,
            'is_active'      => (bool) $this->is_active,
        ];
    }
}
