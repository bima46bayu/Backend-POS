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
    public const KEY_VOID_CODE_EPOCHS = 'sales.void_security_code_epochs';
    public const DEFAULT_VOID_SECURITY_CODE = '2580'; // legacy fallback unused
    public const VOID_CODE_TIMEZONE = 'Asia/Jakarta';
    public const VOID_CODE_DIGITS = 4;
    public const VOID_CODE_ROTATE_MINUTES = 10;

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
     * Time-window bucket in Asia/Jakarta, e.g. 20260818162 for 16:20–16:29.
     */
    public static function voidCodeHourBucket(?\DateTimeInterface $at = null): string
    {
        $dt = static::voidCodeWindowStart($at);

        $slot = intdiv((int) $dt->format('i'), static::VOID_CODE_ROTATE_MINUTES);

        return $dt->format('YmdH') . $slot;
    }

    public static function voidCodeWindowStart(?\DateTimeInterface $at = null): \Carbon\Carbon
    {
        $dt = $at
            ? \Carbon\Carbon::parse($at)->timezone(static::VOID_CODE_TIMEZONE)
            : now(static::VOID_CODE_TIMEZONE);

        $slotMinutes = intdiv((int) $dt->format('i'), static::VOID_CODE_ROTATE_MINUTES)
            * static::VOID_CODE_ROTATE_MINUTES;

        return $dt->copy()->startOfHour()->addMinutes($slotMinutes);
    }

    /** End of the current 10-minute window (ISO8601). */
    public static function voidCodeExpiresAt(?\DateTimeInterface $at = null): string
    {
        return static::voidCodeWindowStart($at)
            ->addMinutes(static::VOID_CODE_ROTATE_MINUTES)
            ->toIso8601String();
    }

    /**
     * @return array{epoch:int,period:?string,valid_until:?string,prev_period:?string,prev_epoch:?int}
     */
    public static function readVoidCodeState(int $ownerStoreId): array
    {
        $all = static::getValue(static::KEY_VOID_CODE_EPOCHS, []);
        if (!is_array($all)) {
            $all = [];
        }

        return static::normalizeVoidCodeState($all[(string) $ownerStoreId] ?? null);
    }

    /**
     * @param  array{epoch?:int,period?:?string,valid_until?:?string,prev_period?:?string,prev_epoch?:?int}  $state
     */
    public static function saveVoidCodeState(int $ownerStoreId, array $state): void
    {
        $all = static::getValue(static::KEY_VOID_CODE_EPOCHS, []);
        if (!is_array($all)) {
            $all = [];
        }

        $all[(string) $ownerStoreId] = [
            'epoch' => (int) ($state['epoch'] ?? 0),
            'period' => $state['period'] ?? null,
            'valid_until' => $state['valid_until'] ?? null,
            'prev_period' => $state['prev_period'] ?? null,
            'prev_epoch' => $state['prev_epoch'] ?? null,
        ];
        static::setValue(static::KEY_VOID_CODE_EPOCHS, $all);
    }

    /**
     * @return array{epoch:int,period:?string,valid_until:?string,prev_period:?string,prev_epoch:?int}
     */
    public static function normalizeVoidCodeState(mixed $row): array
    {
        if (is_int($row) || (is_string($row) && is_numeric($row))) {
            return [
                'epoch' => (int) $row,
                'period' => null,
                'valid_until' => null,
                'prev_period' => null,
                'prev_epoch' => null,
            ];
        }

        if (!is_array($row)) {
            return [
                'epoch' => 0,
                'period' => null,
                'valid_until' => null,
                'prev_period' => null,
                'prev_epoch' => null,
            ];
        }

        return [
            'epoch' => (int) ($row['epoch'] ?? 0),
            'period' => isset($row['period']) && $row['period'] !== ''
                ? (string) $row['period']
                : null,
            'valid_until' => $row['valid_until'] ?? null,
            'prev_period' => isset($row['prev_period']) && $row['prev_period'] !== ''
                ? (string) $row['prev_period']
                : null,
            'prev_epoch' => isset($row['prev_epoch']) ? (int) $row['prev_epoch'] : null,
        ];
    }

    /**
     * Active period + expiry. Rolls to the next 10-minute clock window when expired.
     *
     * @return array{epoch:int,period:string,valid_until:string,prev_period:?string,prev_epoch:?int}
     */
    public static function ensureVoidCodeState(int $ownerStoreId): array
    {
        $state = static::readVoidCodeState($ownerStoreId);
        $now = now(static::VOID_CODE_TIMEZONE);
        $validUntil = !empty($state['valid_until'])
            ? \Carbon\Carbon::parse($state['valid_until'])->timezone(static::VOID_CODE_TIMEZONE)
            : null;

        if ($validUntil && $validUntil->gt($now) && !empty($state['period'])) {
            return $state;
        }

        $next = [
            'epoch' => (int) ($state['epoch'] ?? 0),
            'period' => static::voidCodeHourBucket($now),
            'valid_until' => static::voidCodeExpiresAt($now),
            'prev_period' => $state['period'] ?: static::voidCodeHourBucket(
                $now->copy()->subMinutes(static::VOID_CODE_ROTATE_MINUTES)
            ),
            'prev_epoch' => (int) ($state['epoch'] ?? 0),
        ];

        if (
            ($state['period'] ?? null) === $next['period']
            && (string) ($state['valid_until'] ?? '') === (string) $next['valid_until']
            && (int) ($state['epoch'] ?? 0) === $next['epoch']
        ) {
            return $next;
        }

        static::saveVoidCodeState($ownerStoreId, $next);

        return $next;
    }

    public static function voidCodeEpoch(int $ownerStoreId): int
    {
        return (int) static::readVoidCodeState($ownerStoreId)['epoch'];
    }

    public static function bumpVoidCodeEpoch(int $ownerStoreId): int
    {
        $state = static::ensureVoidCodeState($ownerStoreId);
        $state['epoch'] = (int) $state['epoch'] + 1;
        static::saveVoidCodeState($ownerStoreId, $state);

        return (int) $state['epoch'];
    }

    /**
     * Deterministic 4-digit code for a parent store + period (+ optional epoch).
     * Auto-rotates every 10 minutes; Refresh starts a fresh 10-minute window.
     */
    public static function generateVoidSecurityCode(
        int $ownerStoreId,
        ?string $hourBucket = null,
        ?int $epoch = null
    ): string {
        if ($hourBucket === null || $epoch === null) {
            $state = $hourBucket === null
                ? static::ensureVoidCodeState($ownerStoreId)
                : static::readVoidCodeState($ownerStoreId);
            $hourBucket ??= (string) ($state['period'] ?: static::voidCodeHourBucket());
            $epoch ??= (int) ($state['epoch'] ?? 0);
        }

        $secret = (string) config('app.key');
        $payload = $epoch > 0
            ? "void-code:{$ownerStoreId}:{$hourBucket}:{$epoch}"
            : "void-code:{$ownerStoreId}:{$hourBucket}";
        $digest = hash_hmac('sha256', $payload, $secret);
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
        $state = static::ensureVoidCodeState($ownerId);

        return [
            'owner_store_id' => $ownerId,
            'security_code' => static::generateVoidSecurityCode(
                $ownerId,
                (string) $state['period'],
                (int) $state['epoch']
            ),
            'valid_until' => (string) ($state['valid_until'] ?: static::voidCodeExpiresAt()),
            'timezone' => static::VOID_CODE_TIMEZONE,
            'rotates_every_minutes' => static::VOID_CODE_ROTATE_MINUTES,
        ];
    }

    /**
     * Force a new code and restart the 10-minute timer from now.
     *
     * @return array{owner_store_id:int,security_code:string,valid_until:string,timezone:string,rotates_every_minutes:int}
     */
    public static function rotateVoidSecurityCode(int $storeLocationId): array
    {
        $ownerId = static::voidCodeOwnerStoreId($storeLocationId);
        $state = static::ensureVoidCodeState($ownerId);
        $current = static::generateVoidSecurityCode(
            $ownerId,
            (string) $state['period'],
            (int) $state['epoch']
        );
        $now = now(static::VOID_CODE_TIMEZONE);

        for ($i = 0; $i < 20; $i++) {
            $epoch = (int) ($state['epoch'] ?? 0) + 1;
            $period = 'manual:' . $now->getTimestamp() . ':' . $epoch;
            $next = [
                'epoch' => $epoch,
                'period' => $period,
                'valid_until' => $now->copy()
                    ->addMinutes(static::VOID_CODE_ROTATE_MINUTES)
                    ->toIso8601String(),
                'prev_period' => null,
                'prev_epoch' => null,
            ];
            static::saveVoidCodeState($ownerId, $next);
            $state = $next;
            $code = static::generateVoidSecurityCode($ownerId, $period, $epoch);
            if ($code !== $current) {
                break;
            }
        }

        return static::currentVoidSecurityCode($storeLocationId);
    }

    public static function verifyVoidSecurityCode(?string $plain, ?int $storeLocationId = null): bool
    {
        $plain = trim((string) $plain);
        if ($plain === '' || $storeLocationId === null) {
            return false;
        }

        $ownerId = static::voidCodeOwnerStoreId($storeLocationId);
        $state = static::ensureVoidCodeState($ownerId);

        $current = static::generateVoidSecurityCode(
            $ownerId,
            (string) $state['period'],
            (int) $state['epoch']
        );
        if (hash_equals($current, $plain)) {
            return true;
        }

        // Previous auto window only (Refresh invalidates immediately).
        if (!empty($state['prev_period'])) {
            $prev = static::generateVoidSecurityCode(
                $ownerId,
                (string) $state['prev_period'],
                (int) ($state['prev_epoch'] ?? $state['epoch'])
            );
            if (hash_equals($prev, $plain)) {
                return true;
            }
        }

        return false;
    }

    /** @deprecated Manual codes removed — rotating interval codes are always active. */
    public static function voidSecurityCodeConfigured(?int $storeLocationId = null): bool
    {
        return true;
    }

    /** @deprecated Use rotateVoidSecurityCode() — codes are not set manually. */
    public static function setVoidSecurityCode(string $plain, int $storeLocationId): void
    {
        static::rotateVoidSecurityCode($storeLocationId);
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
