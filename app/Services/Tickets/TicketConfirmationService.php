<?php

namespace App\Services\Tickets;

use App\Models\EventTicketOrder;
use App\Notifications\EventTicketAttendeeNotification;
use App\Notifications\EventTicketConfirmationNotification;
use App\Notifications\EventTicketPaymentReceivedNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Sends the emails that act as the ticket.
 *
 * send() is exactly-once per order: it first claims the order by stamping
 * confirmation_email_sent_at with a conditional UPDATE (so two workers can't
 * both send), and releases the claim if sending fails so the poller retries.
 */
class TicketConfirmationService
{
    /**
     * Sends the buyer's confirmation (and any attendee emails) for a paid order.
     * Returns true if this call sent it, false if already sent / not payable / failed.
     */
    public function send(EventTicketOrder $order): bool
    {
        $claimed = EventTicketOrder::query()
            ->whereKey($order->id)
            ->where('status', EventTicketOrder::STATUS_PAID)
            ->whereNull('confirmation_email_sent_at')
            ->update(['confirmation_email_sent_at' => now()]) === 1;

        if (! $claimed) {
            return false;
        }

        try {
            $this->deliver($order->fresh());

            return true;
        } catch (Throwable $e) {
            // Release the claim so the poller retries instead of losing the email.
            EventTicketOrder::query()->whereKey($order->id)->update(['confirmation_email_sent_at' => null]);
            Log::error('[TicketConfirmation] Could not send confirmation email', [
                'orderNumber' => $order->order_number,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Re-sends the buyer's copy on request ("Find my tickets"). Paid orders only.
     * Never changes any detail of the order and never goes to a new address.
     */
    public function resend(EventTicketOrder $order): bool
    {
        if ($order->status !== EventTicketOrder::STATUS_PAID) {
            return false;
        }

        try {
            Notification::route('mail', $order->buyer_email)->notify(new EventTicketConfirmationNotification($order));

            return true;
        } catch (Throwable $e) {
            Log::error('[TicketConfirmation] Could not resend confirmation email', [
                'orderNumber' => $order->order_number,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Tells the buyer their payment arrived but tickets are not confirmed.
     */
    public function sendPaymentReceived(EventTicketOrder $order): void
    {
        try {
            Notification::route('mail', $order->buyer_email)->notify(new EventTicketPaymentReceivedNotification($order));
        } catch (Throwable $e) {
            Log::error('[TicketConfirmation] Could not send payment-received email', [
                'orderNumber' => $order->order_number,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Paid orders whose email never went out (mail outage): retry them.
     *
     * @return int emails sent
     */
    public function retryUnsent(int $limit = 20): int
    {
        $sent = 0;

        EventTicketOrder::query()
            ->where('status', EventTicketOrder::STATUS_PAID)
            ->whereNull('confirmation_email_sent_at')
            ->where('paid_at', '<', now()->subMinutes(2))
            ->limit($limit)
            ->get()
            ->each(function (EventTicketOrder $order) use (&$sent): void {
                if ($this->send($order)) {
                    $sent++;
                }
            });

        return $sent;
    }

    private function deliver(EventTicketOrder $order): void
    {
        $order->load(['event', 'tickets']);

        Notification::route('mail', $order->buyer_email)->notify(new EventTicketConfirmationNotification($order));

        // Attendees with their own email get only their own ticket.
        foreach ($order->tickets as $ticket) {
            if ($ticket->attendee_email !== null && strcasecmp($ticket->attendee_email, $order->buyer_email) !== 0) {
                Notification::route('mail', $ticket->attendee_email)->notify(new EventTicketAttendeeNotification($ticket));
            }
        }
    }
}
