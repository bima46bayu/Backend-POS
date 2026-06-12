<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StoreLocation extends Model
{
    protected $fillable = ['code', 'name', 'address', 'phone', 'logo_url', 'parent_id'];

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function isRoot(): bool
    {
        return $this->parent_id === null;
    }

    /**
     * Root store + direct branches, or a single branch when $storeId is a branch.
     *
     * @return int[]
     */
    public static function idsInGroup(int $storeId): array
    {
        $store = self::find($storeId);
        if (! $store) {
            return [];
        }

        if ($store->parent_id) {
            return [(int) $store->id];
        }

        $childIds = self::where('parent_id', $store->id)->pluck('id')->all();

        return array_values(array_unique(array_merge([(int) $store->id], array_map('intval', $childIds))));
    }

    /**
     * Region root id for a store (self when root, parent when branch).
     */
    public function regionRootId(): int
    {
        return (int) ($this->parent_id ?: $this->id);
    }
}
