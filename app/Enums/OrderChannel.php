<?php

namespace App\Enums;

class OrderChannel
{
    public const Online = 'online';

    public const Offline = 'offline';

    /** @var list<string> */
    public const ALL = [self::Online, self::Offline];
}
