<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContactMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContactMessageController extends Controller
{
    public function index(): JsonResponse
    {
        $messages = ContactMessage::query()
            ->latest()
            ->get()
            ->map(function (ContactMessage $message): array {
                return [
                    'id' => $message->id,
                    'name' => $message->name,
                    'email' => $message->email,
                    'phone' => $message->phone,
                    'subject' => $message->subject,
                    'message' => $message->message,
                    'status' => $message->status,
                    'ip_address' => $message->ip_address,
                    'user_agent' => $message->user_agent,
                    'created_at' => optional($message->created_at)?->toIso8601String(),
                    'updated_at' => optional($message->updated_at)?->toIso8601String(),
                ];
            });

        return response()->json([
            'data' => [
                'data' => $messages,
            ],
        ]);
    }

    public function update(Request $request, ContactMessage $contactMessage): JsonResponse
    {
        $payload = $request->validate([
            'status' => ['required', 'string', 'in:new,read,replied,closed'],
        ]);

        $contactMessage->update([
            'status' => $payload['status'],
        ]);

        return response()->json([
            'message' => 'Message updated successfully.',
            'data' => [
                'id' => $contactMessage->id,
                'status' => $contactMessage->status,
            ],
        ]);
    }

    public function destroy(ContactMessage $contactMessage): JsonResponse
    {
        $contactMessage->delete();

        return response()->json([
            'message' => 'Message deleted successfully.',
        ]);
    }
}
