<?php

namespace App\Services;

use App\Mail\DatabaseBackupReady;
use App\Models\DatabaseBackup;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class DatabaseBackupService
{
    private const BACKUP_DIRECTORY = 'database-backups';
    private const TEMP_DIRECTORY = 'database-backups/tmp';

    public function __construct(
        private readonly AdminSettingsService $settings,
        private readonly MailSetupService $mailSetup,
    ) {
    }

    public function createBackup(string $type = 'manual', ?User $user = null): DatabaseBackup
    {
        $settings = $this->settings();

        if (! ($settings['enabled'] ?? true)) {
            throw new RuntimeException('Database backup system is disabled.');
        }

        $connection = $this->mysqlConnectionConfig();
        $disk = $this->backupDisk();
        $compress = (bool) ($settings['compress'] ?? true);
        $filename = sprintf(
            'shirin-fashion-db-%s.sql%s',
            now('Asia/Dhaka')->format('Ymd-His'),
            $compress ? '.gz' : '',
        );
        $path = self::BACKUP_DIRECTORY.'/'.$filename;

        $backup = DatabaseBackup::query()->create([
            'filename' => $filename,
            'disk' => $disk,
            'path' => $path,
            'status' => 'pending',
            'type' => $type,
            'created_by' => $user?->id,
        ]);

        $temporaryPath = $this->temporaryPath($filename);

        try {
            $this->dumpDatabase($connection, $temporaryPath, $compress);

            $stream = fopen($temporaryPath, 'rb');

            if (! $stream) {
                throw new RuntimeException('Unable to open generated backup file.');
            }

            Storage::disk($disk)->put($path, $stream);
            fclose($stream);

            @unlink($temporaryPath);

            if (! Storage::disk($disk)->exists($path)) {
                throw new RuntimeException('Backup was generated but could not be stored.');
            }

            $backup->forceFill([
                'status' => 'completed',
                'size' => Storage::disk($disk)->size($path),
                'completed_at' => now(),
                'download_token' => Str::random(64),
                'download_token_expires_at' => now()->addDays($this->downloadTtlDays()),
                'error_message' => null,
            ])->save();

            $this->sendReadyEmail($backup);
            $this->pruneOldBackups();

            return $backup->fresh(['creator', 'restorer']);
        } catch (Throwable $exception) {
            @unlink($temporaryPath);

            $backup->forceFill([
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
            ])->save();

            throw $exception;
        }
    }

    public function storeUploadedBackup(UploadedFile $file, ?User $user = null): DatabaseBackup
    {
        $disk = $this->backupDisk();
        $extension = strtolower($file->getClientOriginalExtension());
        $filename = sprintf(
            'uploaded-db-restore-%s.%s',
            now('Asia/Dhaka')->format('Ymd-His'),
            $extension ?: 'sql',
        );
        $path = self::BACKUP_DIRECTORY.'/'.$filename;

        Storage::disk($disk)->putFileAs(self::BACKUP_DIRECTORY, $file, $filename);

        return DatabaseBackup::query()->create([
            'filename' => $filename,
            'disk' => $disk,
            'path' => $path,
            'size' => Storage::disk($disk)->size($path),
            'status' => 'completed',
            'type' => 'uploaded',
            'created_by' => $user?->id,
            'completed_at' => now(),
            'download_token' => Str::random(64),
            'download_token_expires_at' => now()->addDays($this->downloadTtlDays()),
        ])->fresh(['creator', 'restorer']);
    }

    public function restoreBackup(DatabaseBackup $backup, ?User $user = null): DatabaseBackup
    {
        $settings = $this->settings();

        if (! ($settings['restore_enabled'] ?? false)) {
            throw new RuntimeException('Database restore is disabled.');
        }

        if (! in_array($backup->status, ['completed', 'restored'], true)) {
            throw new RuntimeException('Only completed backups can be restored.');
        }

        if (! Storage::disk($backup->disk)->exists($backup->path)) {
            throw new RuntimeException('Backup file was not found.');
        }

        $backup->forceFill([
            'status' => 'restoring',
            'error_message' => null,
        ])->save();

        $sourcePath = $this->copyBackupToTemporaryFile($backup);
        $restorePath = $sourcePath;

        try {
            if (str_ends_with($backup->filename, '.gz')) {
                $restorePath = $this->decompressGzip($sourcePath);
            }

            $this->importDatabase($this->mysqlConnectionConfig(), $restorePath);

            $backup->forceFill([
                'status' => 'restored',
                'restored_by' => $user?->id,
                'restored_at' => now(),
            ])->save();

            return $backup->fresh(['creator', 'restorer']);
        } catch (Throwable $exception) {
            $backup->forceFill([
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
            ])->save();

            throw $exception;
        } finally {
            @unlink($sourcePath);

            if ($restorePath !== $sourcePath) {
                @unlink($restorePath);
            }
        }
    }

    public function findDownloadableByToken(string $token): DatabaseBackup
    {
        $backup = DatabaseBackup::query()
            ->where('download_token', $token)
            ->where('download_token_expires_at', '>', now())
            ->whereIn('status', ['completed', 'restored'])
            ->first();

        if (! $backup) {
            throw new RuntimeException('Backup download link is invalid or expired.');
        }

        return $backup;
    }

    public function serializeBackup(DatabaseBackup $backup): array
    {
        return [
            'id' => $backup->id,
            'filename' => $backup->filename,
            'disk' => $backup->disk,
            'path' => $backup->path,
            'size' => $backup->size,
            'status' => $backup->status,
            'type' => $backup->type,
            'error_message' => $backup->error_message,
            'created_by' => $backup->creator?->name,
            'restored_by' => $backup->restorer?->name,
            'created_at' => optional($backup->created_at)->toISOString(),
            'updated_at' => optional($backup->updated_at)->toISOString(),
            'completed_at' => optional($backup->completed_at)->toISOString(),
            'restored_at' => optional($backup->restored_at)->toISOString(),
            'download_token_expires_at' => optional($backup->download_token_expires_at)->toISOString(),
            'download_url' => $backup->download_token ? $this->downloadUrl($backup) : null,
        ];
    }

    public function settings(): array
    {
        return $this->settings->getGroup('database_backup');
    }

    public function downloadUrl(DatabaseBackup $backup): string
    {
        return $this->publicDownloadBaseUrl().'/api/database-backups/download/'.$backup->download_token;
    }

    public function shouldRunMonthlyBackup(CarbonInterface $now): bool
    {
        $settings = $this->settings();

        if (! ($settings['enabled'] ?? true) || ! ($settings['monthly_backup_enabled'] ?? true)) {
            return false;
        }

        $targetDay = min(
            max(1, (int) ($settings['monthly_day'] ?? 1)),
            $now->daysInMonth,
        );
        $targetTime = (string) ($settings['monthly_time'] ?? '03:00');
        [$hour] = array_pad(explode(':', $targetTime, 2), 2, '00');

        if ((int) $now->day !== $targetDay || (int) $now->hour !== (int) $hour) {
            return false;
        }

        return ! DatabaseBackup::query()
            ->where('type', 'monthly')
            ->whereDate('created_at', $now->toDateString())
            ->exists();
    }

    private function dumpDatabase(array $connection, string $path, bool $compress): void
    {
        $command = [
            'mysqldump',
            '--host='.$connection['host'],
            '--port='.$connection['port'],
            '--user='.$connection['username'],
            '--single-transaction',
            '--quick',
            '--routines',
            '--triggers',
            $connection['database'],
        ];

        if ($connection['password'] !== '') {
            array_splice($command, 3, 0, ['--password='.$connection['password']]);
        }

        $writer = $compress ? gzopen($path, 'wb9') : fopen($path, 'wb');

        if (! $writer) {
            throw new RuntimeException('Unable to create backup file.');
        }

        $process = new Process($command);
        $process->setTimeout(3600);
        $process->run(function (string $type, string $buffer) use ($writer, $compress): void {
            if ($type !== Process::OUT) {
                return;
            }

            $compress ? gzwrite($writer, $buffer) : fwrite($writer, $buffer);
        });

        $compress ? gzclose($writer) : fclose($writer);

        if (! $process->isSuccessful()) {
            throw new RuntimeException(trim($process->getErrorOutput()) ?: 'Database backup command failed.');
        }
    }

    private function importDatabase(array $connection, string $path): void
    {
        $command = [
            'mysql',
            '--host='.$connection['host'],
            '--port='.$connection['port'],
            '--user='.$connection['username'],
            $connection['database'],
        ];

        if ($connection['password'] !== '') {
            array_splice($command, 3, 0, ['--password='.$connection['password']]);
        }

        $stream = fopen($path, 'rb');

        if (! $stream) {
            throw new RuntimeException('Unable to open backup file for restore.');
        }

        $process = new Process($command);
        $process->setTimeout(3600);
        $process->setInput($stream);
        $process->run();
        fclose($stream);

        if (! $process->isSuccessful()) {
            throw new RuntimeException(trim($process->getErrorOutput()) ?: 'Database restore command failed.');
        }
    }

    private function copyBackupToTemporaryFile(DatabaseBackup $backup): string
    {
        $destination = $this->temporaryPath($backup->filename);
        $stream = Storage::disk($backup->disk)->readStream($backup->path);

        if (! $stream) {
            throw new RuntimeException('Unable to read backup file.');
        }

        $target = fopen($destination, 'wb');

        if (! $target) {
            fclose($stream);
            throw new RuntimeException('Unable to prepare backup file for restore.');
        }

        stream_copy_to_stream($stream, $target);
        fclose($stream);
        fclose($target);

        return $destination;
    }

    private function decompressGzip(string $sourcePath): string
    {
        $destination = preg_replace('/\.gz$/', '', $sourcePath) ?: ($sourcePath.'.sql');
        $input = gzopen($sourcePath, 'rb');
        $output = fopen($destination, 'wb');

        if (! $input || ! $output) {
            if ($input) {
                gzclose($input);
            }

            if ($output) {
                fclose($output);
            }

            throw new RuntimeException('Unable to decompress backup file.');
        }

        while (! gzeof($input)) {
            fwrite($output, gzread($input, 1024 * 1024));
        }

        gzclose($input);
        fclose($output);

        return $destination;
    }

    private function temporaryPath(string $filename): string
    {
        $directory = storage_path('app/private/'.self::TEMP_DIRECTORY);

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create database backup temp directory.');
        }

        return $directory.'/'.Str::uuid().'-'.basename($filename);
    }

    private function pruneOldBackups(): void
    {
        $retentionMonths = max(1, (int) ($this->settings()['retention_months'] ?? 6));
        $cutoff = now()->subMonths($retentionMonths);

        DatabaseBackup::query()
            ->where('created_at', '<', $cutoff)
            ->whereIn('status', ['completed', 'failed', 'restored'])
            ->chunkById(50, function ($backups): void {
                foreach ($backups as $backup) {
                    if (Storage::disk($backup->disk)->exists($backup->path)) {
                        Storage::disk($backup->disk)->delete($backup->path);
                    }

                    $backup->delete();
                }
            });
    }

    private function sendReadyEmail(DatabaseBackup $backup): void
    {
        $recipient = $this->notificationEmail();

        if ($recipient === '') {
            return;
        }

        try {
            $this->mailSetup->configureMailer();
            Mail::to($recipient)->send(new DatabaseBackupReady($backup, $this->downloadUrl($backup)));
        } catch (Throwable $exception) {
            Log::warning('Database backup email notification failed.', [
                'backup_id' => $backup->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function notificationEmail(): string
    {
        $settings = $this->settings();
        $mailSettings = $this->settings->getGroup('mail_setup');
        $generalSettings = $this->settings->getGroup('general');

        return trim((string) (
            $settings['notification_email']
            ?: $mailSettings['recipient_email']
            ?: $generalSettings['support_email']
            ?: ''
        ));
    }

    private function backupDisk(): string
    {
        $disk = (string) ($this->settings()['backup_disk'] ?? 'local');

        return array_key_exists($disk, config('filesystems.disks', [])) ? $disk : 'local';
    }

    private function downloadTtlDays(): int
    {
        return max(1, min(30, (int) ($this->settings()['download_link_ttl_days'] ?? 7)));
    }

    private function publicDownloadBaseUrl(): string
    {
        $settings = $this->settings();
        $candidates = [
            $settings['public_download_base_url'] ?? null,
            env('BACKUP_DOWNLOAD_BASE_URL'),
            env('PUBLIC_API_URL'),
            env('API_URL'),
            config('app.url'),
            request()?->getSchemeAndHttpHost(),
            'https://api.shirinfashion.app',
        ];

        foreach ($candidates as $candidate) {
            $baseUrl = trim((string) $candidate);

            if ($this->isPublicHttpUrl($baseUrl)) {
                return rtrim($baseUrl, '/');
            }
        }

        return 'https://api.shirinfashion.app';
    }

    private function isPublicHttpUrl(string $url): bool
    {
        if ($url === '') {
            return false;
        }

        $host = parse_url($url, PHP_URL_HOST);
        $scheme = parse_url($url, PHP_URL_SCHEME);

        if (! in_array($scheme, ['http', 'https'], true) || ! is_string($host)) {
            return false;
        }

        return ! in_array(strtolower($host), ['localhost', '127.0.0.1', '::1'], true);
    }

    /**
     * @return array{host: string, port: string|int, database: string, username: string, password: string}
     */
    private function mysqlConnectionConfig(): array
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            throw new RuntimeException('Database backup currently supports MySQL connections only.');
        }

        $config = DB::connection()->getConfig();

        return [
            'host' => (string) ($config['host'] ?? '127.0.0.1'),
            'port' => $config['port'] ?? 3306,
            'database' => (string) ($config['database'] ?? ''),
            'username' => (string) ($config['username'] ?? ''),
            'password' => (string) ($config['password'] ?? ''),
        ];
    }
}
