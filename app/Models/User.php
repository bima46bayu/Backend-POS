<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    public const ROLE_ADMIN = 'admin';
    public const ROLE_REGIONAL_MANAGER = 'regional_manager';
    public const ROLE_STORE_ADMIN = 'store_admin';
    public const ROLE_KASIR = 'kasir';

    public const ROLES = [
        self::ROLE_ADMIN,
        self::ROLE_REGIONAL_MANAGER,
        self::ROLE_STORE_ADMIN,
        self::ROLE_KASIR,
    ];

    public const MANAGEMENT_ROLES = [
        self::ROLE_ADMIN,
        self::ROLE_REGIONAL_MANAGER,
        self::ROLE_STORE_ADMIN,
    ];

    /**
     * Kolom yang bisa diisi mass assignment
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'store_location_id',
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
     * Relasi ke lokasi toko
     */
    public function storeLocation()
    {
        return $this->belongsTo(StoreLocation::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isRegionalManager(): bool
    {
        return $this->role === self::ROLE_REGIONAL_MANAGER;
    }

    public function isStoreAdmin(): bool
    {
        return $this->role === self::ROLE_STORE_ADMIN;
    }

    public function isKasir(): bool
    {
        return $this->role === self::ROLE_KASIR;
    }

    public function isManagement(): bool
    {
        return in_array($this->role, self::MANAGEMENT_ROLES, true);
    }

    /**
     * null = unrestricted (HQ admin); [] = no stores; int[] = allowed ids.
     *
     * @return int[]|null
     */
    public function allowedStoreIds(): ?array
    {
        if ($this->isAdmin()) {
            return null;
        }

        if (! $this->store_location_id) {
            return [];
        }

        if ($this->isRegionalManager()) {
            return StoreLocation::idsInGroup((int) $this->store_location_id);
        }

        return [(int) $this->store_location_id];
    }

    public function canAccessStore(?int $storeId): bool
    {
        if ($storeId === null) {
            return $this->isAdmin();
        }

        $allowed = $this->allowedStoreIds();
        if ($allowed === null) {
            return true;
        }

        return in_array((int) $storeId, $allowed, true);
    }

    public function canSwitchStore(): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        $allowed = $this->allowedStoreIds();

        return is_array($allowed) && count($allowed) > 1;
    }

    /**
     * Roles this user may assign when creating/updating other users.
     *
     * @return string[]
     */
    public function assignableRoles(): array
    {
        if ($this->isAdmin()) {
            return self::ROLES;
        }

        if ($this->isRegionalManager()) {
            return [self::ROLE_STORE_ADMIN, self::ROLE_KASIR];
        }

        if ($this->isStoreAdmin()) {
            return [self::ROLE_KASIR];
        }

        return [];
    }

    /**
     * Roles an actor may see in the user list.
     *
     * @return string[]|null null = all roles (HQ admin)
     */
    public static function visibleRolesFor(self $actor): ?array
    {
        if ($actor->isAdmin()) {
            return null;
        }

        if ($actor->isRegionalManager()) {
            return [self::ROLE_STORE_ADMIN, self::ROLE_KASIR];
        }

        if ($actor->isStoreAdmin()) {
            return [self::ROLE_KASIR];
        }

        return [];
    }

    public function scopeVisibleToActor($query, self $actor)
    {
        if ($actor->isAdmin()) {
            return $query;
        }

        $allowed = $actor->allowedStoreIds();
        if ($allowed === null) {
            return $query;
        }

        if ($allowed === []) {
            return $query->whereRaw('1 = 0');
        }

        $query->whereIn('store_location_id', $allowed);

        $visibleRoles = self::visibleRolesFor($actor);
        if ($visibleRoles === null) {
            return $query;
        }

        if ($visibleRoles === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('role', $visibleRoles);
    }

    public function canManageUser(self $target): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        if ($this->isRegionalManager()) {
            if (in_array($target->role, [self::ROLE_ADMIN, self::ROLE_REGIONAL_MANAGER], true)) {
                return false;
            }

            if (! $target->store_location_id) {
                return false;
            }

            return in_array($target->role, [self::ROLE_STORE_ADMIN, self::ROLE_KASIR], true)
                && $this->canAccessStore((int) $target->store_location_id);
        }

        if ($this->isStoreAdmin()) {
            if ($target->role !== self::ROLE_KASIR || ! $target->store_location_id || ! $this->store_location_id) {
                return false;
            }

            return (int) $target->store_location_id === (int) $this->store_location_id;
        }

        return false;
    }

    /**
     * === Tambahan: Mutator password (auto-hash, anti double-hash) ===
     */
    public function setPasswordAttribute($value): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        if (strlen($value) === 60 && str_starts_with($value, '$2')) {
            $this->attributes['password'] = $value;

            return;
        }

        $this->attributes['password'] = \Illuminate\Support\Facades\Hash::make($value);
    }

    public function scopeSearch($q, ?string $s)
    {
        if (! $s) {
            return $q;
        }
        $s = trim($s);

        return $q->where(function ($w) use ($s) {
            $w->where('name', 'like', "%{$s}%")
                ->orWhere('email', 'like', "%{$s}%");
        });
    }

    public function scopeRole($q, ?string $role)
    {
        return $role ? $q->where('role', $role) : $q;
    }
}
