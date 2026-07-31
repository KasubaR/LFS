<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApiRequestLog extends Model
{
    protected $table = 'api_request_logs';

    public const UPDATED_AT = null;

    protected $fillable = [
        'api_client_id',
        'method',
        'path',
        'status',
        'ip',
        'result',
        'duration_ms',
    ];

    protected function casts(): array
    {
        return [
            'api_client_id' => 'integer',
            'status' => 'integer',
            'duration_ms' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(ApiClient::class, 'api_client_id');
    }
}
