<?php

return [
    'duration' => [
        'minute' => 60,
        'hour' => 3600,
        'day' => 86400,
        'week' => 604800,
        'month' => 2592000,
        'year' => 31536000,
    ],
    'names' => [
        'auth' => 'lfs_auth',
        'consent' => 'lfs_consent',
        'preferences' => 'lfs_prefs',
        'csrf' => 'lfs_csrf',
    ],
    'default_consent' => [
        'necessary' => true,
        'analytics' => false,
        'preferences' => false,
        'marketing' => false,
    ],
];
