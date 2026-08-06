<?php

namespace App\Console\Commands;

use App\Models\Membership;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-off (but re-runnable) backfill for membership numbers that predate the
 * "LFS" prefix — the 2026-08 bulk import wrote the spreadsheet's raw "Ref"
 * cell (e.g. "14149") straight into membership_number with no prefix, unlike
 * numbers generated for regular signups (LFS-000123). This gives every
 * membership number an "LFS" prefix, preserving the original digits exactly
 * (e.g. "14149" -> "LFS14149") rather than reformatting them.
 *
 * Deliberately leaves membership_payments.payment_reference untouched — it
 * records the original spreadsheet ref as an accurate historical value, and
 * isn't the member-facing identifier this backfill is about.
 */
class BackfillMembershipNumberPrefixCommand extends Command
{
    protected $signature = 'member-import:backfill-lfs-prefix
        {--dry-run : Print what would change without writing anything}';

    protected $description = 'Prefix legacy (pre-LFS-prefix) membership numbers with the configured "LFS" prefix.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $prefix = strtoupper((string) config('membership.membership_number_prefix', 'LFS'));

        $memberships = Membership::query()
            ->whereNotNull('membership_number')
            ->where('membership_number', '!=', '')
            ->get()
            ->filter(fn (Membership $m) => ! str_starts_with(strtoupper((string) $m->membership_number), $prefix));

        if ($memberships->isEmpty()) {
            $this->info('No membership numbers need a prefix.');

            return self::SUCCESS;
        }

        $rows = [];
        $unresolved = [];
        $changed = 0;

        foreach ($memberships as $membership) {
            $old = (string) $membership->membership_number;

            // Only rewrite the expected shape of a legacy spreadsheet ref
            // (bare digits) — anything else (e.g. stray test/diagnostic
            // data) is reported and left untouched rather than guessed at.
            if (! ctype_digit($old)) {
                $unresolved[] = "membership {$membership->id}: membership_number \"{$old}\" is not purely numeric — left unchanged, needs manual review";

                continue;
            }

            $new = $prefix.$old;
            $rows[] = [$membership->id, $old, $new];
            $changed++;

            if ($dryRun) {
                continue;
            }

            DB::transaction(function () use ($membership, $new) {
                $membership->forceFill(['membership_number' => $new])->save();
            });
        }

        if ($rows !== []) {
            $this->table(['Membership ID', 'Old number', 'New number'], $rows);
        }

        $this->newLine();
        $this->info(($dryRun ? '[DRY RUN] ' : '').$changed.' membership number(s) '.($dryRun ? 'would be' : 'were').' prefixed.');

        if ($unresolved !== []) {
            $this->newLine();
            $this->warn('Unresolved rows (left untouched, need manual review):');
            foreach ($unresolved as $line) {
                $this->line("  - {$line}");
            }
        }

        return self::SUCCESS;
    }
}
