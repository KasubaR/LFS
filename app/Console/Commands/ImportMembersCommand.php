<?php

namespace App\Console\Commands;

use App\Services\MemberImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportMembersCommand extends Command
{
    protected $signature = 'membership:import {file : Path to xlsx/csv file} {--send-welcome : Send welcome emails} {--dry-run : Run the import, then roll everything back}';

    protected $description = 'Import members from a legacy Excel/CSV export';

    public function handle(MemberImportService $importService): int
    {
        $path = $this->argument('file');

        if (! is_file($path)) {
            $this->error('File not found: '.$path);

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        if ($dryRun) {
            DB::beginTransaction();
        }

        try {
            $result = $importService->importFromFile(
                $path,
                'artisan:membership:import',
                $this->option('send-welcome') && ! $dryRun,
            );
        } finally {
            if ($dryRun) {
                DB::rollBack();
                $this->warn('Dry run: nothing was saved.');
            }
        }

        $this->info('Import complete.');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total rows', $result['totalRows']],
                ['Imported', $result['importedRows']],
                ['Skipped', $result['skippedRows']],
                ['Errors', $result['errorRows']],
            ]
        );

        if (! empty($result['errors'])) {
            $this->warn('Issues:');
            foreach ($result['errors'] as $error) {
                $this->line('- '.$error);
            }
        }

        return self::SUCCESS;
    }
}
