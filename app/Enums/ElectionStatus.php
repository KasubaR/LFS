<?php

namespace App\Enums;

class ElectionStatus
{
    public const Draft = 'draft';

    public const RollImported = 'roll_imported';

    public const RollLocked = 'roll_locked';

    public const BallotPreview = 'ballot_preview';

    public const BallotApproved = 'ballot_approved';

    public const Scheduled = 'scheduled';

    public const Open = 'open';

    public const Closed = 'closed';

    public const Certified = 'certified';

    public const Locked = 'locked';

    /** @var list<string> */
    public const ALL = [
        self::Draft,
        self::RollImported,
        self::RollLocked,
        self::BallotPreview,
        self::BallotApproved,
        self::Scheduled,
        self::Open,
        self::Closed,
        self::Certified,
        self::Locked,
    ];

    /**
     * @var array<string, string>
     */
    public const LABELS = [
        self::Draft => 'Draft',
        self::RollImported => 'Roll imported',
        self::RollLocked => 'Roll locked',
        self::BallotPreview => 'Ballot preview',
        self::BallotApproved => 'Ballot approved',
        self::Scheduled => 'Scheduled',
        self::Open => 'Open',
        self::Closed => 'Closed',
        self::Certified => 'Certified',
        self::Locked => 'Locked',
    ];

    public static function label(string $status): string
    {
        return self::LABELS[$status] ?? ucfirst(str_replace('_', ' ', $status));
    }

    public static function isMutableBallot(string $status): bool
    {
        return in_array($status, [
            self::Draft,
            self::RollImported,
            self::RollLocked,
            self::BallotPreview,
        ], true);
    }

    public static function isMutableRoll(string $status): bool
    {
        return in_array($status, [
            self::Draft,
            self::RollImported,
            self::BallotPreview,
            self::BallotApproved,
            self::Scheduled,
        ], true) || $status === self::RollImported;
    }

    public static function canOpenFrom(string $status): bool
    {
        return in_array($status, [
            self::BallotApproved,
            self::Scheduled,
        ], true);
    }
}
