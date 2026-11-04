<?php

namespace Tests\Feature\Admin;

use App\Enums\AdminRole;
use App\Models\Event;
use App\Models\EventTicket;
use App\Models\EventTicketAudit;
use App\Models\EventTicketOrder;
use App\Services\Tickets\TicketAttendanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\ActsAsAdmin;
use Tests\Concerns\MakesTicketFixtures;
use Tests\TestCase;

/**
 * Step 11: the admin ticket list, the entrance attendance flow (search and
 * mark present, no scanning), the audit trail, and the CSV export.
 */
class EventTicketAdminPagesTest extends TestCase
{
    use ActsAsAdmin;
    use MakesTicketFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMembershipBasics();
    }

    /**
     * An order with one ticket per attendee name.
     *
     * @param  list<string>  $names
     * @param  array<string, mixed>  $order
     */
    private function orderWith(Event $event, array $names, string $status = EventTicketOrder::STATUS_PAID, array $order = []): EventTicketOrder
    {
        $o = $this->makeTicketOrder($event, $status, count($names), array_merge([
            'buyer_name' => 'Buyer One',
            'buyer_email' => 'buyer'.Str::random(4).'@example.test',
            'paid_at' => $status === 'paid' ? now() : null,
        ], $order));

        foreach ($names as $i => $name) {
            EventTicket::query()->create([
                'id' => (string) Str::uuid(),
                'order_id' => $o->id,
                'event_id' => $event->id,
                'position' => $i + 1,
                'attendee_name' => $name,
                'ticket_reference' => $o->order_number.'-'.($i + 1),
                'price' => 650,
            ]);
        }

        return $o->fresh('tickets');
    }

    private function officer(): \App\Models\AdminUser
    {
        return $this->makeAdminUser(['role' => AdminRole::EventsOfficer, 'name' => 'Olivia Officer']);
    }

    private function auditor(): \App\Models\AdminUser
    {
        return $this->makeAdminUser(['role' => AdminRole::ReadOnlyAuditor]);
    }

    // ── Ticket list page ─────────────────────────────────────────────────────

    public function test_ticket_list_shows_stats_buyers_and_attendees(): void
    {
        $event = $this->makePaidEvent(['title' => 'End of Year Party', 'ticket_capacity' => 50]);
        $m = $this->makeMember('Chanda Mwale', 'chanda@example.com', [], 'LFS-000412');
        $member = $this->makeDiscountedOrder($event, $m, EventTicketOrder::STATUS_PAID, ['buyer_name' => 'Chanda Mwale', 'total' => 550, 'paid_at' => now()]);
        $guest = $this->orderWith($event, ['Ann Banda', 'Ben Banda'], EventTicketOrder::STATUS_PAID, ['buyer_name' => 'Pat Buyer', 'total' => 1300]);
        $guest->tickets[0]->forceFill(['checked_in_at' => now()])->save();

        $this->actingAsAdmin($this->officer())
            ->get('/admin/events/'.$event->id.'/tickets')
            ->assertOk()
            ->assertSee('Tickets sold')
            ->assertSee('K1,850.00')                // collected
            ->assertSee('Pat Buyer')
            ->assertSee('Ann Banda')
            ->assertSee('Ben Banda')
            ->assertSee('Chanda Mwale')
            ->assertSee('member LFS-000412')
            ->assertSee($guest->order_number.'-1')
            ->assertSee('1 member ticket(s), 2 standard')
            ->assertSee('Entrance attendance')
            ->assertSee('Export attendees');
    }

    public function test_stats_are_calculated_correctly(): void
    {
        $event = $this->makePaidEvent(['ticket_capacity' => 20]);
        $m = $this->makeMember();
        $this->makeDiscountedOrder($event, $m, EventTicketOrder::STATUS_PAID, ['total' => 550, 'paid_at' => now()]);
        $b = $this->orderWith($event, ['A One', 'B Two', 'C Three'], EventTicketOrder::STATUS_PAID, ['total' => 1950]);
        $b->tickets[0]->forceFill(['checked_in_at' => now()])->save();
        $b->tickets[1]->forceFill(['checked_in_at' => now()])->save();
        $this->orderWith($event, ['P One'], EventTicketOrder::STATUS_PENDING_PAYMENT, ['expires_at' => now()->addMinutes(5)]);
        $this->orderWith($event, ['X One', 'X Two'], EventTicketOrder::STATUS_PENDING_PAYMENT, ['expires_at' => now()->subMinutes(5)]); // expired: ignored
        $this->orderWith($event, ['R One'], EventTicketOrder::STATUS_PAYMENT_REVIEW_REQUIRED, ['review_reason' => 'amount_mismatch']);
        $this->orderWith($event, ['F One'], EventTicketOrder::STATUS_FAILED);

        $stats = app(\App\Services\Tickets\TicketReportService::class)->stats($event);

        $this->assertSame(4, $stats['ticketsSold']);
        $this->assertSame(2, $stats['buyers']);
        $this->assertSame(2500.0, $stats['revenue']);
        $this->assertSame(1, $stats['memberTickets']);
        $this->assertSame(3, $stats['standardTickets']);
        $this->assertSame(2, $stats['present']);
        $this->assertSame(2, $stats['notYetPresent']);
        $this->assertSame(1, $stats['pendingOrders']);
        $this->assertSame(1, $stats['pendingTickets']);
        $this->assertSame(1, $stats['reviewOrders']);
        $this->assertSame(15, $stats['remaining'], '20 - 4 paid - 1 live pending');
    }

    public function test_review_orders_are_called_out_with_the_reason(): void
    {
        $event = $this->makePaidEvent();
        $this->orderWith($event, ['R One'], EventTicketOrder::STATUS_PAYMENT_REVIEW_REQUIRED, [
            'review_reason' => 'capacity_unavailable', 'buyer_name' => 'Late Payer', 'total' => 650,
        ]);

        $this->actingAsAdmin($this->officer())
            ->get('/admin/events/'.$event->id.'/tickets')
            ->assertOk()
            ->assertSee('1 order(s) need review')
            ->assertSee('money received, tickets not confirmed')
            ->assertSee('Late Payer')
            ->assertSee('Paid after the reservation expired and the tickets were sold');
    }

    public function test_no_review_banner_when_nothing_needs_review(): void
    {
        $event = $this->makePaidEvent();
        $this->orderWith($event, ['A One']);

        $this->actingAsAdmin($this->officer())
            ->get('/admin/events/'.$event->id.'/tickets')
            ->assertDontSee('need review');
    }

    public function test_status_filter_and_search_narrow_the_list(): void
    {
        $event = $this->makePaidEvent();
        $this->orderWith($event, ['Zed Paid'], EventTicketOrder::STATUS_PAID, ['buyer_name' => 'Paid Buyer']);
        $this->orderWith($event, ['Zed Failed'], EventTicketOrder::STATUS_FAILED, ['buyer_name' => 'Failed Buyer']);
        $url = '/admin/events/'.$event->id.'/tickets';
        $admin = $this->officer();

        $this->actingAsAdmin($admin)->get($url)->assertSee('Paid Buyer')->assertDontSee('Failed Buyer');
        $this->actingAsAdmin($admin)->get($url.'?status=failed')->assertSee('Failed Buyer')->assertDontSee('Paid Buyer');
        $this->actingAsAdmin($admin)->get($url.'?status=all')->assertSee('Paid Buyer')->assertSee('Failed Buyer');
        $this->actingAsAdmin($admin)->get($url.'?status=all&q=Zed+Failed')->assertSee('Failed Buyer')->assertDontSee('Paid Buyer');
        $this->actingAsAdmin($admin)->get($url.'?status=bogus')->assertOk()->assertSee('Paid Buyer');
    }

    public function test_list_is_paginated(): void
    {
        $event = $this->makePaidEvent(['ticket_capacity' => null]);
        for ($i = 1; $i <= 55; $i++) {
            $this->orderWith($event, ['Person '.$i], EventTicketOrder::STATUS_PAID, ['buyer_name' => 'Buyer '.str_pad((string) $i, 3, '0', STR_PAD_LEFT)]);
        }

        $page1 = $this->actingAsAdmin($this->officer())->get('/admin/events/'.$event->id.'/tickets');
        $page2 = $this->actingAsAdmin($this->officer())->get('/admin/events/'.$event->id.'/tickets?page=2');

        $orderRow = '/<tr style="border-bottom:1px solid var\(--border-subtle\); vertical-align:top;">/';
        $this->assertSame(50, preg_match_all($orderRow, $page1->getContent()), '50 orders per page');
        $this->assertSame(5, preg_match_all($orderRow, $page2->getContent()), 'the remainder on page 2');
    }

    public function test_non_paid_event_and_unknown_event_redirect_away(): void
    {
        $open = $this->makePaidEvent(['registration_type' => 'open']);
        $admin = $this->officer();

        $this->actingAsAdmin($admin)->get('/admin/events/'.$open->id.'/tickets')->assertRedirect('/admin/events/list');
        $this->actingAsAdmin($admin)->get('/admin/events/'.$open->id.'/attendance')->assertRedirect('/admin/events/list');
        $this->actingAsAdmin($admin)->get('/admin/events/'.$open->id.'/tickets/export')->assertRedirect('/admin/events/list');
        $this->actingAsAdmin($admin)->get('/admin/events/'.Str::uuid().'/tickets')->assertRedirect('/admin/events/list');
        $this->actingAsAdmin($admin)->getJson('/admin/events/'.$open->id.'/attendance/search?q=anything')->assertNotFound();
    }

    public function test_events_list_links_to_tickets_only_for_paid_events(): void
    {
        $paid = $this->makePaidEvent(['title' => 'Party', 'slug' => 'party-evt']);
        $open = $this->makePaidEvent(['title' => 'Run', 'slug' => 'run-evt', 'registration_type' => 'open']);

        $html = $this->actingAsAdmin($this->officer())->get('/admin/events/list')->assertOk()->getContent();

        $this->assertStringContainsString('/admin/events/'.$paid->id.'/tickets"', $html);
        $this->assertStringNotContainsString('/admin/events/'.$open->id.'/tickets"', $html);
    }

    public function test_pages_require_login(): void
    {
        $event = $this->makePaidEvent();

        $this->get('/admin/events/'.$event->id.'/tickets')->assertRedirect();
        $this->get('/admin/events/'.$event->id.'/attendance')->assertRedirect();
        $this->getJson('/admin/events/'.$event->id.'/attendance/search?q=ab')->assertUnauthorized();
        $this->postJson('/admin/events/'.$event->id.'/tickets/'.Str::uuid().'/present')->assertUnauthorized();
    }

    // ── Entrance search ──────────────────────────────────────────────────────

    public function test_attendance_page_renders_the_search_box_and_counter(): void
    {
        $event = $this->makePaidEvent();
        $this->orderWith($event, ['A One', 'B Two']);

        $this->actingAsAdmin($this->officer())
            ->get('/admin/events/'.$event->id.'/attendance')
            ->assertOk()
            ->assertSee('id="att-search"', false)
            ->assertSee('confirm the customer', false)
            ->assertSee('ticket-attendance.js', false);
    }

    public function test_search_finds_an_order_by_every_identifier(): void
    {
        $event = $this->makePaidEvent();
        $o = $this->orderWith($event, ['Natasha Phiri', 'Kondwani Zulu'], EventTicketOrder::STATUS_PAID, [
            'buyer_name' => 'Mutale Chanda', 'buyer_email' => 'mutale.chanda@example.test',
        ]);
        $o->tickets[1]->forceFill(['attendee_email' => 'kondwani@work.test'])->save();
        $admin = $this->officer();

        foreach ([
            'natasha',                      // attendee name
            'mutale chan',                  // buyer name
            $o->order_number,               // order number
            substr($o->order_number, -5),   // part of the order number
            'mutale.chanda@example',        // buyer email
            'kondwani@work',                // attendee email
            $o->order_number.'-2',          // ticket reference
        ] as $query) {
            $json = $this->actingAsAdmin($admin)
                ->getJson('/admin/events/'.$event->id.'/attendance/search?q='.urlencode($query))
                ->assertOk()->json();

            $this->assertCount(1, $json['results'], "search for '{$query}'");
            $this->assertSame($o->order_number, $json['results'][0]['orderNumber']);
        }
    }

    public function test_search_result_has_everything_staff_need_at_the_door(): void
    {
        $event = $this->makePaidEvent();
        $m = $this->makeMember('Chanda Mwale', 'chanda@example.com', [], 'LFS-000412');
        $o = $this->makeDiscountedOrder($event, $m, EventTicketOrder::STATUS_PAID, ['buyer_name' => 'Chanda Mwale', 'buyer_email' => 'chanda@example.com', 'paid_at' => now()]);

        $r = $this->actingAsAdmin($this->officer())
            ->getJson('/admin/events/'.$event->id.'/attendance/search?q=chanda')->json('results.0');

        $this->assertSame('Chanda Mwale', $r['buyerName']);
        $this->assertSame('chanda@example.com', $r['buyerEmail']);
        $this->assertSame(1, $r['quantity']);
        $this->assertSame(0, $r['presentCount']);
        $this->assertTrue($r['canAdmit']);
        $this->assertSame('paid', $r['status']);
        $this->assertTrue($r['tickets'][0]['isMemberTicket']);
        $this->assertSame('LFS-000412', $r['tickets'][0]['membershipNumber']);
        $this->assertTrue($r['tickets'][0]['canMark']);
        $this->assertSame($o->order_number.'-1', $r['tickets'][0]['reference']);
    }

    public function test_short_queries_return_nothing_and_wildcards_are_literal(): void
    {
        $event = $this->makePaidEvent();
        $this->orderWith($event, ['Ann Banda']);
        $admin = $this->officer();
        $url = '/admin/events/'.$event->id.'/attendance/search?q=';

        $this->assertCount(0, $this->actingAsAdmin($admin)->getJson($url.'a')->json('results'), 'one character');
        $this->assertCount(0, $this->actingAsAdmin($admin)->getJson($url)->json('results'), 'empty');
        $this->assertCount(0, $this->actingAsAdmin($admin)->getJson($url.urlencode('%%'))->json('results'), '% is not a wildcard');
        $this->assertCount(0, $this->actingAsAdmin($admin)->getJson($url.urlencode('__'))->json('results'), '_ is not a wildcard');
    }

    public function test_search_never_returns_another_events_orders(): void
    {
        $a = $this->makePaidEvent();
        $b = $this->makePaidEvent();
        $this->orderWith($a, ['Shared Name']);
        $this->orderWith($b, ['Shared Name']);

        $json = $this->actingAsAdmin($this->officer())->getJson('/admin/events/'.$a->id.'/attendance/search?q=Shared')->json();

        $this->assertCount(1, $json['results']);
    }

    public function test_unpaid_orders_are_listed_but_cannot_admit_and_paid_come_first(): void
    {
        $event = $this->makePaidEvent();
        $this->orderWith($event, ['Sam Pending'], EventTicketOrder::STATUS_PENDING_PAYMENT, ['expires_at' => now()->addMinutes(5)]);
        $this->orderWith($event, ['Sam Paid'], EventTicketOrder::STATUS_PAID);
        $this->orderWith($event, ['Sam Review'], EventTicketOrder::STATUS_PAYMENT_REVIEW_REQUIRED);

        $results = $this->actingAsAdmin($this->officer())
            ->getJson('/admin/events/'.$event->id.'/attendance/search?q=Sam')->json('results');

        $this->assertCount(3, $results);
        $this->assertSame('paid', $results[0]['status'], 'paid first');
        foreach (array_slice($results, 1) as $r) {
            $this->assertFalse($r['canAdmit']);
            $this->assertFalse($r['tickets'][0]['canMark']);
        }
    }

    // ── Mark present ─────────────────────────────────────────────────────────

    public function test_marking_present_records_it_and_writes_an_audit_entry(): void
    {
        $event = $this->makePaidEvent();
        $o = $this->orderWith($event, ['Ann Banda', 'Ben Banda']);
        $admin = $this->officer();

        $this->actingAsAdmin($admin)
            ->postJson('/admin/events/'.$event->id.'/tickets/'.$o->tickets[0]->id.'/present')
            ->assertOk()
            ->assertJsonPath('result', 'marked')
            ->assertJsonPath('ticket.present', true)
            ->assertJsonPath('stats.present', 1);

        $this->assertNotNull($o->tickets[0]->fresh()->checked_in_at);
        $this->assertNull($o->tickets[1]->fresh()->checked_in_at, 'only that attendee is marked');

        $audit = EventTicketAudit::query()->firstOrFail();
        $this->assertSame(EventTicketAudit::ACTION_MARK_PRESENT, $audit->action);
        $this->assertSame($o->tickets[0]->id, $audit->ticket_id);
        $this->assertSame($o->id, $audit->order_id);
        $this->assertSame((string) $admin->id, $audit->admin_id);
        $this->assertSame('Olivia Officer', $audit->admin_name);
        $this->assertSame('Ann Banda', $audit->details['attendee']);
    }

    public function test_the_same_attendee_cannot_be_admitted_twice(): void
    {
        $event = $this->makePaidEvent();
        $o = $this->orderWith($event, ['Ann Banda']);
        $url = '/admin/events/'.$event->id.'/tickets/'.$o->tickets[0]->id.'/present';
        $admin = $this->officer();

        $this->actingAsAdmin($admin)->postJson($url)->assertOk();
        $firstAt = $o->tickets[0]->fresh()->checked_in_at;

        $this->actingAsAdmin($admin)->postJson($url)
            ->assertStatus(409)
            ->assertJsonPath('result', 'already_present')
            ->assertJsonPath('ok', false);

        $this->assertEquals($firstAt, $o->tickets[0]->fresh()->checked_in_at, 'the original time is kept');
        $this->assertSame(1, EventTicketAudit::query()->count(), 'a refused second tap is not an admission');
    }

    public function test_two_organisers_marking_at_once_admit_exactly_one(): void
    {
        $event = $this->makePaidEvent();
        $o = $this->orderWith($event, ['Ann Banda']);
        $svc = app(TicketAttendanceService::class);

        $first = $svc->markPresent($event, $o->tickets[0]->id, '1', 'Organiser A');
        $second = $svc->markPresent($event, $o->tickets[0]->id, '2', 'Organiser B');

        $this->assertSame(TicketAttendanceService::MARKED, $first['result']);
        $this->assertSame(TicketAttendanceService::ALREADY_PRESENT, $second['result']);
        $this->assertSame(1, EventTicketAudit::query()->count());
    }

    public function test_admitted_people_can_never_exceed_tickets_purchased(): void
    {
        $event = $this->makePaidEvent();
        $o = $this->orderWith($event, ['A One', 'B Two']);
        $admin = $this->officer();

        foreach ($o->tickets as $t) {
            // tapping three times each must not admit more than two people
            for ($i = 0; $i < 3; $i++) {
                $this->actingAsAdmin($admin)->postJson('/admin/events/'.$event->id.'/tickets/'.$t->id.'/present');
            }
        }

        $stats = app(\App\Services\Tickets\TicketReportService::class)->stats($event);
        $this->assertSame(2, $stats['present']);
        $this->assertSame(2, $stats['ticketsSold']);
        $this->assertSame(2, EventTicketAudit::query()->count());
    }

    public function test_unpaid_orders_cannot_be_admitted(): void
    {
        $event = $this->makePaidEvent();
        $admin = $this->officer();

        foreach ([
            EventTicketOrder::STATUS_PENDING_PAYMENT, EventTicketOrder::STATUS_PAYMENT_REVIEW_REQUIRED,
            EventTicketOrder::STATUS_CANCELLED, EventTicketOrder::STATUS_FAILED,
        ] as $status) {
            $o = $this->orderWith($event, ['Nope '.$status], $status);

            $this->actingAsAdmin($admin)
                ->postJson('/admin/events/'.$event->id.'/tickets/'.$o->tickets[0]->id.'/present')
                ->assertStatus(422)
                ->assertJsonPath('result', 'not_payable');

            $this->assertNull($o->tickets[0]->fresh()->checked_in_at, $status);
        }

        $this->assertSame(0, EventTicketAudit::query()->count());
    }

    public function test_a_ticket_from_another_event_or_unknown_id_is_not_found(): void
    {
        $a = $this->makePaidEvent();
        $b = $this->makePaidEvent();
        $other = $this->orderWith($b, ['Elsewhere']);
        $admin = $this->officer();

        $this->actingAsAdmin($admin)
            ->postJson('/admin/events/'.$a->id.'/tickets/'.$other->tickets[0]->id.'/present')->assertNotFound();
        $this->actingAsAdmin($admin)
            ->postJson('/admin/events/'.$a->id.'/tickets/'.Str::uuid().'/present')->assertNotFound();

        $this->assertNull($other->tickets[0]->fresh()->checked_in_at);
    }

    // ── Undo ─────────────────────────────────────────────────────────────────

    public function test_undo_needs_a_reason_and_is_audited(): void
    {
        $event = $this->makePaidEvent();
        $o = $this->orderWith($event, ['Ann Banda']);
        $o->tickets[0]->forceFill(['checked_in_at' => now()])->save();
        $url = '/admin/events/'.$event->id.'/tickets/'.$o->tickets[0]->id.'/undo-present';
        $admin = $this->officer();

        $this->actingAsAdmin($admin)->postJson($url, [])->assertStatus(422);
        $this->actingAsAdmin($admin)->postJson($url, ['reason' => 'ab'])->assertStatus(422);
        $this->assertNotNull($o->tickets[0]->fresh()->checked_in_at, 'nothing changes without a reason');

        $this->actingAsAdmin($admin)->postJson($url, ['reason' => 'Tapped the wrong person'])
            ->assertOk()->assertJsonPath('result', 'undone')->assertJsonPath('ticket.present', false);

        $this->assertNull($o->tickets[0]->fresh()->checked_in_at);
        $audit = EventTicketAudit::query()->where('action', EventTicketAudit::ACTION_UNDO_PRESENT)->firstOrFail();
        $this->assertSame('Tapped the wrong person', $audit->reason);
        $this->assertSame('Olivia Officer', $audit->admin_name);
        $this->assertNotNull($audit->details['wasPresentAt']);
    }

    public function test_undoing_someone_who_is_not_present_is_refused(): void
    {
        $event = $this->makePaidEvent();
        $o = $this->orderWith($event, ['Ann Banda']);

        $this->actingAsAdmin($this->officer())
            ->postJson('/admin/events/'.$event->id.'/tickets/'.$o->tickets[0]->id.'/undo-present', ['reason' => 'because'])
            ->assertStatus(409)
            ->assertJsonPath('result', 'not_present');

        $this->assertSame(0, EventTicketAudit::query()->count());
    }

    public function test_an_attendee_can_be_marked_again_after_an_audited_undo(): void
    {
        $event = $this->makePaidEvent();
        $o = $this->orderWith($event, ['Ann Banda']);
        $base = '/admin/events/'.$event->id.'/tickets/'.$o->tickets[0]->id;
        $admin = $this->officer();

        $this->actingAsAdmin($admin)->postJson($base.'/present')->assertOk();
        $this->actingAsAdmin($admin)->postJson($base.'/undo-present', ['reason' => 'mistake'])->assertOk();
        $this->actingAsAdmin($admin)->postJson($base.'/present')->assertOk();

        $this->assertSame(3, EventTicketAudit::query()->count());
    }

    // ── Permissions ──────────────────────────────────────────────────────────

    public function test_read_only_auditor_can_look_but_not_change_attendance(): void
    {
        $event = $this->makePaidEvent();
        $o = $this->orderWith($event, ['Ann Banda']);
        $o->tickets[0]->forceFill(['checked_in_at' => now()])->save();
        $o2 = $this->orderWith($event, ['Ben Banda']);
        $auditor = $this->auditor();
        $base = '/admin/events/'.$event->id;

        $this->actingAsAdmin($auditor)->get($base.'/tickets')->assertOk();
        $this->actingAsAdmin($auditor)->get($base.'/attendance')->assertOk();
        $this->actingAsAdmin($auditor)->getJson($base.'/attendance/search?q=Banda')->assertOk();
        $this->actingAsAdmin($auditor)->get($base.'/tickets/export')->assertOk();

        $this->actingAsAdmin($auditor)->postJson($base.'/tickets/'.$o2->tickets[0]->id.'/present')->assertForbidden();
        $this->actingAsAdmin($auditor)->postJson($base.'/tickets/'.$o->tickets[0]->id.'/undo-present', ['reason' => 'trying'])->assertForbidden();

        $this->assertNull($o2->tickets[0]->fresh()->checked_in_at);
        $this->assertNotNull($o->tickets[0]->fresh()->checked_in_at);
        $this->assertSame(0, EventTicketAudit::query()->count());
    }

    // ── CSV export ───────────────────────────────────────────────────────────

    private function csv(\Illuminate\Testing\TestResponse $r): array
    {
        $body = $r->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body, 'UTF-8 BOM for Excel');

        return array_map('str_getcsv', array_values(array_filter(explode("\n", str_replace("\r", '', substr($body, 3))))));
    }

    public function test_export_has_one_row_per_attendee_for_paid_orders(): void
    {
        $event = $this->makePaidEvent(['slug' => 'end-of-year-party']);
        $m = $this->makeMember('Chanda Mwale', 'chanda@example.com', [], 'LFS-000412');
        $this->makeDiscountedOrder($event, $m, EventTicketOrder::STATUS_PAID, ['buyer_name' => 'Chanda Mwale', 'paid_at' => now()]);
        $o = $this->orderWith($event, ['Ann Banda', 'Ben Banda'], EventTicketOrder::STATUS_PAID, ['buyer_name' => 'Pat Buyer', 'payer_phone' => '0971111111']);
        $o->tickets[0]->forceFill(['checked_in_at' => now(), 'attendee_email' => 'ann@example.test'])->save();
        $this->orderWith($event, ['Unpaid One'], EventTicketOrder::STATUS_FAILED);

        $response = $this->actingAsAdmin($this->officer())->get('/admin/events/'.$event->id.'/tickets/export');
        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment; filename=tickets-end-of-year-party-', $response->headers->get('Content-Disposition'));

        $rows = $this->csv($response);
        $this->assertSame('Order number', $rows[0][0]);
        $this->assertCount(4, $rows, 'header + 3 attendees; the failed order is excluded');

        $byName = collect(array_slice($rows, 1))->keyBy(7);
        $this->assertSame('Yes', $byName['Chanda Mwale'][10], 'member ticket');
        $this->assertSame('LFS-000412', $byName['Chanda Mwale'][11]);
        $this->assertSame('550.00', $byName['Chanda Mwale'][9]);
        $this->assertSame('Yes', $byName['Ann Banda'][12], 'present');
        $this->assertSame('ann@example.test', $byName['Ann Banda'][8]);
        $this->assertSame('No', $byName['Ben Banda'][12]);
        $this->assertSame('Pat Buyer', $byName['Ben Banda'][2], 'buyer is kept separate from the attendee');
        $this->assertSame('0971111111', $byName['Ben Banda'][5]);
        $this->assertFalse($byName->has('Unpaid One'));
    }

    public function test_export_can_include_every_status(): void
    {
        $event = $this->makePaidEvent();
        $this->orderWith($event, ['Paid One']);
        $this->orderWith($event, ['Failed One'], EventTicketOrder::STATUS_FAILED);
        $this->orderWith($event, ['Review One'], EventTicketOrder::STATUS_PAYMENT_REVIEW_REQUIRED);

        $rows = $this->csv($this->actingAsAdmin($this->officer())->get('/admin/events/'.$event->id.'/tickets/export?status=all'));

        $this->assertCount(4, $rows);
        $this->assertEqualsCanonicalizing(['paid', 'failed', 'payment_review_required'], array_column(array_slice($rows, 1), 1));
    }

    public function test_export_neutralises_spreadsheet_formulas(): void
    {
        $event = $this->makePaidEvent();
        $this->orderWith($event, ['=HYPERLINK("http://evil","x")', '+1+1', '@SUM(A1)', '-2+3', 'Normal Name'], EventTicketOrder::STATUS_PAID, [
            'buyer_name' => '=cmd|calc',
        ]);

        $rows = array_slice($this->csv($this->actingAsAdmin($this->officer())->get('/admin/events/'.$event->id.'/tickets/export')), 1);

        foreach ($rows as $row) {
            foreach ($row as $cell) {
                $this->assertDoesNotMatchRegularExpression('/^[=+\-@\t]/', $cell, "unsafe cell: {$cell}");
            }
        }
        $this->assertContains("'=HYPERLINK(\"http://evil\",\"x\")", array_column($rows, 7));
        $this->assertContains('Normal Name', array_column($rows, 7), 'ordinary values are untouched');
    }

    public function test_export_handles_commas_quotes_and_accents(): void
    {
        $event = $this->makePaidEvent();
        $this->orderWith($event, ['Mwape, "Junior" Zéla'], EventTicketOrder::STATUS_PAID, ['buyer_name' => 'Chola Ñ']);

        $rows = $this->csv($this->actingAsAdmin($this->officer())->get('/admin/events/'.$event->id.'/tickets/export'));

        $this->assertSame('Mwape, "Junior" Zéla', $rows[1][7]);
        $this->assertSame('Chola Ñ', $rows[1][2]);
    }
}
