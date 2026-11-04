<?php

namespace App\Services\Tickets;

use App\Models\EventTicketOrder;

/**
 * What the checkout needs after an order is placed: the order plus the
 * "approve on your phone" details from the gateway.
 */
final class TicketOrderResult
{
    public function __construct(
        public readonly EventTicketOrder $order,
        public readonly ?string $paymentInstructions,
        public readonly ?string $paymentUrl,
        public readonly ?string $lencoStatus,
        /** True when an earlier identical submission was returned instead of creating a new order. */
        public readonly bool $resumed = false,
    ) {}
}
