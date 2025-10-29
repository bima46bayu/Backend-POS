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
        'store_location_id',   // ← pakai FK
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

    /**
     * === Tambahan: Mutator password (auto-hash, anti double-hash) ===
     */
    public function setPasswordAttribute($value): void
    {
        if (!is_string($value) || $value === '') return;

        // Jika sudah format bcrypt ($2y$ / $2a$ / $2b$) dengan panjang 60, anggap sudah hashed
        if (strlen($value) === 60 && str_starts_with($value, '$2')) {
            $this->attributes['password'] = $value;
            return;
        }

        // Selain itu, hash sekarang
        $this->attributes['password'] = \Illuminate\Support\Facades\Hash::make($value);
    }

    /**
     * === Tambahan: Scopes untuk memudahkan filter di controller index ===
     */
    public function scopeSearch($q, ?string $s)
    {
        if (!$s) return $q;
        $s = trim($s);
        return $q->where(function ($w) use ($s) {
            $w->where('name','like',"%{$s}%")
              ->orWhere('email','like',"%{$s}%");
        });
    }

    public function scopeRole($q, ?string $role)
    {
        return $role ? $q->where('role', $role) : $q;
    }
}
