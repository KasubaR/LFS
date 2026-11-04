<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Append-only record of an admin action on a ticket or order.
 */
class EventTicketAudit extends Model
{
    public const ACTION_MARK_PRESENT = 'mark_present';

    public const ACTION_UNDO_PRESENT = 'undo_present';

    public const UPDATED_AT = null;

    protected $table = 'event_ticket_audits';

    protected $fillable = [
        'event_id',
        'order_id',
        'ticket_id',
        'admin_id',
        'admin_name',
        'action',
        'reason',
        'details',
    ];

    protected function casts(): array
    {
        return [
            'details' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
