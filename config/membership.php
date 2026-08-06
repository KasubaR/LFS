<?php

return [
    'currency' => 'ZMW',
    'membership_number_prefix' => 'LFS',
    'receipt_number_prefix' => 'LFS-RCT',

    // Every membership runs the same Jan 1 - Dec 31 calendar year. Members who
    // register/renew on or before this month-day may pay in installments up to
    // this date; unpaid balances after it trigger suspension (see
    // SuspendUnpaidMembershipsCommand). Registrations after this date must pay
    // the full (possibly late-joiner-reduced) fee upfront — see 'late_joiner'.
    'membership_year' => [
        'grace_period_end_month_day' => '04-30',
    ],

    // Members registering/renewing on or after this month-day only owe this
    // reduced fee for the remainder of the calendar year (see
    // MembershipService::annualFeeDue()).
    'late_joiner' => [
        'cutoff_month_day' => '06-01',
        'fee' => 500.00,
    ],

    // Shown on the header of a member's payment receipt PDF.
    'issuer' => [
        'name' => 'Lusaka Fitness Squad',
        'address' => 'CV-6 COMESA Village, Great East Road, Lusaka, Zambia',
        'phone' => '+260 966 755 326',
        'email' => 'info@lfszambia.run',
    ],
];
