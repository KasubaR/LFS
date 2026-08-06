<?php

namespace App\Enums;

class PromotionDiscountType
{
    public const Percentage = 'percentage';

    public const Fixed = 'fixed';

    /** @var list<string> */
    public const ALL = [
        self::Percentage,
        self::Fixed,
    ];
}
