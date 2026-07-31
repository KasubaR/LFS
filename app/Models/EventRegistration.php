<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventRegistration extends Model
{
    protected $table = 'event_registrations';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'event_id',
        'user_id',
        'bib_number',
        'status',
        'payment_status',
        'registered_at',
    ];

    protected function casts(): array
    {
        return [
            'registered_at' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
