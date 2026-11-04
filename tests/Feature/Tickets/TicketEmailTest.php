<?php

namespace Tests\Feature\Tickets;

use App\Models\EventTicketOrder;
use App\Notifications\EventTicketAttendeeNotification;
use App\Notifications\EventTicketConfirmationNotification;
use App\Notifications\EventTicketPaymentReceivedNotification;
use App\Services\Tickets\TicketConfirmationService;
use App\Services\Tickets\TicketPaymentOutcomeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\MakesTicketFixtures;
use Tests\TestCase;

/**
 * The confirmation email is the ticket. It is sent once, to the right people,
 * with the right contents, and never claims confirmation before payment.
 */
class TicketEmailTest extends TestCase
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

    private function pay(EventTicketOrder $order, string $source = 'webhook'): string
    {
        return $this->outcome->apply($this->paymentFor($order), $this->lencoSuccess($order), $source);
    }

    /**
     * @return string all text lines of the buyer email
     */
    private function emailText(EventTicketOrder $order): string
    {
        $mail = (new EventTicketConfirmationNotification($order->fresh()))->toMail(new AnonymousNotifiable);

        return $mail->greeting."\n".implode("\n", array_merge($mail->introLines, $mail->outroLines));
    }

    public function test_paying_sends_the_buyer_exactly_one_confirmation(): void
    {
        Notification::fake();
        $order = $this->placeTicketOrder($this->makePaidEvent())->order;

        $this->pay($order);
        $this->pay($order, 'poller');
        $this->pay($order, 'verify');

        Notification::assertSentOnDemandTimes(EventTicketConfirmationNotification::class, 1);
        Notification::assertSentOnDemand(
            EventTicketConfirmationNotification::class,
            fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'john@example.com'
        );
        $this->assertNotNull($order->fresh()->confirmation_email_sent_at);
    }

    public function test_the_email_contains_every_required_detail(): void
    {
        $m = $this->makeMember('Chanda Mwale', 'chanda@example.com', [], 'LFS-000412');
        $event = $this->makePaidEvent([
            'title' => 'End of Year Party',
            'location' => 'Arcades, Lusaka',
            'event_date' => now()->addDays(30)->setTime(18, 30),
        ]);
        $order = $this->placeTicketOrder($event, [
            'buyerName' => 'Chanda Mwale',
            'quantity' => 3,
            'attendees' => [['name' => ''], ['name' => 'Friend One'], ['name' => 'Friend Two']],
        ], $m->user)->order;
        $this->pay($order);

        $text = $this->emailText($order);
        $payment = $this->paymentFor($order);

        foreach ([
            'Payment successful',
            'End of Year Party',
            $event->event_date->format('l, j F Y \a\t H:i'),
            'Arcades, Lusaka',
            'Chanda Mwale',                      // buyer
            '**Number of tickets:** 3',
            'Friend One', 'Friend Two',          // attendees
            $order->order_number.'-1', $order->order_number.'-2', $order->order_number.'-3',
            'K1,850.00',                         // total paid
            $payment->transaction_id,            // payment reference
            $order->order_number,
            'membership no. LFS-000412',
            'Names cannot be changed',
        ] as $needle) {
            $this->assertStringContainsString($needle, $text);
        }

        $mail = (new EventTicketConfirmationNotification($order->fresh()))->toMail(new AnonymousNotifiable);
        $this->assertStringContainsString('/tickets/'.$order->order_number, $mail->actionUrl);
        $this->assertStringContainsString('signature=', $mail->actionUrl, 'link is signed');
    }

    public function test_standard_order_email_has_no_member_note(): void
    {
        $order = $this->placeTicketOrder($this->makePaidEvent())->order;
        $this->pay($order);

        $text = $this->emailText($order);

        $this->assertStringNotContainsString('membership', strtolower($text));
        $this->assertStringContainsString('K650.00', $text);
    }

    public function test_attendee_with_their_own_email_gets_only_their_ticket(): void
    {
        Notification::fake();
        $order = $this->placeTicketOrder($this->makePaidEvent(), [
            'buyerName' => 'Pat Buyer',
            'quantity' => 3,
            'attendees' => [
                ['name' => 'John Banda', 'email' => 'JOHN@example.com'],     // same as buyer: no duplicate
                ['name' => 'Mary Phiri', 'email' => 'mary@example.com'],
                ['name' => 'No Email'],
            ],
        ])->order;

        $this->pay($order);

        Notification::assertSentOnDemandTimes(EventTicketConfirmationNotification::class, 1);
        Notification::assertSentOnDemandTimes(EventTicketAttendeeNotification::class, 1);
        Notification::assertSentOnDemand(
            EventTicketAttendeeNotification::class,
            fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'mary@example.com'
        );

        $ticket = $order->tickets->firstWhere('attendee_name', 'Mary Phiri');
        $mail = (new EventTicketAttendeeNotification($ticket))->toMail(new AnonymousNotifiable);
        $text = implode("\n", array_merge($mail->introLines, $mail->outroLines));

        $this->assertStringContainsString('Pat Buyer has bought you a ticket', $text);
        $this->assertStringContainsString('Mary Phiri', $text);
        $this->assertStringContainsString($ticket->ticket_reference, $text);
        $this->assertStringNotContainsString('John Banda', $text, 'no other attendees');
        $this->assertStringNotContainsString('No Email', $text);
        $this->assertStringNotContainsString('0971111111', $text, 'no buyer phone');
        $this->assertStringNotContainsString('Total paid', $text, 'no payment details');
        $this->assertStringNotContainsString($order->buyer_email, $text);
    }

    public function test_no_confirmation_is_sent_before_payment_or_for_failed_orders(): void
    {
        Notification::fake();
        $event = $this->makePaidEvent();

        $this->placeTicketOrder($event); // pending: nothing yet
        $failed = $this->placeTicketOrder($event, ['buyerEmail' => 'f@example.com'])->order;
        $this->outcome->apply($this->paymentFor($failed), ['status' => 'failed', 'internalStatus' => 'failed'], 'webhook');

        Notification::assertNothingSent();
    }

    public function test_review_order_gets_the_payment_received_email_not_a_confirmation(): void
    {
        Notification::fake();
        $order = $this->placeTicketOrder($this->makePaidEvent())->order;

        $this->outcome->apply($this->paymentFor($order), $this->lencoSuccess($order, ['amount' => 1.0]), 'webhook');

        Notification::assertSentOnDemandTimes(EventTicketPaymentReceivedNotification::class, 1);
        Notification::assertSentOnDemandTimes(EventTicketConfirmationNotification::class, 0);

        $mail = (new EventTicketPaymentReceivedNotification($order->fresh()))->toMail(new AnonymousNotifiable);
        $text = implode("\n", array_merge($mail->introLines, $mail->outroLines));
        $this->assertStringContainsString('NOT confirmed', $text);
        $this->assertStringContainsString($order->order_number, $text);
        $this->assertNull($order->fresh()->confirmation_email_sent_at);
    }

    public function test_a_mail_outage_never_breaks_payment_confirmation_and_is_retried(): void
    {
        $order = $this->placeTicketOrder($this->makePaidEvent())->order;

        // Mail transport blows up.
        Notification::shouldReceive('route')->andThrow(new \RuntimeException('SMTP down'));
        $result = $this->pay($order);

        $this->assertSame(TicketPaymentOutcomeService::PAID, $result, 'the order is still paid');
        $this->assertSame(EventTicketOrder::STATUS_PAID, $order->fresh()->status);
        $this->assertNull($order->fresh()->confirmation_email_sent_at, 'claim released so it can be retried');
    }

    public function test_poller_retries_unsent_confirmations_after_a_grace_period(): void
    {
        $order = $this->placeTicketOrder($this->makePaidEvent())->order;
        $order->forceFill(['status' => EventTicketOrder::STATUS_PAID, 'paid_at' => now()->subMinutes(5), 'confirmation_email_sent_at' => null])->save();
        $fresh = $this->placeTicketOrder($this->makePaidEvent(), ['buyerEmail' => 'new@example.com'])->order;
        $fresh->forceFill(['status' => EventTicketOrder::STATUS_PAID, 'paid_at' => now(), 'confirmation_email_sent_at' => null])->save();

        Notification::fake();
        $sent = app(TicketConfirmationService::class)->retryUnsent();

        $this->assertSame(1, $sent, 'only the order older than the grace period');
        $this->assertNotNull($order->fresh()->confirmation_email_sent_at);
        $this->assertNull($fresh->fresh()->confirmation_email_sent_at);
        Notification::assertSentOnDemandTimes(EventTicketConfirmationNotification::class, 1);
    }

    public function test_concurrent_senders_cannot_double_send(): void
    {
        Notification::fake();
        $order = $this->placeTicketOrder($this->makePaidEvent())->order;
        $order->forceFill(['status' => EventTicketOrder::STATUS_PAID, 'paid_at' => now()])->save();
        $service = app(TicketConfirmationService::class);

        $a = $service->send($order);
        $b = $service->send($order->fresh());

        $this->assertTrue($a);
        $this->assertFalse($b);
        Notification::assertSentOnDemandTimes(EventTicketConfirmationNotification::class, 1);
    }

    // ── "Find my tickets" ────────────────────────────────────────────────────

    public function test_find_my_tickets_resends_to_the_email_on_the_order(): void
    {
        Notification::fake();
        $order = $this->placeTicketOrder($this->makePaidEvent())->order;
        $this->pay($order);
        $before = $this->countConfirmations();

        $this->post('/tickets/find', ['orderNumber' => $order->order_number, 'email' => ' John@Example.com '])
            ->assertOk()
            ->assertSee('Check your email');

        $this->assertSame($before + 1, $this->countConfirmations());
    }

    public function test_find_my_tickets_answers_identically_for_wrong_details_and_sends_nothing(): void
    {
        Notification::fake();
        $order = $this->placeTicketOrder($this->makePaidEvent())->order;
        $this->pay($order);
        $before = $this->countConfirmations();

        $good = $this->post('/tickets/find', ['orderNumber' => $order->order_number, 'email' => 'john@example.com']);
        $wrongEmail = $this->post('/tickets/find', ['orderNumber' => $order->order_number, 'email' => 'other@example.com']);
        $wrongOrder = $this->post('/tickets/find', ['orderNumber' => 'LFSEV-00000000-NOPE0', 'email' => 'john@example.com']);
        $empty = $this->post('/tickets/find', ['orderNumber' => '', 'email' => '']);

        foreach ([$wrongEmail, $wrongOrder, $empty] as $r) {
            $r->assertOk()->assertSee('Check your email');
            $this->assertSame($this->bodyOf($good), $this->bodyOf($r), 'indistinguishable from a real match');
        }
        $this->assertSame($before + 1, $this->countConfirmations(), 'only the real match sent anything');
    }

    public function test_find_my_tickets_is_limited_to_one_resend_per_order_per_ten_minutes(): void
    {
        Notification::fake();
        $order = $this->placeTicketOrder($this->makePaidEvent())->order;
        $this->pay($order);
        $before = $this->countConfirmations();

        for ($i = 0; $i < 3; $i++) {
            $this->post('/tickets/find', ['orderNumber' => $order->order_number, 'email' => 'john@example.com'])->assertOk();
        }

        $this->assertSame($before + 1, $this->countConfirmations());
    }

    public function test_find_my_tickets_never_resends_an_unpaid_order(): void
    {
        Notification::fake();
        $order = $this->placeTicketOrder($this->makePaidEvent())->order;

        $this->post('/tickets/find', ['orderNumber' => $order->order_number, 'email' => 'john@example.com'])->assertOk();

        Notification::assertNothingSent();
    }

    public function test_find_my_tickets_form_renders(): void
    {
        $this->get('/tickets/find')->assertOk()->assertSee('Order number')->assertSee('Email used to buy');
    }

    private function countConfirmations(): int
    {
        return Notification::sent(new AnonymousNotifiable, EventTicketConfirmationNotification::class)->count();
    }

    private function bodyOf(\Illuminate\Testing\TestResponse $r): string
    {
        // Strip the per-request CSRF token so bodies can be compared.
        return preg_replace('/name="_token" value="[^"]+"/', '', $r->getContent()) ?? '';
    }
}
