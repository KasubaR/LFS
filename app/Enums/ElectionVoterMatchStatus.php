<?php

namespace App\Enums;

class ElectionVoterMatchStatus
{
    public const Matched = 'matched';

    public const Unmatched = 'unmatched';

    public const Ambiguous = 'ambiguous';

    /** @var list<string> */
    public const ALL = [
        self::Matched,
        self::Unmatched,
        self::Ambiguous,
    ];
}
