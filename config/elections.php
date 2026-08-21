<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Vote encryption key
    |--------------------------------------------------------------------------
    |
    | Prefer ELECTION_VOTE_KEY (base64-encoded 32 bytes). Falls back to APP_KEY
    | only in local/testing. Production dual-control custody: assemble this key
    | via two custodians for the election window — never store the full key in
    | documentation.
    |
    */
    'vote_key' => env('ELECTION_VOTE_KEY'),

    'ballot_preview_hours' => (int) env('ELECTION_BALLOT_PREVIEW_HOURS', 48),

    'vote_flush_min_seconds' => (int) env('ELECTION_VOTE_FLUSH_MIN_SECONDS', 15),

    'vote_flush_max_seconds' => (int) env('ELECTION_VOTE_FLUSH_MAX_SECONDS', 60),

    'max_proxies_per_holder' => 5,

    'quorum_percent_default' => 50,

    'certifications_required' => 2,

    'roll_template_headers' => [
        'membership_number',
        'email',
        'name',
    ],
];
