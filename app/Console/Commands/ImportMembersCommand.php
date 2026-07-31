<?php

namespace App\Console\Commands;

use App\Services\MemberImportService;
use Illuminate\Console\Command;

class ImportMembersCommand extends Command
{
    protected $signature = 'membership:import {file : Path to xlsx/csv file} {--send-welcome : Send welcome emails}';

    protected $description = 'Import members from a legacy Excel/CSV export';

    public function handle(MemberImportService $importService): int
    {
        $path = $this->argument('file');

        if (! is_file($path)) {
            $this->error('File not found: '.$path);

            return self::FAILURE;
        }

        $result = $importService->importFromFile(
            $path,
            'artisan:membership:import',
            $this->option('send-welcome'),
        );

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
            foreach (array_slice($result['errors'], 0, 10) as $error) {
                $this->line('- '.$error);
            }
        }

        return self::SUCCESS;
    }
}
