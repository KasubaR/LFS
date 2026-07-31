<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventResult extends Model
{
    protected $table = 'event_results';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'event_id',
        'runner_name',
        'position',
        'time',
        'category',
        'club',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
