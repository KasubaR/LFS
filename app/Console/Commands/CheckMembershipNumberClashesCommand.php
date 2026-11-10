<?php

namespace App\Console\Commands;

use App\Services\MembershipNumberRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Read-only report: membership numbers held by more than one person.
 *
 * Run this on the live database BEFORE migrating to add the number registry,
 * and any time afterwards to confirm nothing slipped through. It never changes
 * data. Exit code is non-zero when clashes exist, so it can gate a deploy.
 */
class CheckMembershipNumberClashesCommand extends Command
{
    protected $signature = 'membership:check-number-clashes';

    protected $description = 'List membership numbers shared by different people (read-only)';

    public function handle(): int
    {
        $byKey = [];

        DB::table('memberships')
            ->leftJoin('users', 'users.id', '=', 'memberships.user_id')
            ->whereNotNull('memberships.membership_number')
            ->where('memberships.membership_number', '!=', '')
            ->orderBy('memberships.created_at')
            ->select(['memberships.id as membership_id', 'memberships.membership_number', 'memberships.user_id', 'memberships.status', 'users.email'])
            ->chunk(500, function ($rows) use (&$byKey): void {
                foreach ($rows as $row) {
                    $key = MembershipNumberRegistry::keyFor((string) $row->membership_number);
                    if ($key !== '') {
                        $byKey[$key][] = $row;
                    }
                }
            });

        $clashes = array_filter($byKey, fn (array $rows): bool => count(array_unique(array_map(fn ($r) => (int) $r->user_id, $rows))) > 1);

        if ($clashes === []) {
            $this->info('No membership number is shared by different people.');

            return self::SUCCESS;
        }

        $this->error(count($clashes).' membership number(s) are held by more than one person:');

        foreach ($clashes as $key => $rows) {
            $this->line('');
            $this->line("Number {$key}:");
            $this->table(
                ['As stored', 'User id', 'Email', 'Membership id', 'Status'],
                array_map(fn ($r) => [$r->membership_number, $r->user_id, $r->email ?? '(deleted)', $r->membership_id, $r->status], $rows)
            );
        }

        $this->warn('Give each person a different number (or merge duplicate accounts) before relying on the number registry.');

        return self::FAILURE;
    }
}
