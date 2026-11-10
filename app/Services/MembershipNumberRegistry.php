<?php

namespace App\Services;

use App\Exceptions\DuplicateMembershipNumberException;
use App\Support\MembershipNumberLookup;
use Illuminate\Support\Facades\DB;

/**
 * Guarantees a membership number belongs to ONE person.
 *
 * A renewal is a new memberships row carrying the same number, so the number
 * column itself can't be unique. This registry holds one row per number
 * (normalised: letters and digits only, upper-case, so LFS14149 = LFS-14149 =
 * "lfs 14149") with its owner, and the primary key makes the claim atomic even
 * when two requests race.
 *
 * Claiming a number you already own is a no-op, so renewals and retries are fine.
 */
class MembershipNumberRegistry
{
    public const TABLE = 'membership_number_owners';

    public static function keyFor(string $number): string
    {
        return MembershipNumberLookup::normalize($number);
    }

    /**
     * The user who owns this number, or null if it is free.
     */
    public function ownerOf(string $number): ?int
    {
        $key = self::keyFor($number);
        if ($key === '') {
            return null;
        }

        $owner = DB::table(self::TABLE)->where('number_key', $key)->value('user_id');

        return $owner === null ? null : (int) $owner;
    }

    /**
     * Reserve $number for $userId.
     *
     * @throws DuplicateMembershipNumberException when a different person already holds it
     */
    public function claim(string $number, int $userId): void
    {
        $key = self::keyFor($number);
        if ($key === '') {
            return;
        }

        // insertOrIgnore is atomic on the primary key: of two racing claims,
        // exactly one inserts and the other sees the existing owner.
        $inserted = DB::table(self::TABLE)->insertOrIgnore([
            'number_key' => $key,
            'user_id' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($inserted === 1) {
            return;
        }

        $owner = (int) DB::table(self::TABLE)->where('number_key', $key)->value('user_id');
        if ($owner !== $userId) {
            throw new DuplicateMembershipNumberException($number, $owner);
        }
    }
}
