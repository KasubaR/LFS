<?php

namespace Tests\Feature\Tickets;

use App\Models\EventTicketOrder;
use App\Services\Tickets\AvailabilityResult;
use App\Services\Tickets\TicketAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesTicketFixtures;
use Tests\TestCase;

class TicketAvailabilityTest extends TestCase
{
    use MakesTicketFixtures;
    use RefreshDatabase;

    private TicketAvailability $availability;

    protected function setUp(): void
    {
        parent::setUp();
        $this->availability = app(TicketAvailability::class);
    }

    public function test_fresh_paid_event_is_available(): void
    {
        $result = $this->availability->check($this->makePaidEvent(), 2);

        $this->assertTrue($result->ok());
        $this->assertSame(100, $result->remaining);
    }

    public function test_non_paid_event_is_not_ticketed(): void
    {
        $event = $this->makePaidEvent(['registration_type' => 'open']);

        $this->assertSame(AvailabilityResult::NOT_TICKETED, $this->availability->check($event)->reason);
    }

    public function test_paid_event_without_prices_is_not_ticketed(): void
    {
        $event = $this->makePaidEvent(['ticket_price_member' => null, 'ticket_price_non_member' => null]);

        $this->assertSame(AvailabilityResult::NOT_TICKETED, $this->availability->check($event)->reason);
    }

    public function test_cancelled_event_is_refused(): void
    {
        $event = $this->makePaidEvent(['ticket_status' => 'cancelled']);

        $this->assertSame(AvailabilityResult::CANCELLED, $this->availability->check($event)->reason);
    }

    public function test_postponed_event_alone_does_not_block_sales(): void
    {
        $event = $this->makePaidEvent(['ticket_status' => 'postponed', 'event_date' => null]);

        $this->assertTrue($this->availability->check($event)->ok());
    }

    public function test_sales_switch_off_closes_sales_even_with_capacity_free(): void
    {
        $event = $this->makePaidEvent(['ticket_sales_open' => false]);

        $result = $this->availability->check($event);
        $this->assertSame(AvailabilityResult::SALES_CLOSED, $result->reason);
        $this->assertSame(100, $this->availability->remainingCapacity($event));
    }

    public function test_sales_not_yet_open(): void
    {
        $event = $this->makePaidEvent(['registration_open' => now()->addDay()]);

        $this->assertSame(AvailabilityResult::NOT_YET_OPEN, $this->availability->check($event)->reason);
    }

    public function test_deadline_passed_closes_sales_automatically(): void
    {
        $event = $this->makePaidEvent(['registration_close' => now()->subMinute()]);

        $this->assertSame(AvailabilityResult::DEADLINE_PASSED, $this->availability->check($event)->reason);
    }

    public function test_event_already_over(): void
    {
        $event = $this->makePaidEvent(['event_date' => now()->subDay()]);

        $this->assertSame(AvailabilityResult::EVENT_OVER, $this->availability->check($event)->reason);
    }

    public function test_quantity_must_be_between_one_and_the_order_limit(): void
    {
        $event = $this->makePaidEvent();

        $this->assertSame(AvailabilityResult::INVALID_QUANTITY, $this->availability->check($event, 0)->reason);
        $this->assertSame(AvailabilityResult::INVALID_QUANTITY, $this->availability->check($event, -3)->reason);
        $this->assertTrue($this->availability->check($event, 10)->ok());
        $this->assertSame(AvailabilityResult::OVER_ORDER_LIMIT, $this->availability->check($event, 11)->reason);
    }

    public function test_order_limit_follows_config(): void
    {
        config(['tickets.max_per_order' => 4]);
        $event = $this->makePaidEvent();

        $this->assertTrue($this->availability->check($event, 4)->ok());
        $this->assertSame(AvailabilityResult::OVER_ORDER_LIMIT, $this->availability->check($event, 5)->reason);
    }

    public function test_unlimited_capacity_has_no_capacity_restriction(): void
    {
        $event = $this->makePaidEvent(['ticket_capacity' => null]);
        $this->makeTicketOrder($event, EventTicketOrder::STATUS_PAID, 500);

        $result = $this->availability->check($event, 10);
        $this->assertTrue($result->ok());
        $this->assertNull($result->remaining);
    }

    public function test_sold_out_closes_sales_automatically(): void
    {
        $event = $this->makePaidEvent(['ticket_capacity' => 100]);
        $this->makeTicketOrder($event, EventTicketOrder::STATUS_PAID, 100);

        $result = $this->availability->check($event);
        $this->assertSame(AvailabilityResult::SOLD_OUT, $result->reason);
        $this->assertSame(0, $result->remaining);
    }

