<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Kolom yang bisa diisi mass assignment
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'store_location_id',   // ← ganti: pakai FK, bukan store_name/address/phone
    ];

    /**
     * Kolom yang disembunyikan saat return JSON
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Cast kolom tertentu
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    /**
     * (Opsional) Auto eager-load relasi agar FE selalu dapat storeLocation
     * Bisa kamu aktifkan kalau ingin /auth/me langsung include relasi tanpa ->load()
     */
    // protected $with = ['storeLocation'];

    /**
     * Relasi ke lokasi toko
     */
    public function storeLocation()
    {
        return $this->belongsTo(StoreLocation::class);
    }

    /**
     * Helper untuk cek role
     */
    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isKasir(): bool
    {
        return $this->role === 'kasir';
    }
}
