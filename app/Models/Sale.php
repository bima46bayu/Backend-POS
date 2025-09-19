<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Sale extends Model
{
    use HasFactory;

    protected $fillable = [
        'code','cashier_id','customer_name',
        'subtotal','discount','service_charge','tax',
        'total','paid','change','status'
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

    public function items()    { return $this->hasMany(SaleItem::class); }
    public function payments() { return $this->hasMany(SalePayment::class); }
    public function cashier()  { return $this->belongsTo(User::class, 'cashier_id'); }
}
