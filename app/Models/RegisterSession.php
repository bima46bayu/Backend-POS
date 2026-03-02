<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RegisterSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'store_location_id',
        'cashier_id',
        'opening_cash',
        'closing_cash',
        'note_open',
        'note_close',
        'opened_at',
        'closed_at',
        'total_transactions',
        'total_sales',
        'cash_payments',
        'non_cash_payments',
        'expected_cash',
        'difference',
    ];

    protected $casts = [
        'opening_cash'       => 'float',
        'closing_cash'       => 'float',
        'total_transactions' => 'int',
        'total_sales'        => 'float',
        'cash_payments'      => 'float',
        'non_cash_payments'  => 'float',
        'expected_cash'      => 'float',
        'difference'         => 'float',
        'opened_at'          => 'datetime',
        'closed_at'          => 'datetime',
    ];

    public function cashier()
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function storeLocation()
    {
        return $this->belongsTo(StoreLocation::class, 'store_location_id');
    }
}

