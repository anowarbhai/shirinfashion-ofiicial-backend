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
                                $this->renderMessage($campaign->sms_message ?: $campaign->message, $customer),
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
        $html = $this->renderEmailHtml($campaign, $customer);

        Mail::html($html, function ($message) use ($campaign, $customer): void {
            $message
                ->to($customer->email)
                ->subject($this->renderMessage((string) $campaign->subject, $customer));
        });
    }

    private function renderEmailHtml(CustomerOfferCampaign $campaign, User $customer): string
    {
        $subject = e($this->renderMessage((string) $campaign->subject, $customer));
        $body = nl2br(e($this->renderMessage($campaign->email_message ?: $campaign->message, $customer)));
        $template = $campaign->email_template ?: 'classic';

        if ($template === 'promo') {
            return '<div style="margin:0;padding:32px;background:#fff1f2;font-family:Arial,sans-serif;color:#111827">'
                .'<div style="max-width:620px;margin:0 auto;background:#ffffff;border-radius:18px;overflow:hidden;border:1px solid #ffe4e6">'
                .'<div style="padding:28px;background:#e11d48;color:#ffffff">'
                .'<div style="font-size:13px;letter-spacing:2px;text-transform:uppercase;font-weight:700">Shirin Fashion</div>'
                .'<h1 style="margin:10px 0 0;font-size:26px;line-height:1.25">'.$subject.'</h1>'
                .'</div>'
                .'<div style="padding:28px;font-size:15px;line-height:1.8">'.$body
                .'<div style="margin-top:26px"><a href="https://shirinfashion.com.bd" style="display:inline-block;background:#111827;color:#ffffff;text-decoration:none;padding:12px 18px;border-radius:10px;font-weight:700">Shop Now</a></div>'
                .'</div>'
                .'</div></div>';
        }

        if ($template === 'minimal') {
            return '<div style="margin:0;padding:28px;background:#f8fafc;font-family:Arial,sans-serif;color:#111827">'
                .'<div style="max-width:620px;margin:0 auto;background:#ffffff;border:1px solid #e2e8f0;border-radius:14px;padding:28px">'
                .'<h1 style="margin:0 0 18px;font-size:22px;line-height:1.3">'.$subject.'</h1>'
                .'<div style="font-size:15px;line-height:1.8">'.$body.'</div>'
                .'<p style="margin-top:28px;color:#64748b;font-size:13px">Shirin Fashion</p>'
                .'</div></div>';
        }

        return '<div style="margin:0;padding:32px;background:#eef2ff;font-family:Arial,sans-serif;color:#111827">'
            .'<div style="max-width:620px;margin:0 auto;background:#ffffff;border-radius:18px;overflow:hidden;border:1px solid #dbe4f0">'
            .'<div style="padding:20px 28px;border-bottom:1px solid #e2e8f0;color:#e11d48;font-size:18px;font-weight:800;letter-spacing:1px">SHIRIN FASHION</div>'
            .'<div style="padding:28px">'
            .'<h1 style="margin:0 0 18px;font-size:24px;line-height:1.3">'.$subject.'</h1>'
            .'<div style="font-size:15px;line-height:1.8">'.$body.'</div>'
            .'<div style="margin-top:26px"><a href="https://shirinfashion.com.bd" style="display:inline-block;background:#e11d48;color:#ffffff;text-decoration:none;padding:12px 18px;border-radius:10px;font-weight:700">Visit Store</a></div>'
            .'</div></div></div>';
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
