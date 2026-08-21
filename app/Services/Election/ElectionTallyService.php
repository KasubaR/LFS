<?php

namespace App\Services\Election;

use App\Enums\ElectionVoteTallyStatus;
use App\Models\Election;
use App\Models\ElectionBallotEntitlement;
use App\Models\ElectionCandidate;
use App\Models\ElectionVote;
use RuntimeException;

class ElectionTallyService
{
    public function __construct(
        private readonly ElectionVoteCrypto $crypto,
    ) {}

    /**
     * @return array{
     *   positions: list<array{
     *     id: string,
     *     title: string,
     *     candidates: list<array{id: string, name: string, votes: int}>,
     *     abstentions: int,
     *     rejected: int,
     *     incomplete: int,
     *     winner: ?array{id: string, name: string, votes: int}
     *   }>,
     *   incomplete_total: int
     * }
     */
    public function tally(Election $election): array
    {
        if (! $election->isClosedOrLater()) {
            throw new RuntimeException('Tallies are only available after polls close.');
        }

        $positions = [];
        $incompleteTotal = 0;

        foreach ($election->positions()->with('candidates')->get() as $position) {
            $used = ElectionBallotEntitlement::query()
                ->where('election_id', $election->id)
                ->where('position_id', $position->id)
                ->whereNotNull('used_at')
                ->count();

            $votes = ElectionVote::query()
                ->where('election_id', $election->id)
                ->where('position_id', $position->id)
                ->get();

            $counts = [];
            foreach ($position->candidates as $candidate) {
                $counts[$candidate->id] = 0;
            }

            $abstentions = 0;
            $rejected = 0;

            foreach ($votes as $vote) {
                if ($vote->tally_status !== null) {
                    if ($vote->tally_status === ElectionVoteTallyStatus::Abstain) {
                        $abstentions++;
                    } elseif ($vote->tally_status === ElectionVoteTallyStatus::Rejected) {
                        $rejected++;
                    } elseif ($vote->tally_status === ElectionVoteTallyStatus::Valid && $vote->candidate_id) {
                        $counts[$vote->candidate_id] = ($counts[$vote->candidate_id] ?? 0) + 1;
                    }

                    continue;
                }

                try {
                    $payload = $this->crypto->withVoteKey(
                        fn () => $this->crypto->decrypt($vote->ciphertext)
                    );

                    if (! empty($payload['abstain'])) {
                        $vote->update([
                            'tally_status' => ElectionVoteTallyStatus::Abstain,
                            'candidate_id' => null,
                        ]);
                        $abstentions++;
                    } else {
                        $candidateId = $payload['candidate_id'] ?? null;
                        if (! is_string($candidateId) || ! isset($counts[$candidateId])) {
                            $vote->update([
                                'tally_status' => ElectionVoteTallyStatus::Rejected,
                                'candidate_id' => null,
                            ]);
                            $rejected++;
                        } else {
                            $vote->update([
                                'tally_status' => ElectionVoteTallyStatus::Valid,
                                'candidate_id' => $candidateId,
                            ]);
                            $counts[$candidateId]++;
                        }
                    }
                } catch (\Throwable) {
                    $vote->update([
                        'tally_status' => ElectionVoteTallyStatus::Rejected,
                        'candidate_id' => null,
                    ]);
                    $rejected++;
                }
            }

            $flushed = $votes->count();
            $incomplete = max(0, $used - $flushed);
            $incompleteTotal += $incomplete;

            $candidateRows = [];
            $winner = null;
            foreach ($position->candidates as $candidate) {
                $row = [
                    'id' => $candidate->id,
                    'name' => $candidate->name,
                    'votes' => $counts[$candidate->id] ?? 0,
                ];
                $candidateRows[] = $row;
                if ($winner === null || $row['votes'] > $winner['votes']) {
                    $winner = $row;
                }
            }

            // Simple majority: most votes among candidates (ties leave winner as first max).
            $maxVotes = $winner['votes'] ?? 0;
            $top = array_values(array_filter($candidateRows, fn ($c) => $c['votes'] === $maxVotes && $maxVotes > 0));
            $winnerResult = count($top) === 1 ? $top[0] : null;

            $positions[] = [
                'id' => $position->id,
                'title' => $position->title,
                'candidates' => $candidateRows,
                'abstentions' => $abstentions,
                'rejected' => $rejected,
                'incomplete' => $incomplete,
                'winner' => $winnerResult,
            ];
        }

        return [
            'positions' => $positions,
            'incomplete_total' => $incompleteTotal,
        ];
    }
}
