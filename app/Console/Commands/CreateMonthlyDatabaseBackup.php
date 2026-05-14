<?php

namespace App\Console\Commands;

use App\Services\DatabaseBackupService;
use Illuminate\Console\Command;
use Throwable;

class CreateMonthlyDatabaseBackup extends Command
{
    protected $signature = 'backup:database-monthly';

    protected $description = 'Create the scheduled monthly database backup when settings say it is due.';

    public function handle(DatabaseBackupService $backups): int
    {
        $now = now('Asia/Dhaka');

        if (! $backups->shouldRunMonthlyBackup($now)) {
            $this->components->info('Monthly database backup is not due.');

            return self::SUCCESS;
        }

        try {
            $backup = $backups->createBackup('monthly');
            $this->components->info("Monthly database backup created: {$backup->filename}");

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
