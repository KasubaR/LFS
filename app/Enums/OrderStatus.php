<?php

namespace App\Enums;

class OrderStatus
{
    public const PendingPayment = 'pending_payment';

    public const Paid = 'paid';

    public const Processing = 'processing';

    public const Ready = 'ready';

    public const Collected = 'collected';

    public const Cancelled = 'cancelled';

    public const PaymentFailed = 'payment_failed';

    /** Money arrived but not for the order total - needs a human to look. */
    public const PaymentReview = 'payment_review';

    /** @var list<string> */
    public const ALL = [
        self::PendingPayment,
        self::Paid,
        self::Processing,
        self::Ready,
        self::Collected,
        self::Cancelled,
        self::PaymentFailed,
        self::PaymentReview,
    ];
}
