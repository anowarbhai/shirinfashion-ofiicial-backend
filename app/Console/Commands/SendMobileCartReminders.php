<?php

namespace App\Console\Commands;

use App\Services\MobileCartReminderService;
use Illuminate\Console\Command;

class SendMobileCartReminders extends Command
{
    protected $signature = 'mobile:send-cart-reminders
        {--delay-minutes=120 : Minutes to wait after the latest cart sync}
        {--repeat-hours=24 : Hours before sending another reminder}
        {--max=2 : Maximum reminders per cart snapshot}';

    protected $description = 'Send mobile app abandoned cart reminder push notifications.';

    public function handle(MobileCartReminderService $cartReminder): int
    {
        $result = $cartReminder->sendDueReminders(
            (int) $this->option('delay-minutes'),
            (int) $this->option('repeat-hours'),
            (int) $this->option('max'),
        );

        $this->info(sprintf(
            'Processed %d cart(s). Sent %d, failed %d, skipped %d.',
            $result['processed'],
            $result['sent'],
            $result['failed'],
            $result['skipped'],
        ));

        return self::SUCCESS;
    }
}
