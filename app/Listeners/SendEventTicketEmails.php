<?php

namespace App\Listeners;

use App\Events\EventTicketOrderNeedsReview;
use App\Events\EventTicketOrderPaid;
use App\Services\Tickets\TicketConfirmationService;

/**
 * Auto-discovered by Laravel (handle* methods type-hinted with the event).
 * Mail failures are caught inside the service, so a mail outage can never
 * turn a successful payment webhook into an error response.
 */
class SendEventTicketEmails
{
    public function __construct(private readonly TicketConfirmationService $confirmations) {}

    public function handlePaid(EventTicketOrderPaid $event): void
    {
        $this->confirmations->send($event->order);
    }

    public function handleNeedsReview(EventTicketOrderNeedsReview $event): void
    {
        $this->confirmations->sendPaymentReceived($event->order);
    }
}
