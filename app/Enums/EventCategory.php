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
