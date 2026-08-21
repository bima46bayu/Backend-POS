<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MemberOtpChallenge extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'phone', 'purpose', 'code_hash', 'attempts', 'max_attempts', 'expires_at', 'consumed_at'];

    protected $hidden = ['code_hash'];

    protected $casts = ['expires_at' => 'datetime', 'consumed_at' => 'datetime'];
}
