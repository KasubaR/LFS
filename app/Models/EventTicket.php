<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One admission. Holds the ATTENDEE, the price of this ticket and attendance;
 * the buyer and payment live on {@see EventTicketOrder}.
 */
class EventTicket extends Model
{
    /** Max admin typo corrections per standard ticket. */
    public const MAX_NAME_CORRECTIONS = 2;

    protected $table = 'event_tickets';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'order_id',
        'event_id',
        'position',
        'attendee_name',
        'attendee_email',
        'ticket_reference',
        'price',
        'is_member_discount',
        'membership_id',
        'membership_number',
        'name_correction_count',
        'checked_in_at',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'price' => 'decimal:2',
            'is_member_discount' => 'boolean',
            'name_correction_count' => 'integer',
            'checked_in_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(EventTicketOrder::class, 'order_id');
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
