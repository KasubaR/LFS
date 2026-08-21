<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ElectionResultCertification extends Model
{
    protected $table = 'election_result_certifications';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'election_id',
        'admin_user_id',
        'certified_at',
    ];

    protected function casts(): array
    {
        return [
            'certified_at' => 'datetime',
        ];
    }

    public function election(): BelongsTo
    {
        return $this->belongsTo(Election::class, 'election_id');
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'admin_user_id');
    }
}
