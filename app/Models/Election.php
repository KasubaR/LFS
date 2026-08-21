<?php

namespace App\Models;

use App\Enums\ElectionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Election extends Model
{
    protected $table = 'elections';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'title',
        'type',
        'status',
        'description',
        'scheduled_open_at',
        'scheduled_close_at',
        'opened_at',
        'closed_at',
        'roll_locked_at',
        'ballot_approved_at',
        'quorum_percent',
        'quorum_confirmed_at',
        'early_open_override_at',
        'early_open_override_by',
        'early_open_override_second_by',
        'early_open_override_reason',
        'incomplete_ballot_count',
        'locked_at',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_open_at' => 'datetime',
            'scheduled_close_at' => 'datetime',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'roll_locked_at' => 'datetime',
            'ballot_approved_at' => 'datetime',
            'quorum_confirmed_at' => 'datetime',
            'early_open_override_at' => 'datetime',
            'locked_at' => 'datetime',
            'quorum_percent' => 'integer',
            'incomplete_ballot_count' => 'integer',
        ];
    }

    public function positions(): HasMany
    {
        return $this->hasMany(ElectionPosition::class, 'election_id')->orderBy('sort_order');
    }

    public function voters(): HasMany
    {
        return $this->hasMany(ElectionVoter::class, 'election_id');
    }

    public function proxies(): HasMany
    {
        return $this->hasMany(ElectionProxy::class, 'election_id');
    }

    public function entitlements(): HasMany
    {
        return $this->hasMany(ElectionBallotEntitlement::class, 'election_id');
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(ElectionAttendance::class, 'election_id');
    }

    public function certifications(): HasMany
    {
        return $this->hasMany(ElectionResultCertification::class, 'election_id');
    }

    public function complaints(): HasMany
    {
        return $this->hasMany(ElectionComplaint::class, 'election_id');
    }

    public function isBallotMutable(): bool
    {
        return ElectionStatus::isMutableBallot((string) $this->status)
            && $this->ballot_approved_at === null;
    }

    public function isRollLocked(): bool
    {
        return $this->roll_locked_at !== null;
    }

    public function isOpen(): bool
    {
        return $this->status === ElectionStatus::Open;
    }

    public function isClosedOrLater(): bool
    {
        return in_array((string) $this->status, [
            ElectionStatus::Closed,
            ElectionStatus::Certified,
            ElectionStatus::Locked,
        ], true);
    }

    public function resultsArePublic(): bool
    {
        return in_array((string) $this->status, [
            ElectionStatus::Certified,
            ElectionStatus::Locked,
        ], true);
    }
}
