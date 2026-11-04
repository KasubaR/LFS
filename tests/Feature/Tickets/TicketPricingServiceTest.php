<?php

namespace Tests\Feature\Tickets;

use App\Enums\MembershipStatus;
use App\Models\EventTicketOrder;
use App\Services\Tickets\PricingResult;
use App\Services\Tickets\TicketPricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\Concerns\MakesTicketFixtures;
use Tests\TestCase;

class TicketPricingServiceTest extends TestCase
{
    use MakesTicketFixtures;
    use RefreshDatabase;

    private TicketPricingService $pricing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMembershipBasics();
        $this->pricing = app(TicketPricingService::class);
    }

    // ── Guests and non-members ───────────────────────────────────────────────

    public function test_guest_pays_the_non_member_price_for_every_ticket(): void
    {
        $r = $this->pricing->resolve($this->makePaidEvent(), 3);

        $this->assertFalse($r->isMemberPricing());
        $this->assertSame(PricingResult::DENIED_GUEST, $r->discountDeniedReason);
        $this->assertSame(195000, $r->totalNgwee);
        $this->assertSame(1950.0, $r->total());
        $this->assertCount(1, $r->lines);
        $this->assertSame(3, $r->lines[0]['quantity']);
    }

    public function test_logged_in_user_without_a_membership_pays_non_member_price(): void
    {
        $user = \App\Models\User::factory()->create();

        $r = $this->pricing->resolve($this->makePaidEvent(), 1, $user);

        $this->assertSame(65000, $r->totalNgwee);
        $this->assertSame(PricingResult::DENIED_NO_MEMBERSHIP, $r->discountDeniedReason);
    }

    public function test_zero_or_negative_quantity_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->pricing->resolve($this->makePaidEvent(), 0);
    }

    // ── Active members: one discounted ticket ────────────────────────────────

    public function test_active_member_buying_one_ticket_pays_member_price(): void
    {
        $m = $this->makeMember();

        $r = $this->pricing->resolve($this->makePaidEvent(), 1, $m->user);

        $this->assertTrue($r->isMemberPricing());
        $this->assertSame(55000, $r->totalNgwee);
        $this->assertSame(1, $r->memberTickets());
        $this->assertSame($m->id, $r->membership->id);
        $this->assertNull($r->discountDeniedReason);
    }

    public function test_member_buying_three_tickets_pays_550_plus_650_plus_650(): void
    {
        $m = $this->makeMember();

        $r = $this->pricing->resolve($this->makePaidEvent(), 3, $m->user);

        $this->assertSame(185000, $r->totalNgwee);
        $this->assertSame(1850.0, $r->total());
        $this->assertCount(2, $r->lines);
        $this->assertSame(['member', 1, 55000], [$r->lines[0]['key'], $r->lines[0]['quantity'], $r->lines[0]['amountNgwee']]);
        $this->assertSame(['guest', 2, 130000], [$r->lines[1]['key'], $r->lines[1]['quantity'], $r->lines[1]['amountNgwee']]);
    }

    public function test_member_buying_ten_tickets_pays_550_plus_nine_at_650(): void
    {
        $m = $this->makeMember();

        $r = $this->pricing->resolve($this->makePaidEvent(), 10, $m->user);

        $this->assertSame(55000 + 9 * 65000, $r->totalNgwee);
        $this->assertSame(1, $r->memberTickets(), 'never more than one discounted ticket');
    }

    public function test_ticket_name_comes_from_the_membership_record(): void
    {
        $m = $this->makeMember('Chanda Mwale', 'chanda@example.com');

        $r = $this->pricing->resolve($this->makePaidEvent(), 2, $m->user);

        $this->assertSame('Chanda Mwale', $r->memberName);
    }

    public function test_member_not_attending_gets_no_discount(): void
    {
        $m = $this->makeMember();

        $r = $this->pricing->resolve($this->makePaidEvent(), 2, $m->user, buyerAttending: false);

        $this->assertFalse($r->isMemberPricing());
        $this->assertSame(PricingResult::DENIED_NOT_ATTENDING, $r->discountDeniedReason);
        $this->assertSame(130000, $r->totalNgwee);
        $this->assertNull($r->memberName);
    }

    public function test_user_with_both_an_expired_and_an_active_membership_gets_the_discount(): void
    {
        $active = $this->makeMember();
        $this->makeMember('Chanda Mwale', 'other@example.com', [
            'user_id' => $active->user_id,
            'status' => MembershipStatus::Expired,
            'expiry_date' => now()->subYear()->toDateString(),
        ]);

        $r = $this->pricing->resolve($this->makePaidEvent(), 1, $active->user);

        $this->assertTrue($r->isMemberPricing());
    }

    // ── Only paid-up, active members ─────────────────────────────────────────

    public function test_inactive_memberships_get_no_discount(): void
    {
        $event = $this->makePaidEvent();

        $cases = [
            'expired date' => ['status' => MembershipStatus::Active, 'expiry_date' => now()->subDay()->toDateString()],
            'expired status' => ['status' => MembershipStatus::Expired],
            'pending payment' => ['status' => MembershipStatus::PendingPayment],
            'suspended' => ['status' => MembershipStatus::Suspended],
            'cancelled' => ['status' => MembershipStatus::Cancelled],
            'draft' => ['status' => MembershipStatus::Draft],
        ];

        $i = 0;
        foreach ($cases as $label => $overrides) {
            $m = $this->makeMember('Member Number'.chr(65 + $i), "m{$i}@example.com", $overrides);
            $r = $this->pricing->resolve($event, 1, $m->user);

            $this->assertFalse($r->isMemberPricing(), $label);
            $this->assertSame(PricingResult::DENIED_INACTIVE, $r->discountDeniedReason, $label);
            $this->assertSame(65000, $r->totalNgwee, $label);
            $i++;
        }
    }

    public function test_membership_expiring_today_still_counts_as_active(): void
    {
        $m = $this->makeMember(membershipOverrides: ['expiry_date' => now()->toDateString()]);

        $this->assertTrue($this->pricing->resolve($this->makePaidEvent(), 1, $m->user)->isMemberPricing());
    }

    // ── Guest proof: membership number + registered email ────────────────────

    public function test_guest_with_membership_number_and_matching_email_gets_member_price(): void
    {
        $m = $this->makeMember('Chanda Mwale', 'chanda@example.com', [], 'LFS-000412');

        $r = $this->pricing->resolve($this->makePaidEvent(), 2, null, 'LFS-000412', 'chanda@example.com');

        $this->assertTrue($r->isMemberPricing());
        $this->assertSame(120000, $r->totalNgwee);
        $this->assertSame('Chanda Mwale', $r->memberName);
        $this->assertSame($m->id, $r->membership->id);
    }

    public function test_email_proof_ignores_case_and_whitespace(): void
    {
        $this->makeMember('Chanda Mwale', 'chanda@example.com', [], 'LFS-000412');

        $r = $this->pricing->resolve($this->makePaidEvent(), 1, null, 'LFS-000412', '  Chanda@Example.COM ');

        $this->assertTrue($r->isMemberPricing());
    }

    public function test_wrong_email_and_unknown_number_fail_identically(): void
    {
        $this->makeMember('Chanda Mwale', 'chanda@example.com', [], 'LFS-000412');
        $event = $this->makePaidEvent();

        $wrongEmail = $this->pricing->resolve($event, 1, null, 'LFS-000412', 'someone@else.com');
        $unknownNumber = $this->pricing->resolve($event, 1, null, 'LFS-999999', 'chanda@example.com');
        $noEmail = $this->pricing->resolve($event, 1, null, 'LFS-000412', '');
        $surnameIsNotProof = $this->pricing->resolve($event, 1, null, 'LFS-000412', 'Mwale');

        foreach ([$wrongEmail, $unknownNumber, $noEmail, $surnameIsNotProof] as $r) {
            $this->assertFalse($r->isMemberPricing());
            $this->assertSame(PricingResult::DENIED_VERIFICATION_FAILED, $r->discountDeniedReason);
            $this->assertNull($r->memberName, 'no name may leak when verification fails');
            $this->assertSame(65000, $r->totalNgwee);
        }
    }

    public function test_verified_guest_with_inactive_membership_gets_no_discount(): void
    {
        $this->makeMember('Chanda Mwale', 'chanda@example.com', ['status' => MembershipStatus::Suspended], 'LFS-000412');

        $r = $this->pricing->resolve($this->makePaidEvent(), 1, null, 'LFS-000412', 'chanda@example.com');

        $this->assertSame(PricingResult::DENIED_INACTIVE, $r->discountDeniedReason);
        $this->assertSame(65000, $r->totalNgwee);
    }

    public function test_logged_in_account_wins_over_a_supplied_membership_number(): void
    {
        $mine = $this->makeMember('Mine Banda', 'mine@example.com', [], 'LFS-000500');
        $this->makeMember('Other Phiri', 'other@example.com', [], 'LFS-000501');

        $r = $this->pricing->resolve($this->makePaidEvent(), 1, $mine->user, 'LFS-000501', 'other@example.com');

        $this->assertSame($mine->id, $r->membership->id);
    }

    // ── One discount per membership per event ────────────────────────────────

    public function test_second_order_for_the_same_membership_gets_no_discount(): void
    {
        $m = $this->makeMember();
        $event = $this->makePaidEvent();
        $this->makeDiscountedOrder($event, $m, EventTicketOrder::STATUS_PAID);

        $r = $this->pricing->resolve($event, 1, $m->user);

        $this->assertFalse($r->isMemberPricing());
        $this->assertSame(PricingResult::DENIED_ALREADY_CLAIMED, $r->discountDeniedReason);
        $this->assertSame(65000, $r->totalNgwee);
    }

    public function test_no_second_discount_by_logging_out_and_using_the_membership_number(): void
    {
        $m = $this->makeMember('Chanda Mwale', 'chanda@example.com', [], 'LFS-000412');
        $event = $this->makePaidEvent();
        $this->makeDiscountedOrder($event, $m, EventTicketOrder::STATUS_PAID);

        $asGuest = $this->pricing->resolve($event, 1, null, 'LFS-000412', 'chanda@example.com');

        $this->assertSame(PricingResult::DENIED_ALREADY_CLAIMED, $asGuest->discountDeniedReason);
    }

    public function test_review_and_live_pending_orders_hold_the_discount(): void
    {
        $event = $this->makePaidEvent();

        $a = $this->makeMember('Alice Mumba', 'a@example.com');
        $this->makeDiscountedOrder($event, $a, EventTicketOrder::STATUS_PAYMENT_REVIEW_REQUIRED);

        $b = $this->makeMember('Bwalya Chola', 'b@example.com');
        $this->makeDiscountedOrder($event, $b, EventTicketOrder::STATUS_PENDING_PAYMENT, ['expires_at' => now()->addMinutes(10)]);

        $this->assertSame(PricingResult::DENIED_ALREADY_CLAIMED, $this->pricing->resolve($event, 1, $a->user)->discountDeniedReason);
        $this->assertSame(PricingResult::DENIED_ALREADY_CLAIMED, $this->pricing->resolve($event, 1, $b->user)->discountDeniedReason);
    }

    public function test_expired_failed_and_cancelled_orders_free_the_discount(): void
    {
        $event = $this->makePaidEvent();

        $cases = [
            [EventTicketOrder::STATUS_PENDING_PAYMENT, ['expires_at' => now()->subMinute()]],
            [EventTicketOrder::STATUS_FAILED, []],
            [EventTicketOrder::STATUS_CANCELLED, []],
        ];

        foreach ($cases as $i => [$status, $extra]) {
            $m = $this->makeMember('Member Number'.chr(65 + $i), "free{$i}@example.com");
            $this->makeDiscountedOrder($event, $m, $status, $extra);

            $this->assertTrue(
                $this->pricing->resolve($event, 1, $m->user)->isMemberPricing(),
                "discount should be available again after a {$status} order"
            );
        }
    }

    public function test_repricing_a_pending_order_ignores_its_own_claim(): void
    {
        $m = $this->makeMember();
        $event = $this->makePaidEvent();
        $order = $this->makeDiscountedOrder($event, $m, EventTicketOrder::STATUS_PENDING_PAYMENT, ['expires_at' => now()->addMinutes(10)]);

        $this->assertFalse($this->pricing->resolve($event, 1, $m->user)->isMemberPricing());
        $this->assertTrue($this->pricing->resolve($event, 1, $m->user, excludeOrderId: $order->id)->isMemberPricing());
    }

    public function test_discount_is_per_membership_and_per_event(): void
    {
        $event = $this->makePaidEvent();
        $other = $this->makePaidEvent();

        $a = $this->makeMember('Alice Mumba', 'a@example.com');
        $b = $this->makeMember('Bwalya Chola', 'b@example.com');
        $this->makeDiscountedOrder($event, $a, EventTicketOrder::STATUS_PAID);

        $this->assertTrue($this->pricing->resolve($event, 1, $b->user)->isMemberPricing(), 'another member is unaffected');
        $this->assertTrue($this->pricing->resolve($other, 1, $a->user)->isMemberPricing(), 'another event is unaffected');
    }

    public function test_two_members_sharing_a_phone_number_are_assessed_independently(): void
    {
        $event = $this->makePaidEvent();
        $a = $this->makeMember('Alice Mumba', 'a@example.com');
        $b = $this->makeMember('Bwalya Chola', 'b@example.com');
        $a->user->forceFill(['phone' => '0971111111'])->save();
        $b->user->forceFill(['phone' => '0971111111'])->save();
        $this->makeDiscountedOrder($event, $a, EventTicketOrder::STATUS_PAID, ['payer_phone' => '0971111111']);

        $this->assertTrue($this->pricing->resolve($event, 1, $b->user)->isMemberPricing());
    }

    // ── Name quality ─────────────────────────────────────────────────────────

    public function test_incomplete_record_name_blocks_the_discount(): void
    {
        $m = $this->makeMember('X', 'x@example.com');

        $r = $this->pricing->resolve($this->makePaidEvent(), 1, $m->user);

        $this->assertFalse($r->isMemberPricing());
        $this->assertSame(PricingResult::DENIED_NAME_INCOMPLETE, $r->discountDeniedReason);
        $this->assertSame(65000, $r->totalNgwee);
    }

    // ── Money handling ───────────────────────────────────────────────────────

    public function test_prices_with_ngwee_do_not_drift(): void
    {
        $m = $this->makeMember();
        $event = $this->makePaidEvent(['ticket_price_member' => 549.99, 'ticket_price_non_member' => 649.95]);

        $r = $this->pricing->resolve($event, 3, $m->user);

        $this->assertSame(54999 + 2 * 64995, $r->totalNgwee);
        $this->assertSame(1849.89, $r->total());
    }

    public function test_to_array_exposes_a_display_breakdown(): void
    {
        $m = $this->makeMember();

        $a = $this->pricing->resolve($this->makePaidEvent(), 3, $m->user)->toArray();

        $this->assertSame(1850.0, $a['total']);
        $this->assertTrue($a['isMemberPricing']);
        $this->assertSame('Member ticket', $a['lines'][0]['label']);
        $this->assertSame(550.0, $a['lines'][0]['unitPrice']);
        $this->assertSame('Guest tickets', $a['lines'][1]['label']);
        $this->assertSame(1300.0, $a['lines'][1]['amount']);
    }

    public function test_member_price_equal_to_non_member_price_still_works(): void
    {
        $m = $this->makeMember();
        $event = $this->makePaidEvent(['ticket_price_member' => 650]);

        $this->assertSame(65000 * 2, $this->pricing->resolve($event, 2, $m->user)->totalNgwee);
    }
}
