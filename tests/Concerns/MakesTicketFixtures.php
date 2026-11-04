<?php

namespace Tests\Concerns;

use App\Enums\ApprovalStatus;
use App\Enums\BillingCycle;
use App\Enums\MembershipStatus;
use App\Models\Event;
use App\Models\EventTicket;
use App\Models\EventTicketOrder;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\Satellite;
use App\Models\User;
use App\Support\Uuid;
use Database\Seeders\MembershipPlanSeeder;
use Database\Seeders\SatelliteSeeder;
use Illuminate\Support\Str;

/**
 * Shared fixtures for event ticketing tests. Call seedMembershipBasics()
 * from setUp() before creating memberships.
 */
trait MakesTicketFixtures
{
    private ?MembershipPlan $ticketPlan = null;

    private ?Satellite $ticketSatellite = null;

    private int $membershipCounter = 0;

    protected function seedMembershipBasics(): void
    {
        $this->seed(SatelliteSeeder::class);
        $this->seed(MembershipPlanSeeder::class);

        $this->ticketSatellite = Satellite::query()->where('slug', 'woodies')->first();
        $this->ticketPlan = MembershipPlan::query()->where('billing_cycle', BillingCycle::Annual)->first();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function makePaidEvent(array $overrides = []): Event
    {
        return Event::query()->create(array_merge([
            'id' => (string) Str::uuid(),
            'title' => 'End of Year Party',
            'slug' => 'end-of-year-party-'.Str::random(8),
            'event_date' => now()->addDays(30),
            'registration_type' => 'paid',
            'ticket_price_member' => 550,
            'ticket_price_non_member' => 650,
            'ticket_capacity' => 100,
            'ticket_sales_open' => true,
            'ticket_status' => 'scheduled',
        ], $overrides));
    }

    /**
     * Creates a user plus membership. Name is "$name" (last word = surname).
     *
     * @param  array<string, mixed>  $membershipOverrides
     */
    protected function makeMember(
        string $name = 'Chanda Mwale',
        string $email = 'chanda@example.com',
        array $membershipOverrides = [],
        ?string $number = null,
    ): Membership {
        $parts = explode(' ', trim($name));
        $lastName = array_pop($parts);

        $user = User::factory()->create([
            'last_name' => $lastName,
            'other_names' => implode(' ', $parts),
            'email' => $email,
            'satellite_id' => $this->ticketSatellite->id,
        ]);

        $this->membershipCounter++;

        return Membership::query()->create(array_merge([
            'id' => Uuid::v4(),
            'user_id' => $user->id,
            'membership_number' => $number ?? sprintf('LFS-%06d', 400 + $this->membershipCounter),
            'public_token' => Uuid::v4(),
            'status' => MembershipStatus::Active,
            'start_date' => now()->subMonths(2)->toDateString(),
            'expiry_date' => now()->addMonths(10)->toDateString(),
            'current_plan_id' => $this->ticketPlan->id,
            'approval_status' => ApprovalStatus::Approved,
            'joined_at' => now()->subMonths(2),
        ], $membershipOverrides));
    }

    /**
     * Replaces Lenco's initiation call (everything else stays real, so our
     * own generateReference() still runs). $result overrides the fake reply.
     *
     * @param  array<string, mixed>  $result
     */
    /**
     * Wraps a REAL LencoService (constructor run, config read) so any method
     * without an expectation still behaves normally, e.g. generateReference()
     * and verifyWebhookSignature(). Mockery::partialMock() would skip the
     * constructor and leave typed properties uninitialised.
     *
     * @param  callable(\Mockery\MockInterface): void  $configure
     */
    protected function lencoPartial(callable $configure): void
    {
        $mock = \Mockery::mock(new \App\Services\LencoService);
        $configure($mock);
        $this->instance(\App\Services\LencoService::class, $mock);
    }

    protected function mockLencoInitiation(array $result = []): void
    {
        $this->lencoPartial(function ($mock) use ($result): void {
            $mock->shouldReceive('initiateMobileMoneyPayment')->andReturnUsing(
                fn (array $order, string $phone, string $op, ?string $reference = null): array => array_merge([
                    'transactionId' => 'lenco-'.Str::random(8),
                    'lencoReference' => 'LR-'.Str::random(6),
                    'reference' => $reference,
                    'status' => 'pending',
                    'internalStatus' => 'pending',
                    'amount' => $order['totals']['total'],
                    'currency' => 'ZMW',
                    'paymentInstructions' => 'Approve the payment on your phone.',
                    'paymentUrl' => null,
                    'expiresAt' => null,
                    'rawResponse' => [],
                ], $result)
            );
        });
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function orderInput(array $overrides = []): array
    {
        return array_merge([
            'buyerName' => 'John Banda',
            'buyerEmail' => 'john@example.com',
            'buyerPhone' => '0971111111',
            'payerPhone' => '0971111111',
            'provider' => 'mtn',
            'quantity' => 1,
            'attendees' => [['name' => 'John Banda']],
            'buyerAttending' => true,
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    protected function placeTicketOrder(Event $event, array $input = [], ?User $user = null): \App\Services\Tickets\TicketOrderResult
    {
        return app(\App\Services\Tickets\TicketOrderService::class)->create($event->id, $this->orderInput($input), $user);
    }

    /**
     * A normalised "Lenco says it succeeded" result for an order.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function lencoSuccess(EventTicketOrder $order, array $overrides = []): array
    {
        return array_merge([
            'status' => 'successful',
            'internalStatus' => 'completed',
            'amount' => (float) $order->total,
            'currency' => 'ZMW',
        ], $overrides);
    }

    protected function paymentFor(EventTicketOrder $order): \App\Models\Payment
    {
        return \App\Models\Payment::query()->where('order_number', $order->order_number)->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function makeTicketOrder(Event $event, string $status, int $quantity, array $overrides = []): EventTicketOrder
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

    /**
     * An order whose first ticket carries the member discount for $membership.
     *
     * @param  array<string, mixed>  $orderOverrides
     */
    protected function makeDiscountedOrder(Event $event, Membership $membership, string $status, array $orderOverrides = []): EventTicketOrder
    {
        $order = $this->makeTicketOrder($event, $status, 1, array_merge([
            'membership_id' => $membership->id,
            'is_member_pricing' => true,
        ], $orderOverrides));

        EventTicket::query()->create([
            'id' => (string) Str::uuid(),
            'order_id' => $order->id,
            'event_id' => $event->id,
            'position' => 1,
            'attendee_name' => $membership->user->name,
            'ticket_reference' => $order->order_number.'-1',
            'price' => 550,
            'is_member_discount' => true,
            'membership_id' => $membership->id,
            'membership_number' => $membership->membership_number,
        ]);

        return $order;
    }
}
