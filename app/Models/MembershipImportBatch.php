<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MembershipImportBatch extends Model
{
    protected $fillable = [
        'uuid',
        'filename',
        'imported_by',
        'imported_at',
        'total_rows',
        'imported_rows',
        'skipped_rows',
        'error_rows',
        'status',
        'rolled_back_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'imported_at' => 'datetime',
            'rolled_back_at' => 'datetime',
            'notes' => 'array',
        ];
    }

    public function records(): HasMany
    {
        return $this->hasMany(MembershipImportRecord::class, 'batch_id');
    }
}
