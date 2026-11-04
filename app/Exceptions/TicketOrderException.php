<?php

namespace App\Exceptions;

use App\Models\EventTicketOrder;
use App\Services\Tickets\PricingResult;
use RuntimeException;

/**
 * A ticket order could not be placed. $reason is a stable machine code the
 * controller maps to a response; the message is safe to show to the buyer.
 */
class TicketOrderException extends RuntimeException
{
    /** Tickets not available (sold out, closed, ...). */
    public const UNAVAILABLE = 'unavailable';

    /** The total differs from what the buyer last saw; they must reconfirm. */
    public const PRICE_CHANGED = 'price_changed';

    /** The member row's name did not match the membership record. */
    public const MEMBER_NAME_MISMATCH = 'member_name_mismatch';

    /** Lenco definitively refused the charge; nothing was charged. */
    public const PAYMENT_FAILED = 'payment_failed';

    /** Lenco outcome unknown (timeout/5xx); reservation kept, poller resolves. */
    public const PAYMENT_UNKNOWN = 'payment_unknown';

    public function __construct(
        string $message,
        public readonly string $reason,
        public readonly ?PricingResult $pricing = null,
        public readonly ?EventTicketOrder $order = null,
        public readonly ?string $availabilityReason = null,
    ) {
        parent::__construct($message);
    }
}
