<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WelcomeImportedMemberNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Welcome to Lusaka Fitness Squad')
            ->greeting('Hello '.$notifiable->name.'!')
            ->line('Your LFS member account has been created from our membership records.')
            ->line('Please sign in, verify your email address, and set a new password when prompted.')
            ->action('Sign in to LFS', url('/login'))
            ->line('If you need help, contact us at info@lfszambia.run.');
    }
}
