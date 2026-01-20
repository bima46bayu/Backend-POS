<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\PaymentRequest;
use App\Models\Payee;
use App\Models\Coa;

class PaymentRequestDetail extends Model
{
    protected $fillable = [
        'payment_request_id',
        'payee_id',
        'coa_id',
        'description',
        'amount',
        'deduction',
        'transfer_amount',
        'remark',
    ];

    public function paymentRequest()
    {
        return $this->belongsTo(PaymentRequest::class);
    }

    public function payee()
    {
        return $this->belongsTo(Payee::class);
    }

    public function coa()
    {
        return $this->belongsTo(Coa::class);
    }
}
