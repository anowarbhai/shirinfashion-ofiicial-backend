<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\MailSetupService;
use App\Services\SmsGatewayService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\Rule;
use RuntimeException;
use Throwable;

class CustomerOfferCampaignController extends Controller
{
    public function __construct(
        private readonly MailSetupService $mailSetup,
        private readonly SmsGatewayService $smsGateway,
    ) {
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

        $channel = (string) $payload['channel'];
        $sendEmail = in_array($channel, ['email', 'both'], true);
        $sendSms = in_array($channel, ['sms', 'both'], true);

        try {
            if ($sendEmail) {
                $this->mailSetup->configureMailer();
            }
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $result = [
            'matched_customers' => 0,
            'email_sent' => 0,
            'email_failed' => 0,
            'sms_sent' => 0,
            'sms_failed' => 0,
            'skipped' => 0,
        ];

        $this->recipientQuery(
            (string) $payload['audience'],
            (bool) ($payload['only_marketing_opt_in'] ?? true),
        )
            ->orderBy('id')
            ->chunkById(100, function ($customers) use ($payload, $sendEmail, $sendSms, &$result): void {
                foreach ($customers as $customer) {
                    $result['matched_customers']++;
                    $sentSomething = false;

                    if ($sendEmail && $this->hasUsableEmail($customer)) {
                        try {
                            $this->sendEmail($customer, (string) $payload['subject'], (string) $payload['message']);
                            $result['email_sent']++;
                            $sentSomething = true;
                        } catch (Throwable $exception) {
                            $result['email_failed']++;
                            Log::warning('Customer offer email failed.', [
                                'customer_id' => $customer->id,
                                'email' => $customer->email,
                                'error' => $exception->getMessage(),
                            ]);
                        }
                    }

                    if ($sendSms && $this->hasUsablePhone($customer)) {
                        try {
                            $this->smsGateway->sendMessage(
                                (string) $customer->phone,
                                $this->renderMessage((string) $payload['message'], $customer),
                            );
                            $result['sms_sent']++;
                            $sentSomething = true;
                        } catch (Throwable $exception) {
                            $result['sms_failed']++;
                            Log::warning('Customer offer SMS failed.', [
                                'customer_id' => $customer->id,
                                'phone' => $customer->phone,
                                'error' => $exception->getMessage(),
                            ]);
                        }
                    }

                    if (! $sentSomething) {
                        $result['skipped']++;
                    }
                }
            });

        return response()->json([
            'message' => 'Customer offer campaign processed.',
            'data' => $result,
        ]);
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

    private function sendEmail(User $customer, string $subject, string $message): void
    {
        $renderedMessage = $this->renderMessage($message, $customer);
        $html = new HtmlString(
            '<div style="font-family:Arial,sans-serif;font-size:15px;line-height:1.7;color:#111827">'
            .nl2br(e($renderedMessage))
            .'<p style="margin-top:24px;color:#64748b">Shirin Fashion</p>'
            .'</div>'
        );

        Mail::html((string) $html, function ($mail) use ($customer, $subject): void {
            $mail
                ->to((string) $customer->email, (string) $customer->name)
                ->subject($this->renderMessage($subject, $customer));
        });
    }

    private function renderMessage(string $message, User $customer): string
    {
        return strtr($message, [
            '{{name}}' => (string) $customer->name,
            '{{customer_name}}' => (string) $customer->name,
            '{{phone}}' => (string) $customer->phone,
            '{{email}}' => (string) $customer->email,
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
}
