<?php

namespace App\Enums;

class BillingCycle
{
    public const Annual = 'annual';

    public const SemiAnnual = 'semi_annual';

    public const Quarterly = 'quarterly';

    /** @var list<string> */
    public const ALL = [
        self::Annual,
        self::SemiAnnual,
        self::Quarterly,
    ];
}
