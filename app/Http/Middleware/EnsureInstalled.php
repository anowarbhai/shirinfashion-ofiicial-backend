<?php

namespace App\Http\Middleware;

use Closure;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class EnsureInstalled
{
    public function handle(Request $request, Closure $next): Response
    {
        $lockFile = storage_path('installed');

        // Allow installer pages, installer APIs, and health check
        if ($request->is('install') || $request->is('api/installer/*') || $request->is('health')) {
            return $next($request);
        }

        // If lock file is missing, check if DB is already migrated & has users
        if (!File::exists($lockFile)) {
            try {
                if (Schema::hasTable('users') && User::exists()) {
                    // Database is already installed! Auto-create storage/installed lock file
                    File::put($lockFile, json_encode([
                        'installed_at' => now()->toIso8601String(),
                        'auto_detected' => true,
                    ]));

                    return $next($request);
                }
            } catch (\Throwable $e) {
                // DB check failed (e.g. no DB connection or table missing)
            }

            $appName = config('app.name', 'BD Caliph');

            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'installed' => false,
                    'message' => "{$appName} is not installed yet. Please complete setup.",
                    'installer_url' => url('/install'),
                ], 503);
            }

            return redirect('/install');
        }

        return $next($request);
    }
}
