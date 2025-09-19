<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SalePayment extends Model
{
    use HasFactory;

    protected $fillable = ['sale_id','method','amount','reference'];

    protected $casts = [
        'amount' => 'float',
    ];

    public function sale() { return $this->belongsTo(Sale::class); }

    // opsional → biar aman kalau ada input "qris" lowercase
    public function setMethodAttribute($value)
    {
        $this->attributes['method'] =
            (is_string($value) && strtolower($value) === 'qris') ? 'QRIS' : $value;
    }
}