    public function test_only_n_left_when_quantity_exceeds_remaining(): void
    {
        $event = $this->makePaidEvent(['ticket_capacity' => 5]);
        $this->makeTicketOrder($event, EventTicketOrder::STATUS_PAID, 3);

        $result = $this->availability->check($event, 3);
        $this->assertSame(AvailabilityResult::ONLY_N_LEFT, $result->reason);
        $this->assertSame(2, $result->remaining);
        $this->assertStringContainsString('Only 2 ticket(s) left', $result->message());
        $this->assertTrue($this->availability->check($event, 2)->ok());
    }

    public function test_capacity_counts_paid_and_live_pending_only(): void
    {
        $event = $this->makePaidEvent(['ticket_capacity' => 100]);

        $this->makeTicketOrder($event, EventTicketOrder::STATUS_PAID, 10);
        $this->makeTicketOrder($event, EventTicketOrder::STATUS_PAYMENT_REVIEW_REQUIRED, 5);
        $this->makeTicketOrder($event, EventTicketOrder::STATUS_PENDING_PAYMENT, 2, ['expires_at' => now()->addMinutes(5)]);
        // These must release their tickets.
        $this->makeTicketOrder($event, EventTicketOrder::STATUS_PENDING_PAYMENT, 30, ['expires_at' => now()->subSecond()]);
        $this->makeTicketOrder($event, EventTicketOrder::STATUS_FAILED, 30);
        $this->makeTicketOrder($event, EventTicketOrder::STATUS_CANCELLED, 30);

        // 10 paid + 2 live pending. The review order (5) holds no capacity.
        $this->assertSame(12, $this->availability->committedTickets($event->id));
        $this->assertSame(88, $this->availability->remainingCapacity($event));
    }

    public function test_pending_order_without_expiry_does_not_hold_capacity(): void
    {
        $event = $this->makePaidEvent(['ticket_capacity' => 10]);
        $this->makeTicketOrder($event, EventTicketOrder::STATUS_PENDING_PAYMENT, 10, ['expires_at' => null]);

        $this->assertSame(0, $this->availability->committedTickets($event->id));
    }

    public function test_capacity_is_per_event(): void
    {
        $a = $this->makePaidEvent(['ticket_capacity' => 5]);
        $b = $this->makePaidEvent(['ticket_capacity' => 5]);
        $this->makeTicketOrder($a, EventTicketOrder::STATUS_PAID, 5);

        $this->assertSame(AvailabilityResult::SOLD_OUT, $this->availability->check($a)->reason);
        $this->assertTrue($this->availability->check($b, 5)->ok());
    }

    public function test_every_failure_has_a_specific_human_message(): void
    {
        foreach ([
            AvailabilityResult::NOT_TICKETED, AvailabilityResult::CANCELLED, AvailabilityResult::SALES_CLOSED,
            AvailabilityResult::NOT_YET_OPEN, AvailabilityResult::DEADLINE_PASSED, AvailabilityResult::EVENT_OVER,
            AvailabilityResult::INVALID_QUANTITY, AvailabilityResult::OVER_ORDER_LIMIT, AvailabilityResult::SOLD_OUT,
        ] as $reason) {
            $msg = (new AvailabilityResult($reason))->message();
            $this->assertNotSame('Tickets are not available.', $msg, $reason.' has no specific message');
        }
    }

    // Note: the "lockEvent() outside a transaction throws" guard can't be
    // exercised here, because RefreshDatabase already wraps every test in one.

    public function test_lock_event_returns_fresh_row_inside_a_transaction(): void
    {
        $event = $this->makePaidEvent(['ticket_capacity' => 10]);
        // Another request changes capacity after this one loaded its copy.
        $event->newQuery()->whereKey($event->id)->update(['ticket_capacity' => 1]);

        $locked = DB::transaction(fn () => $this->availability->lockEvent($event->id));

        $this->assertSame(1, $locked->ticket_capacity);
        $this->assertSame($event->id, $locked->id);
    }

    public function test_reserving_the_last_tickets_in_a_transaction_blocks_the_second_buyer(): void
    {
        $event = $this->makePaidEvent(['ticket_capacity' => 2]);

        $reserve = function (string $label) use ($event): string {
            return DB::transaction(function () use ($event, $label): string {
                $locked = $this->availability->lockEvent($event->id);
                $check = $this->availability->check($locked, 2);
                if (! $check->ok()) {
                    return $check->reason;
                }

                $this->makeTicketOrder($locked, EventTicketOrder::STATUS_PENDING_PAYMENT, 2, [
                    'buyer_name' => $label,
                    'expires_at' => now()->addMinutes(15),
                ]);

                return 'reserved';
            });
        };

        $this->assertSame('reserved', $reserve('first'));
        $this->assertSame(AvailabilityResult::SOLD_OUT, $reserve('second'));
        $this->assertSame(1, EventTicketOrder::query()->where('event_id', $event->id)->count());
    }
}
