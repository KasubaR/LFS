<?php

namespace App\Events;

use App\Models\EventTicketOrder;

/**
 * Money was received but the order could not be confirmed automatically
 * (late payment with no capacity, amount mismatch, ...). No tickets are valid.
 */
class EventTicketOrderNeedsReview
{
    public function __construct(
        public readonly EventTicketOrder $order,
        public readonly string $reason,
        public readonly string $source,
    ) {}
}
