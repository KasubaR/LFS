<?php

namespace App\Notifications;

use App\Models\EventTicketOrder;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent when money arrived but the order could not be confirmed automatically
 * (payment_review_required). It must never say the tickets are confirmed.
 */
class EventTicketPaymentReceivedNotification extends Notification
{
    public function __construct(private readonly EventTicketOrder $order) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $order = $this->order->loadMissing('event');

        return (new MailMessage)
            ->subject('We received your payment: '.$order->event->title)
            ->greeting('Payment received, awaiting confirmation')
            ->line('Thank you, '.$order->buyer_name.'. We received your payment of K'.number_format((float) $order->total, 2).' for order '.$order->order_number.', but we could not confirm your tickets automatically.')
            ->line('Your tickets are NOT confirmed yet. The organisers will review this and contact you shortly. If the tickets are no longer available, your payment will be refunded.')
            ->line('Please keep this email and quote your order number if you contact us.');
    }
}
