<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Sale extends Model
{
    protected $fillable = [
        'code','cashier_id','customer_name',
        'subtotal','discount','tax','total','paid','change','status'
    ];

    public function items() { return $this->hasMany(SaleItem::class); }
    public function payments() { return $this->hasMany(SalePayment::class); }
    public function cashier() { return $this->belongsTo(User::class, 'cashier_id'); }
}
