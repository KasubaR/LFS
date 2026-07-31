<?php

namespace App\Enums;

class BlogCategory
{
    public const ClubNews = 'Club News';

    public const RaceReports = 'Race Reports';

    public const TrainingTips = 'Training Tips';

    public const Announcements = 'Announcements';

    /** @var list<string> */
    public const ALL = [
        self::ClubNews,
        self::RaceReports,
        self::TrainingTips,
        self::Announcements,
    ];

    /** @var array<string, string> */
    public const LABELS = [
        self::ClubNews => 'Club News',
        self::RaceReports => 'Race Reports',
        self::TrainingTips => 'Training Tips',
        self::Announcements => 'Announcements',
    ];
}
