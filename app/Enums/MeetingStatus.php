<?php

namespace App\Enums;

class MeetingStatus
{
    public const Scheduled = 'scheduled';

    public const Live = 'live';

    public const Ended = 'ended';

    /** @var list<string> */
    public const ALL = [
        self::Scheduled,
        self::Live,
        self::Ended,
    ];

    /**
     * @var array<string, string>
     */
    public const LABELS = [
        self::Scheduled => 'Scheduled',
        self::Live => 'Live',
        self::Ended => 'Ended',
    ];

    public static function label(string $status): string
    {
        return self::LABELS[$status] ?? ucfirst(str_replace('_', ' ', $status));
    }
}
