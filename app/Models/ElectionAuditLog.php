<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ElectionAuditLog extends Model
{
    protected $table = 'election_audit_logs';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'id',
        'election_id',
        'actor_type',
        'actor_id',
        'action',
        'metadata',
        'ip_address',
        'prev_hash',
        'entry_hash',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
