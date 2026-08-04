<?php

namespace App\Enums;

class MembershipHistoryEvent
{
    public const Activated = 'activated';

    public const Renewed = 'renewed';

    public const Expired = 'expired';

    public const PlanChanged = 'plan_changed';

    public const ManualAdjustment = 'manual_adjustment';

    public const Imported = 'imported';

    public const AdminEdit = 'admin_edit';

    public const PaymentReceived = 'payment_received';

    /** Application row created (Draft). */
    public const Created = 'created';

    /** Draft submitted for payment (PendingPayment). */
    public const Submitted = 'submitted';

    public const Cancelled = 'cancelled';

    /** @var list<string> */
    public const ALL = [
        self::Activated,
        self::Renewed,
        self::Expired,
        self::PlanChanged,
        self::ManualAdjustment,
        self::Imported,
        self::AdminEdit,
        self::PaymentReceived,
        self::Created,
        self::Submitted,
        self::Cancelled,
    ];
}
