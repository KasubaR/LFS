<?php

namespace Tests\Feature\Admin;

use App\Models\Event;
use App\Models\EventTicket;
use App\Models\EventTicketOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\ActsAsAdmin;
use Tests\TestCase;

/**
 * Phase 1 of docs/EVENT_TICKETING_PLAN.md: ticketing settings on the admin
 * event form and the ticket order / ticket tables behind them.
 */
class EventTicketingAdminTest extends TestCase
{
    use ActsAsAdmin;
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function paidPayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'End of Year Party',
            'slug' => 'end-of-year-party-'.Str::random(8),
            'description' => 'Annual party.',
            'location' => 'Lusaka',
            'recurrenceType' => 'none',
            'eventDate' => now()->addDays(30)->format('Y-m-d\TH:i'),
            'category' => 'Social',
            'registrationType' => 'paid',
            'ticketPriceMember' => '550',
            'ticketPriceNonMember' => '650',
            'ticketCapacity' => '100',
            'ticketSalesOpen' => '1',
            'ticketStatus' => 'scheduled',
            'dist_label' => [''],
            'dist_route_existing' => [''],
        ], $overrides);
    }

    private function createPaidEvent(array $overrides = []): Event
    {
        $this->actingAsAdmin()->post('/admin/events', $this->paidPayload($overrides))
            ->assertRedirect('/admin/events');

        return Event::query()->latest('created_at')->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeOrder(Event $event, string $status, int $quantity, array $overrides = []): EventTicketOrder
    {
        return EventTicketOrder::query()->create(array_merge([
            'id' => (string) Str::uuid(),
            'order_number' => 'LFSEV-'.strtoupper(Str::random(10)),
            'event_id' => $event->id,
            'buyer_name' => 'Buyer One',
            'buyer_email' => 'buyer@example.test',
            'standard_unit_price' => 650,
            'quantity' => $quantity,
            'total' => 650 * $quantity,
            'status' => $status,
        ], $overrides));
    }

    public function test_paid_event_saves_prices_capacity_and_switches(): void
    {
        $event = $this->createPaidEvent();

        $this->assertSame('paid', $event->registration_type);
        $this->assertSame('550.00', (string) $event->ticket_price_member);
        $this->assertSame('650.00', (string) $event->ticket_price_non_member);
        $this->assertSame(100, $event->ticket_capacity);
        $this->assertTrue($event->ticket_sales_open);
        $this->assertSame('scheduled', $event->ticket_status);
    }

    public function test_empty_capacity_means_unlimited(): void
    {
        $event = $this->createPaidEvent(['ticketCapacity' => '']);

        $this->assertNull($event->ticket_capacity);
    }

    public function test_paid_event_requires_both_prices(): void
    {
        $this->actingAsAdmin()
            ->post('/admin/events', $this->paidPayload(['ticketPriceNonMember' => '']))
            ->assertOk()
            ->assertSee('Enter both the member price and the non-member price');

        $this->assertDatabaseCount('events', 0);
    }

    public function test_member_price_cannot_exceed_non_member_price(): void
    {
        $this->actingAsAdmin()
            ->post('/admin/events', $this->paidPayload(['ticketPriceMember' => '700', 'ticketPriceNonMember' => '650']))
            ->assertOk()
            ->assertSee('member price cannot be higher');

        $this->assertDatabaseCount('events', 0);
    }

    public function test_zero_negative_and_non_numeric_prices_are_rejected(): void
    {
        foreach (['0', '-5', 'abc', '10.999'] as $bad) {
            $this->actingAsAdmin()
                ->post('/admin/events', $this->paidPayload(['ticketPriceMember' => $bad]))
                ->assertOk();
        }

        $this->assertDatabaseCount('events', 0);
    }

    public function test_invalid_capacity_is_rejected(): void
    {
        foreach (['0', '-3', '2.5', 'many'] as $bad) {
            $this->actingAsAdmin()
                ->post('/admin/events', $this->paidPayload(['ticketCapacity' => $bad]))
                ->assertOk();
        }

        $this->assertDatabaseCount('events', 0);
    }

    public function test_paid_tickets_are_only_allowed_for_social_events(): void
    {
        foreach (['LSD', 'Road Race', 'Training', 'Training Camp', 'Other', ''] as $category) {
            $this->actingAsAdmin()
                ->post('/admin/events', $this->paidPayload(['category' => $category]))
                ->assertOk()
                ->assertSee('only available for Social events');
        }

        $this->assertDatabaseCount('events', 0);

        // Social is allowed.
        $this->createPaidEvent(['category' => 'Social']);
        $this->assertDatabaseCount('events', 1);
    }

    public function test_every_non_social_event_keeps_its_normal_registration_options(): void
    {
        foreach (['open', 'members', 'none'] as $type) {
            foreach (['LSD', 'Road Race', 'Training Camp', 'Social'] as $category) {
                $this->actingAsAdmin()
                    ->post('/admin/events', $this->paidPayload([
                        'category' => $category,
                        'registrationType' => $type,
                        'registrationLink' => 'https://squidal.com/event',
                        'slug' => 'evt-'.$type.'-'.str_replace(' ', '-', strtolower($category)),
                    ]))
                    ->assertRedirect('/admin/events');
            }
        }

        $this->assertDatabaseCount('events', 12);
        $event = Event::query()->where('slug', 'evt-open-lsd')->firstOrFail();
        $this->assertSame('open', $event->registration_type);
        $this->assertSame('https://squidal.com/event', $event->registration_link, 'external registration link still works');
        $this->assertNull($event->ticket_price_member);
    }

    public function test_a_social_event_can_still_use_the_normal_registration_instead_of_tickets(): void
    {
        $this->actingAsAdmin()->post('/admin/events', $this->paidPayload([
            'category' => 'Social', 'registrationType' => 'open', 'registrationLink' => 'https://squidal.com/party',
        ]))->assertRedirect('/admin/events');

        $event = Event::query()->firstOrFail();
        $this->assertSame('open', $event->registration_type);
        $this->assertNull($event->ticket_price_member);

        $this->get('/events/'.$event->slug)->assertOk()->assertDontSee('Get tickets');
    }

    public function test_changing_a_paid_event_to_a_non_social_category_is_refused(): void
    {
        $event = $this->createPaidEvent();

        $this->actingAsAdmin()
            ->post('/admin/events/'.$event->id, $this->paidPayload(['slug' => $event->slug, 'category' => 'LSD']))
            ->assertOk()
            ->assertSee('only available for Social events');

        $this->assertSame('Social', $event->fresh()->category);
        $this->assertSame('paid', $event->fresh()->registration_type);
    }

    public function test_form_offers_paid_tickets_as_an_optional_extra_for_social_only(): void
    {
        $html = $this->actingAsAdmin()->get('/admin/events/create')->assertOk()->getContent();

        // The normal options are all still there.
        foreach (['Open to all', 'Members only', 'No registration', 'External registration link'] as $label) {
            $this->assertStringContainsString($label, $html);
        }
        $this->assertStringContainsString('Paid tickets', $html);
        $this->assertStringContainsString('["Social"]', $html, 'the page is told which categories may sell tickets');
        $this->assertStringContainsString('Social events only', $html);
    }

    public function test_weekly_recurrence_is_rejected_for_paid_events(): void
    {
        $this->actingAsAdmin()
            ->post('/admin/events', $this->paidPayload([
                'recurrenceType' => 'weekly',
                'recurrence_days' => ['saturday'],
                'eventDate' => '',
            ]))
            ->assertOk()
            ->assertSee('must be one-off');

        $this->assertDatabaseCount('events', 0);
    }

    public function test_non_paid_event_stores_no_ticket_fields(): void
    {
        $this->actingAsAdmin()->post('/admin/events', $this->paidPayload([
            'registrationType' => 'open',
        ]))->assertRedirect('/admin/events');

        $event = Event::query()->firstOrFail();
        $this->assertNull($event->ticket_price_member);
        $this->assertNull($event->ticket_price_non_member);
        $this->assertNull($event->ticket_capacity);
        $this->assertSame('scheduled', $event->ticket_status);
    }

    public function test_unknown_ticket_status_is_rejected(): void
    {
        $this->actingAsAdmin()
            ->post('/admin/events', $this->paidPayload(['ticketStatus' => 'exploded']))
            ->assertOk();

        $this->assertDatabaseCount('events', 0);
    }

    public function test_capacity_cannot_be_lowered_below_tickets_already_sold_or_reserved(): void
    {
        $event = $this->createPaidEvent();

        $this->makeOrder($event, EventTicketOrder::STATUS_PAID, 40);
        $this->makeOrder($event, EventTicketOrder::STATUS_PENDING_PAYMENT, 5, [
            'expires_at' => now()->addMinutes(10),
        ]);
        // These must NOT count: payment review (no valid tickets), expired
        // reservation, failed and cancelled orders.
        $this->makeOrder($event, EventTicketOrder::STATUS_PAYMENT_REVIEW_REQUIRED, 5);
        $this->makeOrder($event, EventTicketOrder::STATUS_PENDING_PAYMENT, 20, [
            'expires_at' => now()->subMinutes(10),
        ]);
        $this->makeOrder($event, EventTicketOrder::STATUS_FAILED, 20);
        $this->makeOrder($event, EventTicketOrder::STATUS_CANCELLED, 20);

        // 45 committed. 44 is refused...
        $this->actingAsAdmin()
            ->post('/admin/events/'.$event->id, $this->paidPayload([
                'slug' => $event->slug,
                'ticketCapacity' => '44',
            ]))
            ->assertOk()
            ->assertSee('45 ticket(s) already sold or reserved');
        $this->assertSame(100, $event->fresh()->ticket_capacity);

        // ...exactly 45 is allowed, and so is raising it.
        $this->actingAsAdmin()
            ->post('/admin/events/'.$event->id, $this->paidPayload([
                'slug' => $event->slug,
                'ticketCapacity' => '45',
            ]))
            ->assertRedirect('/admin/events');
        $this->assertSame(45, $event->fresh()->ticket_capacity);

        $this->actingAsAdmin()
            ->post('/admin/events/'.$event->id, $this->paidPayload([
                'slug' => $event->slug,
                'ticketCapacity' => '200',
            ]))
            ->assertRedirect('/admin/events');
        $this->assertSame(200, $event->fresh()->ticket_capacity);
    }

    public function test_paid_event_with_live_orders_cannot_switch_registration_type(): void
    {
        $event = $this->createPaidEvent();
        $this->makeOrder($event, EventTicketOrder::STATUS_PAID, 2);

        $this->actingAsAdmin()
            ->post('/admin/events/'.$event->id, $this->paidPayload([
                'slug' => $event->slug,
                'registrationType' => 'open',
            ]))
            ->assertOk()
            ->assertSee('cannot be changed away from paid ticketing');

        $this->assertSame('paid', $event->fresh()->registration_type);
    }

    public function test_paid_event_without_orders_can_switch_registration_type(): void
    {
        $event = $this->createPaidEvent();

        $this->actingAsAdmin()
            ->post('/admin/events/'.$event->id, $this->paidPayload([
                'slug' => $event->slug,
                'registrationType' => 'open',
            ]))
            ->assertRedirect('/admin/events');

        $fresh = $event->fresh();
        $this->assertSame('open', $fresh->registration_type);
        $this->assertNull($fresh->ticket_price_member);
    }

    public function test_edit_form_shows_ticketing_section_with_saved_values(): void
    {
        $event = $this->createPaidEvent();

        $this->actingAsAdmin()
            ->get('/admin/events/'.$event->id.'/edit')
            ->assertOk()
            ->assertSee('id="ticketing_wrap"', false)
            ->assertSee('value="550.00"', false)
            ->assertSee('value="650.00"', false)
            ->assertSee('Paid tickets');
    }

    public function test_sales_switch_can_be_turned_off(): void
    {
        $event = $this->createPaidEvent();

        // An unchecked checkbox is simply absent from the POST.
        $payload = $this->paidPayload(['slug' => $event->slug]);
        unset($payload['ticketSalesOpen']);

        $this->actingAsAdmin()
            ->post('/admin/events/'.$event->id, $payload)
            ->assertRedirect('/admin/events');

        $this->assertFalse($event->fresh()->ticket_sales_open);
    }

    public function test_orders_and_tickets_keep_buyer_and_attendee_separate(): void
    {
        $event = $this->createPaidEvent();
        $order = $this->makeOrder($event, EventTicketOrder::STATUS_PAID, 2, [
            'buyer_name' => 'John Banda',
            'buyer_email' => 'john@example.test',
            'payer_phone' => '0971111111',
            'buyer_phone' => '0972222222',
        ]);

        EventTicket::query()->create([
            'id' => (string) Str::uuid(),
            'order_id' => $order->id,
            'event_id' => $event->id,
            'position' => 1,
            'attendee_name' => 'John Banda',
            'ticket_reference' => $order->order_number.'-1',
            'price' => 550,
            'is_member_discount' => true,
            'membership_number' => 'LFS-0001',
        ]);
        EventTicket::query()->create([
            'id' => (string) Str::uuid(),
            'order_id' => $order->id,
            'event_id' => $event->id,
            'position' => 2,
            'attendee_name' => 'Mary Phiri',
            'attendee_email' => null,
            'ticket_reference' => $order->order_number.'-2',
            'price' => 650,
        ]);

        $tickets = $order->fresh()->tickets;
        $this->assertCount(2, $tickets);
        $this->assertSame(['John Banda', 'Mary Phiri'], $tickets->pluck('attendee_name')->all());
        $this->assertNull($tickets[1]->attendee_email, 'attendee email must not default to the buyer email');
        $this->assertTrue($tickets[0]->is_member_discount);
        $this->assertFalse($tickets[1]->is_member_discount);
        $this->assertNull($tickets[0]->checked_in_at);
        $this->assertSame(0, $tickets[0]->name_correction_count);
        $this->assertSame($event->id, $order->event->id);
    }

    public function test_ticket_position_and_reference_must_be_unique(): void
    {
        $event = $this->createPaidEvent();
        $order = $this->makeOrder($event, EventTicketOrder::STATUS_PAID, 1);

        $base = [
            'order_id' => $order->id,
            'event_id' => $event->id,
            'position' => 1,
            'attendee_name' => 'A Person',
            'price' => 650,
        ];

        EventTicket::query()->create($base + ['id' => (string) Str::uuid(), 'ticket_reference' => 'REF-1']);

        $this->expectException(\Illuminate\Database\QueryException::class);
        EventTicket::query()->create($base + ['id' => (string) Str::uuid(), 'ticket_reference' => 'REF-2']);
    }

    public function test_deleting_an_event_removes_its_orders_and_tickets(): void
    {
        $event = $this->createPaidEvent();
        $order = $this->makeOrder($event, EventTicketOrder::STATUS_PENDING_PAYMENT, 1);
        EventTicket::query()->create([
            'id' => (string) Str::uuid(),
            'order_id' => $order->id,
            'event_id' => $event->id,
            'position' => 1,
            'attendee_name' => 'A Person',
            'ticket_reference' => 'REF-X',
            'price' => 650,
        ]);

        $event->delete();

        $this->assertDatabaseCount('event_ticket_orders', 0);
        $this->assertDatabaseCount('event_tickets', 0);
    }
}
