<?php

namespace App\Enums;

class ProductCategory
{
    public const RunningKits = 'running-kits';

    public const TShirts = 't-shirts';

    public const Caps = 'caps';

    public const Shorts = 'shorts';

    public const Accessories = 'accessories';

    public const Other = 'other';

    /** @var list<string> */
    public const ALL = [
        self::RunningKits,
        self::TShirts,
        self::Caps,
        self::Shorts,
        self::Accessories,
        self::Other,
    ];
}
