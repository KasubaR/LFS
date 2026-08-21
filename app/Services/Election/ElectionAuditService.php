<?php

namespace App\Services\Election;

use App\Models\ElectionAuditLog;
use App\Support\Uuid;
use Illuminate\Support\Facades\DB;

class ElectionAuditService
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        ?string $electionId,
        string $action,
        string $actorType,
        ?string $actorId = null,
        array $metadata = [],
        ?string $ipAddress = null,
    ): ElectionAuditLog {
        return DB::transaction(function () use ($electionId, $action, $actorType, $actorId, $metadata, $ipAddress) {
            $prev = ElectionAuditLog::query()
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            $prevHash = $prev?->entry_hash;
            $id = Uuid::v4();
            $createdAt = now();

            $canonical = json_encode([
                'id' => $id,
                'election_id' => $electionId,
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'action' => $action,
                'metadata' => $metadata,
                'ip_address' => $ipAddress,
                'prev_hash' => $prevHash,
                'created_at' => $createdAt->toIso8601String(),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            $entryHash = hash('sha256', ($prevHash ?? '').'|'.($canonical ?: ''));

            return ElectionAuditLog::query()->create([
                'id' => $id,
                'election_id' => $electionId,
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'action' => $action,
                'metadata' => $metadata,
                'ip_address' => $ipAddress,
                'prev_hash' => $prevHash,
                'entry_hash' => $entryHash,
                'created_at' => $createdAt,
            ]);
        });
    }

    /**
     * @return array{ok: bool, checked: int, broken_at: ?string}
     */
    public function verifyChain(?string $electionId = null): array
    {
        $query = ElectionAuditLog::query()->orderBy('created_at')->orderBy('id');
        if ($electionId !== null) {
            $query->where(function ($q) use ($electionId) {
                $q->where('election_id', $electionId)->orWhereNull('election_id');
            });
        }

        $prevHash = null;
        $checked = 0;

        foreach ($query->cursor() as $entry) {
            $checked++;
            $canonical = json_encode([
                'id' => $entry->id,
                'election_id' => $entry->election_id,
                'actor_type' => $entry->actor_type,
                'actor_id' => $entry->actor_id,
                'action' => $entry->action,
                'metadata' => $entry->metadata ?? [],
                'ip_address' => $entry->ip_address,
                'prev_hash' => $entry->prev_hash,
                'created_at' => $entry->created_at?->toIso8601String(),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            $expected = hash('sha256', ($entry->prev_hash ?? '').'|'.($canonical ?: ''));

            if ($entry->entry_hash !== $expected) {
                return ['ok' => false, 'checked' => $checked, 'broken_at' => (string) $entry->id];
            }

            if ($prevHash !== null && $entry->prev_hash !== $prevHash && $electionId === null) {
                // Global chain: prev must match previous entry
                // When filtering by election, chain may skip — skip prev continuity check
            }

            if ($electionId === null && $entry->prev_hash !== $prevHash) {
                return ['ok' => false, 'checked' => $checked, 'broken_at' => (string) $entry->id];
            }

            $prevHash = $entry->entry_hash;
        }

        return ['ok' => true, 'checked' => $checked, 'broken_at' => null];
    }
}
