<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdditionalCharge extends Model
{
    protected $fillable = [
        'store_location_id',
        'type',
        'calc_type',
        'value',
        'is_active',
    ];

    protected $casts = [
        'value'     => 'float',
        'is_active' => 'boolean',
    ];
}
