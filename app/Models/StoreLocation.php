<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StoreLocation extends Model
{
    protected $fillable = ['code','name','address','phone','logo_url'];

    public function users()
    {
        return $this->hasMany(User::class);
    }
}
