<?php

namespace App\Services;

use App\Models\Member;
use App\Models\MemberAccount;
use App\Models\StoreLocation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Self-service sign-up from the member app (email + password).
 *
 * Creates a brand-new member card. It never links to an existing
 * cashier-registered member: that would let anyone claim another
 * customer's points by typing their details. Linking is the job of the
 * phone OTP flow, which proves possession of the number.
 */
final class MemberRegistrationService
{
    private const MAX_CODE_ATTEMPTS = 3;

    public function register(string $name, string $email, string $password): MemberAccount
    {
        $email = mb_strtolower(trim($email));
        $storeId = $this->defaultStoreId();

        // Member::nextCode() is read-then-insert; a concurrent sign-up can
        // take the same code, which the unique index rejects. Retry with a
        // fresh code rather than lock the whole members table.
        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(function () use ($name, $email, $password, $storeId) {
                    $member = Member::create([
                        'store_location_id' => $storeId,
                        'code' => Member::nextCode(),
                        'name' => trim($name),
                        'email' => $email,
                        'is_active' => true,
                    ]);

                    return MemberAccount::create([
                        'member_id' => $member->id,
                        'email' => $email,
                        'password' => $password,
                    ])->setRelation('member', $member);
                });
            } catch (UniqueConstraintViolationException $e) {
                if (MemberAccount::where('email', $email)->exists()) {
                    throw ValidationException::withMessages(['email' => ['An account with this email already exists.']]);
                }
                if ($attempt >= self::MAX_CODE_ATTEMPTS) {
                    throw $e;
                }
            }
        }
    }

    /** Store new sign-ups are registered at; see config/member_app.php. */
    public function defaultStoreId(): int
    {
        $configured = config('member_app.default_store_id');
        if ($configured !== null && $configured !== '' && StoreLocation::whereKey($configured)->exists()) {
            return (int) $configured;
        }

        $first = StoreLocation::query()->whereNull('parent_id')->orderBy('id')->value('id');
        if ($first === null) {
            throw new RuntimeException('No store location exists to register members at.');
        }

        return (int) $first;
    }
}
