<?php

namespace App\Models;
use App\Models\Purchase;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Supplier extends Model {
    use HasFactory;
    protected $fillable = ['name','contact'];

    public function purchases() {
        return $this->hasMany(Purchase::class);
    }
}
