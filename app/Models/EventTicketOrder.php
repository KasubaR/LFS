<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One purchase of tickets for a paid event. Holds the BUYER and the money;
 * attendees live on {@see EventTicket}.
 */
class EventTicketOrder extends Model
{
    public const STATUS_PENDING_PAYMENT = 'pending_payment';

    public const STATUS_PAID = 'paid';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_PAYMENT_REVIEW_REQUIRED = 'payment_review_required';

    protected $table = 'event_ticket_orders';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'order_number',
        'event_id',
        'user_id',
        'membership_id',
        'buyer_name',
        'buyer_email',
        'buyer_phone',
        'payer_phone',
        'is_member_pricing',
        'member_unit_price',
        'standard_unit_price',
        'quantity',
        'total',
        'status',
        'expires_at',
        'paid_at',
        'cancelled_at',
        'confirmation_email_sent_at',
        'idempotency_token',
        'review_reason',
    ];

    protected function casts(): array
    {
        return [
            'is_member_pricing' => 'boolean',
            'member_unit_price' => 'decimal:2',
            'standard_unit_price' => 'decimal:2',
            'total' => 'decimal:2',
            'quantity' => 'integer',
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'confirmation_email_sent_at' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(EventTicket::class, 'order_id')->orderBy('position');
    }
}
