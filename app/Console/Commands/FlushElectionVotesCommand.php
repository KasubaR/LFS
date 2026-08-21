<?php

namespace App\Console\Commands;

use App\Services\Election\ElectionVoteFlushService;
use Illuminate\Console\Command;

class FlushElectionVotesCommand extends Command
{
    protected $signature = 'elections:flush-votes {--limit=200}';

    protected $description = 'Batch-flush pending election vote outbox rows into election_votes';

    public function handle(ElectionVoteFlushService $flush): int
    {
        // Light jitter only — long sleeps break the scheduler.
        usleep(random_int(0, 2_000_000));

        $count = $flush->flush((int) $this->option('limit'));
        $this->info("Flushed {$count} vote(s).");

        return self::SUCCESS;
    }
}
