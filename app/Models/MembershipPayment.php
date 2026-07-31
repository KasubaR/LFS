<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MembershipPayment extends Model
{
    protected $table = 'membership_payments';

    protected $fillable = [
        'membership_id',
        'plan_id',
        'amount',
        'amount_paid',
        'currency',
        'payment_reference',
        'payment_gateway',
        'status',
        'paid_at',
        'covers_from',
        'covers_to',
        'lenco_transaction_id',
        'lenco_reference',
        'lenco_provider',
        'lenco_status',
        'lenco_response',
        'webhook_received',
        'webhook_payload',
        'webhook_received_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'plan_id' => 'integer',
            'amount' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'paid_at' => 'datetime',
            'covers_from' => 'date',
            'covers_to' => 'date',
            'lenco_response' => 'array',
            'webhook_received' => 'boolean',
            'webhook_payload' => 'array',
            'webhook_received_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(MembershipPlan::class, 'plan_id');
    }
}
