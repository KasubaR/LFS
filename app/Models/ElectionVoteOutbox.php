<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ElectionVoteOutbox extends Model
{
    protected $table = 'election_vote_outbox';

    protected $primaryKey = 'blind_ballot_id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'blind_ballot_id',
        'election_id',
        'position_id',
        'ciphertext',
        'status',
        'attempts',
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
        ];
    }
}
