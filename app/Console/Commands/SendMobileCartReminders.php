<?php

namespace App\Console\Commands;

use App\Services\MobileCartReminderService;
use Illuminate\Console\Command;

class SendMobileCartReminders extends Command
{
    protected $signature = 'mobile:send-cart-reminders
        {--delay-minutes= : Minutes to wait after the latest cart sync}
        {--repeat-hours= : Hours before sending another reminder}
        {--max= : Maximum reminders per cart snapshot}';

    protected $description = 'Send mobile app abandoned cart reminder push notifications.';

    public function handle(MobileCartReminderService $cartReminder): int
    {
        $result = $cartReminder->sendDueReminders(
            $this->option('delay-minutes') !== null ? (int) $this->option('delay-minutes') : null,
            $this->option('repeat-hours') !== null ? (int) $this->option('repeat-hours') : null,
            $this->option('max') !== null ? (int) $this->option('max') : null,
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
