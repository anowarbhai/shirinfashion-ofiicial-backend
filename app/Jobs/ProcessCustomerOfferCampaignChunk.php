<?php

namespace App\Jobs;

use App\Models\CustomerOfferCampaign;
use App\Models\User;
use App\Services\MailSetupService;
use App\Services\SmsGatewayService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class ProcessCustomerOfferCampaignChunk implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 90;
    public int $tries = 1;

    /**
     * @param  array<int>  $customerIds
     */
    public function __construct(
        public readonly int $campaignId,
        public readonly array $customerIds,
    ) {
        $this->onQueue('campaigns');
    }

    public function handle(MailSetupService $mailSetup, SmsGatewayService $smsGateway): void
    {
        $campaign = CustomerOfferCampaign::query()->find($this->campaignId);

        if (! $campaign || $campaign->status !== 'processing') {
            return;
        }

        $sendEmail = in_array($campaign->channel, ['email', 'both'], true);
        $sendSms = in_array($campaign->channel, ['sms', 'both'], true);

        if ($sendEmail) {
            $mailSetup->configureMailer();
        }

        User::query()
            ->whereKey($this->customerIds)
            ->orderBy('id')
            ->get()
            ->each(function (User $customer) use ($campaign, $sendEmail, $sendSms, $smsGateway): void {
                $counts = [
                    'processed_customers' => 1,
                    'email_sent' => 0,
                    'email_failed' => 0,
                    'sms_sent' => 0,
                    'sms_failed' => 0,
                    'skipped' => 0,
                ];

                $sentSomething = false;

                if ($sendEmail) {
                    if ($this->hasUsableEmail($customer)) {
                        try {
                            $this->sendEmail($campaign, $customer);
                            $counts['email_sent']++;
                            $sentSomething = true;
                        } catch (Throwable $exception) {
                            $counts['email_failed']++;
                            $this->logFailure($campaign, $customer, 'email', $exception);
                        }
                    } else {
                        $counts['email_failed']++;
                    }
                }

                if ($sendSms) {
                    if ($this->hasUsablePhone($customer)) {
                        try {
                            $smsGateway->sendMessage(
                                $customer->phone,
                                $this->renderMessage($campaign->message, $customer),
                            );
                            $counts['sms_sent']++;
                            $sentSomething = true;
                        } catch (Throwable $exception) {
                            $counts['sms_failed']++;
                            $this->logFailure($campaign, $customer, 'sms', $exception);
                        }
                    } else {
                        $counts['sms_failed']++;
                    }
                }

                if (! $sentSomething) {
                    $counts['skipped']++;
                }

                $this->incrementCampaign($campaign->id, $counts);
            });

        $this->completeIfFinished($campaign->id);
    }

    public function failed(Throwable $exception): void
    {
        CustomerOfferCampaign::query()
            ->whereKey($this->campaignId)
            ->whereIn('status', ['queued', 'processing'])
            ->update([
                'status' => 'failed',
                'last_error' => mb_strimwidth($exception->getMessage(), 0, 500, '...'),
                'finished_at' => now(),
            ]);
    }

    private function sendEmail(CustomerOfferCampaign $campaign, User $customer): void
    {
        $html = '<div style="font-family:Arial,sans-serif;font-size:15px;line-height:1.7;color:#111827">'
            .nl2br(e($this->renderMessage($campaign->message, $customer)))
            .'<p style="margin-top:24px;color:#64748b">Shirin Fashion</p>'
            .'</div>';

        Mail::html($html, function ($message) use ($campaign, $customer): void {
            $message
                ->to($customer->email)
                ->subject($this->renderMessage((string) $campaign->subject, $customer));
        });
    }

    private function renderMessage(string $message, User $customer): string
    {
        $name = trim((string) ($customer->name ?: $customer->phone ?: 'Customer'));

        return strtr($message, [
            '{{name}}' => $name,
            '{{customer_name}}' => $name,
            '{{email}}' => (string) ($customer->email ?? ''),
            '{{phone}}' => (string) ($customer->phone ?? ''),
            '{{store_name}}' => 'Shirin Fashion',
        ]);
    }

    private function hasUsableEmail(User $customer): bool
    {
        $email = trim((string) $customer->email);

        return $email !== '' && ! str_contains($email, '@guest.');
    }

    private function hasUsablePhone(User $customer): bool
    {
        return trim((string) $customer->phone) !== '';
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function incrementCampaign(int $campaignId, array $counts): void
    {
        $updates = [];

        foreach ($counts as $column => $amount) {
            if ($amount > 0) {
                $updates[$column] = DB::raw($column.' + '.(int) $amount);
            }
        }

        if ($updates !== []) {
            CustomerOfferCampaign::query()->whereKey($campaignId)->update($updates);
        }
    }

    private function completeIfFinished(int $campaignId): void
    {
        $campaign = CustomerOfferCampaign::query()->find($campaignId);

        if (! $campaign || $campaign->status !== 'processing') {
            return;
        }

        if ((int) $campaign->processed_customers >= (int) $campaign->matched_customers) {
            $campaign->forceFill([
                'status' => 'completed',
                'finished_at' => now(),
            ])->save();
        }
    }

    private function logFailure(CustomerOfferCampaign $campaign, User $customer, string $channel, Throwable $exception): void
    {
        Log::warning('Customer offer campaign delivery failed.', [
            'campaign_id' => $campaign->id,
            'customer_id' => $customer->id,
            'channel' => $channel,
            'message' => $exception->getMessage(),
        ]);
    }
}
