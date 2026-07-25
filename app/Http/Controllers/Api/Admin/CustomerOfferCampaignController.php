<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SendCustomerOfferCampaign;
use App\Models\CustomerOfferCampaign;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CustomerOfferCampaignController extends Controller
{
    public function index(): JsonResponse
    {
        $campaigns = CustomerOfferCampaign::query()
            ->latest()
            ->limit(10)
            ->get()
            ->map(fn (CustomerOfferCampaign $campaign): array => $this->serializeCampaign($campaign));

        return response()->json(['data' => $campaigns]);
    }

    public function show(CustomerOfferCampaign $customerOfferCampaign): JsonResponse
    {
        return response()->json([
            'data' => $this->serializeCampaign($customerOfferCampaign->fresh()),
        ]);
    }

    public function preview(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'channel' => ['required', 'string', Rule::in(['email', 'sms', 'both'])],
            'audience' => ['required', 'string', Rule::in(['all', 'email_customers', 'mobile_only'])],
            'only_marketing_opt_in' => ['sometimes', 'boolean'],
        ]);

        $query = $this->recipientQuery(
            (string) $payload['channel'],
            (string) $payload['audience'],
            (bool) ($payload['only_marketing_opt_in'] ?? true),
        );

        return response()->json([
            'data' => [
                'matched' => (clone $query)->count(),
                'email' => (clone $query)
                    ->where(fn (Builder $emailQuery) => $this->whereHasUsableEmail($emailQuery))
                    ->count(),
                'sms' => (clone $query)
                    ->where(fn (Builder $phoneQuery) => $this->whereHasUsablePhone($phoneQuery))
                    ->count(),
            ],
        ]);
    }

    public function send(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'channel' => ['required', 'string', Rule::in(['email', 'sms', 'both'])],
            'audience' => ['required', 'string', Rule::in(['all', 'email_customers', 'mobile_only'])],
            'only_marketing_opt_in' => ['sometimes', 'boolean'],
            'subject' => ['nullable', 'required_if:channel,email,both', 'string', 'max:150'],
            'email_template' => ['nullable', 'string', Rule::in(['classic', 'promo', 'minimal'])],
            'message' => ['nullable', 'string', 'min:5', 'max:1000'],
            'email_message' => ['nullable', 'required_if:channel,email,both', 'string', 'min:5', 'max:2000'],
            'email_html' => ['nullable', 'string', 'max:20000'],
            'sms_message' => ['nullable', 'required_if:channel,sms,both', 'string', 'min:5', 'max:500'],
        ]);

        $fallbackMessage = (string) (
            $payload['message']
            ?? $payload['email_message']
            ?? $payload['sms_message']
            ?? ''
        );

        $campaign = CustomerOfferCampaign::query()->create([
            'created_by' => $request->user()?->id,
            'channel' => $payload['channel'],
            'audience' => $payload['audience'],
            'only_marketing_opt_in' => (bool) ($payload['only_marketing_opt_in'] ?? true),
            'subject' => $payload['subject'] ?? null,
            'email_template' => $payload['email_template'] ?? 'classic',
            'message' => $fallbackMessage,
            'email_message' => $payload['email_message'] ?? null,
            'email_html' => isset($payload['email_html']) ? $this->sanitizeEmailHtml((string) $payload['email_html']) : null,
            'sms_message' => $payload['sms_message'] ?? null,
            'status' => 'queued',
            'matched_customers' => $this->recipientQuery(
                (string) $payload['channel'],
                (string) $payload['audience'],
                (bool) ($payload['only_marketing_opt_in'] ?? true),
            )->count(),
        ]);

        SendCustomerOfferCampaign::dispatch($campaign->id);

        return response()->json([
            'message' => 'Customer offer campaign queued.',
            'data' => $this->serializeCampaign($campaign),
        ], 202);
    }

    private function recipientQuery(string $channel, string $audience, bool $onlyMarketingOptIn): Builder
    {
        return User::query()
            ->where('role', 'customer')
            ->where('status', 'active')
            ->when($onlyMarketingOptIn, fn (Builder $query) => $query->where('marketing_opt_in', true))
            ->when(
                $channel === 'email',
                fn (Builder $query) => $this->whereHasUsableEmail($query),
            )
            ->when(
                $channel === 'sms',
                fn (Builder $query) => $this->whereHasUsablePhone($query),
            )
            ->when(
                $channel === 'both',
                fn (Builder $query) => $query->where(function (Builder $deliveryQuery): void {
                    $deliveryQuery
                        ->where(fn (Builder $emailQuery) => $this->whereHasUsableEmail($emailQuery))
                        ->orWhere(fn (Builder $phoneQuery) => $this->whereHasUsablePhone($phoneQuery));
                }),
            )
            ->when(
                $audience === 'email_customers',
                fn (Builder $query) => $this->whereHasUsableEmail($query),
            )
            ->when(
                $audience === 'mobile_only',
                fn (Builder $query) => $query
                    ->whereNotNull('phone')
                    ->where('phone', '!=', '')
                    ->where(function (Builder $emailQuery): void {
                        $emailQuery
                            ->whereNull('email')
                            ->orWhere('email', '')
                            ->orWhere('email', 'like', '%@guest.%');
                    }),
            );
    }

    private function whereHasUsableEmail(Builder $query): Builder
    {
        return $query
            ->whereNotNull('email')
            ->where('email', 'not like', '%@guest.%')
            ->where('email', '!=', '');
    }

    private function whereHasUsablePhone(Builder $query): Builder
    {
        return $query
            ->whereNotNull('phone')
            ->where('phone', '!=', '');
    }

    private function serializeCampaign(?CustomerOfferCampaign $campaign): array
    {
        if (! $campaign) {
            return [];
        }

        $matched = (int) $campaign->matched_customers;
        $processed = (int) $campaign->processed_customers;

        return [
            'id' => $campaign->id,
            'channel' => $campaign->channel,
            'audience' => $campaign->audience,
            'only_marketing_opt_in' => $campaign->only_marketing_opt_in,
            'subject' => $campaign->subject,
            'email_template' => $campaign->email_template,
            'message' => $campaign->message,
            'email_message' => $campaign->email_message,
            'email_html' => $campaign->email_html,
            'sms_message' => $campaign->sms_message,
            'status' => $campaign->status,
            'matched_customers' => $matched,
            'processed_customers' => $processed,
            'progress' => $matched > 0 ? round(($processed / $matched) * 100, 1) : 0,
            'email_sent' => (int) $campaign->email_sent,
            'email_failed' => (int) $campaign->email_failed,
            'sms_sent' => (int) $campaign->sms_sent,
            'sms_failed' => (int) $campaign->sms_failed,
            'skipped' => (int) $campaign->skipped,
            'last_error' => $campaign->last_error,
            'started_at' => $campaign->started_at?->toIso8601String(),
            'finished_at' => $campaign->finished_at?->toIso8601String(),
            'created_at' => $campaign->created_at?->toIso8601String(),
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
