<?php

namespace App\Enums;

class EventCategory
{
    public const Lsd = 'LSD';

    public const RoadRace = 'Road Race';

    public const Training = 'Training';

    public const TrainingCamp = 'Training Camp';

    public const Social = 'Social';

    public const Other = 'Other';

    /**
     * Categories that may sell paid tickets. Ticketing is an OPTION for
     * social-style events (e.g. the end-of-year party); every other event
     * keeps the existing registration (open / members / none / external link).
     *
     * @var list<string>
     */
    public const TICKETABLE = [
        self::Social,
    ];

    /** @var list<string> */
    public const ALL = [
        self::Lsd,
        self::RoadRace,
        self::Training,
        self::TrainingCamp,
        self::Social,
        self::Other,
    ];
}
