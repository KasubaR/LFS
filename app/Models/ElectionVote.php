<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ElectionVote extends Model
{
    protected $table = 'election_votes';

    protected $primaryKey = 'blind_ballot_id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'blind_ballot_id',
        'election_id',
        'position_id',
        'ciphertext',
        'tally_status',
        'candidate_id',
        'flushed_at',
    ];

    protected function casts(): array
    {
        return [
            'flushed_at' => 'datetime',
        ];
    }
}
