<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\User;
use App\Models\StoreLocation;
use App\Models\Discount;

class Sale extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'cashier_id',
        'store_location_id',   // ✅ TAMBAH INI
        'customer_name',
        'subtotal',
        'discount',
        'service_charge',
        'tax',
        'total',
        'paid',
        'change',
        'status',
        'discount_id',
        'discount_name',
        'discount_kind',
        'discount_value',
    ];

    protected $casts = [
        'subtotal'       => 'float',
        'discount'       => 'float',
        'service_charge' => 'float',
        'tax'            => 'float',
        'total'          => 'float',
        'paid'           => 'float',
        'change'         => 'float',
    ];

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
