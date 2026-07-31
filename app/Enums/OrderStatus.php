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

    /** @var list<string> */
    public const ALL = [
        self::PendingPayment,
        self::Paid,
        self::Processing,
        self::Ready,
        self::Collected,
        self::Cancelled,
        self::PaymentFailed,
    ];
}
