<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CostAdjustment extends Model
{
    protected $fillable = [
        'original_movement_id',
        'layer_id',
        'goods_receipt_id',
        'goods_receipt_item_id',
        'product_id',
        'store_location_id',
        'qty_affected',
        'old_unit_cost',
        'new_unit_cost',
        'cogs_delta',
        'reason',
        'created_by',
    ];

    protected $casts = [
        'qty_affected'  => 'float',
        'old_unit_cost' => 'float',
        'new_unit_cost' => 'float',
        'cogs_delta'    => 'float',
    ];

    public function goodsReceipt()
    {
        return $this->belongsTo(GoodsReceipt::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
