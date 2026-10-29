<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\BirthdayGreetingNotification;
use Illuminate\Console\Command;

class SendBirthdayGreetingsCommand extends Command
{
    protected $signature = 'members:send-birthday-greetings';

    protected $description = 'Email a birthday greeting to every user whose birthday is today';

    public function handle(): int
    {
        $today = now();

        $users = User::query()
            ->whereNotNull('date_of_birth')
            ->whereMonth('date_of_birth', $today->month)
            ->whereDay('date_of_birth', $today->day)
            ->get();

        // Leap-day birthdays are greeted on 28 Feb in non-leap years.
        if ($today->month === 2 && $today->day === 28 && ! $today->isLeapYear()) {
            $users = $users->merge(
                User::query()
                    ->whereMonth('date_of_birth', 2)
                    ->whereDay('date_of_birth', 29)
                    ->get()
            );
        }

        foreach ($users as $user) {
            $user->notify(new BirthdayGreetingNotification);
        }

        $this->info("Sent {$users->count()} birthday greeting(s).");

        return self::SUCCESS;
    }
}
