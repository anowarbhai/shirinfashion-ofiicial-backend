<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\DatabaseBackup;
use App\Services\AdminSettingsService;
use App\Services\DatabaseBackupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class DatabaseBackupController extends Controller
{
    public function __construct(
        private readonly AdminSettingsService $settings,
        private readonly DatabaseBackupService $backups,
    ) {
    }

    public function index(): JsonResponse
    {
        $items = DatabaseBackup::query()
            ->with(['creator:id,name', 'restorer:id,name'])
            ->latest()
            ->limit(60)
            ->get()
            ->map(fn (DatabaseBackup $backup): array => $this->backups->serializeBackup($backup));

        return response()->json([
            'data' => [
                'settings' => $this->settings->getGroup('database_backup'),
                'backups' => $items,
            ],
        ]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'enabled' => ['required', 'boolean'],
            'monthly_backup_enabled' => ['required', 'boolean'],
            'monthly_day' => ['required', 'integer', 'min:1', 'max:31'],
            'monthly_time' => ['required', 'date_format:H:i'],
            'retention_months' => ['required', 'integer', 'min:1', 'max:36'],
            'notification_email' => ['nullable', 'email'],
            'backup_disk' => ['required', 'string', 'in:local,s3'],
            'compress' => ['required', 'boolean'],
            'restore_enabled' => ['required', 'boolean'],
            'download_link_ttl_days' => ['required', 'integer', 'min:1', 'max:30'],
            'public_download_base_url' => ['nullable', 'url', 'max:255'],
        ]);

        $data = $this->settings->saveGroup('database_backup', $payload);
        $items = DatabaseBackup::query()
            ->with(['creator:id,name', 'restorer:id,name'])
            ->latest()
            ->limit(60)
            ->get()
            ->map(fn (DatabaseBackup $backup): array => $this->backups->serializeBackup($backup));

        return response()->json([
            'message' => 'Database backup settings saved successfully.',
            'data' => [
                'settings' => $data,
                'backups' => $items,
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $backup = $this->backups->createBackup('manual', $request->user());

            return response()->json([
                'message' => 'Database backup created successfully.',
                'data' => $this->backups->serializeBackup($backup),
            ], 201);
        } catch (Throwable $exception) {
            return response()->json([
                'message' => $exception->getMessage() ?: 'Unable to create database backup.',
            ], 422);
        }
    }

    public function restore(DatabaseBackup $databaseBackup, Request $request): JsonResponse
    {
        try {
            $backup = $this->backups->restoreBackup($databaseBackup, $request->user());

            return response()->json([
                'message' => 'Database backup restored successfully.',
                'data' => $this->backups->serializeBackup($backup),
            ]);
        } catch (Throwable $exception) {
            return response()->json([
                'message' => $exception->getMessage() ?: 'Unable to restore database backup.',
            ], 422);
        }
    }

    public function restoreUpload(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'backup_file' => ['required', 'file', 'max:512000'],
        ]);

        try {
            $filename = strtolower($payload['backup_file']->getClientOriginalName());

            if (! Str::endsWith($filename, ['.sql', '.sql.gz', '.gz'])) {
                return response()->json([
                    'message' => 'Please upload a valid .sql or .sql.gz database backup file.',
                ], 422);
            }

            $backup = $this->backups->storeUploadedBackup($payload['backup_file'], $request->user());
            $restored = $this->backups->restoreBackup($backup, $request->user());

            return response()->json([
                'message' => 'Uploaded database backup restored successfully.',
                'data' => $this->backups->serializeBackup($restored),
            ]);
        } catch (Throwable $exception) {
            return response()->json([
                'message' => $exception->getMessage() ?: 'Unable to restore uploaded database backup.',
            ], 422);
        }
    }

    public function download(DatabaseBackup $databaseBackup): StreamedResponse|JsonResponse
    {
        return $this->downloadBackup($databaseBackup);
    }

    public function destroy(DatabaseBackup $databaseBackup): JsonResponse
    {
        try {
            if (Storage::disk($databaseBackup->disk)->exists($databaseBackup->path)) {
                Storage::disk($databaseBackup->disk)->delete($databaseBackup->path);
            }

            $databaseBackup->delete();

            return response()->json([
                'message' => 'Database backup deleted successfully.',
            ]);
        } catch (Throwable $exception) {
            return response()->json([
                'message' => $exception->getMessage() ?: 'Unable to delete database backup.',
            ], 422);
        }
    }

    public function downloadByToken(string $token): StreamedResponse|JsonResponse
    {
        try {
            return $this->downloadBackup($this->backups->findDownloadableByToken($token));
        } catch (Throwable $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 404);
        }
    }

    private function downloadBackup(DatabaseBackup $backup): StreamedResponse|JsonResponse
    {
        if (! Storage::disk($backup->disk)->exists($backup->path)) {
            return response()->json([
                'message' => 'Backup file was not found.',
            ], 404);
        }

        $response = Storage::disk($backup->disk)->download($backup->path, $backup->filename);
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
