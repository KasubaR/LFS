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
use Illuminate\Http\UploadedFile;
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
    public function addCandidate(Election $election, ElectionPosition $position, array $data, AdminUser $admin, ?UploadedFile $photo = null): ElectionCandidate
    {
        $this->assertBallotMutable($election);

        $max = (int) $position->candidates()->max('sort_order');

        $candidate = ElectionCandidate::query()->create([
            'id' => Uuid::v4(),
            'position_id' => $position->id,
            'name' => $data['name'],
            'photo_path' => $photo ? $this->storeCandidatePhoto($photo) : null,
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

    /**
     * @param  array{name?: string, remove_photo?: bool}  $data
     */
    public function updateCandidate(Election $election, ElectionCandidate $candidate, array $data, AdminUser $admin, ?UploadedFile $photo = null): ElectionCandidate
    {
        $this->assertBallotMutable($election);

        $oldPhotoPath = $candidate->photo_path;
        $newPhotoPath = $oldPhotoPath;

        if ($photo) {
            $newPhotoPath = $this->storeCandidatePhoto($photo);
        } elseif (! empty($data['remove_photo'])) {
            $newPhotoPath = null;
        }

        $candidate->fill([
            'name' => $data['name'] ?? $candidate->name,
            'photo_path' => $newPhotoPath,
        ])->save();

        if ($newPhotoPath !== $oldPhotoPath && $oldPhotoPath) {
            $this->deleteCandidatePhoto($oldPhotoPath);
        }

        $this->audit->record(
            $election->id,
            ElectionAuditAction::ElectionUpdated,
            'admin',
            (string) $admin->id,
            ['event' => 'candidate_updated', 'position_id' => $candidate->position_id, 'candidate_id' => $candidate->id, 'name' => $candidate->name],
        );

        return $candidate;
    }

    public function deleteCandidate(Election $election, ElectionCandidate $candidate, AdminUser $admin): void
    {
        $this->assertBallotMutable($election);
        $candidateId = $candidate->id;
        $positionId = $candidate->position_id;
        $name = $candidate->name;
        $photoPath = $candidate->photo_path;
        $candidate->delete();

        if ($photoPath) {
            $this->deleteCandidatePhoto($photoPath);
        }

        $this->audit->record(
            $election->id,
            ElectionAuditAction::ElectionUpdated,
            'admin',
            (string) $admin->id,
            ['event' => 'candidate_removed', 'position_id' => $positionId, 'candidate_id' => $candidateId, 'name' => $name],
        );
    }

    /**
     * Moves an uploaded candidate photo into the public web root and returns
     * its public path. The stored filename is derived from the file's
     * sniffed mime type (not the client-supplied name) and a fresh UUID, so
     * an attacker cannot plant an executable file in a publicly served
     * directory via a crafted filename.
     */
    private function storeCandidatePhoto(UploadedFile $photo): string
    {
        $dir = public_path('images/election-candidates');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $allowed = ['jpg', 'jpeg', 'png', 'webp'];
        $guessed = strtolower((string) ($photo->extension() ?: $photo->guessExtension() ?: ''));
        $ext = in_array($guessed, $allowed, true) ? $guessed : 'jpg';

        $filename = 'candidate_'.Uuid::v4().'.'.$ext;
        $photo->move($dir, $filename);

        return '/images/election-candidates/'.$filename;
    }

    private function deleteCandidatePhoto(string $path): void
    {
        $prefix = '/images/election-candidates/';
        if (str_contains($path, '..') || ! str_starts_with($path, $prefix)) {
            return;
        }

        $full = public_path(ltrim($path, '/'));
        $realFull = realpath($full);
        $realDir = realpath(public_path(ltrim($prefix, '/')));

        if ($realFull === false || $realDir === false || ! str_starts_with($realFull, $realDir)) {
            return;
        }

        @unlink($realFull);
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
