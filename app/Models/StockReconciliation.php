<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\StockReconciliationItem;
use App\Models\User;

class StockReconciliation extends Model
{
    protected $fillable = [
        'reference_code','store_location_id','user_id','status',
        'date_from','date_to','reconciled_at','note','total_items','total_value',
    ];

    protected $casts = [
        'reconciled_at' => 'datetime',
        'date_from'     => 'date',
        'date_to'       => 'date',
    ];

    public function items() {
        return $this->hasMany(StockReconciliationItem::class, 'reconciliation_id');
    }

    // app/Models/StockReconciliation.php
    public function user()
    {
        return $this->belongsTo(\App\Models\User::class, 'user_id');
    }

}
