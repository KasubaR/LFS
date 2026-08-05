<?php

return [
    // Shared temporary password every bulk-imported member starts with. All
    // accounts created by MemberImportService use this one secret — nothing
    // per-user is generated or stored in plaintext. Must be set before an
    // import can run.
    'temp_password' => env('MEMBER_IMPORT_TEMP_PASSWORD'),

    // How many days the shared temp password stays valid for a given member,
    // counted from the moment they're imported. Once it expires, that login
    // is rejected (see AuthenticatedSessionController) and the member must
    // use "Forgot password" to set their own.
    'temp_password_ttl_days' => (int) env('MEMBER_IMPORT_TEMP_PASSWORD_TTL_DAYS', 14),
];
