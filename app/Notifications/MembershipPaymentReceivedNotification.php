<?php

namespace App\Notifications;

use App\Enums\MembershipPaymentStatus;
use App\Models\Membership;
use App\Models\MembershipPayment;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent when a membership payment (full or an instalment) is confirmed.
 */
class MembershipPaymentReceivedNotification extends Notification
{
    public function __construct(
        private readonly Membership $membership,
        private readonly MembershipPayment $payment,
        private readonly float $chargedAmount,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $total = (float) $this->payment->amount;
        $paid = (float) $this->payment->amount_paid;
        $balance = max(0.0, round($total - $paid, 2));
        $isFull = $this->payment->status === MembershipPaymentStatus::Paid || $balance <= 0;

        $mail = (new MailMessage)
            ->subject($isFull ? 'LFS membership payment received' : 'LFS instalment received')
            ->greeting('Thank you, '.trim((string) ($notifiable->other_names ?? '')).'!')
            ->line('We received your payment of K'.number_format($this->chargedAmount, 2).' for your Lusaka Fitness Squad membership.');

        if ($this->membership->membership_number) {
            $mail->line('Membership number: '.$this->membership->membership_number);
        }
        if ($this->payment->receipt_number) {
            $mail->line('Receipt number: '.$this->payment->receipt_number);
        }

        if ($isFull) {
            $mail->line('Your membership is paid in full'
                .($this->membership->expiry_date ? ' and runs until '.$this->membership->expiry_date->format('j F Y').'.' : '.'));
        } else {
            $mail->line('Paid so far: K'.number_format($paid, 2).' of K'.number_format($total, 2).'. Balance: K'.number_format($balance, 2).'.');
            if ($this->membership->grace_period_ends_at) {
                $mail->line('Please clear the balance by '.$this->membership->grace_period_ends_at->format('j F Y').' to avoid suspension.');
            }
        }

        return $mail
            ->action('View your membership and receipt', url('/account'))
            ->line('If you did not make this payment, please contact us.');
    }
}
