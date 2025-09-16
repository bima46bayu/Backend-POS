<?php

namespace App\Models;
use App\Models\Purchase;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    use HasFactory;
    // use SoftDeletes;

    protected $fillable = [
        'name',
        'contact',      // untuk skema minimalis
        // field opsional (kalau kamu tambahkan di migration, tinggal aktifkan di sini):
        'type', 'address', 'phone', 'email', 'pic_name', 'pic_phone',
    ];

    // Contoh casting sederhana bila diperlukan
    protected $casts = [
        // 'deleted_at' => 'datetime',
    ];

    public function purchases() {
        return $this->hasMany(Purchase::class);
    }
}