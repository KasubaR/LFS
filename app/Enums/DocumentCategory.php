<?php

namespace App\Enums;

class DocumentCategory
{
    public const Constitution = 'constitution';

    public const Policies = 'policies';

    public const Forms = 'forms';

    public const AnnualReports = 'annual_reports';

    public const MeetingMinutes = 'meeting_minutes';

    public const TrainingMaterial = 'training_material';

    /** @var list<string> */
    public const ALL = [
        self::Constitution,
        self::Policies,
        self::Forms,
        self::AnnualReports,
        self::MeetingMinutes,
        self::TrainingMaterial,
    ];

    /**
     * @var array<string, string>
     */
    public const LABELS = [
        self::Constitution => 'Constitution',
        self::Policies => 'Policies',
        self::Forms => 'Forms',
        self::AnnualReports => 'Annual reports',
        self::MeetingMinutes => 'Meeting minutes',
        self::TrainingMaterial => 'Training material',
    ];

    public static function label(string $category): string
    {
        return self::LABELS[$category] ?? ucfirst(str_replace('_', ' ', $category));
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return self::LABELS;
    }
}
