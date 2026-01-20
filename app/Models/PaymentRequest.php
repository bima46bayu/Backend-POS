<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\PaymentRequestDetail;
use App\Models\PaymentRequestBalance;
use App\Models\StoreLocation;
use App\Models\BankAccount;

class PaymentRequest extends Model
{
    protected $fillable = [
        'store_location_id',
        'main_bank_account_id',
        'currency',
    ];

    /* ================= Relations ================= */

    public function items()
    {
        return $this->hasMany(PaymentRequestDetail::class);
    }

    public function balances()
    {
        return $this->hasMany(PaymentRequestBalance::class);
    }

    public function storeLocation()
    {
        return $this->belongsTo(StoreLocation::class);
    }

    public function bankAccount()
    {
        return $this->belongsTo(BankAccount::class, 'main_bank_account_id');
    }
}
