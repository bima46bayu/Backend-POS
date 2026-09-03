<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PurchaseItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'purchase_id', 'product_id', 'qty_order', 'qty_received',
        'unit_price', 'discount', 'tax', 'line_total',
        'status', 'cancelled_at', 'cancelled_by', 'cancelled_note',
    ];

    protected $attributes = [
        'qty_received' => 0,
        'discount' => 0,
        'tax' => 0,
        'status' => 'open',
    ];

    protected $casts = [
        'qty_order' => 'float',
        'qty_received' => 'float',
        'unit_price' => 'float',
        'discount' => 'float',
        'tax' => 'float',
        'line_total' => 'float',
        'cancelled_at' => 'datetime',
    ];

    public function purchase()
    {
        return $this->belongsTo(Purchase::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function goodsReceiptItems()
    {
        return $this->hasMany(GoodsReceiptItem::class);
    }

    public function scopeActive($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('status')->orWhereNotIn('status', ['cancelled', 'canceled']);
        });
    }

    public function isCancelled(): bool
    {
        return in_array(strtolower((string) ($this->status ?? 'open')), ['cancelled', 'canceled'], true);
    }
}
