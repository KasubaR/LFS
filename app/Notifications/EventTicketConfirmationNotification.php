<?php

namespace App\Notifications;

use App\Models\EventTicketOrder;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/**
 * The confirmation email IS the ticket: it is shown at the entrance. There
 * are no QR codes or PDFs. It is sent to the buyer and carries the whole
 * order; attendees with their own email get a separate, smaller message
 * ({@see EventTicketAttendeeNotification}).
 *
 * Sent synchronously (not queued) on purpose: the sender stamps
 * confirmation_email_sent_at only after this succeeds, so a failure is
 * retried by the poller instead of being silently lost on a queue.
 */
class EventTicketConfirmationNotification extends Notification
{
    public function __construct(private readonly EventTicketOrder $order) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $order = $this->order->loadMissing(['event', 'tickets']);
        $event = $order->event;

        $mail = (new MailMessage)
            ->subject('Your tickets: '.$event->title)
            ->greeting('Payment successful')
            ->line('Thank you, '.$order->buyer_name.'. Your payment has been received and your tickets are confirmed. Show this email at the entrance.')
            ->line('**Event:** '.$event->title)
            ->line('**Date and time:** '.($event->event_date ? $event->event_date->format('l, j F Y \a\t H:i') : 'To be announced'))
            ->line('**Venue:** '.($event->location !== null && $event->location !== '' ? $event->location : 'To be announced'))
            ->line('**Buyer:** '.$order->buyer_name)
            ->line('**Number of tickets:** '.$order->quantity);

        foreach ($order->tickets as $ticket) {
            $note = $ticket->is_member_discount ? ' (member ticket, membership no. '.$ticket->membership_number.')' : '';
            $mail->line(sprintf(
                '**Ticket %d:** %s — ref %s — K%s%s',
                $ticket->position,
                $ticket->attendee_name,
                $ticket->ticket_reference,
                number_format((float) $ticket->price, 2),
                $note
            ));
        }

        $payment = \App\Models\Payment::query()->where('order_number', $order->order_number)->first();

        $mail->line('**Total paid:** K'.number_format((float) $order->total, 2))
            ->line('**Payment reference:** '.($payment?->transaction_id ?? 'n/a'))
            ->line('**Order number:** '.$order->order_number)
            ->action('View your order', URL::temporarySignedRoute('tickets.show', now()->addDays(120), ['orderNumber' => $order->order_number]))
            ->line('Names cannot be changed after payment. If something is wrong, contact the organisers before the event.');

        if ($order->tickets->contains('is_member_discount', true)) {
            $mail->line('The member ticket is personal. Please bring your membership card or a valid ID.');
        }

        return $mail;
    }
}
