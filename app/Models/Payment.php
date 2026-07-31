<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    protected $table = 'payments';

    protected $fillable = [
        'order_number',
        'payment_method',
        'amount',
        'currency',
        'status',
        'customer_name',
        'customer_email',
        'customer_phone',
        'lenco_transaction_id',
        'lenco_reference',
        'lenco_provider',
        'lenco_status',
        'lenco_response',
        'transaction_id',
        'payment_instructions',
        'expires_at',
        'completed_at',
        'failed_at',
        'failure_reason',
        'webhook_received',
        'webhook_payload',
        'webhook_received_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'lenco_response' => 'array',
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
            'webhook_received' => 'boolean',
            'webhook_payload' => 'array',
            'webhook_received_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_number', 'order_number');
    }
}
