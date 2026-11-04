<?php

namespace App\Services\Tickets;

/**
 * Outcome of {@see TicketAvailability::check()}: either purchasable, or one
 * specific reason it is not (so the UI can say why, not just "unavailable").
 */
final class AvailabilityResult
{
    public const OK = 'ok';

    public const NOT_TICKETED = 'not_ticketed';

    public const CANCELLED = 'cancelled';

    public const SALES_CLOSED = 'sales_closed';

    public const NOT_YET_OPEN = 'not_yet_open';

    public const DEADLINE_PASSED = 'deadline_passed';

    public const EVENT_OVER = 'event_over';

    public const INVALID_QUANTITY = 'invalid_quantity';

    public const OVER_ORDER_LIMIT = 'over_order_limit';

    public const SOLD_OUT = 'sold_out';

    public const ONLY_N_LEFT = 'only_n_left';

    /**
     * @param  int|null  $remaining  tickets still available; null = unlimited
     */
    public function __construct(
        public readonly string $reason,
        public readonly ?int $remaining = null,
    ) {}

    public function ok(): bool
    {
        return $this->reason === self::OK;
    }

    public function message(): string
    {
        return match ($this->reason) {
            self::OK => 'Tickets are available.',
            self::NOT_TICKETED => 'Tickets are not sold for this event.',
            self::CANCELLED => 'This event has been cancelled.',
            self::SALES_CLOSED => 'Ticket sales are closed.',
            self::NOT_YET_OPEN => 'Ticket sales have not opened yet.',
            self::DEADLINE_PASSED => 'The registration deadline has passed.',
            self::EVENT_OVER => 'This event has already taken place.',
            self::INVALID_QUANTITY => 'Choose at least one ticket.',
            self::OVER_ORDER_LIMIT => 'You can buy at most '.(int) config('tickets.max_per_order', 10).' tickets per order.',
            self::SOLD_OUT => 'Sorry, this event is sold out.',
            self::ONLY_N_LEFT => 'Only '.(int) $this->remaining.' ticket(s) left.',
            default => 'Tickets are not available.',
        };
    }
}
