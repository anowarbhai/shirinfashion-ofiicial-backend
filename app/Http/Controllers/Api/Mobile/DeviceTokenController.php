<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\MobileDeviceToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceTokenController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'token' => ['required', 'string', 'max:512'],
            'platform' => ['nullable', 'string', 'max:32'],
            'device_id' => ['nullable', 'string', 'max:128'],
            'app_version' => ['nullable', 'string', 'max:64'],
            'locale' => ['nullable', 'string', 'max:32'],
            'timezone' => ['nullable', 'string', 'max:64'],
        ]);

        $deviceToken = MobileDeviceToken::query()->updateOrCreate(
            ['token' => $payload['token']],
            [
                'user_id' => $request->user()?->id,
                'platform' => $payload['platform'] ?? null,
                'device_id' => $payload['device_id'] ?? null,
                'app_version' => $payload['app_version'] ?? null,
                'locale' => $payload['locale'] ?? null,
                'timezone' => $payload['timezone'] ?? null,
                'enabled' => true,
                'last_seen_at' => now(),
            ],
        );

        return response()->json([
            'message' => 'Device token registered.',
            'data' => [
                'id' => $deviceToken->id,
                'user_id' => $deviceToken->user_id,
                'enabled' => $deviceToken->enabled,
            ],
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'token' => ['required', 'string', 'max:512'],
        ]);

        MobileDeviceToken::query()
            ->where('token', $payload['token'])
            ->update(['enabled' => false]);

        return response()->json(['message' => 'Device token disabled.']);
    }
}
