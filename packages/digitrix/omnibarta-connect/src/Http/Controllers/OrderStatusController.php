<?php

namespace Digitrix\OmniBarta\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderStatusController
{
    public function __invoke(Request $request, string $order): JsonResponse
    {
        $secret = (string) config('omnibarta.webhook_secret');
        $timestamp = (string) $request->header('X-Digitrix-Timestamp');
        $signature = (string) $request->header('X-Digitrix-Signature');
        abort_unless($secret !== '' && ctype_digit($timestamp) && abs(time() - (int) $timestamp) <= 300, 401, 'Invalid or expired request.');
        $expected = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);
        abort_unless(hash_equals($expected, $signature), 401, 'Invalid signature.');

        $validated = $request->validate(['status' => ['required', 'in:completed,cancelled,on-hold']]);
        $model = (string) config('omnibarta.order_model');
        abort_unless(class_exists($model), 500, 'Configured order model was not found.');
        $record = $model::query()->findOrFail($order);
        $column = (string) config('omnibarta.order_status_column', 'status');
        $record->forceFill([$column => config('omnibarta.status_map.'.$validated['status'])])->save();

        return response()->json(['order_id' => (string) $record->getKey(), 'status' => $validated['status']]);
    }
}
