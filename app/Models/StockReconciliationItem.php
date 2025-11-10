<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockReconciliationItem extends Model
{
    protected $fillable = [
        'reconciliation_id','product_id','sku','name',
        'system_stock','real_stock','avg_cost','diff_stock','direction','note',
    ];

    public function header() {
        return $this->belongsTo(StockReconciliation::class, 'reconciliation_id');
    }
}
