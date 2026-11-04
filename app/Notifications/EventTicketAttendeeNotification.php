<?php

namespace App\Notifications;

use App\Models\EventTicket;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to an attendee who was given their own email address. It contains
 * only THEIR ticket: never the other tickets, the buyer's phone, the amount
 * paid, or any payment details.
 */
class EventTicketAttendeeNotification extends Notification
{
    public function __construct(private readonly EventTicket $ticket) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $ticket = $this->ticket->loadMissing(['event', 'order']);
        $event = $ticket->event;

        $mail = (new MailMessage)
            ->subject('Your ticket: '.$event->title)
            ->greeting('Hello '.$ticket->attendee_name)
            ->line($ticket->order->buyer_name.' has bought you a ticket. Show this email at the entrance.')
            ->line('**Event:** '.$event->title)
            ->line('**Date and time:** '.($event->event_date ? $event->event_date->format('l, j F Y \a\t H:i') : 'To be announced'))
            ->line('**Venue:** '.($event->location !== null && $event->location !== '' ? $event->location : 'To be announced'))
            ->line('**Attendee:** '.$ticket->attendee_name)
            ->line('**Ticket reference:** '.$ticket->ticket_reference);

        if ($ticket->is_member_discount) {
            $mail->line('This is a member ticket. Please bring your membership card or a valid ID.');
        }

        return $mail->line('Names cannot be changed after payment.');
    }
}
