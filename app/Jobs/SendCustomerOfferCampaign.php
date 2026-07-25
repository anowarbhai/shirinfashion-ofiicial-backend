<?php

namespace App\Jobs;

use App\Models\CustomerOfferCampaign;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SendCustomerOfferCampaign implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 90;
    public int $tries = 1;

    public function __construct(public readonly int $campaignId)
    {
        $this->onQueue('campaigns');
    }

    public function handle(): void
    {
        $campaign = CustomerOfferCampaign::query()->find($this->campaignId);

        if (! $campaign || ! in_array($campaign->status, ['queued', 'processing'], true)) {
            return;
        }

        $query = $this->recipientQuery($campaign);
        $matched = (clone $query)->count();

        $campaign->forceFill([
            'status' => 'processing',
            'matched_customers' => $matched,
            'started_at' => $campaign->started_at ?? now(),
            'last_error' => null,
        ])->save();

        if ($matched === 0) {
            $campaign->forceFill([
                'status' => 'completed',
                'finished_at' => now(),
            ])->save();

            return;
        }

        $query->orderBy('id')->chunkById(50, function ($customers) use ($campaign): void {
            ProcessCustomerOfferCampaignChunk::dispatch(
                $campaign->id,
                $customers->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            );
        });
    }

    public function failed(Throwable $exception): void
    {
        CustomerOfferCampaign::query()
            ->whereKey($this->campaignId)
            ->update([
                'status' => 'failed',
                'last_error' => mb_strimwidth($exception->getMessage(), 0, 500, '...'),
                'finished_at' => now(),
            ]);
    }

    private function recipientQuery(CustomerOfferCampaign $campaign): Builder
    {
        return User::query()
            ->where('role', 'customer')
            ->where('status', 'active')
            ->when($campaign->only_marketing_opt_in, fn (Builder $query) => $query->where('marketing_opt_in', true))
            ->when(
                $campaign->audience === 'email_customers',
                fn (Builder $query) => $query
                    ->whereNotNull('email')
                    ->where('email', 'not like', '%@guest.%')
                    ->where('email', '!=', ''),
            )
            ->when(
                $campaign->audience === 'mobile_only',
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

}
