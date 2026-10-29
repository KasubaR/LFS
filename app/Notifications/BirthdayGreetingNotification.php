<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BirthdayGreetingNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Happy Birthday from Lusaka Fitness Squad!')
            ->greeting('Happy Birthday, '.$notifiable->other_names.'!')
            ->line('Everyone at Lusaka Fitness Squad wishes you a wonderful day filled with energy and joy.')
            ->line('Thank you for being part of our community. See you at the next session!');
    }
}
