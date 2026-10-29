<?php

namespace Tests\Feature\Console;

use App\Models\User;
use App\Notifications\BirthdayGreetingNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SendBirthdayGreetingsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_greets_only_users_whose_birthday_is_today(): void
    {
        Notification::fake();
        $this->travelTo('2026-10-29 07:00:00');

        $today = User::factory()->create(['date_of_birth' => '1990-10-29']);
        $other = User::factory()->create(['date_of_birth' => '1990-10-30']);
        $unknown = User::factory()->create(['date_of_birth' => null]);

        $this->artisan('members:send-birthday-greetings')->assertSuccessful();

        Notification::assertSentTo($today, BirthdayGreetingNotification::class);
        Notification::assertNotSentTo($other, BirthdayGreetingNotification::class);
        Notification::assertNotSentTo($unknown, BirthdayGreetingNotification::class);
    }

    public function test_leap_day_birthdays_are_greeted_on_28_feb_in_non_leap_years(): void
    {
        Notification::fake();
        $this->travelTo('2027-02-28 07:00:00');

        $leap = User::factory()->create(['date_of_birth' => '1992-02-29']);

        $this->artisan('members:send-birthday-greetings')->assertSuccessful();

        Notification::assertSentTo($leap, BirthdayGreetingNotification::class);
    }
}
