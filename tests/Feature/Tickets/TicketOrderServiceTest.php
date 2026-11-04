<?php

namespace Tests\Feature\Tickets;

use App\Exceptions\LencoApiException;
use App\Exceptions\TicketOrderException;
use App\Models\EventTicket;
use App\Models\EventTicketOrder;
use App\Models\Payment;
use App\Services\LencoService;
use App\Services\Tickets\AvailabilityResult;
use App\Services\Tickets\TicketPricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\MakesTicketFixtures;
use Tests\TestCase;

class TicketOrderServiceTest extends TestCase
{
    use MakesTicketFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMembershipBasics();
    }

    // ── Happy paths ──────────────────────────────────────────────────────────

    public function test_guest_order_creates_pending_order_tickets_and_a_write_ahead_payment(): void
    {
        $this->mockLencoInitiation();
        $event = $this->makePaidEvent();

        $result = $this->placeTicketOrder($event, [
            'quantity' => 2,
            'attendees' => [['name' => 'John Banda'], ['name' => 'Mary Phiri']],
        ]);

        $order = $result->order;
        $this->assertSame(EventTicketOrder::STATUS_PENDING_PAYMENT, $order->status);
        $this->assertStringStartsWith('LFSEV-', $order->order_number);
        $this->assertSame(2, $order->quantity);
        $this->assertSame('1300.00', (string) $order->total);
        $this->assertFalse($order->is_member_pricing);
        $this->assertNull($order->user_id);
        $this->assertSame('Approve the payment on your phone.', $result->paymentInstructions);

        // Reservation window is set once, 15 minutes by default.
        $this->assertEqualsWithDelta(15, now()->diffInMinutes($order->expires_at, false), 1);

        $tickets = $order->tickets;
        $this->assertSame(['John Banda', 'Mary Phiri'], $tickets->pluck('attendee_name')->all());
        $this->assertSame([$order->order_number.'-1', $order->order_number.'-2'], $tickets->pluck('ticket_reference')->all());
        $this->assertSame([false, false], $tickets->pluck('is_member_discount')->all());

        // Payment row was written with OUR reference, then given Lenco's details.
        $payment = $this->paymentFor($order);
        $this->assertSame('pending', $payment->status);
        $this->assertStringStartsWith('LFS-'.$order->order_number, $payment->transaction_id);
        $this->assertNotNull($payment->lenco_transaction_id);
        $this->assertSame('mtn', $payment->lenco_provider);
        $this->assertSame('1300.00', (string) $payment->amount);
        $this->assertSame('0971111111', $payment->metadata['payerPhone']);
    }

    public function test_lenco_is_called_with_the_reference_already_saved_on_the_payment(): void
    {
        $seen = null;
        $this->lencoPartial(function ($mock) use (&$seen): void {
            $mock->shouldReceive('initiateMobileMoneyPayment')->once()->andReturnUsing(
                function (array $order, string $phone, string $op, ?string $reference = null) use (&$seen): array {
                    // At the moment of the gateway call, the row must already exist.
                    $seen = [
                        'reference' => $reference,
                        'rowExists' => Payment::query()->where('transaction_id', $reference)->exists(),
                        'phone' => $phone,
                        'operator' => $op,
                        'amount' => $order['totals']['total'],
                    ];

                    return ['transactionId' => 'T1', 'lencoReference' => 'L1', 'reference' => $reference,
                        'status' => 'pending', 'internalStatus' => 'pending', 'rawResponse' => []];
                }
            );
        });

        $this->placeTicketOrder($this->makePaidEvent(), ['payerPhone' => '+260971234567', 'provider' => 'airtel']);

        $this->assertNotNull($seen['reference']);
        $this->assertTrue($seen['rowExists'], 'payment row must be written BEFORE the gateway call');
        $this->assertSame('+260971234567', $seen['phone']);
        $this->assertSame('airtel', $seen['operator']);
        $this->assertSame(650.0, $seen['amount']);
    }

    public function test_active_member_buying_three_gets_one_discounted_ticket_bound_to_the_record(): void
    {
        $this->mockLencoInitiation();
        $m = $this->makeMember('Chanda Mwale', 'chanda@example.com', [], 'LFS-000412');
        $event = $this->makePaidEvent();

        $order = $this->placeTicketOrder($event, [
            'buyerName' => 'Chanda Mwale',
            'buyerEmail' => 'chanda@example.com',
            'quantity' => 3,
            // Slot 0 left blank: filled from the membership record.
            'attendees' => [['name' => ''], ['name' => 'Friend One'], ['name' => 'Friend Two']],
        ], $m->user)->order;

        $this->assertTrue($order->is_member_pricing);
        $this->assertSame($m->id, $order->membership_id);
        $this->assertSame($m->user_id, $order->user_id);
        $this->assertSame('1850.00', (string) $order->total);
        $this->assertSame('550.00', (string) $order->member_unit_price);

        [$first, $second, $third] = $order->tickets->all();
        $this->assertSame('Chanda Mwale', $first->attendee_name);
        $this->assertTrue($first->is_member_discount);
        $this->assertSame($m->id, $first->membership_id);
        $this->assertSame('LFS-000412', $first->membership_number);
        $this->assertSame('550.00', (string) $first->price);

        foreach ([$second, $third] as $guest) {
            $this->assertFalse($guest->is_member_discount);
            $this->assertNull($guest->membership_id);
            $this->assertSame('650.00', (string) $guest->price);
        }
        $this->assertSame(1, EventTicket::query()->where('order_id', $order->id)->where('is_member_discount', true)->count());
    }

    public function test_guest_can_claim_the_member_price_with_membership_number_and_email(): void
    {
        $this->mockLencoInitiation();
        $this->makeMember('Chanda Mwale', 'chanda@example.com', [], 'LFS-000412');

        $order = $this->placeTicketOrder($this->makePaidEvent(), [
            'buyerName' => 'Someone Typing',
            'attendees' => [['name' => '']],
            'membershipNumber' => 'LFS-000412',
            'membershipEmail' => 'chanda@example.com',
        ])->order;

        $this->assertTrue($order->is_member_pricing);
        $this->assertNull($order->user_id);
        $this->assertSame('Chanda Mwale', $order->tickets->first()->attendee_name, 'name comes from the record, not the form');
        $this->assertSame('550.00', (string) $order->total);
    }

    public function test_member_not_attending_pays_standard_price_and_keeps_the_discount(): void
    {
        $this->mockLencoInitiation();
        $m = $this->makeMember();
        $event = $this->makePaidEvent();

        $order = $this->placeTicketOrder($event, [
            'quantity' => 2,
            'buyerAttending' => false,
            'attendees' => [['name' => 'Guest One'], ['name' => 'Guest Two']],
        ], $m->user)->order;

        $this->assertFalse($order->is_member_pricing);
        $this->assertNull($order->membership_id);
        $this->assertSame('1300.00', (string) $order->total);
        $this->assertSame(0, EventTicket::query()->where('is_member_discount', true)->count());

        // The discount was not consumed: a later order of their own still gets it.
        $later = $this->placeTicketOrder($event, ['attendees' => [['name' => '']]], $m->user)->order;
        $this->assertTrue($later->is_member_pricing);
    }

    public function test_buyer_email_is_never_copied_to_attendees(): void
    {
        $this->mockLencoInitiation();

        $order = $this->placeTicketOrder($this->makePaidEvent(), [
            'quantity' => 2,
            'attendees' => [['name' => 'John Banda'], ['name' => 'Mary Phiri', 'email' => 'Mary@Example.com']],
        ])->order;

        $this->assertSame('john@example.com', $order->buyer_email);
        $this->assertNull($order->tickets[0]->attendee_email);
        $this->assertSame('mary@example.com', $order->tickets[1]->attendee_email);
    }

    public function test_blank_buyer_slot_defaults_to_the_buyer_name_for_a_non_member(): void
    {
        $this->mockLencoInitiation();

        $order = $this->placeTicketOrder($this->makePaidEvent(), ['attendees' => [['name' => '']]])->order;

        $this->assertSame('John Banda', $order->tickets->first()->attendee_name);
    }

    // ── Member name is bound to the record ───────────────────────────────────

    public function test_a_different_name_on_the_member_ticket_is_rejected_not_substituted(): void
    {
        $this->mockLencoInitiation();
        $m = $this->makeMember('Chanda Mwale', 'chanda@example.com');
        $event = $this->makePaidEvent();

        try {
            $this->placeTicketOrder($event, ['attendees' => [['name' => 'A Friend']]], $m->user);
            $this->fail('expected the order to be refused');
        } catch (TicketOrderException $e) {
            $this->assertSame(TicketOrderException::MEMBER_NAME_MISMATCH, $e->reason);
        }

        $this->assertSame(0, EventTicketOrder::query()->count(), 'nothing may be created');
        $this->assertSame(0, Payment::query()->count());
    }

    public function test_the_record_name_is_accepted_case_and_spacing_insensitively(): void
    {
        $this->mockLencoInitiation();
        $m = $this->makeMember('Chanda Mwale', 'chanda@example.com');

        $order = $this->placeTicketOrder($this->makePaidEvent(), ['attendees' => [['name' => '  chanda   MWALE ']]], $m->user)->order;

        $this->assertSame('Chanda Mwale', $order->tickets->first()->attendee_name);
    }

    // ── Validation ───────────────────────────────────────────────────────────

    public function test_invalid_input_is_rejected_with_validation_errors(): void
    {
        $this->mockLencoInitiation();
        $event = $this->makePaidEvent();

        $cases = [
            'zero quantity' => ['quantity' => 0, 'attendees' => []],
            'eleven tickets' => ['quantity' => 11, 'attendees' => array_fill(0, 11, ['name' => 'Some Person'])],
            'negative quantity' => ['quantity' => -2],
            'non numeric quantity' => ['quantity' => 'lots'],
            'attendee count mismatch' => ['quantity' => 2, 'attendees' => [['name' => 'Only One']]],
            'blank second attendee' => ['quantity' => 2, 'attendees' => [['name' => 'John Banda'], ['name' => '']]],
            'one letter attendee' => ['quantity' => 2, 'attendees' => [['name' => 'John Banda'], ['name' => 'X']]],
            'bad phone' => ['payerPhone' => '12ab'],
            'bad provider' => ['provider' => 'zamtel'],
            'bad buyer email' => ['buyerEmail' => 'not-an-email'],
            'missing buyer name' => ['buyerName' => ''],
            'bad attendee email' => ['attendees' => [['name' => 'John Banda', 'email' => 'nope']]],
        ];

        foreach ($cases as $label => $override) {
            try {
                $this->placeTicketOrder($event, $override);
                $this->fail("expected a validation error for: {$label}");
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(0, EventTicketOrder::query()->count());
    }

    public function test_not_attending_buyer_must_name_every_attendee(): void
    {
        $this->mockLencoInitiation();

        $this->expectException(ValidationException::class);
        $this->placeTicketOrder($this->makePaidEvent(), ['buyerAttending' => false, 'attendees' => [['name' => '']]]);
    }

    // ── Pricing and availability enforced at the order endpoint ──────────────

    public function test_price_changed_since_the_buyer_saw_it_blocks_the_charge(): void
    {
        $this->mockLencoInitiation();
        $event = $this->makePaidEvent();

        try {
            // The buyer saw K550 (member price) but is now a guest at K650.
            $this->placeTicketOrder($event, ['expectedTotal' => 550]);
            $this->fail('expected PRICE_CHANGED');
        } catch (TicketOrderException $e) {
            $this->assertSame(TicketOrderException::PRICE_CHANGED, $e->reason);
            $this->assertSame(65000, $e->pricing->totalNgwee, 'the new total is returned for reconfirmation');
        }

        $this->assertSame(0, EventTicketOrder::query()->count());
        $this->assertSame(0, Payment::query()->count());
    }

    public function test_matching_expected_total_is_accepted(): void
    {
        $this->mockLencoInitiation();

        $order = $this->placeTicketOrder($this->makePaidEvent(), ['expectedTotal' => 650])->order;

        $this->assertSame('650.00', (string) $order->total);
    }

    public function test_sold_out_event_is_refused_with_the_specific_reason(): void
    {
        $this->mockLencoInitiation();
        $event = $this->makePaidEvent(['ticket_capacity' => 1]);
        $this->makeTicketOrder($event, EventTicketOrder::STATUS_PAID, 1);

        try {
            $this->placeTicketOrder($event);
            $this->fail('expected UNAVAILABLE');
        } catch (TicketOrderException $e) {
            $this->assertSame(TicketOrderException::UNAVAILABLE, $e->reason);
            $this->assertSame(AvailabilityResult::SOLD_OUT, $e->availabilityReason);
        }
    }

    public function test_closed_sales_and_passed_deadline_are_enforced_server_side(): void
    {
        $this->mockLencoInitiation();

        foreach ([
            [['ticket_sales_open' => false], AvailabilityResult::SALES_CLOSED],
            [['registration_close' => now()->subMinute()], AvailabilityResult::DEADLINE_PASSED],
            [['ticket_status' => 'cancelled'], AvailabilityResult::CANCELLED],
        ] as [$state, $reason]) {
            try {
                $this->placeTicketOrder($this->makePaidEvent($state));
                $this->fail("expected {$reason}");
            } catch (TicketOrderException $e) {
                $this->assertSame($reason, $e->availabilityReason);
            }
        }
    }

    public function test_last_tickets_cannot_be_sold_twice(): void
    {
        $this->mockLencoInitiation();
        $event = $this->makePaidEvent(['ticket_capacity' => 2]);
        $two = ['quantity' => 2, 'attendees' => [['name' => 'Person One'], ['name' => 'Person Two']]];

        $this->placeTicketOrder($event, $two);

        try {
            $this->placeTicketOrder($event, array_merge($two, ['buyerEmail' => 'second@example.com']));
            $this->fail('second buyer should be refused');
        } catch (TicketOrderException $e) {
            $this->assertSame(AvailabilityResult::SOLD_OUT, $e->availabilityReason);
        }

        $this->assertSame(1, EventTicketOrder::query()->count());
    }

    public function test_second_order_by_the_same_member_gets_no_second_discount(): void
    {
        $this->mockLencoInitiation();
        $m = $this->makeMember('Chanda Mwale', 'chanda@example.com', [], 'LFS-000412');
        $event = $this->makePaidEvent();

        $first = $this->placeTicketOrder($event, ['attendees' => [['name' => '']]], $m->user)->order;
        $this->assertTrue($first->is_member_pricing);

        // Same member again: logged in, and as a logged-out guest with the number.
        $second = $this->placeTicketOrder($event, ['buyerEmail' => 'b@example.com', 'attendees' => [['name' => 'Someone Else']]], $m->user)->order;
        $third = $this->placeTicketOrder($event, [
            'buyerEmail' => 'c@example.com',
            'attendees' => [['name' => 'Another Person']],
            'membershipNumber' => 'LFS-000412',
            'membershipEmail' => 'chanda@example.com',
        ])->order;

        foreach ([$second, $third] as $o) {
            $this->assertFalse($o->is_member_pricing);
            $this->assertSame('650.00', (string) $o->total);
        }
        $this->assertSame(1, EventTicket::query()->where('is_member_discount', true)->count());
    }

    // ── Idempotency ──────────────────────────────────────────────────────────

    public function test_repeat_submission_with_the_same_token_returns_the_same_order_and_charges_once(): void
    {
        $calls = 0;
        $this->lencoPartial(function ($mock) use (&$calls): void {
            $mock->shouldReceive('initiateMobileMoneyPayment')->andReturnUsing(
                function (array $order, string $phone, string $op, ?string $reference = null) use (&$calls): array {
                    $calls++;

                    return ['transactionId' => 'T'.$calls, 'lencoReference' => 'L'.$calls, 'reference' => $reference,
                        'status' => 'pending', 'internalStatus' => 'pending', 'paymentInstructions' => 'Check your phone', 'rawResponse' => []];
                }
            );
        });
        $event = $this->makePaidEvent();

        $first = $this->placeTicketOrder($event, ['idempotencyToken' => 'page-load-abc']);
        $again = $this->placeTicketOrder($event, ['idempotencyToken' => 'page-load-abc']);

        $this->assertFalse($first->resumed);
        $this->assertTrue($again->resumed);
        $this->assertSame($first->order->id, $again->order->id);
        $this->assertSame('Check your phone', $again->paymentInstructions);
        $this->assertSame(1, $calls, 'only one mobile money prompt');
        $this->assertSame(1, EventTicketOrder::query()->count());
        $this->assertSame(1, Payment::query()->count());
    }

    // ── Gateway failures ─────────────────────────────────────────────────────

    public function test_lenco_refusal_fails_the_order_and_releases_capacity_and_discount(): void
    {
        $this->lencoPartial(function ($mock): void {
            $mock->shouldReceive('initiateMobileMoneyPayment')
                ->andThrow(new LencoApiException('Invalid phone number', 422, false));
        });
        $m = $this->makeMember();
        $event = $this->makePaidEvent(['ticket_capacity' => 1]);

        try {
            $this->placeTicketOrder($event, ['attendees' => [['name' => '']]], $m->user);
            $this->fail('expected PAYMENT_FAILED');
        } catch (TicketOrderException $e) {
            $this->assertSame(TicketOrderException::PAYMENT_FAILED, $e->reason);
            $this->assertSame('Invalid phone number', $e->getMessage());
        }

        $order = EventTicketOrder::query()->firstOrFail();
        $this->assertSame(EventTicketOrder::STATUS_FAILED, $order->status);
        $this->assertSame('failed', $this->paymentFor($order)->status);

        // Capacity and the discount are free again for a retry.
        $this->mockLencoInitiation();
        $retry = $this->placeTicketOrder($event, ['attendees' => [['name' => '']]], $m->user)->order;
        $this->assertTrue($retry->is_member_pricing);
    }

    public function test_lenco_outage_keeps_the_reservation_and_tells_the_buyer_not_to_retry(): void
    {
        foreach ([new LencoApiException('HTTP error: timeout', 0, true), new LencoApiException('Bad gateway', 502, true)] as $outage) {
            $this->lencoPartial(function ($mock) use ($outage): void {
                $mock->shouldReceive('initiateMobileMoneyPayment')->andThrow($outage);
            });
            $event = $this->makePaidEvent(['ticket_capacity' => 1]);

            try {
                $this->placeTicketOrder($event);
                $this->fail('expected PAYMENT_UNKNOWN');
            } catch (TicketOrderException $e) {
                $this->assertSame(TicketOrderException::PAYMENT_UNKNOWN, $e->reason);
                $this->assertStringContainsString('before trying again', $e->getMessage());
                $this->assertNotNull($e->order);
            }

            $order = EventTicketOrder::query()->where('event_id', $event->id)->firstOrFail();
            $this->assertSame(EventTicketOrder::STATUS_PENDING_PAYMENT, $order->status, 'reservation is kept');
            $this->assertSame('pending', $this->paymentFor($order)->status);
            $this->assertStringStartsWith('LFS-'.$order->order_number, $this->paymentFor($order)->transaction_id, 'findable by our reference');

            // Capacity stays held, so a second buyer cannot take the ticket.
            $this->mockLencoInitiation();
            try {
                $this->placeTicketOrder($event, ['buyerEmail' => 'other@example.com']);
                $this->fail('capacity should still be held');
            } catch (TicketOrderException $e) {
                $this->assertSame(AvailabilityResult::SOLD_OUT, $e->availabilityReason);
            }
        }
    }

    public function test_unexpected_gateway_error_fails_the_order_cleanly(): void
    {
        $this->lencoPartial(function ($mock): void {
            $mock->shouldReceive('initiateMobileMoneyPayment')->andThrow(new \RuntimeException('LENCO_API_SECRET_KEY environment variable is not set.'));
        });

        $this->expectException(TicketOrderException::class);
        try {
            $this->placeTicketOrder($this->makePaidEvent());
        } finally {
            $this->assertSame(EventTicketOrder::STATUS_FAILED, EventTicketOrder::query()->firstOrFail()->status);
        }
    }

    public function test_pricing_service_is_used_not_client_numbers(): void
    {
        $this->mockLencoInitiation();
        $event = $this->makePaidEvent();

        // Extra client-supplied price fields are simply ignored.
        $order = $this->placeTicketOrder($event, ['total' => 1, 'price' => 1, 'unitPrice' => 1, 'isMember' => true])->order;

        $this->assertSame('650.00', (string) $order->total);
        $this->assertSame(
            (float) (app(TicketPricingService::class)->resolve($event, 1)->totalNgwee / 100),
            (float) $order->total
        );
    }
}
