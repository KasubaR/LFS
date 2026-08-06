<?php

namespace App\Enums;

class MembershipStatus
{
    public const Draft = 'draft';

    public const PendingPayment = 'pending_payment';

    public const Active = 'active';

    public const Expired = 'expired';

    public const Cancelled = 'cancelled';

    /** Grace period (30 April) ended without full payment for the year. */
    public const Suspended = 'suspended';

    /** @var list<string> */
    public const ALL = [
        self::Draft,
        self::PendingPayment,
        self::Active,
        self::Expired,
        self::Cancelled,
        self::Suspended,
    ];

    /**
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        self::Draft => [self::PendingPayment, self::Cancelled],
        self::PendingPayment => [self::Active, self::Cancelled],
        self::Active => [self::Expired, self::Suspended, self::Cancelled],
        self::Suspended => [self::Active, self::Expired, self::Cancelled],
        self::Expired => [self::PendingPayment, self::Cancelled],
    ];

    /**
     * @var array<string, list<string>>
     */
    public const ADMIN_DISPLAY_MAP = [
        'active' => [self::Active],
        'pending' => [self::Draft, self::PendingPayment],
        'suspended' => [self::Suspended],
        'inactive' => [self::Expired, self::Cancelled],
    ];

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    public static function adminDisplayStatus(string $status): string
    {
        foreach (self::ADMIN_DISPLAY_MAP as $display => $statuses) {
            if (in_array($status, $statuses, true)) {
                return $display;
            }
        }

        return 'inactive';
    }

    /**
     * @return list<string>
     */
    public static function statusesForAdminFilter(string $filter): array
    {
        return self::ADMIN_DISPLAY_MAP[$filter] ?? self::ALL;
    }
}
