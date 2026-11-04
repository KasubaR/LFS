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

];
