<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Admin section permissions by role
    |--------------------------------------------------------------------------
    |
    | Levels: none | read | write
    | write implies read. Sections not listed default to none.
    |
    */

    'sections' => [
        'dashboard',
        'messages',
        'members',
        'events',
        'gallery',
        'blog',
        'faqs',
        'announcements',
        'documents',
        'products',
        'orders',
        'api_clients',
        'admin_users',
    ],

    'matrix' => [
        'super_admin' => [
            'dashboard' => 'write',
            'messages' => 'write',
            'members' => 'write',
            'events' => 'write',
            'gallery' => 'write',
            'blog' => 'write',
            'faqs' => 'write',
            'announcements' => 'write',
            'documents' => 'write',
            'products' => 'write',
            'orders' => 'write',
            'api_clients' => 'write',
            'admin_users' => 'write',
        ],
        'finance' => [
            'dashboard' => 'read',
            'messages' => 'read',
            'orders' => 'write',
        ],
        'membership_officer' => [
            'dashboard' => 'write',
            'messages' => 'write',
            'members' => 'write',
            'announcements' => 'write',
            'documents' => 'write',
        ],
        'events_officer' => [
            'dashboard' => 'write',
            'events' => 'write',
            'gallery' => 'write',
        ],
        'satellite_administrator' => [
            'dashboard' => 'write',
            'members' => 'write',
            'announcements' => 'read',
            'documents' => 'read',
        ],
        'read_only_auditor' => [
            'dashboard' => 'read',
            'messages' => 'read',
            'members' => 'read',
            'events' => 'read',
            'gallery' => 'read',
            'blog' => 'read',
            'faqs' => 'read',
            'announcements' => 'read',
            'documents' => 'read',
            'products' => 'read',
            'orders' => 'read',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Route path prefix → permission section
    |--------------------------------------------------------------------------
    */
    'route_sections' => [
        'dashboard' => 'dashboard',
        'activity' => 'dashboard',
        'messages' => 'messages',
        'members' => 'members',
        'events' => 'events',
        'gallery' => 'gallery',
        'blog' => 'blog',
        'faqs' => 'faqs',
        'announcements' => 'announcements',
        'documents' => 'documents',
        'products' => 'products',
        'shop' => 'products',
        'orders' => 'orders',
        'api-clients' => 'api_clients',
        'users' => 'admin_users',
        'profile' => null, // any authenticated admin
    ],
];
