<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ElectionComplaint extends Model
{
    protected $table = 'election_complaints';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'election_id',
        'reporter_user_id',
        'reporter_admin_id',
        'reporter_name',
        'reporter_email',
        'body',
        'status',
    ];

    public function election(): BelongsTo
    {
        return $this->belongsTo(Election::class, 'election_id');
    }
}
