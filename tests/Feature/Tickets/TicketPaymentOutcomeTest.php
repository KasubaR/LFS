<?php

namespace Tests\Feature\Tickets;

use App\Events\EventTicketOrderNeedsReview;
use App\Events\EventTicketOrderPaid;
use App\Models\EventTicketOrder;
use App\Models\Payment;
use App\Services\Tickets\TicketAvailability;
use App\Services\Tickets\TicketPaymentOutcomeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event as EventFacade;
use Tests\Concerns\MakesTicketFixtures;
use Tests\TestCase;

class TicketPaymentOutcomeTest extends TestCase
{
    use MakesTicketFixtures;
    use RefreshDatabase;

    private TicketPaymentOutcomeService $outcome;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMembershipBasics();
        $this->mockLencoInitiation();
        $this->outcome = app(TicketPaymentOutcomeService::class);
    }

    private function place(\App\Models\Event $event, array $input = [], ?\App\Models\User $user = null): EventTicketOrder
    {
        return $this->placeTicketOrder($event, $input, $user)->order;
    }

    /** Pretend the buyer's reservation ran out. */
    private function lapse(EventTicketOrder $order): void
    {
        $order->forceFill(['expires_at' => now()->subMinutes(30)])->save();
    }

    private function committed(\App\Models\Event $event): int
    {
        return app(TicketAvailability::class)->committedTickets($event->id);
    }

    // ── Success inside the reservation ───────────────────────────────────────

    public function test_success_inside_the_reservation_marks_the_order_paid_once(): void
    {
        EventFacade::fake([EventTicketOrderPaid::class, EventTicketOrderNeedsReview::class]);
        $order = $this->place($this->makePaidEvent());
        $payment = $this->paymentFor($order);

        $result = $this->outcome->apply($payment, $this->lencoSuccess($order), 'webhook');

        $this->assertSame(TicketPaymentOutcomeService::PAID, $result);
        $fresh = $order->fresh();
        $this->assertSame(EventTicketOrder::STATUS_PAID, $fresh->status);
        $this->assertNotNull($fresh->paid_at);
        $this->assertSame('completed', $payment->fresh()->status);
        $this->assertNotNull($payment->fresh()->completed_at);
        $this->assertSame(1, $fresh->tickets()->count());

        EventFacade::assertDispatchedTimes(EventTicketOrderPaid::class, 1);
        EventFacade::assertNotDispatched(EventTicketOrderNeedsReview::class);
    }

    public function test_duplicate_delivery_changes_nothing_and_fires_no_second_event(): void
    {
        EventFacade::fake([EventTicketOrderPaid::class]);
        $order = $this->place($this->makePaidEvent());
        $payment = $this->paymentFor($order);

        $first = $this->outcome->apply($payment, $this->lencoSuccess($order), 'webhook');
        $second = $this->outcome->apply($payment->fresh(), $this->lencoSuccess($order), 'poller');
        $third = $this->outcome->apply($payment->fresh(), $this->lencoSuccess($order), 'verify');

        $this->assertSame(TicketPaymentOutcomeService::PAID, $first);
        $this->assertSame(TicketPaymentOutcomeService::NOOP, $second);
        $this->assertSame(TicketPaymentOutcomeService::NOOP, $third);
        $this->assertSame(EventTicketOrder::STATUS_PAID, $order->fresh()->status);
        EventFacade::assertDispatchedTimes(EventTicketOrderPaid::class, 1);
    }

    public function test_webhook_verify_and_poller_produce_identical_state(): void
    {
        $event = $this->makePaidEvent();
        $states = [];

        foreach (['webhook', 'verify', 'poller'] as $i => $source) {
            $order = $this->place($event, ['buyerEmail' => "buyer{$i}@example.com"]);
            $payment = $this->paymentFor($order);

            $this->outcome->apply($payment, $this->lencoSuccess($order), $source);

            $states[$source] = [
                'order' => $order->fresh()->status,
                'payment' => $payment->fresh()->status,
                'paidAtSet' => $order->fresh()->paid_at !== null,
                'tickets' => $order->fresh()->tickets()->count(),
            ];
        }

        $this->assertSame($states['webhook'], $states['verify']);
        $this->assertSame($states['webhook'], $states['poller']);
        $this->assertSame('paid', $states['webhook']['order']);
    }

    public function test_pending_gateway_result_changes_nothing(): void
    {
        $order = $this->place($this->makePaidEvent());
        $payment = $this->paymentFor($order);

        foreach (['pending', 'processing'] as $status) {
            $result = $this->outcome->apply($payment, ['status' => $status, 'internalStatus' => $status], 'poller');
            $this->assertSame(TicketPaymentOutcomeService::PENDING, $result);
        }

        $this->assertSame(EventTicketOrder::STATUS_PENDING_PAYMENT, $order->fresh()->status);
        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_success_on_a_still_live_reservation_is_honoured_even_if_capacity_is_otherwise_full(): void
    {
        $event = $this->makePaidEvent(['ticket_capacity' => 1]);
        $order = $this->place($event);

        $this->assertSame(1, $this->committed($event));
        $this->outcome->apply($this->paymentFor($order), $this->lencoSuccess($order), 'webhook');

        $this->assertSame(EventTicketOrder::STATUS_PAID, $order->fresh()->status);
        $this->assertSame(1, $this->committed($event));
    }

    // ── Failure and cancellation ─────────────────────────────────────────────

    public function test_failed_payment_fails_the_order_and_releases_capacity_and_discount(): void
    {
        $m = $this->makeMember();
        $event = $this->makePaidEvent(['ticket_capacity' => 1]);
        $order = $this->place($event, ['attendees' => [['name' => '']]], $m->user);
        $payment = $this->paymentFor($order);

        $result = $this->outcome->apply($payment, [
            'status' => 'failed', 'internalStatus' => 'failed', 'failureReason' => 'Insufficient funds',
        ], 'webhook');

        $this->assertSame(TicketPaymentOutcomeService::FAILED, $result);
        $this->assertSame(EventTicketOrder::STATUS_FAILED, $order->fresh()->status);
        $this->assertSame('failed', $payment->fresh()->status);
        $this->assertSame('Insufficient funds', $payment->fresh()->failure_reason);
        $this->assertSame(0, $this->committed($event));

        $retry = $this->place($event, ['attendees' => [['name' => '']]], $m->user);
        $this->assertTrue($retry->is_member_pricing, 'discount is available again');
    }

    public function test_cancelled_payment_cancels_the_order(): void
    {
        $order = $this->place($this->makePaidEvent());

        $result = $this->outcome->apply($this->paymentFor($order), ['status' => 'cancelled', 'internalStatus' => 'cancelled'], 'poller');

        $this->assertSame(TicketPaymentOutcomeService::CANCELLED, $result);
        $this->assertSame(EventTicketOrder::STATUS_CANCELLED, $order->fresh()->status);
        $this->assertNotNull($order->fresh()->cancelled_at);
    }

    public function test_a_failure_after_success_is_ignored_and_a_success_after_failure_is_a_noop(): void
    {
        $event = $this->makePaidEvent();

        $paid = $this->place($event);
        $this->outcome->apply($this->paymentFor($paid), $this->lencoSuccess($paid), 'webhook');
        $this->outcome->apply($this->paymentFor($paid), ['status' => 'failed', 'internalStatus' => 'failed'], 'poller');
        $this->assertSame(EventTicketOrder::STATUS_PAID, $paid->fresh()->status, 'paid never goes backwards');

        $failed = $this->place($event, ['buyerEmail' => 'x@example.com']);
        $this->outcome->apply($this->paymentFor($failed), ['status' => 'failed', 'internalStatus' => 'failed'], 'webhook');
        $result = $this->outcome->apply($this->paymentFor($failed), $this->lencoSuccess($failed), 'poller');
        $this->assertSame(TicketPaymentOutcomeService::NOOP, $result);
        $this->assertSame(EventTicketOrder::STATUS_FAILED, $failed->fresh()->status);
    }

    // ── Late payments ────────────────────────────────────────────────────────

    public function test_late_success_with_capacity_free_revives_the_order_at_its_original_price(): void
    {
        EventFacade::fake([EventTicketOrderPaid::class]);
        $event = $this->makePaidEvent(['ticket_capacity' => 10]);
        $order = $this->place($event);
        $this->lapse($order);
        $this->outcome->expireIfStale($order->fresh());
        $this->assertSame(EventTicketOrder::STATUS_CANCELLED, $order->fresh()->status);

        // The event's prices change in the meantime; the order keeps its own.
        $event->update(['ticket_price_non_member' => 900]);

        $result = $this->outcome->apply($this->paymentFor($order), $this->lencoSuccess($order), 'webhook');

        $this->assertSame(TicketPaymentOutcomeService::REVIVED, $result);
        $fresh = $order->fresh();
        $this->assertSame(EventTicketOrder::STATUS_PAID, $fresh->status);
        $this->assertSame('650.00', (string) $fresh->total);
        $this->assertNull($fresh->cancelled_at);
        EventFacade::assertDispatched(EventTicketOrderPaid::class, fn ($e) => $e->revived === true);
    }

    public function test_last_two_tickets_scenario_never_exceeds_capacity(): void
    {
        EventFacade::fake([EventTicketOrderNeedsReview::class, EventTicketOrderPaid::class]);
        $event = $this->makePaidEvent(['ticket_capacity' => 2]);
        $two = ['quantity' => 2, 'attendees' => [['name' => 'Person One'], ['name' => 'Person Two']]];

        // A reserves the last two and starts paying; the payment stays pending.
        $a = $this->place($event, $two);
        // A's reservation expires.
        $this->lapse($a);
        $this->assertSame(0, $this->committed($event));

        // B buys the last two and pays.
        $b = $this->place($event, array_merge($two, ['buyerEmail' => 'b@example.com']));
        $this->outcome->apply($this->paymentFor($b), $this->lencoSuccess($b), 'webhook');
        $this->assertSame(EventTicketOrder::STATUS_PAID, $b->fresh()->status);

        // A's money finally arrives.
        $result = $this->outcome->apply($this->paymentFor($a), $this->lencoSuccess($a), 'webhook');

        $this->assertSame(TicketPaymentOutcomeService::REVIEW, $result);
        $aFresh = $a->fresh();
        $this->assertSame(EventTicketOrder::STATUS_PAYMENT_REVIEW_REQUIRED, $aFresh->status);
        $this->assertSame('capacity_unavailable', $aFresh->review_reason);
        $this->assertSame('completed', $this->paymentFor($a)->status, 'the money is recorded against the order');
        $this->assertSame(EventTicketOrder::STATUS_PAID, $b->fresh()->status, 'B keeps the tickets');
        $this->assertSame(2, $this->committed($event), 'capacity is never exceeded');

        EventFacade::assertDispatched(EventTicketOrderNeedsReview::class, fn ($e) => $e->reason === 'capacity_unavailable');
        EventFacade::assertDispatchedTimes(EventTicketOrderPaid::class, 1);
    }

    public function test_partial_capacity_is_not_fulfilled_automatically(): void
    {
        $event = $this->makePaidEvent(['ticket_capacity' => 3]);
        $a = $this->place($event, ['quantity' => 2, 'attendees' => [['name' => 'Person One'], ['name' => 'Person Two']]]);
        $this->lapse($a);
        $this->place($event, ['buyerEmail' => 'b@example.com']); // takes 1 of the 3, leaving 2... then another
        $this->place($event, ['buyerEmail' => 'c@example.com']); // leaving exactly 1

        $result = $this->outcome->apply($this->paymentFor($a), $this->lencoSuccess($a), 'poller');

        $this->assertSame(TicketPaymentOutcomeService::REVIEW, $result);
        $this->assertSame('capacity_unavailable', $a->fresh()->review_reason);
    }

    public function test_two_late_successes_racing_for_one_slot_revive_only_one(): void
    {
        $event = $this->makePaidEvent(['ticket_capacity' => 1]);
        $a = $this->place($event);
        $this->lapse($a);
        $b = $this->place($event, ['buyerEmail' => 'b@example.com']);
        $this->lapse($b);

        $first = $this->outcome->apply($this->paymentFor($a), $this->lencoSuccess($a), 'webhook');
        $second = $this->outcome->apply($this->paymentFor($b), $this->lencoSuccess($b), 'webhook');

        $this->assertSame(TicketPaymentOutcomeService::REVIVED, $first);
        $this->assertSame(TicketPaymentOutcomeService::REVIEW, $second);
        $this->assertSame(1, $this->committed($event));
    }

    public function test_late_success_loses_the_discount_to_a_newer_live_claim(): void
    {
        $m = $this->makeMember();
        $event = $this->makePaidEvent();

        $old = $this->place($event, ['attendees' => [['name' => '']]], $m->user);
        $this->lapse($old); // reservation over: the discount is free again
        $new = $this->place($event, ['buyerEmail' => 'again@example.com', 'attendees' => [['name' => '']]], $m->user);
        $this->assertTrue($new->is_member_pricing, 'the member re-claims the freed discount');

        $result = $this->outcome->apply($this->paymentFor($old), $this->lencoSuccess($old), 'webhook');

        $this->assertSame(TicketPaymentOutcomeService::REVIEW, $result);
        $this->assertSame('discount_unavailable', $old->fresh()->review_reason);
        $this->assertSame(EventTicketOrder::STATUS_PENDING_PAYMENT, $new->fresh()->status, 'the newer claim is untouched');
    }

    public function test_late_success_for_a_cancelled_event_goes_to_review(): void
    {
        $event = $this->makePaidEvent();
        $order = $this->place($event);
        $this->lapse($order);
        $event->update(['ticket_status' => 'cancelled']);

        $result = $this->outcome->apply($this->paymentFor($order), $this->lencoSuccess($order), 'poller');

        $this->assertSame(TicketPaymentOutcomeService::REVIEW, $result);
        $this->assertSame('event_cancelled', $order->fresh()->review_reason);
    }

    public function test_pending_order_lapsed_but_not_yet_swept_is_rechecked_not_blindly_paid(): void
    {
        $event = $this->makePaidEvent(['ticket_capacity' => 1]);
        $a = $this->place($event);
        $this->lapse($a);                // still status pending_payment, but expired
        $this->place($event, ['buyerEmail' => 'b@example.com']); // someone else took the slot

        $result = $this->outcome->apply($this->paymentFor($a), $this->lencoSuccess($a), 'webhook');

        $this->assertSame(TicketPaymentOutcomeService::REVIEW, $result);
    }

    // ── Amount checks ────────────────────────────────────────────────────────

    public function test_amount_mismatch_never_marks_the_order_paid(): void
    {
        EventFacade::fake([EventTicketOrderNeedsReview::class, EventTicketOrderPaid::class]);
        $event = $this->makePaidEvent();

        foreach ([['amount' => 600.0], ['amount' => 700.0], ['currency' => 'USD']] as $i => $override) {
            $order = $this->place($event, ['buyerEmail' => "m{$i}@example.com"]);
            $result = $this->outcome->apply($this->paymentFor($order), $this->lencoSuccess($order, $override), 'webhook');

            $this->assertSame(TicketPaymentOutcomeService::REVIEW, $result);
            $this->assertSame(EventTicketOrder::STATUS_PAYMENT_REVIEW_REQUIRED, $order->fresh()->status);
            $this->assertSame('amount_mismatch', $order->fresh()->review_reason);
        }

        EventFacade::assertNotDispatched(EventTicketOrderPaid::class);
        EventFacade::assertDispatchedTimes(EventTicketOrderNeedsReview::class, 3);
    }

    public function test_unreported_amount_is_not_treated_as_a_mismatch(): void
    {
        $order = $this->place($this->makePaidEvent());

        $result = $this->outcome->apply($this->paymentFor($order), ['status' => 'successful', 'internalStatus' => 'completed'], 'poller');

        $this->assertSame(TicketPaymentOutcomeService::PAID, $result);
    }

    public function test_orders_in_review_do_not_hold_capacity(): void
    {
        $event = $this->makePaidEvent(['ticket_capacity' => 5]);
        $order = $this->place($event, ['quantity' => 2, 'attendees' => [['name' => 'Person One'], ['name' => 'Person Two']]]);
        $this->assertSame(2, $this->committed($event));

        $this->outcome->apply($this->paymentFor($order), $this->lencoSuccess($order, ['amount' => 1.0]), 'webhook');

        $this->assertSame(EventTicketOrder::STATUS_PAYMENT_REVIEW_REQUIRED, $order->fresh()->status);
        $this->assertSame(0, $this->committed($event), 'no valid tickets exist, so no capacity is held');
    }

    public function test_a_review_order_still_holds_the_member_discount_until_resolved(): void
    {
        $m = $this->makeMember();
        $event = $this->makePaidEvent();
        $order = $this->place($event, ['attendees' => [['name' => '']]], $m->user);
        $this->outcome->apply($this->paymentFor($order), $this->lencoSuccess($order, ['amount' => 1.0]), 'webhook');

        $second = $this->place($event, ['buyerEmail' => 'again@example.com', 'attendees' => [['name' => 'Someone Else']]], $m->user);

        $this->assertFalse($second->is_member_pricing, 'a second discount cannot slip in while the first is under review');
    }

    // ── Expiry sweep, give-up, initiation failure ────────────────────────────

    public function test_expire_if_stale_waits_for_expiry_plus_slack_and_leaves_the_payment_pending(): void
    {
        $order = $this->place($this->makePaidEvent());

        $this->assertFalse($this->outcome->expireIfStale($order->fresh()), 'still inside the reservation');

        $order->forceFill(['expires_at' => now()->subSeconds(30)])->save();
        $this->assertFalse($this->outcome->expireIfStale($order->fresh()), 'inside the slack window');

        $this->lapse($order);
        $this->assertTrue($this->outcome->expireIfStale($order->fresh()));
        $this->assertSame(EventTicketOrder::STATUS_CANCELLED, $order->fresh()->status);
        $this->assertSame('pending', $this->paymentFor($order)->status, 'payment stays open for a late success');
        $this->assertFalse($this->outcome->expireIfStale($order->fresh()), 'only once');
    }

    public function test_expire_if_stale_never_touches_a_paid_order(): void
    {
        $order = $this->place($this->makePaidEvent());
        $this->outcome->apply($this->paymentFor($order), $this->lencoSuccess($order), 'webhook');
        $this->lapse($order);

        $this->assertFalse($this->outcome->expireIfStale($order->fresh()));
        $this->assertSame(EventTicketOrder::STATUS_PAID, $order->fresh()->status);
    }

    public function test_give_up_closes_the_payment_so_it_is_not_polled_forever(): void
    {
        $order = $this->place($this->makePaidEvent());

        $this->assertTrue($this->outcome->giveUpOnPayment($this->paymentFor($order)));

        $this->assertSame('cancelled', $this->paymentFor($order)->status);
    }

    public function test_ticket_order_numbers_are_recognised_by_prefix(): void
    {
        $this->assertTrue(TicketPaymentOutcomeService::isTicketOrderNumber('LFSEV-20261101-AB12C'));
        $this->assertFalse(TicketPaymentOutcomeService::isTicketOrderNumber('LFS-20261101-AB12C'));
        $this->assertFalse(TicketPaymentOutcomeService::isTicketOrderNumber(''));
    }

    public function test_payment_without_a_ticket_order_is_a_noop(): void
    {
        $payment = Payment::query()->create([
            'order_number' => 'LFSEV-20260101-NOORD',
            'payment_method' => 'mobile_money',
            'amount' => 650,
            'currency' => 'ZMW',
            'status' => 'pending',
            'transaction_id' => 'LFS-LFSEV-20260101-NOORD-1',
        ]);

        $result = $this->outcome->apply($payment, ['status' => 'successful', 'internalStatus' => 'completed', 'amount' => 650], 'webhook');

        $this->assertSame(TicketPaymentOutcomeService::NOOP, $result);
        $this->assertSame('pending', $payment->fresh()->status);
    }
}
