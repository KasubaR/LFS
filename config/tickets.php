<?php

/*
|--------------------------------------------------------------------------
| Event ticketing (see docs/EVENT_TICKETING_PLAN.md)
|--------------------------------------------------------------------------
*/

return [

    // Most tickets one order may contain.
    'max_per_order' => (int) env('TICKETS_MAX_PER_ORDER', 10),

    // How long a pending order holds its tickets and member discount.
    'reservation_minutes' => (int) env('TICKETS_RESERVATION_MINUTES', 15),

    // Payments completed within this many minutes after the reservation
    // expired still count as on time (covers in-flight approvals).
    'late_slack_minutes' => (int) env('TICKETS_LATE_SLACK_MINUTES', 2),

    // The poller stops asking Lenco about a ticket payment this many hours
    // after its reservation expired, if Lenco still reports it non-terminal.
    'poll_give_up_hours' => (int) env('TICKETS_POLL_GIVE_UP_HOURS', 24),

    // Order numbers; the prefix is how shared payment code (webhook, poller)
    // tells a ticket payment from a shop payment.
    'order_prefix' => 'LFSEV-',

];
