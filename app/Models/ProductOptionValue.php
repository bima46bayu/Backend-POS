<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductOptionValue extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_option_group_id',
        'name',
        'price_delta',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'price_delta' => 'float',
        'is_active'   => 'boolean',
        'sort_order'  => 'integer',
    ];

    public function group()
    {
        return $this->belongsTo(ProductOptionGroup::class, 'product_option_group_id');
    }
}
