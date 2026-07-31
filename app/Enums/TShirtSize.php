<?php

namespace App\Enums;

class TShirtSize
{
    public const XS = 'XS';

    public const S = 'S';

    public const M = 'M';

    public const L = 'L';

    public const XL = 'XL';

    public const XXL = '2XL';

    public const XXXL = '3XL';

    public const XXXXL = '4XL';

    /** @var list<string> */
    public const ALL = [
        self::XS,
        self::S,
        self::M,
        self::L,
        self::XL,
        self::XXL,
        self::XXXL,
        self::XXXXL,
    ];

    /**
     * @var array<string, string>
     */
    private const LEGACY_MAP = [
        'x-small' => self::XS,
        'xsmall' => self::XS,
        'xs' => self::XS,
        'small' => self::S,
        's' => self::S,
        'medium' => self::M,
        'm' => self::M,
        'large' => self::L,
        'l' => self::L,
        'x-large' => self::XL,
        'xlarge' => self::XL,
        'xl' => self::XL,
        'xxl' => self::XXL,
        '2xl' => self::XXL,
        '2x-large' => self::XXL,
        '3xl' => self::XXXL,
        '3x-large' => self::XXXL,
        '4xl' => self::XXXXL,
    ];

    public static function normalize(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $trimmed = trim($value);
        if (in_array($trimmed, self::ALL, true)) {
            return $trimmed;
        }

        $key = strtolower(str_replace(' ', '-', $trimmed));

        return self::LEGACY_MAP[$key] ?? null;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_combine(self::ALL, self::ALL);
    }
}
