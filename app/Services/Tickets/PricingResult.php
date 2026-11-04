<?php

namespace App\Services\Tickets;

use App\Models\Membership;

/**
 * Server-side price for an order, with a line-by-line breakdown.
 *
 * Money is held in ngwee (integers) so totals never suffer float drift.
 * The client never supplies any of this; it is display-only on the page.
 */
final class PricingResult
{
    // Why the member discount was NOT applied (null when it was).
    public const DENIED_GUEST = 'guest';                         // nothing identifying the buyer as a member

    public const DENIED_NOT_ATTENDING = 'not_attending';         // member is buying only for other people

    public const DENIED_NO_MEMBERSHIP = 'no_membership';         // logged in, has no membership at all

    public const DENIED_INACTIVE = 'membership_inactive';        // expired, pending, suspended, cancelled...

    public const DENIED_VERIFICATION_FAILED = 'verification_failed'; // unknown number OR wrong email (identical on purpose)

    public const DENIED_ALREADY_CLAIMED = 'already_claimed';     // this membership already holds the event's discount

    public const DENIED_NAME_INCOMPLETE = 'name_incomplete';     // record name too incomplete to bind a ticket to

    /**
     * @param  list<array{key: string, label: string, unitNgwee: int, quantity: int, amountNgwee: int}>  $lines
     */
    public function __construct(
        public readonly int $quantity,
        public readonly array $lines,
        public readonly int $totalNgwee,
        public readonly int $memberUnitNgwee,
        public readonly int $standardUnitNgwee,
        public readonly ?Membership $membership,
        public readonly ?string $memberName,
        public readonly ?string $discountDeniedReason,
    ) {}

    public function isMemberPricing(): bool
    {
        return $this->membership !== null;
    }

    /** Number of tickets at the member price: 0 or 1. */
    public function memberTickets(): int
    {
        return $this->isMemberPricing() ? 1 : 0;
    }

    public function total(): float
    {
        return $this->totalNgwee / 100;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'quantity' => $this->quantity,
            'isMemberPricing' => $this->isMemberPricing(),
            'discountDeniedReason' => $this->discountDeniedReason,
            'memberName' => $this->memberName,
            'lines' => array_map(static fn (array $l): array => [
                'key' => $l['key'],
                'label' => $l['label'],
                'unitPrice' => (float) ($l['unitNgwee'] / 100),
                'quantity' => $l['quantity'],
                'amount' => (float) ($l['amountNgwee'] / 100),
            ], $this->lines),
            'total' => $this->total(),
        ];
    }
}
