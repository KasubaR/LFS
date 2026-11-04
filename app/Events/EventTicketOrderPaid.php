<?php

namespace App\Events;

use App\Models\EventTicketOrder;

/**
 * Fired exactly once per order, by the worker whose conditional status update
 * moved it to `paid` (including a revived late payment). The confirmation
 * email listener hangs off this; it must guard on confirmation_email_sent_at.
 */
class EventTicketOrderPaid
{
    public function __construct(
        public readonly EventTicketOrder $order,
        public readonly string $source,
        public readonly bool $revived = false,
    ) {}
}
