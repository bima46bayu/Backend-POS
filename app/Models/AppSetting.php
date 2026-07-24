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
