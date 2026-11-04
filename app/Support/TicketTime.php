<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * Display times for ticketing in the organisers' local time.
 *
 * The app stores real moments (paid_at, checked_in_at, ...) in UTC, while an
 * event's own date and time are saved exactly as the admin typed them (local
 * wall-clock time). Showing a stored moment without converting it would put it
 * two hours behind Lusaka, so every server-rendered "when did this happen" goes
 * through here. Event dates are NOT converted.
 */
class TicketTime
{
    public static function zone(): string
    {
        return (string) config('tickets.display_timezone', 'Africa/Lusaka');
    }

    public static function local(?CarbonInterface $moment, string $format = 'H:i'): string
    {
        return $moment === null ? '' : $moment->copy()->setTimezone(self::zone())->format($format);
    }
}
