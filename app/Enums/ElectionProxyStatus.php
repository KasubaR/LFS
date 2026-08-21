<?php

namespace App\Enums;

class ElectionProxyStatus
{
    public const Pending = 'pending';

    public const Approved = 'approved';

    public const Revoked = 'revoked';

    /** @var list<string> */
    public const ALL = [
        self::Pending,
        self::Approved,
        self::Revoked,
    ];
}
