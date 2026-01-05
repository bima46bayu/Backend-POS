<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Sale;
use App\Models\Product;
use App\Models\Discount;

class SaleItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'sale_id',
        'product_id',

        // harga & qty
        'qty',
        'unit_price',

        // diskon item (hasil hitung otomatis)
        'discount_id',        // id diskon master ITEM
        'discount_name',      // snapshot
        'discount_kind',      // snapshot: PERCENT / FIXED
        'discount_value',     // snapshot: 10 / 5000
        'discount_nominal',   // NOMINAL diskon per baris

        // harga setelah diskon
        'net_unit_price',
        'line_total',
    ];

    protected $casts = [
        'qty'              => 'integer',   // ⬅️ jangan integer (aman untuk desimal)
        'unit_price'       => 'float',
        'discount_nominal' => 'float',
        'net_unit_price'   => 'float',
        'line_total'       => 'float',
        'discount_value'   => 'float',
    ];

    /* ========================= RELATIONS ========================= */

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function discountMaster()
    {
        return $this->belongsTo(Discount::class, 'discount_id');
    }

    /* ========================= HELPERS (OPSIONAL) ========================= */

    /**
     * Subtotal baris sebelum diskon
     */
    public function getLineSubtotalAttribute(): float
    {
        return max(0, $this->qty * $this->unit_price);
    }

    /**
     * Apakah item ini pakai diskon
     */
    public function hasDiscount(): bool
    {
        return ($this->discount_nominal ?? 0) > 0;
    }
}
