<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Discount extends Model
{
    protected $fillable = [
        'name',
        'scope',
        'kind',
        'value',
        'max_amount',
        'min_subtotal',
        'active',
        'store_location_id',
    ];
}
