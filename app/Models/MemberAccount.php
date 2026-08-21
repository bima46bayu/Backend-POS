<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class MemberAccount extends Authenticatable
{
    use HasApiTokens;

    protected $fillable = ['member_id', 'phone', 'password', 'phone_verified_at', 'last_login_at', 'is_active'];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = ['password' => 'hashed', 'phone_verified_at' => 'datetime', 'last_login_at' => 'datetime', 'is_active' => 'boolean'];

    public function member()
    {
        return $this->belongsTo(Member::class);
    }
}
