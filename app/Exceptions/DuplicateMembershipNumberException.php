<?php

namespace App\Exceptions;

/**
 * A membership number already belongs to a different person.
 */
class DuplicateMembershipNumberException extends CodeException
{
    public const CODE = 'DUPLICATE_MEMBERSHIP_NUMBER';

    public function __construct(
        public readonly string $membershipNumber,
        public readonly int $ownerUserId,
    ) {
        parent::__construct(
            "Membership number {$membershipNumber} already belongs to another member.",
            self::CODE
        );
    }
}
