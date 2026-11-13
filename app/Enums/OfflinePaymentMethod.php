<?php

namespace App\Enums;

class OfflinePaymentMethod
{
    public const Cash = 'cash';

    public const MobileMoney = 'mobile_money';

    public const Card = 'card';

    /** @var list<string> */
    public const ALL = [self::Cash, self::MobileMoney, self::Card];

    /** @var array<string, string> */
    public const LABELS = [
        self::Cash => 'Cash',
        self::MobileMoney => 'Mobile money',
        self::Card => 'Card / POS',
    ];
}
