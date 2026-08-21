<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ElectionVoterImportBatch extends Model
{
    protected $table = 'election_voter_import_batches';

    protected $fillable = [
        'election_id',
        'filename',
        'uploaded_by',
        'total_rows',
        'matched_rows',
        'unmatched_rows',
        'ambiguous_rows',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'notes' => 'array',
            'total_rows' => 'integer',
            'matched_rows' => 'integer',
            'unmatched_rows' => 'integer',
            'ambiguous_rows' => 'integer',
        ];
    }

    public function election(): BelongsTo
    {
        return $this->belongsTo(Election::class, 'election_id');
    }

    public function voters(): HasMany
    {
        return $this->hasMany(ElectionVoter::class, 'import_batch_id');
    }
}
