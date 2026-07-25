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

    public function send(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'channel' => ['required', 'string', Rule::in(['email', 'sms', 'both'])],
            'audience' => ['required', 'string', Rule::in(['all', 'email_customers', 'mobile_only'])],
            'only_marketing_opt_in' => ['sometimes', 'boolean'],
            'subject' => ['nullable', 'required_if:channel,email,both', 'string', 'max:150'],
            'message' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $campaign = CustomerOfferCampaign::query()->create([
            'created_by' => $request->user()?->id,
            'channel' => $payload['channel'],
            'audience' => $payload['audience'],
            'only_marketing_opt_in' => (bool) ($payload['only_marketing_opt_in'] ?? true),
            'subject' => $payload['subject'] ?? null,
            'message' => $payload['message'],
            'status' => 'queued',
            'matched_customers' => $this->recipientQuery(
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

    private function recipientQuery(string $audience, bool $onlyMarketingOptIn): Builder
    {
        return User::query()
            ->where('role', 'customer')
            ->where('status', 'active')
            ->when($onlyMarketingOptIn, fn (Builder $query) => $query->where('marketing_opt_in', true))
            ->when(
                $audience === 'email_customers',
                fn (Builder $query) => $query
                    ->whereNotNull('email')
                    ->where('email', 'not like', '%@guest.%')
                    ->where('email', '!=', ''),
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
            'message' => $campaign->message,
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
}
