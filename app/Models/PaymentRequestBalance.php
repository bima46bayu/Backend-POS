<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\PaymentRequest;
use App\Models\BankAccount;

class PaymentRequestBalance extends Model
{
    protected $fillable = [
        'payment_request_id',
        'bank_account_id',
        'saldo',
    ];

    public function bankAccount()
    {
        return $this->belongsTo(BankAccount::class);
    }
    public function paymentRequest()
    {
        return $this->belongsTo(PaymentRequest::class);
    }
}
