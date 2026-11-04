<?php

namespace App\Services\Tickets;

use App\Models\Event;
use App\Models\EventTicket;
use App\Models\EventTicketOrder;
use App\Models\Membership;
use App\Models\User;
use App\Services\MemberVerificationService;
use InvalidArgumentException;
use LogicException;

/**
 * Prices a ticket order on the server. Rules (docs/EVENT_TICKETING_PLAN.md):
 *
 *  - Only a paid-up, active member (Membership::isCardActive()) gets the
 *    member price, and only for ONE ticket per membership per event: the
 *    buyer's own. Every other ticket is the non-member price.
 *  - The discount follows the buyer's own membership. Attendees never supply
 *    membership numbers.
 *  - A logged-in buyer is identified by their account. A guest proves
 *    membership with the membership number plus the email registered on it.
 *  - A buyer who is not attending ("Ticket for me" unticked) gets no discount.
 *  - A membership that already holds a live discounted ticket for the event
 *    (paid, under review, or pending inside its reservation window) gets none.
 *  - Eligibility is decided once, here, at order creation. The caller
 *    snapshots the result, so later renewals/expiry never re-price an order.
 *
 * Race-safety against two simultaneous orders is the caller's job: run this
 * inside the transaction that holds the event row lock (see TicketAvailability).
 */
class TicketPricingService
{
    public function __construct(
        private readonly MemberVerificationService $verification,
    ) {}

    /**
     * @param  string|null  $excludeOrderId  ignore this order's own claim (re-pricing a pending order)
     */
    public function resolve(
        Event $event,
        int $quantity,
        ?User $user = null,
        ?string $membershipNumber = null,
        ?string $proofEmail = null,
        bool $buyerAttending = true,
        ?string $excludeOrderId = null,
    ): PricingResult {
        if ($quantity < 1) {
            throw new InvalidArgumentException('Quantity must be at least 1.');
        }
        if ($event->ticket_price_member === null || $event->ticket_price_non_member === null) {
            throw new LogicException('Event has no ticket prices.');
        }

        $memberNgwee = $this->toNgwee($event->ticket_price_member);
        $standardNgwee = $this->toNgwee($event->ticket_price_non_member);

        [$membership, $denied] = $this->resolveMembership(
            $event, $user, $membershipNumber, $proofEmail, $buyerAttending, $excludeOrderId
        );

        $lines = [];
        if ($membership !== null) {
            $lines[] = $this->line('member', 'Member ticket', $memberNgwee, 1);
            if ($quantity > 1) {
                $lines[] = $this->line('guest', 'Guest tickets', $standardNgwee, $quantity - 1);
            }
        } else {
            $lines[] = $this->line('standard', $quantity === 1 ? 'Ticket' : 'Tickets', $standardNgwee, $quantity);
        }

        return new PricingResult(
            quantity: $quantity,
            lines: $lines,
            totalNgwee: array_sum(array_column($lines, 'amountNgwee')),
            memberUnitNgwee: $memberNgwee,
            standardUnitNgwee: $standardNgwee,
            membership: $membership,
            // The ticket name is taken from the membership record, never typed.
            memberName: $membership?->user?->name,
            discountDeniedReason: $membership !== null ? null : $denied,
        );
    }

    /**
     * Does this membership already hold the event's discounted ticket on a
     * live order (paid, under review, or pending and unexpired)?
     */
    public function discountAlreadyClaimed(Event $event, string $membershipId, ?string $excludeOrderId = null): bool
    {
        return EventTicket::query()
            ->where('event_id', $event->id)
            ->where('is_member_discount', true)
            ->where('membership_id', $membershipId)
            ->whereHas('order', function ($q) use ($excludeOrderId): void {
                if ($excludeOrderId !== null) {
                    $q->where('id', '!=', $excludeOrderId);
                }
                $q->where(function ($q2): void {
                    $q2->whereIn('status', [
                        EventTicketOrder::STATUS_PAID,
                        EventTicketOrder::STATUS_PAYMENT_REVIEW_REQUIRED,
                    ])->orWhere(function ($q3): void {
                        $q3->where('status', EventTicketOrder::STATUS_PENDING_PAYMENT)
                            ->where('expires_at', '>', now());
                    });
                });
            })
            ->exists();
    }

    /**
     * @return array{0: Membership|null, 1: string|null}  membership if discount applies, else denial reason
     */
    private function resolveMembership(
        Event $event,
        ?User $user,
        ?string $membershipNumber,
        ?string $proofEmail,
        bool $buyerAttending,
        ?string $excludeOrderId,
    ): array {
        if (! $buyerAttending) {
            return [null, PricingResult::DENIED_NOT_ATTENDING];
        }

        if ($user !== null) {
            $memberships = Membership::query()->with('user')->where('user_id', $user->id)->get();
            if ($memberships->isEmpty()) {
                return [null, PricingResult::DENIED_NO_MEMBERSHIP];
            }
            $membership = $memberships->first(fn (Membership $m): bool => $m->isCardActive());
            if ($membership === null) {
                return [null, PricingResult::DENIED_INACTIVE];
            }
        } elseif ($membershipNumber !== null && trim($membershipNumber) !== '') {
            $membership = $this->verification->findVerifiedMembership($membershipNumber, (string) $proofEmail);
            if ($membership === null) {
                // Same answer for an unknown number and a wrong email.
                return [null, PricingResult::DENIED_VERIFICATION_FAILED];
            }
            if (! $membership->isCardActive()) {
                return [null, PricingResult::DENIED_INACTIVE];
            }
        } else {
            return [null, PricingResult::DENIED_GUEST];
        }

        if (! $this->nameIsUsable($membership->user)) {
            return [null, PricingResult::DENIED_NAME_INCOMPLETE];
        }

        if ($this->discountAlreadyClaimed($event, (string) $membership->id, $excludeOrderId)) {
            return [null, PricingResult::DENIED_ALREADY_CLAIMED];
        }

        return [$membership, null];
    }

    /**
     * The ticket name comes from the record and the entrance check compares
     * it with ID, so a blank or one-letter name can't back a discount.
     */
    private function nameIsUsable(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return trim((string) $user->last_name) !== '' && mb_strlen(trim((string) $user->name)) >= 3;
    }

    private function toNgwee(mixed $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    /**
     * @return array{key: string, label: string, unitNgwee: int, quantity: int, amountNgwee: int}
     */
    private function line(string $key, string $label, int $unitNgwee, int $quantity): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'unitNgwee' => $unitNgwee,
            'quantity' => $quantity,
            'amountNgwee' => $unitNgwee * $quantity,
        ];
    }
}
