<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
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

        // If application is not installed yet
        if (!File::exists($lockFile)) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'installed' => false,
                    'message' => 'Shirin Beauty Atelier is not installed yet. Please complete setup.',
                    'installer_url' => url('/install'),
                ], 503);
            }

            return redirect('/install');
        }

        return $next($request);
    }
}
