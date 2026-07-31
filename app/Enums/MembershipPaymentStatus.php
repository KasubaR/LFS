<?php

namespace App\Enums;

class MembershipPaymentStatus
{
    public const Pending = 'pending';

    public const Paid = 'paid';

    public const PartiallyPaid = 'partially_paid';

    public const Failed = 'failed';

    public const Refunded = 'refunded';

    /** @var list<string> */
    public const ALL = [
        self::Pending,
        self::Paid,
        self::PartiallyPaid,
        self::Failed,
        self::Refunded,
    ];

    /** @var list<string> */
    public const TERMINAL = [
        self::Paid,
        self::Failed,
        self::Refunded,
    ];

    public static function isTerminal(string $status): bool
    {
        return in_array($status, self::TERMINAL, true);
    }

    public static function resolveFromAmounts(float $amountDue, float $amountPaid): string
    {
        if ($amountPaid <= 0) {
            return self::Pending;
        }

        if ($amountPaid >= $amountDue) {
            return self::Paid;
        }

        return self::PartiallyPaid;
    }
}
