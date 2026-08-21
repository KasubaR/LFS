<?php

namespace App\Enums;

class ElectionType
{
    public const Agm = 'agm';

    public const Egm = 'egm';

    public const ByElection = 'by_election';

    /** @var list<string> */
    public const ALL = [
        self::Agm,
        self::Egm,
        self::ByElection,
    ];

    /**
     * @var array<string, string>
     */
    public const LABELS = [
        self::Agm => 'AGM',
        self::Egm => 'EGM',
        self::ByElection => 'By-election',
    ];

    public static function label(string $type): string
    {
        return self::LABELS[$type] ?? ucfirst(str_replace('_', ' ', $type));
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return self::LABELS;
    }
}
