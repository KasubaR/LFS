<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

class WelcomeImportedMemberNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $tempPassword,
        private readonly Carbon $tempPasswordExpiresAt,
    ) {}

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
            ->line('Sign in with your email address and this temporary password:')
            ->line('**'.$this->tempPassword.'**')
            ->line('This temporary password works until '.$this->tempPasswordExpiresAt->format('j M Y, H:i').'. '
                .'After that, or as soon as you use it, you\'ll be asked to verify your email and set your own '
                .'permanent password.')
            ->action('Sign in to LFS', url('/login'))
            ->line('If the temporary password has expired or you need help, use "Forgot password" on the sign-in '
                .'page or contact us at info@lfszambia.run.');
    }
}
