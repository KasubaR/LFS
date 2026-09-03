<?php

namespace App\Models;

use App\Enums\MeetingStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Meeting extends Model
{
    protected $table = 'meetings';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'title',
        'type',
        'status',
        'description',
        'scheduled_starts_at',
        'started_at',
        'ended_at',
        'quorum_percent',
        'quorum_confirmed_at',
        'hms_room_id',
        'hms_room_code_host',
        'hms_room_code_guest',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_starts_at' => 'datetime',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'quorum_confirmed_at' => 'datetime',
            'quorum_percent' => 'integer',
        ];
    }

    public function elections(): HasMany
    {
        return $this->hasMany(Election::class, 'meeting_id');
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(MeetingAttendance::class, 'meeting_id');
    }

    /**
     * The election whose voters' roll governs eligibility/good-standing
     * for this meeting. Every meeting hosts at most one ballot today, so
     * this is just the first (and normally only) linked election.
     */
    public function primaryElection(): ?Election
    {
        return $this->elections()->orderBy('created_at')->first();
    }

    public function isLive(): bool
    {
        return $this->status === MeetingStatus::Live;
    }
}
