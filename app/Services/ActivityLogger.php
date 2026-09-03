<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\MemberAccount;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class ActivityLogger
{
    private const SKIP_PATHS = [
        'activity-logs',
        'ping',
        'login',
        'v1/member/auth/login',
        'v1/member/auth/verify',
        'v1/member/auth/password/reset',
        'v1/member/auth/otp',
    ];

    public static function fromRequest(Request $request, Response $response): void
    {
        if (! self::shouldLog($request, $response)) {
            return;
        }

        $user = $request->user();
        $path = self::path($request);
        $method = strtoupper($request->method());
        $mapped = self::describe($method, $path);

        $actorType = 'staff';
        $actorId = null;
        $actorName = null;
        $actorRole = null;
        $storeId = null;

        if ($user instanceof User) {
            $actorType = 'staff';
            $actorId = (int) $user->id;
            $actorName = $user->name;
            $actorRole = $user->role;
            $storeId = $user->store_location_id ? (int) $user->store_location_id : null;
        } elseif ($user instanceof MemberAccount) {
            $actorType = 'member';
            $actorId = (int) $user->id;
            $actorName = $user->member?->name ?: $user->phone;
            $actorRole = 'member';
            $storeId = $user->member?->store_location_id ? (int) $user->member->store_location_id : null;
        } else {
            $actorType = 'guest';
            $actorName = 'Guest';
        }

        self::record([
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'actor_name' => $actorName,
            'actor_role' => $actorRole,
            'store_location_id' => $storeId,
            'method' => $method,
            'path' => mb_substr($path, 0, 255),
            'action' => $mapped['action'],
            'description' => $mapped['description'],
            'subject_type' => $mapped['subject_type'],
            'subject_id' => $mapped['subject_id'],
            'status_code' => $response->getStatusCode(),
            'ip' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255) ?: null,
            'meta' => self::safeMeta($request),
            'created_at' => now(),
        ]);
    }

    public static function forActor(Request $request, User|MemberAccount $actor, string $method, string $path, int $status = 200): void
    {
        $mapped = self::describe($method, $path);
        $isMember = $actor instanceof MemberAccount;

        self::record([
            'actor_type' => $isMember ? 'member' : 'staff',
            'actor_id' => (int) $actor->id,
            'actor_name' => $isMember ? ($actor->member?->name ?: $actor->phone) : $actor->name,
            'actor_role' => $isMember ? 'member' : $actor->role,
            'store_location_id' => $isMember
                ? ($actor->member?->store_location_id ? (int) $actor->member->store_location_id : null)
                : ($actor->store_location_id ? (int) $actor->store_location_id : null),
            'method' => $method,
            'path' => mb_substr($path, 0, 255),
            'action' => $mapped['action'],
            'description' => $mapped['description'],
            'subject_type' => $mapped['subject_type'],
            'subject_id' => $mapped['subject_id'],
            'status_code' => $status,
            'ip' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255) ?: null,
            'meta' => self::safeMeta($request),
            'created_at' => now(),
        ]);
    }

    public static function record(array $attrs): void
    {
        if (! Schema::hasTable('activity_logs')) {
            return;
        }

        try {
            ActivityLog::create($attrs);
        } catch (\Throwable $e) {
            Log::warning('activity log write failed', ['error' => $e->getMessage()]);
        }
    }

    public static function shouldLog(Request $request, Response $response): bool
    {
        $method = strtoupper($request->method());
        if (! in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return false;
        }

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 400) {
            return false;
        }

        $path = ltrim(self::path($request), '/');
        foreach (self::SKIP_PATHS as $skip) {
            if (str_starts_with($path, $skip)) {
                return false;
            }
        }

        // Cart drafts fire on every keystroke in POS.
        if (preg_match('#(?:^|/)registers/\d+/cart$#', $path)) {
            return false;
        }

        return true;
    }

    public static function path(Request $request): string
    {
        $path = $request->path();
        $path = preg_replace('#^api/#', '', $path) ?? $path;

        return '/'.ltrim($path, '/');
    }

    /**
     * @return array{action:string,description:string,subject_type:?string,subject_id:?string}
     */
    public static function describe(string $method, string $path): array
    {
        $trimmed = '/'.ltrim($path, '/');
        $id = null;
        if (preg_match('#/(\d+)(?:/|$)#', $trimmed, $m)) {
            $id = $m[1];
        }

        $normalized = preg_replace('#/\d+#', '/{id}', $trimmed) ?? $trimmed;
        $normalized = preg_replace('#/[0-9a-f-]{36}#i', '/{id}', $normalized) ?? $normalized;
        $key = $method.' '.$normalized;

        $catalog = [
            'POST /login' => ['staff.login', 'Login staff', 'user', null],
            'POST /logout' => ['staff.logout', 'Logout staff', 'user', null],
            'PUT /me/store' => ['staff.switch_store', 'Ganti cabang aktif', 'user', null],

            'POST /v1/member/auth/verify' => ['member.register', 'Member mendaftarkan akun aplikasi', 'member', null],
            'POST /v1/member/auth/login' => ['member.login', 'Login member', 'member', null],
            'POST /v1/member/auth/password/reset' => ['member.reset_password', 'Member mereset password', 'member', null],
            'POST /v1/member/auth/logout' => ['member.logout', 'Logout member', 'member', null],
            'PATCH /v1/member/profile' => ['member.update_profile', 'Member mengubah profil', 'member', null],
            'POST /v1/member/reservations' => ['member.reserve_reward', 'Member reservasi hadiah', 'reward_reservation', null],
            'POST /v1/member/reservations/{id}/cancel' => ['member.cancel_reservation', 'Member membatalkan reservasi hadiah', 'reward_reservation', $id],
            'POST /v1/staff/reward-redemptions/{id}/fulfill' => ['staff.fulfill_reward', 'Staff menyerahkan hadiah', 'reward_reservation', $id],
            'POST /v1/staff/reward-redemptions/{id}/reject' => ['staff.reject_reward', 'Staff menolak hadiah', 'reward_reservation', $id],

            'POST /pos/registers/open' => ['register.open', 'Buka register', 'register_session', null],
            'POST /pos/registers/{id}/close' => ['register.close', 'Tutup register', 'register_session', $id],

            'POST /sales' => ['sale.create', 'Membuat transaksi penjualan', 'sale', null],
            'POST /sales/{id}/void' => ['sale.void', 'Void transaksi', 'sale', $id],

            'POST /products' => ['product.create', 'Membuat produk', 'product', null],
            'PUT /products/{id}' => ['product.update', 'Mengubah produk', 'product', $id],
            'PATCH /products/{id}' => ['product.update', 'Mengubah produk', 'product', $id],
            'DELETE /products/{id}' => ['product.delete', 'Menghapus produk', 'product', $id],
            'POST /products/{id}/upload' => ['product.upload', 'Upload foto produk', 'product', $id],

            'POST /categories' => ['category.create', 'Membuat kategori', 'category', null],
            'PUT /categories/{id}' => ['category.update', 'Mengubah kategori', 'category', $id],
            'DELETE /categories/{id}' => ['category.delete', 'Menghapus kategori', 'category', $id],

            'POST /sub-categories' => ['sub_category.create', 'Membuat sub kategori', 'sub_category', null],
            'PUT /sub-categories/{id}' => ['sub_category.update', 'Mengubah sub kategori', 'sub_category', $id],
            'DELETE /sub-categories/{id}' => ['sub_category.delete', 'Menghapus sub kategori', 'sub_category', $id],

            'POST /users' => ['user.create', 'Membuat user', 'user', null],
            'PUT /users/{id}' => ['user.update', 'Mengubah user', 'user', $id],
            'DELETE /users/{id}' => ['user.delete', 'Menghapus user', 'user', $id],

            'POST /members' => ['member.create', 'Membuat member', 'member', null],
            'PUT /members/{id}' => ['member.update', 'Mengubah member', 'member', $id],
            'DELETE /members/{id}' => ['member.delete', 'Menghapus member', 'member', $id],

            'POST /purchases' => ['purchase.create', 'Membuat purchase order', 'purchase', null],
            'PUT /purchases/{id}' => ['purchase.update', 'Mengubah purchase order', 'purchase', $id],
            'DELETE /purchases/{id}' => ['purchase.delete', 'Menghapus purchase order', 'purchase', $id],
            'POST /purchases/{id}/receive' => ['purchase.receive', 'Goods receipt', 'purchase', $id],
            'POST /purchases/{id}/approve' => ['purchase.approve', 'Approve purchase order', 'purchase', $id],
            'POST /purchases/{id}/cancel' => ['purchase.cancel', 'Cancel purchase order', 'purchase', $id],
            'DELETE /purchases/{id}/items/{id}' => ['purchase.line_cancel', 'PO line cancelled, GR/layer removed, stock adjusted to 0', 'purchase_item', $id],
            'POST /receipts/{id}/void' => ['gr.void', 'Hapus / reverse goods receipt', 'goods_receipt', $id],
            'POST /receipts/{id}/cost-adjustments' => ['gr.cost_adjust', 'Cost adjustment FIFO', 'cost_adjustment', $id],
            'POST /receipts/{id}/review' => ['gr.review', 'Flag GR untuk manual review', 'goods_receipt', $id],
            'POST /receipts/{id}/review/resolve' => ['gr.review_resolve', 'Selesai manual review GR', 'goods_receipt', $id],

            'POST /stock-write-offs' => ['write_off.create', 'Mencatat write-off / waste', 'stock_write_off', null],
            'POST /stock-write-offs/batches' => ['write_off.create', 'Mencatat write-off / waste', 'stock_write_off', null],
            'PUT /stock-write-offs/batches/{id}' => ['write_off.update', 'Mengubah write-off / waste', 'stock_write_off', $id],
            'POST /stock-write-offs/batches/{id}/submit' => ['write_off.submit', 'Submit write-off / waste', 'stock_write_off', $id],
            'DELETE /stock-write-offs/batches/{id}' => ['write_off.delete', 'Menghapus write-off / waste', 'stock_write_off', $id],

            'POST /inventory/reconciliations' => ['recon.create', 'Membuat rekonsiliasi stok', 'stock_reconciliation', null],
            'POST /inventory/reconciliations/{id}/apply' => ['recon.apply', 'Menerapkan rekonsiliasi stok', 'stock_reconciliation', $id],
        ];

        if (isset($catalog[$key])) {
            [$action, $description, $subjectType, $subjectId] = $catalog[$key];
            if ($subjectId) {
                $description .= ' #'.$subjectId;
            }

            return [
                'action' => $action,
                'description' => $description,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId !== null ? (string) $subjectId : null,
            ];
        }

        $resource = trim(str_replace(['/v1/member', '/v1/staff', '/pos'], '', $normalized), '/');
        $resource = preg_replace('#/\{id\}.*$#', '', $resource) ?: $resource;
        $resource = $resource !== '' ? str_replace('-', ' ', $resource) : 'data';

        $verb = match ($method) {
            'POST' => 'Membuat',
            'PUT', 'PATCH' => 'Mengubah',
            'DELETE' => 'Menghapus',
            default => $method,
        };

        return [
            'action' => strtolower($method).'.'.$resource,
            'description' => $verb.' '.$resource.($id ? ' #'.$id : ''),
            'subject_type' => explode('/', $resource)[0] ?? null,
            'subject_id' => $id,
        ];
    }

    private static function safeMeta(Request $request): ?array
    {
        $hidden = [
            'password', 'password_confirmation', 'otp', 'token', 'current_password',
            'security_code', 'image', 'images', 'code_hash',
        ];

        $input = $request->except($hidden);
        $clean = [];
        $count = 0;
        foreach ($input as $key => $value) {
            if ($count >= 16) {
                break;
            }
            if (is_array($value)) {
                $clean[$key] = ['count' => count($value)];
            } elseif (is_scalar($value) || $value === null) {
                $str = mb_substr((string) $value, 0, 120);
                $clean[$key] = $str;
            }
            $count++;
        }

        return $clean === [] ? null : $clean;
    }
}
