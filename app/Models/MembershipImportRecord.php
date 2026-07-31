<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MembershipImportRecord extends Model
{
    protected $fillable = [
        'batch_id',
        'user_id',
        'membership_id',
        'payment_id',
        'row_ref',
        'row_email',
        'row_payload',
    ];

    protected function casts(): array
    {
        return [
            'row_payload' => 'array',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(MembershipImportBatch::class, 'batch_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
