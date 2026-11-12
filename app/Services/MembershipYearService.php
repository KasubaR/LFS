<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Decides which membership year (a Jan 1 - Dec 31 calendar year) a payment
 * made at a given moment is for. Normally that is the current calendar year,
 * but once an admin sets "next year opens on" (e.g. 1 Nov), anything paid from
 * that date until 31 Dec is for the following year.
 */
class MembershipYearService
{
    private const TIMEZONE = 'Africa/Lusaka';

    private ?Carbon $opensOn = null;

    private bool $loaded = false;

    public function opensOn(): ?Carbon
    {
        if (! $this->loaded) {
            $value = DB::table('membership_settings')->where('id', 1)->value('next_year_opens_on');
            $this->opensOn = $value ? Carbon::parse($value)->startOfDay() : null;
            $this->loaded = true;
        }

        return $this->opensOn;
    }

    public function setOpensOn(?string $date, ?int $adminId = null): void
    {
        DB::table('membership_settings')->updateOrInsert(
            ['id' => 1],
            ['next_year_opens_on' => $date, 'updated_by' => $adminId, 'updated_at' => now(), 'created_at' => now()]
        );

        $this->loaded = false;
    }

    /** Calendar date in Lusaka time, so 11:30pm on 31 Dec is still 31 Dec. */
    public function localDate(?Carbon $at = null): Carbon
    {
        return ($at ?? now())->copy()->setTimezone(self::TIMEZONE)->startOfDay();
    }

    /** The membership year a payment made at $at is for. */
    public function yearFor(?Carbon $at = null): int
    {
        $opensOn = $this->opensOn();

        // With nothing configured, behave exactly as before: the year of the date.
        if ($opensOn === null) {
            return ($at ?? now())->year;
        }

        $day = $this->localDate($at);

        if ( $day->year === $opensOn->year && $day->toDateString() >= $opensOn->toDateString()) {
            return $day->year + 1;
        }

        return $day->year;
    }

    public function isNextYearOpen(?Carbon $at = null): bool
    {
        return $this->yearFor($at) > ($this->opensOn() === null ? ($at ?? now())->year : $this->localDate($at)->year);
    }
}
