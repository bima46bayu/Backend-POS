<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PromotionDismissal extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = null;

    protected $fillable = ['member_id', 'promotion_id', 'dismissed_at'];

    protected $casts = ['dismissed_at' => 'datetime'];
}
