<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class AppSetting extends Model
{
    protected $fillable = ['key', 'value'];

    protected $casts = [
        'value' => 'array',
    ];

    public const KEY_PR_SIGNATORIES = 'payment_request.signatories'; // legacy flat snapshot
    public const KEY_PR_SIGNER_ROLES = 'payment_request.signer_roles';
    public const KEY_VOID_SECURITY_CODE = 'sales.void_security_code'; // legacy (unused for verify)
    public const DEFAULT_VOID_SECURITY_CODE = '2580'; // legacy fallback unused
    public const VOID_CODE_TIMEZONE = 'Asia/Jakarta';
    public const VOID_CODE_DIGITS = 4;

    public static function getValue(string $key, mixed $default = null): mixed
    {
        $row = Cache::remember("app_setting:{$key}", 60, function () use ($key) {
            return static::query()->where('key', $key)->first();
        });

        if (!$row) {
            return $default;
        }

        return $row->value ?? $default;
    }

    public static function setValue(string $key, mixed $value): self
    {
        $row = static::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $value]
        );

        Cache::forget("app_setting:{$key}");

        return $row;
    }

    /**
     * Parent/region root id that owns the void code for a store.
     */
    public static function voidCodeOwnerStoreId(int $storeLocationId): int
    {
        $store = StoreLocation::query()->find($storeLocationId);
        if (!$store) {
            return $storeLocationId;
        }

        return (int) ($store->parent_id ?: $store->id);
    }

    /**
     * Hour bucket in Asia/Jakarta, e.g. 2026080314 for 14:00–14:59.
     */
    public static function voidCodeHourBucket(?\DateTimeInterface $at = null): string
    {
        $dt = $at
            ? \Carbon\Carbon::parse($at)->timezone(static::VOID_CODE_TIMEZONE)
            : now(static::VOID_CODE_TIMEZONE);

        return $dt->format('YmdH');
    }

    /** End of the current hour window (ISO8601). */
    public static function voidCodeExpiresAt(?\DateTimeInterface $at = null): string
    {
        $dt = $at
            ? \Carbon\Carbon::parse($at)->timezone(static::VOID_CODE_TIMEZONE)
            : now(static::VOID_CODE_TIMEZONE);

        return $dt->copy()->startOfHour()->addHour()->toIso8601String();
    }

    /**
     * Deterministic 4-digit code for a parent store + hour bucket.
     * Rotates automatically every hour — no manual setup.
     */
    public static function generateVoidSecurityCode(int $ownerStoreId, ?string $hourBucket = null): string
    {
        $bucket = $hourBucket ?: static::voidCodeHourBucket();
        $secret = (string) config('app.key');
        $digest = hash_hmac('sha256', "void-code:{$ownerStoreId}:{$bucket}", $secret);
        $n = hexdec(substr($digest, 0, 8)) % (10 ** static::VOID_CODE_DIGITS);

        return str_pad((string) $n, static::VOID_CODE_DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Current rotating code + metadata for management UI.
     *
     * @return array{owner_store_id:int,security_code:string,valid_until:string,timezone:string,rotates_every_minutes:int}
     */
    public static function currentVoidSecurityCode(int $storeLocationId): array
    {
        $ownerId = static::voidCodeOwnerStoreId($storeLocationId);

        return [
            'owner_store_id' => $ownerId,
            'security_code' => static::generateVoidSecurityCode($ownerId),
            'valid_until' => static::voidCodeExpiresAt(),
            'timezone' => static::VOID_CODE_TIMEZONE,
            'rotates_every_minutes' => 60,
        ];
    }

    public static function verifyVoidSecurityCode(?string $plain, ?int $storeLocationId = null): bool
    {
        $plain = trim((string) $plain);
        if ($plain === '' || $storeLocationId === null) {
            return false;
        }

        $ownerId = static::voidCodeOwnerStoreId($storeLocationId);

        // Current hour
        if (hash_equals(static::generateVoidSecurityCode($ownerId), $plain)) {
            return true;
        }

        // Previous hour (grace across the hour boundary)
        $prevBucket = static::voidCodeHourBucket(
            now(static::VOID_CODE_TIMEZONE)->subHour()
        );
        if (hash_equals(static::generateVoidSecurityCode($ownerId, $prevBucket), $plain)) {
            return true;
        }

        return false;
    }

    /** @deprecated Manual codes removed — rotating hourly codes are always active. */
    public static function voidSecurityCodeConfigured(?int $storeLocationId = null): bool
    {
        return true;
    }

    /** @deprecated No-op — codes rotate automatically. */
    public static function setVoidSecurityCode(string $plain, int $storeLocationId): void
    {
        // Kept for API compatibility; rotating codes do not persist.
    }

    public static function defaultSignerRoleLabels(): array
    {
        return [
            'submitted' => 'Diajukan Oleh,',
            'acknowledged' => 'Diketahui Oleh,',
            'approved' => 'Disetujui Oleh,',
        ];
    }

    /**
     * Role assignment config: which signer fills which PDF column.
     * Shape: role => ['signer_id' => int|null, 'label' => string]
     */
    public static function paymentRequestSignerRoles(): array
    {
        $labels = static::defaultSignerRoleLabels();
        $stored = static::getValue(static::KEY_PR_SIGNER_ROLES, []);
        if (!is_array($stored)) {
            $stored = [];
        }

        $out = [];
        foreach ($labels as $role => $defaultLabel) {
            $row = is_array($stored[$role] ?? null) ? $stored[$role] : [];
            $signerId = $row['signer_id'] ?? null;
            $signerId = $signerId !== null && $signerId !== ''
                ? (int) $signerId
                : null;

            $out[$role] = [
                'signer_id' => $signerId && $signerId > 0 ? $signerId : null,
                'label' => (string) ($row['label'] ?? $defaultLabel),
            ];
        }

        return $out;
    }

    public static function setPaymentRequestSignerRoles(array $roles): array
    {
        $labels = static::defaultSignerRoleLabels();
        $normalized = [];

        foreach ($labels as $role => $defaultLabel) {
            $row = is_array($roles[$role] ?? null) ? $roles[$role] : [];
            $signerId = $row['signer_id'] ?? null;
            $signerId = $signerId !== null && $signerId !== ''
                ? (int) $signerId
                : null;

            $normalized[$role] = [
                'signer_id' => $signerId && $signerId > 0 ? $signerId : null,
                'label' => (string) ($row['label'] ?? $defaultLabel),
            ];
        }

        static::setValue(static::KEY_PR_SIGNER_ROLES, $normalized);

        return static::paymentRequestSignerRoles();
    }

    /**
     * Resolved signatories for Payment Request PDF.
     * Keys: submitted, acknowledged, approved.
     */
    public static function paymentRequestSignatories(): array
    {
        $roles = static::paymentRequestSignerRoles();
        $ids = collect($roles)->pluck('signer_id')->filter()->unique()->values()->all();
        $signers = $ids
            ? PaymentRequestSigner::query()->whereIn('id', $ids)->get()->keyBy('id')
            : collect();

        $out = [];
        foreach ($roles as $role => $assignment) {
            $signer = isset($assignment['signer_id'])
                ? $signers->get($assignment['signer_id'])
                : null;

            $signature = $signer?->signature;
            if (is_string($signature)) {
                $signature = trim($signature);
                $signature = $signature === '' ? null : ltrim(str_replace('\\', '/', $signature), '/');
            } else {
                $signature = null;
            }

            $out[$role] = [
                'label' => (string) ($assignment['label'] ?? ''),
                'name' => $signer ? (string) $signer->name : '',
                'signature' => $signature,
                'show_signature' => (bool) $signature,
                'signer_id' => $signer?->id,
            ];
        }

        return $out;
    }
}
