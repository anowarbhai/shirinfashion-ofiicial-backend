<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Services\AdminSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AppStatusController extends Controller
{
    public function __construct(private readonly AdminSettingsService $settings)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $settings = $this->settings->getGroup('mobile_push');
        $currentBuild = max(0, (int) $request->integer('build_number'));
        $latestBuild = max(0, (int) ($settings['latest_build_number'] ?? 0));
        $minimumBuild = max(0, (int) ($settings['minimum_build_number'] ?? 0));
        $enabled = (bool) ($settings['app_update_enabled'] ?? true);
        $updateAvailable = $enabled && $latestBuild > 0 && $currentBuild > 0 && $currentBuild < $latestBuild;
        $required = $enabled && $minimumBuild > 0 && $currentBuild > 0 && $currentBuild < $minimumBuild;

        return response()->json([
            'data' => [
                'current_build_number' => $currentBuild,
                'latest_version' => (string) ($settings['latest_version'] ?? ''),
                'latest_build_number' => $latestBuild,
                'minimum_build_number' => $minimumBuild,
                'update_available' => $updateAvailable,
                'update_required' => $required,
                'update_url' => (string) ($settings['update_url'] ?? ''),
                'title' => $required
                    ? (string) ($settings['critical_update_title'] ?? 'Update required')
                    : (string) ($settings['update_title'] ?? 'New app update available'),
                'message' => $required
                    ? (string) ($settings['critical_update_message'] ?? 'Please update to continue.')
                    : (string) ($settings['update_message'] ?? 'A newer app version is available.'),
                'reminder_hours' => max(1, (int) ($settings['update_reminder_hours'] ?? 24)),
            ],
        ]);
    }
}
