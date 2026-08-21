<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\PaymentRequestSigner;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AppSettingController extends Controller
{
    /* ========== Resolved PDF roles (backward compatible) ========== */

    public function paymentRequestSignatories()
    {
        return response()->json([
            'signatories' => AppSetting::paymentRequestSignatories(),
            'roles' => AppSetting::paymentRequestSignerRoles(),
            'signers' => PaymentRequestSigner::query()
                ->orderBy('name')
                ->get()
                ->map(fn (PaymentRequestSigner $s) => $s->toApiArray())
                ->values(),
        ]);
    }

    /* ========== Master people (database) ========== */

    public function listSigners(Request $request)
    {
        $q = PaymentRequestSigner::query()->orderBy('name');

        if ($request->boolean('active_only')) {
            $q->where('is_active', true);
        }

        return response()->json([
            'data' => $q->get()->map(fn (PaymentRequestSigner $s) => $s->toApiArray())->values(),
        ]);
    }

    public function storeSigner(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'signature' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $signature = $this->normalizeSignaturePath($data['signature'] ?? null);

        $signer = PaymentRequestSigner::create([
            'name' => trim($data['name']),
            'signature' => $signature,
            'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true,
        ]);

        return response()->json($signer->toApiArray(), 201);
    }

    public function updateSigner(Request $request, $id)
    {
        $signer = PaymentRequestSigner::findOrFail($id);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'signature' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
            'clear_signature' => ['nullable', 'boolean'],
        ]);

        if (array_key_exists('name', $data)) {
            $signer->name = trim($data['name']);
        }
        if (!empty($data['clear_signature'])) {
            $signer->signature = null;
        } elseif (array_key_exists('signature', $data)) {
            $signer->signature = $this->normalizeSignaturePath($data['signature']);
        }
        if (array_key_exists('is_active', $data)) {
            $signer->is_active = (bool) $data['is_active'];
        }

        $signer->save();

        return response()->json($signer->toApiArray());
    }

    public function destroySigner($id)
    {
        $signer = PaymentRequestSigner::findOrFail($id);

        // Unassign from roles if currently selected
        $roles = AppSetting::paymentRequestSignerRoles();
        $changed = false;
        foreach ($roles as $role => $row) {
            if ((int) ($row['signer_id'] ?? 0) === (int) $signer->id) {
                $roles[$role]['signer_id'] = null;
                $changed = true;
            }
        }
        if ($changed) {
            AppSetting::setPaymentRequestSignerRoles($roles);
        }

        $signer->delete();

        return response()->noContent();
    }

    /* ========== Role assignment (pick who fills PDF columns) ========== */

    public function updatePaymentRequestSignatories(Request $request)
    {
        // Prefer new assignment payload; keep legacy flat payload support briefly.
        if ($request->has('roles')) {
            $data = $request->validate([
                'roles' => ['required', 'array'],
                'roles.submitted' => ['required', 'array'],
                'roles.acknowledged' => ['required', 'array'],
                'roles.approved' => ['required', 'array'],
                'roles.*.signer_id' => ['nullable', 'integer'],
                'roles.*.label' => ['nullable', 'string', 'max:120'],
            ]);

            $roles = AppSetting::setPaymentRequestSignerRoles($data['roles']);

            // Validate signer ids exist (soft: ignore missing by nulling)
            $ids = collect($roles)->pluck('signer_id')->filter()->unique()->values();
            $existing = PaymentRequestSigner::query()->whereIn('id', $ids)->pluck('id')->all();
            $existingSet = array_flip($existing);
            $fixed = false;
            foreach ($roles as $role => $row) {
                $sid = $row['signer_id'] ?? null;
                if ($sid && !isset($existingSet[$sid])) {
                    $roles[$role]['signer_id'] = null;
                    $fixed = true;
                }
            }
            if ($fixed) {
                $roles = AppSetting::setPaymentRequestSignerRoles($roles);
            }

            return response()->json([
                'message' => 'Payment request signatory roles updated',
                'roles' => $roles,
                'signatories' => AppSetting::paymentRequestSignatories(),
            ]);
        }

        // Legacy: create/update people from flat payload (compat only)
        $data = $request->validate([
            'signatories' => ['required', 'array'],
            'signatories.submitted' => ['required', 'array'],
            'signatories.acknowledged' => ['required', 'array'],
            'signatories.approved' => ['required', 'array'],
            'signatories.*.label' => ['nullable', 'string', 'max:120'],
            'signatories.*.name' => ['required', 'string', 'max:120'],
            'signatories.*.signature' => ['nullable', 'string', 'max:255'],
            'signatories.*.show_signature' => ['nullable', 'boolean'],
            'signatories.*.signer_id' => ['nullable', 'integer'],
        ]);

        $roles = [];
        foreach (['submitted', 'acknowledged', 'approved'] as $role) {
            $row = $data['signatories'][$role];
            $signerId = $row['signer_id'] ?? null;
            if ($signerId) {
                $signer = PaymentRequestSigner::find($signerId);
            } else {
                $signer = PaymentRequestSigner::create([
                    'name' => trim($row['name']),
                    'signature' => !empty($row['show_signature'])
                        ? $this->normalizeSignaturePath($row['signature'] ?? null)
                        : $this->normalizeSignaturePath($row['signature'] ?? null),
                    'is_active' => true,
                ]);
                $signerId = $signer->id;
            }

            $roles[$role] = [
                'signer_id' => (int) $signerId,
                'label' => (string) ($row['label'] ?? AppSetting::defaultSignerRoleLabels()[$role]),
            ];
        }

        AppSetting::setPaymentRequestSignerRoles($roles);

        return response()->json([
            'message' => 'Payment request signatories updated',
            'roles' => AppSetting::paymentRequestSignerRoles(),
            'signatories' => AppSetting::paymentRequestSignatories(),
        ]);
    }

    public function uploadSignature(Request $request)
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            'signer_id' => ['nullable', 'integer', 'exists:payment_request_signers,id'],
            'role' => ['nullable', 'in:submitted,acknowledged,approved'],
        ]);

        $file = $data['file'];
        $uploadDir = public_path('signatures');
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $tag = $data['signer_id'] ?? ($data['role'] ?? 'sig');
        $ext = strtolower($file->getClientOriginalExtension() ?: 'png');
        $filename = 'sig-' . $tag . '-' . Str::lower(Str::random(8)) . '.' . $ext;
        $file->move($uploadDir, $filename);

        $relative = 'signatures/' . $filename;

        if (!empty($data['signer_id'])) {
            $signer = PaymentRequestSigner::findOrFail($data['signer_id']);
            $signer->signature = $relative;
            $signer->save();

            return response()->json([
                'path' => $relative,
                'url' => url('/' . $relative),
                'signer' => $signer->toApiArray(),
            ]);
        }

        return response()->json([
            'path' => $relative,
            'url' => url('/' . $relative),
        ]);
    }

    protected function normalizeSignaturePath(?string $signature): ?string
    {
        if (!is_string($signature)) {
            return null;
        }
        $signature = trim($signature);
        if ($signature === '') {
            return null;
        }

        return ltrim(str_replace('\\', '/', $signature), '/');
    }

    /* ========== Void security code (auto-rotates every 10 minutes per parent) ========== */

    public function voidSecurityCode(Request $request)
    {
        $data = $request->validate([
            'store_location_id' => ['nullable', 'integer', 'exists:store_locations,id'],
        ]);

        $storeId = isset($data['store_location_id'])
            ? (int) $data['store_location_id']
            : null;

        if ($storeId !== null) {
            $this->authorizeStoreAccess($request->user(), $storeId);
            $meta = AppSetting::currentVoidSecurityCode($storeId);
            $owner = \App\Models\StoreLocation::query()->find($meta['owner_store_id']);

            return response()->json([
                'store_location_id' => $storeId,
                'owner_store_id' => $meta['owner_store_id'],
                'owner_store_name' => $owner
                    ? trim(($owner->code ? $owner->code . ' - ' : '') . $owner->name)
                    : null,
                'security_code' => $meta['security_code'],
                'valid_until' => $meta['valid_until'],
                'timezone' => $meta['timezone'],
                'rotates_every_minutes' => $meta['rotates_every_minutes'],
                'mode' => 'interval_auto',
            ]);
        }

        // Summary list for all parent/root stores the user can access.
        $parents = \App\Models\StoreLocation::query()
            ->whereNull('parent_id')
            ->orderBy('name')
            ->get();

        $user = $request->user();
        $items = [];
        foreach ($parents as $parent) {
            if (!$user->canAccessStore((int) $parent->id)) {
                continue;
            }
            $meta = AppSetting::currentVoidSecurityCode((int) $parent->id);
            $items[] = [
                'owner_store_id' => (int) $parent->id,
                'owner_store_name' => trim(($parent->code ? $parent->code . ' - ' : '') . $parent->name),
                'security_code' => $meta['security_code'],
                'valid_until' => $meta['valid_until'],
            ];
        }

        return response()->json([
            'items' => $items,
            'timezone' => AppSetting::VOID_CODE_TIMEZONE,
            'rotates_every_minutes' => AppSetting::VOID_CODE_ROTATE_MINUTES,
            'mode' => 'interval_auto',
            'message' => 'Kode void diganti otomatis setiap 10 menit (Asia/Jakarta). Refresh mengganti kode sekarang.',
        ]);
    }

    public function updateVoidSecurityCode(Request $request)
    {
        $data = $request->validate([
            'store_location_id' => ['required', 'integer', 'exists:store_locations,id'],
        ]);

        $storeId = (int) $data['store_location_id'];
        $this->authorizeStoreAccess($request->user(), $storeId);
        $meta = AppSetting::rotateVoidSecurityCode($storeId);
        $owner = \App\Models\StoreLocation::query()->find($meta['owner_store_id']);

        return response()->json([
            'message' => 'Kode void diganti.',
            'owner_store_id' => $meta['owner_store_id'],
            'owner_store_name' => $owner
                ? trim(($owner->code ? $owner->code . ' - ' : '') . $owner->name)
                : null,
            'security_code' => $meta['security_code'],
            'valid_until' => $meta['valid_until'],
            'timezone' => $meta['timezone'],
            'rotates_every_minutes' => $meta['rotates_every_minutes'],
            'mode' => 'interval_auto',
        ]);
    }

    protected function authorizeStoreAccess($user, ?int $storeId): void
    {
        if (!$user || !$user->canAccessStore($storeId)) {
            abort(403, 'Store access denied');
        }
    }
}
