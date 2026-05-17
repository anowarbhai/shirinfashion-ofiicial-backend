<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('backup:database-monthly')->hourly();
Schedule::command('mobile:send-cart-reminders --delay-minutes=120 --repeat-hours=24 --max=2')->hourly();
