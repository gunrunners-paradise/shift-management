<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AccountManagementService;
use App\Services\WorkerCsvReader;
use Illuminate\Console\Command;
use Throwable;

class ImportWorkers extends Command
{
    protected $signature = 'app:import-workers {path : Local CSV path, relative to the Laravel root or absolute} {--dry-run : Validate and preview without creating accounts}';

    protected $description = 'Import active worker accounts from a validated private CSV file';

    /**
     * Trusted operator-only CLI workflow; no HTTP route invokes this command.
     * Validate the entire file before requesting any account writes. Unexpected
     * exceptions are deliberately neither rendered nor logged because SQL,
     * bindings and stack arguments may contain sensitive CSV values.
     */
    public function handle(WorkerCsvReader $reader, AccountManagementService $accounts): int
    {
        try {
            $this->info('Reading CSV file...');
            $this->line('Validating employee data...');
            $result = $reader->read((string) $this->argument('path'));

            if ($result['errors'] !== []) {
                foreach ($result['errors'] as $error) {
                    $this->error($error);
                }

                $this->line('Invalid records: '.$result['invalid']);
                $this->error('Validation failed. No accounts were created.');

                return self::FAILURE;
            }

            $this->info('CSV validation successful.');

            if ($this->option('dry-run')) {
                return $this->preview($result['workers']);
            }

            $import = $accounts->importWorkers($result['workers']);
            $this->showSkippedUsernames($import['skipped']);
            $this->info('Import completed successfully.');
            $this->line('Workers created: '.$import['created']);
            $this->line('Existing usernames skipped: '.count($import['skipped']));
            $this->line('Invalid records: 0');

            return self::SUCCESS;
        } catch (Throwable) {
            // Do not hand the original exception to Artisan's global reporter.
            $this->error('Import failed. Check database/file access and the account list before retrying; existing usernames will be skipped.');

            return self::FAILURE;
        }
    }

    /** Preview uses SELECTs only, without locks, hashing or persistence. */
    private function preview(array $workers): int
    {
        $skipped = [];

        foreach ($workers as $attributes) {
            if (User::where('username', $attributes['username'])->exists()) {
                $skipped[] = $attributes['username'];
            }
        }

        $this->showSkippedUsernames($skipped);
        $this->line('New workers ready to import: '.(count($workers) - count($skipped)));
        $this->line('Existing usernames skipped: '.count($skipped));
        $this->line('Invalid records: 0');
        $this->info('Dry run completed. No database changes were made.');

        return self::SUCCESS;
    }

    private function showSkippedUsernames(array $usernames): void
    {
        foreach ($usernames as $username) {
            // These names have already passed the ASCII username validation.
            $this->warn('Existing username skipped: '.$username);
        }
    }
}
