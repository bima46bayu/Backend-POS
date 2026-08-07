<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductOptionGroup extends Model
{
    use HasFactory;

    public const SELECTION_SINGLE = 'SINGLE';
    public const SELECTION_MULTI  = 'MULTI';

    protected $fillable = [
        'store_location_id',
        'name',
        'selection_type',
        'is_required',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_required' => 'boolean',
        'is_active'   => 'boolean',
        'sort_order'  => 'integer',
    ];

    public function values()
    {
        return $this->hasMany(ProductOptionValue::class, 'product_option_group_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function storeLocation()
    {
        return $this->belongsTo(StoreLocation::class, 'store_location_id');
    }

    public function products()
    {
        return $this->belongsToMany(
            Product::class,
            'product_product_option_group',
            'product_option_group_id',
            'product_id'
        );
    }

    public function isMulti(): bool
    {
        return $this->selection_type === self::SELECTION_MULTI;
    }
}
