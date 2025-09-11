<?php

namespace App\Models;

use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Purchase extends Model
{
    protected $fillable = [
        'purchase_number','product_id','supplier_id','user_id',
        'amount','price','status','approved_by','approved_at','note'
    ];

    protected $casts = [
        'approved_at' => 'datetime',
    ];

    public function product(){ return $this->belongsTo(Product::class); }
    public function supplier(){ return $this->belongsTo(Supplier::class); }
    public function user(){ return $this->belongsTo(User::class); } // pembuat
    public function approver(){ return $this->belongsTo(User::class, 'approved_by'); } // yang approve
}

