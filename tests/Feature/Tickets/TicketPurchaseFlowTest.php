<?php

namespace Tests\Feature\Tickets;

use App\Exceptions\LencoApiException;
use App\Models\EventTicketOrder;
use App\Models\Payment;
use App\Models\User;
use App\Services\Tickets\TicketPaymentOutcomeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\Concerns\MakesTicketFixtures;
use Tests\TestCase;

/**
 * The public purchase flow over HTTP: checkout page, live quote, order
 * placement, the order page and who may see it, and status polling.
 */
class TicketPurchaseFlowTest extends TestCase
{
    use MakesTicketFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMembershipBasics();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'buyerName' => 'John Banda',
            'buyerEmail' => 'john@example.com',
            'buyerPhone' => '0971111111',
            'payerPhone' => '0971111111',
            'provider' => 'mtn',
            'quantity' => 1,
            'attendees' => [['name' => 'John Banda', 'email' => '']],
            'buyerAttending' => true,
            'idempotencyToken' => 'tok-'.uniqid(),
        ], $overrides);
    }

    private function url(\App\Models\Event $event, string $suffix = ''): string
    {
        return '/events/'.$event->slug.'/tickets'.$suffix;
    }

    // ── Checkout page ────────────────────────────────────────────────────────

    public function test_checkout_page_shows_the_event_prices_and_form(): void
    {
        $event = $this->makePaidEvent(['title' => 'End of Year Party', 'location' => 'Arcades, Lusaka']);

        $this->get($this->url($event))
            ->assertOk()
            ->assertSee('End of Year Party')
            ->assertSee('Arcades, Lusaka')
            ->assertSee('K550')
            ->assertSee('K650')
            ->assertSee('id="ticket-form"', false)
            ->assertSee("Names can't be changed after payment", false)
            ->assertSee('Order summary')
            ->assertSee('Payment through Lenco mobile money')
            ->assertSee('Find my tickets');
    }

    public function test_guest_sees_the_membership_lookup_but_a_logged_in_user_does_not(): void
    {
        $event = $this->makePaidEvent();

        $this->get($this->url($event))->assertSee('id="membershipNumber"', false);

        $m = $this->makeMember();
        $this->actingAs($m->user)->get($this->url($event))->assertDontSee('id="membershipNumber"', false);
    }

    public function test_logged_in_buyer_details_are_prefilled_from_the_account(): void
    {
        $m = $this->makeMember('Chanda Mwale', 'chanda@example.com');
        $m->user->forceFill(['phone' => '0977000111'])->save();

        $this->actingAs($m->user)
            ->get($this->url($this->makePaidEvent()))
            ->assertSee('value="Chanda Mwale"', false)
            ->assertSee('value="chanda@example.com"', false)
            ->assertSee('value="0977000111"', false);
    }

    public function test_sold_out_event_shows_the_reason_and_no_form(): void
    {
        $event = $this->makePaidEvent(['ticket_capacity' => 1]);
        $this->makeTicketOrder($event, EventTicketOrder::STATUS_PAID, 1);

        $this->get($this->url($event))
            ->assertOk()
            ->assertSee('Sorry, this event is sold out.')
            ->assertDontSee('id="ticket-form"', false);
    }

    public function test_each_closed_state_shows_its_own_message(): void
    {
        foreach ([
            [['ticket_sales_open' => false], 'Ticket sales are closed.'],
            [['registration_close' => now()->subMinute()], 'The registration deadline has passed.'],
            [['registration_open' => now()->addDay()], 'Ticket sales have not opened yet.'],
            [['ticket_status' => 'cancelled'], 'This event has been cancelled.'],
        ] as [$state, $message]) {
            $this->get($this->url($this->makePaidEvent($state)))->assertOk()->assertSee($message);
        }
    }

    public function test_non_paid_event_redirects_to_its_page_and_unknown_slug_is_404(): void
    {
        $event = $this->makePaidEvent(['registration_type' => 'open']);

        $this->get($this->url($event))->assertRedirect('/events/'.$event->slug);
        $this->get('/events/nope-nope/tickets')->assertNotFound();
    }

    public function test_quantity_options_never_exceed_what_is_left(): void
    {
        $event = $this->makePaidEvent(['ticket_capacity' => 3]);

        $html = $this->get($this->url($event))->getContent();

        $this->assertStringContainsString('<option value="3">3</option>', $html);
        $this->assertStringNotContainsString('<option value="4">4</option>', $html);
    }

    // ── Quote ────────────────────────────────────────────────────────────────

    public function test_quote_prices_a_guest_server_side(): void
    {
        $event = $this->makePaidEvent();

        $this->postJson($this->url($event, '/quote'), ['quantity' => 3])
            ->assertOk()
            ->assertJsonPath('available', true)
            ->assertJsonPath('pricing.total', 1950)
            ->assertJsonPath('pricing.isMemberPricing', false)
            ->assertJsonPath('pricing.discountDeniedReason', 'guest')
            ->assertJsonPath('saving', 100);
    }

    public function test_quote_for_a_logged_in_member_gives_550_plus_650_plus_650(): void
    {
        $m = $this->makeMember('Chanda Mwale', 'chanda@example.com');

        $this->actingAs($m->user)
            ->postJson($this->url($this->makePaidEvent(), '/quote'), ['quantity' => 3, 'buyerAttending' => true])
            ->assertOk()
            ->assertJsonPath('pricing.total', 1850)
            ->assertJsonPath('pricing.isMemberPricing', true)
            ->assertJsonPath('pricing.memberName', 'Chanda Mwale')
            ->assertJsonPath('pricing.lines.0.label', 'Member ticket')
            ->assertJsonPath('pricing.lines.1.quantity', 2);
    }

    public function test_quote_not_attending_removes_the_discount(): void
    {
        $m = $this->makeMember();

        $this->actingAs($m->user)
            ->postJson($this->url($this->makePaidEvent(), '/quote'), ['quantity' => 1, 'buyerAttending' => false])
            ->assertJsonPath('pricing.total', 650)
            ->assertJsonPath('pricing.discountDeniedReason', 'not_attending')
            ->assertJsonPath('pricing.memberName', null);
    }

    public function test_quote_with_membership_number_and_email_unlocks_the_member_price_for_a_guest(): void
    {
        $this->makeMember('Chanda Mwale', 'chanda@example.com', [], 'LFS-000412');
        $event = $this->makePaidEvent();

        $this->postJson($this->url($event, '/quote'), [
            'quantity' => 1, 'membershipNumber' => 'LFS-000412', 'membershipEmail' => 'chanda@example.com',
        ])->assertJsonPath('pricing.total', 550)->assertJsonPath('pricing.memberName', 'Chanda Mwale');

        // Wrong email: same answer as an unknown number, and no name leaks.
        foreach ([['LFS-000412', 'wrong@example.com'], ['LFS-999999', 'chanda@example.com']] as [$n, $e]) {
            $this->postJson($this->url($event, '/quote'), ['quantity' => 1, 'membershipNumber' => $n, 'membershipEmail' => $e])
                ->assertJsonPath('pricing.total', 650)
                ->assertJsonPath('pricing.discountDeniedReason', 'verification_failed')
                ->assertJsonPath('pricing.memberName', null);
        }
    }

    public function test_quote_clamps_quantity_and_reports_availability(): void
    {
        $event = $this->makePaidEvent(['ticket_capacity' => 2]);

        $this->postJson($this->url($event, '/quote'), ['quantity' => 999])
            ->assertOk()
            ->assertJsonPath('pricing.quantity', 10)
            ->assertJsonPath('available', false)
            ->assertJsonPath('availabilityReason', 'only_n_left')
            ->assertJsonPath('remaining', 2);

        $this->postJson($this->url($event, '/quote'), ['quantity' => -4])->assertJsonPath('pricing.quantity', 1);
    }

    public function test_quote_is_not_available_for_a_non_paid_event(): void
    {
        $event = $this->makePaidEvent(['registration_type' => 'open', 'ticket_price_member' => null, 'ticket_price_non_member' => null]);

        $this->postJson($this->url($event, '/quote'), ['quantity' => 1])->assertNotFound();
    }

    // ── Place order ──────────────────────────────────────────────────────────

    public function test_placing_an_order_returns_a_redirect_and_grants_access_to_this_browser(): void
    {
        $this->mockLencoInitiation();
        $event = $this->makePaidEvent();

        $response = $this->postJson($this->url($event, '/order'), $this->payload())
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('paymentInstructions', 'Approve the payment on your phone.');

        $order = EventTicketOrder::query()->firstOrFail();
        $response->assertJsonPath('orderNumber', $order->order_number)
            ->assertJsonPath('redirect', url('/tickets/'.$order->order_number));
        $this->assertSame(EventTicketOrder::STATUS_PENDING_PAYMENT, $order->status);
        $this->assertTrue(session()->get("order_access.{$order->order_number}"));
    }

    public function test_server_ignores_client_prices_and_rejects_a_stale_expected_total(): void
    {
        $this->mockLencoInitiation();
        $event = $this->makePaidEvent();

        $this->postJson($this->url($event, '/order'), $this->payload(['expectedTotal' => 550, 'total' => 1]))
            ->assertStatus(409)
            ->assertJsonPath('reason', 'price_changed')
            ->assertJsonPath('pricing.total', 650);

        $this->assertDatabaseCount('event_ticket_orders', 0);
    }

    public function test_invalid_order_input_returns_422_with_field_errors(): void
    {
        $this->mockLencoInitiation();

        $this->postJson($this->url($this->makePaidEvent(), '/order'), $this->payload(['payerPhone' => 'abc', 'buyerEmail' => 'nope']))
            ->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonStructure(['message', 'errors' => ['payerPhone', 'buyerEmail']]);
    }

    public function test_sold_out_order_returns_409_with_the_reason(): void
    {
        $this->mockLencoInitiation();
        $event = $this->makePaidEvent(['ticket_capacity' => 1]);
        $this->makeTicketOrder($event, EventTicketOrder::STATUS_PAID, 1);

        $this->postJson($this->url($event, '/order'), $this->payload())
            ->assertStatus(409)
            ->assertJsonPath('reason', 'sold_out');
    }

    public function test_member_name_tampering_is_rejected(): void
    {
        $this->mockLencoInitiation();
        $m = $this->makeMember('Chanda Mwale', 'chanda@example.com');

        $this->actingAs($m->user)
            ->postJson($this->url($this->makePaidEvent(), '/order'), $this->payload(['attendees' => [['name' => 'A Friend']]]))
            ->assertStatus(422)
            ->assertJsonPath('reason', 'member_name_mismatch');

        $this->assertDatabaseCount('event_ticket_orders', 0);
    }

    public function test_member_order_via_http_binds_the_ticket_to_the_member(): void
    {
        $this->mockLencoInitiation();
        $m = $this->makeMember('Chanda Mwale', 'chanda@example.com');

        $this->actingAs($m->user)
            ->postJson($this->url($this->makePaidEvent(), '/order'), $this->payload([
                'quantity' => 2,
                'attendees' => [['name' => ''], ['name' => 'Friend One', 'email' => 'friend@example.com']],
                'expectedTotal' => 1200,
            ]))->assertOk();

        $order = EventTicketOrder::query()->firstOrFail();
        $this->assertSame('1200.00', (string) $order->total);
        $this->assertSame('Chanda Mwale', $order->tickets[0]->attendee_name);
        $this->assertTrue($order->tickets[0]->is_member_discount);
        $this->assertSame('friend@example.com', $order->tickets[1]->attendee_email);
    }

    public function test_gateway_refusal_returns_502_and_no_live_order(): void
    {
        $this->lencoPartial(function ($mock): void {
            $mock->shouldReceive('initiateMobileMoneyPayment')->andThrow(new LencoApiException('Invalid phone number', 422, false));
        });

        $this->postJson($this->url($this->makePaidEvent(), '/order'), $this->payload())
            ->assertStatus(502)
            ->assertJsonPath('reason', 'payment_failed')
            ->assertJsonPath('message', 'Please check your mobile money number and network, then try again.');

        $this->assertSame(EventTicketOrder::STATUS_FAILED, EventTicketOrder::query()->firstOrFail()->status);
    }

    public function test_gateway_outage_returns_202_and_sends_the_buyer_to_the_waiting_page(): void
    {
        $this->lencoPartial(function ($mock): void {
            $mock->shouldReceive('initiateMobileMoneyPayment')->andThrow(new LencoApiException('timeout', 0, true));
        });

        $response = $this->postJson($this->url($this->makePaidEvent(), '/order'), $this->payload())
            ->assertStatus(202)
            ->assertJsonPath('reason', 'payment_unknown');

        $order = EventTicketOrder::query()->firstOrFail();
        $response->assertJsonPath('redirect', url('/tickets/'.$order->order_number));
        $this->assertSame(EventTicketOrder::STATUS_PENDING_PAYMENT, $order->status, 'reservation kept');
        $this->assertTrue(session()->get("order_access.{$order->order_number}"), 'buyer can watch the order');
    }

    public function test_double_submit_with_the_same_token_returns_the_same_order(): void
    {
        $this->mockLencoInitiation();
        $event = $this->makePaidEvent();
        $body = $this->payload(['idempotencyToken' => 'one-page-load']);

        $first = $this->postJson($this->url($event, '/order'), $body)->assertOk();
        $second = $this->postJson($this->url($event, '/order'), $body)->assertOk();

        $this->assertSame($first->json('orderNumber'), $second->json('orderNumber'));
        $this->assertTrue($second->json('resumed'));
        $this->assertDatabaseCount('event_ticket_orders', 1);
    }

    // ── Order page: who can see it, and what it says ─────────────────────────

    private function placed(array $input = []): EventTicketOrder
    {
        $this->mockLencoInitiation();

        return $this->placeTicketOrder($this->makePaidEvent(), $input)->order;
    }

    public function test_order_page_is_404_for_a_stranger_and_for_unknown_orders(): void
    {
        $order = $this->placed();

        $this->get('/tickets/'.$order->order_number)->assertNotFound();
        $this->get('/tickets/LFSEV-00000000-NOPE0')->assertNotFound();
        $this->getJson('/tickets/'.$order->order_number.'/status')->assertNotFound();
    }

    public function test_order_page_is_visible_to_the_session_that_placed_it(): void
    {
        $order = $this->placed();

        $this->withSession(["order_access.{$order->order_number}" => true])
            ->get('/tickets/'.$order->order_number)
            ->assertOk()
            ->assertSee('Approve the payment on your phone')
            ->assertSee($order->order_number)
            ->assertSee('0971111111');
    }

    public function test_order_page_is_visible_to_the_logged_in_buyer_but_not_to_another_user(): void
    {
        $m = $this->makeMember('Chanda Mwale', 'chanda@example.com');
        $this->mockLencoInitiation();
        $order = $this->placeTicketOrder($this->makePaidEvent(), ['attendees' => [['name' => '']]], $m->user)->order;

        $this->actingAs($m->user)->get('/tickets/'.$order->order_number)->assertOk();

        $stranger = User::factory()->create();
        $this->actingAs($stranger)->get('/tickets/'.$order->order_number)->assertNotFound();
    }

    public function test_order_page_is_visible_to_a_user_whose_email_matches_the_buyer_email(): void
    {
        $order = $this->placed(['buyerEmail' => 'john@example.com']);
        $user = User::factory()->create(['email' => 'John@Example.com']);

        $this->actingAs($user)->get('/tickets/'.$order->order_number)->assertOk();
    }

    public function test_signed_link_from_the_email_opens_the_order_without_a_session(): void
    {
        $order = $this->placed();

        $signed = URL::temporarySignedRoute('tickets.show', now()->addDay(), ['orderNumber' => $order->order_number]);
        $this->get($signed)->assertOk();

        $this->get($signed.'x')->assertNotFound();
        $expired = URL::temporarySignedRoute('tickets.show', now()->subMinute(), ['orderNumber' => $order->order_number]);
        $this->get($expired)->assertNotFound();
    }

    public function test_paid_order_page_shows_everything_the_email_shows(): void
    {
        $m = $this->makeMember('Chanda Mwale', 'chanda@example.com', [], 'LFS-000412');
        $this->mockLencoInitiation();
        $event = $this->makePaidEvent(['title' => 'End of Year Party', 'location' => 'Arcades, Lusaka']);
        $order = $this->placeTicketOrder($event, [
            'quantity' => 2, 'attendees' => [['name' => ''], ['name' => 'Friend One']],
        ], $m->user)->order;
        app(TicketPaymentOutcomeService::class)->apply($this->paymentFor($order), $this->lencoSuccess($order), 'webhook');

        $this->withSession(["order_access.{$order->order_number}" => true])
            ->get('/tickets/'.$order->order_number)
            ->assertOk()
            ->assertSee('Payment successful')
            ->assertSee('End of Year Party')
            ->assertSee('Arcades, Lusaka')
            ->assertSee('Chanda Mwale')
            ->assertSee('Friend One')
            ->assertSee($order->order_number.'-1')
            ->assertSee($order->order_number.'-2')
            ->assertSee('LFS-000412')
            ->assertSee('K1,200.00')
            ->assertSee($this->paymentFor($order)->transaction_id)
            ->assertDontSee('Approve the payment');
    }

    public function test_review_cancelled_and_failed_orders_never_claim_to_be_confirmed(): void
    {
        $event = $this->makePaidEvent();
        $this->mockLencoInitiation();

        $cases = [
            EventTicketOrder::STATUS_PAYMENT_REVIEW_REQUIRED => ['Payment received, awaiting confirmation', 'Your tickets are not confirmed yet'],
            EventTicketOrder::STATUS_CANCELLED => ['Your reservation expired', 'do not need to pay again'],
            EventTicketOrder::STATUS_FAILED => ["Payment didn't go through", 'Nothing was charged'],
        ];

        foreach ($cases as $status => [$heading, $note]) {
            $order = $this->placeTicketOrder($event, ['buyerEmail' => $status.'@example.com'])->order;
            $order->forceFill(['status' => $status])->save();

            $page = $this->withSession(["order_access.{$order->order_number}" => true])
                ->get('/tickets/'.$order->order_number)
                ->assertOk()
                ->assertSee($heading, false)
                ->assertSee($note, false)
                ->assertDontSee('Payment successful');
            $this->assertStringNotContainsString($order->order_number.'-1', $page->getContent(), 'no ticket references until paid');
        }
    }

    // ── Status polling ───────────────────────────────────────────────────────

    public function test_status_endpoint_settles_a_pending_payment_through_the_gateway(): void
    {
        $order = $this->placed();
        $this->lencoPartial(function ($mock) use ($order): void {
            $mock->shouldReceive('verifyPayment')->once()->andReturn($this->lencoSuccess($order));
        });

        $this->withSession(["order_access.{$order->order_number}" => true])
            ->getJson('/tickets/'.$order->order_number.'/status')
            ->assertOk()
            ->assertJsonPath('orderStatus', 'paid')
            ->assertJsonPath('gatewayUnavailable', false);

        $this->assertSame(EventTicketOrder::STATUS_PAID, $order->fresh()->status);
    }

    public function test_status_endpoint_reports_last_known_state_when_the_gateway_is_down(): void
    {
        $order = $this->placed();
        $this->lencoPartial(function ($mock): void {
            $mock->shouldReceive('verifyPayment')->andThrow(new \RuntimeException('down'));
        });

        $this->withSession(["order_access.{$order->order_number}" => true])
            ->getJson('/tickets/'.$order->order_number.'/status')
            ->assertOk()
            ->assertJsonPath('orderStatus', 'pending_payment')
            ->assertJsonPath('gatewayUnavailable', true);
    }

    public function test_status_endpoint_does_not_call_the_gateway_for_a_paid_order(): void
    {
        $order = $this->placed();
        app(TicketPaymentOutcomeService::class)->apply($this->paymentFor($order), $this->lencoSuccess($order), 'webhook');
        $this->lencoPartial(function ($mock): void {
            $mock->shouldNotReceive('verifyPayment');
        });

        $this->withSession(["order_access.{$order->order_number}" => true])
            ->getJson('/tickets/'.$order->order_number.'/status')
            ->assertJsonPath('orderStatus', 'paid');
    }

    public function test_a_lapsed_order_with_a_late_payment_is_revived_when_the_page_checks(): void
    {
        $order = $this->placed();
        $order->forceFill(['status' => EventTicketOrder::STATUS_CANCELLED, 'expires_at' => now()->subHour()])->save();
        $this->lencoPartial(function ($mock) use ($order): void {
            $mock->shouldReceive('verifyPayment')->once()->andReturn($this->lencoSuccess($order));
        });

        $this->withSession(["order_access.{$order->order_number}" => true])
            ->getJson('/tickets/'.$order->order_number.'/status')
            ->assertJsonPath('orderStatus', 'paid');
    }

    // ── Event page ───────────────────────────────────────────────────────────

    public function test_paid_event_page_shows_prices_and_a_get_tickets_button(): void
    {
        $event = $this->makePaidEvent(['title' => 'End of Year Party']);

        $this->get('/events/'.$event->slug)
            ->assertOk()
            ->assertSee('Get tickets')
            ->assertSee('Tickets on sale')
            ->assertSee('Members K550')
            ->assertSee('Everyone else K650')
            ->assertSee('/events/'.$event->slug.'/tickets', false)
            ->assertDontSee('Register Interest');
    }

    public function test_sold_out_paid_event_page_has_no_buy_link(): void
    {
        $event = $this->makePaidEvent(['ticket_capacity' => 1]);
        $this->makeTicketOrder($event, EventTicketOrder::STATUS_PAID, 1);

        $this->get('/events/'.$event->slug)
            ->assertOk()
            ->assertSee('Sorry, this event is sold out.')
            ->assertDontSee('href="'.url('/events/'.$event->slug.'/tickets').'"', false);
    }

    public function test_non_paid_event_page_is_unchanged(): void
    {
        $event = $this->makePaidEvent([
            'registration_type' => 'open', 'ticket_price_member' => null, 'ticket_price_non_member' => null,
        ]);

        $this->get('/events/'.$event->slug)
            ->assertOk()
            ->assertDontSee('Get tickets')
            ->assertDontSee('Ticket prices');
    }

    public function test_payments_are_recorded_against_the_ticket_order_only(): void
    {
        $order = $this->placed();

        $this->assertSame(1, Payment::query()->where('order_number', $order->order_number)->count());
        $this->assertDatabaseCount('orders', 0);
    }
}
