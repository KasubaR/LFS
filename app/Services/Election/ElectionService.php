<?php

namespace App\Services\Election;

use App\Enums\ElectionAuditAction;
use App\Enums\ElectionStatus;
use App\Enums\ElectionType;
use App\Enums\ElectionVoterMatchStatus;
use App\Models\AdminUser;
use App\Models\Election;
use App\Models\ElectionCandidate;
use App\Models\ElectionPosition;
use App\Support\Uuid;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ElectionService
{
    public function __construct(
        private readonly ElectionAuditService $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, AdminUser $admin): Election
    {
        $election = Election::query()->create([
            'id' => Uuid::v4(),
            'title' => (string) $data['title'],
            'type' => (string) ($data['type'] ?? ElectionType::ByElection),
            'status' => ElectionStatus::Draft,
            'description' => $data['description'] ?? null,
            'scheduled_open_at' => $data['scheduled_open_at'] ?? null,
            'scheduled_close_at' => $data['scheduled_close_at'] ?? null,
            'quorum_percent' => (int) ($data['quorum_percent'] ?? config('elections.quorum_percent_default', 50)),
        ]);

        $this->audit->record(
            $election->id,
            ElectionAuditAction::ElectionUpdated,
            'admin',
            (string) $admin->id,
            ['event' => 'created', 'title' => $election->title],
        );

        return $election;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Election $election, array $data, AdminUser $admin): Election
    {
        if ($election->status === ElectionStatus::Locked) {
            throw ValidationException::withMessages(['election' => 'This election is permanently locked.']);
        }

        $election->fill([
            'title' => $data['title'] ?? $election->title,
            'type' => $data['type'] ?? $election->type,
            'description' => array_key_exists('description', $data) ? $data['description'] : $election->description,
            'scheduled_open_at' => array_key_exists('scheduled_open_at', $data) ? $data['scheduled_open_at'] : $election->scheduled_open_at,
            'scheduled_close_at' => array_key_exists('scheduled_close_at', $data) ? $data['scheduled_close_at'] : $election->scheduled_close_at,
            'quorum_percent' => isset($data['quorum_percent']) ? (int) $data['quorum_percent'] : $election->quorum_percent,
        ])->save();

        if ($election->status === ElectionStatus::BallotApproved && $election->scheduled_open_at) {
            $election->update(['status' => ElectionStatus::Scheduled]);
        }

        $this->audit->record(
            $election->id,
            ElectionAuditAction::ElectionUpdated,
            'admin',
            (string) $admin->id,
            ['event' => 'updated'],
        );

        return $election->fresh();
    }

    /**
     * @param  array{title: string, allow_abstain?: bool}  $data
     */
    public function addPosition(Election $election, array $data, AdminUser $admin): ElectionPosition
    {
        $this->assertBallotMutable($election);

        $max = (int) $election->positions()->max('sort_order');

        $position = ElectionPosition::query()->create([
            'id' => Uuid::v4(),
            'election_id' => $election->id,
            'title' => $data['title'],
            'allow_abstain' => (bool) ($data['allow_abstain'] ?? false),
            'sort_order' => $max + 1,
        ]);

        $this->audit->record(
            $election->id,
            ElectionAuditAction::ElectionUpdated,
            'admin',
            (string) $admin->id,
            ['event' => 'position_added', 'position_id' => $position->id, 'title' => $position->title],
        );

        return $position;
    }

    /**
     * @param  array{title?: string, allow_abstain?: bool}  $data
     */
    public function updatePosition(Election $election, ElectionPosition $position, array $data, AdminUser $admin): ElectionPosition
    {
        $this->assertBallotMutable($election);

        $position->fill([
            'title' => $data['title'] ?? $position->title,
            'allow_abstain' => array_key_exists('allow_abstain', $data)
                ? (bool) $data['allow_abstain']
                : $position->allow_abstain,
        ])->save();

        $this->audit->record(
            $election->id,
            ElectionAuditAction::ElectionUpdated,
            'admin',
            (string) $admin->id,
            ['event' => 'position_updated', 'position_id' => $position->id, 'title' => $position->title],
        );

        return $position;
    }

    public function deletePosition(Election $election, ElectionPosition $position, AdminUser $admin): void
    {
        $this->assertBallotMutable($election);
        $positionId = $position->id;
        $title = $position->title;
        $position->delete();

        $this->audit->record(
            $election->id,
            ElectionAuditAction::ElectionUpdated,
            'admin',
            (string) $admin->id,
            ['event' => 'position_removed', 'position_id' => $positionId, 'title' => $title],
        );
    }

    /**
     * @param  array{name: string}  $data
     */
    public function addCandidate(Election $election, ElectionPosition $position, array $data, AdminUser $admin): ElectionCandidate
    {
        $this->assertBallotMutable($election);

        $max = (int) $position->candidates()->max('sort_order');

        $candidate = ElectionCandidate::query()->create([
            'id' => Uuid::v4(),
            'position_id' => $position->id,
            'name' => $data['name'],
            'sort_order' => $max + 1,
            'is_active' => true,
        ]);

        $this->audit->record(
            $election->id,
            ElectionAuditAction::ElectionUpdated,
            'admin',
            (string) $admin->id,
            ['event' => 'candidate_added', 'position_id' => $position->id, 'candidate_id' => $candidate->id, 'name' => $candidate->name],
        );

        return $candidate;
    }

    public function deleteCandidate(Election $election, ElectionCandidate $candidate, AdminUser $admin): void
    {
        $this->assertBallotMutable($election);
        $candidateId = $candidate->id;
        $positionId = $candidate->position_id;
        $name = $candidate->name;
        $candidate->delete();

        $this->audit->record(
            $election->id,
            ElectionAuditAction::ElectionUpdated,
            'admin',
            (string) $admin->id,
            ['event' => 'candidate_removed', 'position_id' => $positionId, 'candidate_id' => $candidateId, 'name' => $name],
        );
    }

    private function assertBallotMutable(Election $election): void
    {
        if (! $election->isBallotMutable()) {
            throw ValidationException::withMessages([
                'ballot' => 'Ballot is locked after approval. Unlock the ballot before editing.',
            ]);
        }
    }
}
