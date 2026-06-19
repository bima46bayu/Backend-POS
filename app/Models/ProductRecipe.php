<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductRecipe extends Model
{
    protected $fillable = [
        'product_id',
        'store_location_id',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function storeLocation(): BelongsTo
    {
        return $this->belongsTo(StoreLocation::class, 'store_location_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ProductRecipeItem::class, 'recipe_id');
    }

    public static function forProductAtStore(int $productId, int $storeId): ?self
    {
        return self::query()
            ->with(['items.ingredient'])
            ->where('product_id', $productId)
            ->where('store_location_id', $storeId)
            ->where('is_active', true)
            ->first();
    }
}
