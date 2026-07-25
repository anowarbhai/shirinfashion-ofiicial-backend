<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\CustomerEmailTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CustomerEmailTemplateController extends Controller
{
    public function index(): JsonResponse
    {
        $templates = CustomerEmailTemplate::query()
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn (CustomerEmailTemplate $template): array => $this->serializeTemplate($template));

        return response()->json(['data' => $templates]);
    }

    public function store(Request $request): JsonResponse
    {
        $payload = $this->validatedPayload($request);

        $template = CustomerEmailTemplate::query()->create([
            'created_by' => $request->user()?->id,
            'name' => $payload['name'],
            'template_key' => $payload['template_key'] ?? 'custom',
            'subject' => $payload['subject'],
            'html_content' => $this->sanitizeEmailHtml($payload['html_content']),
            'text_content' => $payload['text_content'] ?? null,
            'is_default' => false,
        ]);

        return response()->json([
            'message' => 'Email template saved successfully.',
            'data' => $this->serializeTemplate($template),
        ], 201);
    }

    public function update(Request $request, CustomerEmailTemplate $customerEmailTemplate): JsonResponse
    {
        $payload = $this->validatedPayload($request);

        $customerEmailTemplate->update([
            'name' => $payload['name'],
            'template_key' => $payload['template_key'] ?? $customerEmailTemplate->template_key,
            'subject' => $payload['subject'],
            'html_content' => $this->sanitizeEmailHtml($payload['html_content']),
            'text_content' => $payload['text_content'] ?? null,
        ]);

        return response()->json([
            'message' => 'Email template updated successfully.',
            'data' => $this->serializeTemplate($customerEmailTemplate->fresh()),
        ]);
    }

    public function destroy(CustomerEmailTemplate $customerEmailTemplate): JsonResponse
    {
        $customerEmailTemplate->delete();

        return response()->json([
            'message' => 'Email template deleted successfully.',
        ]);
    }

    private function validatedPayload(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'template_key' => ['nullable', 'string', Rule::in(['classic', 'promo', 'minimal', 'custom'])],
            'subject' => ['required', 'string', 'max:150'],
            'html_content' => ['required', 'string', 'min:5', 'max:20000'],
            'text_content' => ['nullable', 'string', 'max:5000'],
        ]);
    }

    private function serializeTemplate(?CustomerEmailTemplate $template): array
    {
        if (! $template) {
            return [];
        }

        return [
            'id' => $template->id,
            'name' => $template->name,
            'template_key' => $template->template_key,
            'subject' => $template->subject,
            'html_content' => $template->html_content,
            'text_content' => $template->text_content,
            'is_default' => $template->is_default,
            'created_at' => $template->created_at?->toIso8601String(),
            'updated_at' => $template->updated_at?->toIso8601String(),
        ];
    }

    private function sanitizeEmailHtml(string $html): string
    {
        $html = preg_replace('/<\s*(script|style|iframe|object|embed|form|input|button)[^>]*>.*?<\s*\/\s*\1\s*>/is', '', $html) ?? '';
        $html = preg_replace('/\son[a-z]+\s*=\s*(".*?"|\'.*?\'|[^\s>]+)/i', '', $html) ?? '';
        $html = preg_replace('/(href|src)\s*=\s*([\'"])\s*javascript:.*?\2/i', '$1="#"', $html) ?? '';

        return trim(strip_tags($html, '<p><br><strong><b><em><i><u><small><h1><h2><h3><ul><ol><li><a><img><div><span><blockquote><hr>'));
    }
}
