<?php

namespace App\Services\Election;

use App\Models\ElectionVote;
use App\Models\ElectionVoteOutbox;
use Illuminate\Support\Facades\DB;

class ElectionVoteFlushService
{
    /**
     * Flush pending outbox rows into election_votes (batch, no user linkage).
     */
    public function flush(?int $limit = 200): int
    {
        $flushed = 0;

        $rows = ElectionVoteOutbox::query()
            ->where('status', 'pending')
            ->orderBy('created_at')
            ->limit($limit ?? 200)
            ->get();

        if ($rows->isEmpty()) {
            return 0;
        }

        // Shuffle within the batch so relative order is not insertion order.
        $shuffled = $rows->shuffle();

        DB::transaction(function () use ($shuffled, &$flushed) {
            foreach ($shuffled as $row) {
                /** @var ElectionVoteOutbox $locked */
                $locked = ElectionVoteOutbox::query()
                    ->where('blind_ballot_id', $row->blind_ballot_id)
                    ->where('status', 'pending')
                    ->lockForUpdate()
                    ->first();

                if ($locked === null) {
                    continue;
                }

                ElectionVote::query()->create([
                    'blind_ballot_id' => $locked->blind_ballot_id,
                    'election_id' => $locked->election_id,
                    'position_id' => $locked->position_id,
                    'ciphertext' => $locked->ciphertext,
                    'flushed_at' => now(),
                    'tally_status' => null,
                    'candidate_id' => null,
                ]);

                $locked->delete();
                $flushed++;
            }
        });

        return $flushed;
    }

    public function drainElection(string $electionId): int
    {
        $total = 0;
        do {
            $batch = ElectionVoteOutbox::query()
                ->where('election_id', $electionId)
                ->where('status', 'pending')
                ->limit(200)
                ->get();

            if ($batch->isEmpty()) {
                break;
            }

            foreach ($batch as $row) {
                ElectionVote::query()->firstOrCreate(
                    ['blind_ballot_id' => $row->blind_ballot_id],
                    [
                        'election_id' => $row->election_id,
                        'position_id' => $row->position_id,
                        'ciphertext' => $row->ciphertext,
                        'flushed_at' => now(),
                    ]
                );
                $row->delete();
                $total++;
            }
        } while (true);

        return $total;
    }
}
