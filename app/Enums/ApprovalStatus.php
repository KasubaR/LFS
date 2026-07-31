<?php

namespace App\Enums;

class ApprovalStatus
{
    public const Pending = 'pending';

    public const Approved = 'approved';

    public const Rejected = 'rejected';

    /** @var list<string> */
    public const ALL = [
        self::Pending,
        self::Approved,
        self::Rejected,
    ];
}
