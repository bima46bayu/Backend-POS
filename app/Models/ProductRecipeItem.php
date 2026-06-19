<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductRecipeItem extends Model
{
    protected $fillable = [
        'recipe_id',
        'ingredient_product_id',
        'qty',
        'unit_id',
    ];

    protected $casts = [
        'qty' => 'float',
        'unit_id' => 'integer',
    ];

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(ProductRecipe::class, 'recipe_id');
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'ingredient_product_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'unit_id');
    }
}
