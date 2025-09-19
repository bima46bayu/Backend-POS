<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SaleItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'sale_id','product_id',
        'unit_price','discount_nominal','net_unit_price',
        'qty','line_total'
    ];

    protected $casts = [
        'unit_price'       => 'float',
        'discount_nominal' => 'float',
        'net_unit_price'   => 'float',
        'line_total'       => 'float',
        'qty'              => 'integer',
    ];

    public function sale()    { return $this->belongsTo(Sale::class); }
    public function product() { return $this->belongsTo(Product::class); }
}
