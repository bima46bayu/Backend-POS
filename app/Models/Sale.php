<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Sale extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'client_uuid',
        'cashier_id',
        'store_location_id',
        'customer_name',

        // core numbers
        'subtotal',
        'discount',

        // 🔥 NEW CORE
        'grand_total',
        'additional_charges_snapshot',
        'additional_charge_total',
        'final_total',

        // legacy (jangan dipakai di logic baru)
        'service_charge',
        'tax',
        'total',

        // payment
        'paid',
        'change',
        'status',

        // discount snapshot
        'discount_id',
        'discount_name',
        'discount_kind',
        'discount_value',

        // offline sync (mobile POS)
        'is_offline',
        'offline_created_at',
        'synced_at',
        'stock_shortfall',
        'needs_review',
    ];

    protected $casts = [
        'subtotal'                     => 'float',
        'discount'                     => 'float',
        'grand_total'                  => 'float',
        'additional_charge_total'      => 'float',
        'final_total'                  => 'float',

        // legacy
        'service_charge'               => 'float',
        'tax'                          => 'float',
        'total'                        => 'float',

        'paid'                         => 'float',
        'change'                       => 'float',

        'additional_charges_snapshot'  => 'array',

        // offline sync
        'is_offline'                   => 'boolean',
        'needs_review'                 => 'boolean',
        'offline_created_at'           => 'datetime',
        'synced_at'                    => 'datetime',
        'stock_shortfall'              => 'array',
    ];

    /* ================= RELATIONS ================= */

    public function items()
    {
        return $this->hasMany(SaleItem::class);
    }

    public function payments()
    {
        return $this->hasMany(SalePayment::class);
    }

    public function cashier()
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function storeLocation()
    {
        return $this->belongsTo(StoreLocation::class, 'store_location_id');
    }

    public function discountMaster()
    {
        return $this->belongsTo(Discount::class, 'discount_id');
    }
}
