<?php

namespace App\Console\Commands;

use App\Services\Election\ElectionAuditService;
use Illuminate\Console\Command;

class VerifyElectionAuditChainCommand extends Command
{
    protected $signature = 'elections:verify-audit {--election=}';

    protected $description = 'Verify the hash chain on election audit logs';

    public function handle(ElectionAuditService $audit): int
    {
        $result = $audit->verifyChain($this->option('election') ?: null);
        if ($result['ok']) {
            $this->info("Audit chain OK ({$result['checked']} entries).");

            return self::SUCCESS;
        }

        $this->error("Audit chain broken at {$result['broken_at']} after {$result['checked']} entries.");

        return self::FAILURE;
    }
}
