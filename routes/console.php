<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// the mailbox: both do nothing until the admin turns them on in Settings → Email
Schedule::command('hr:mail-send')->everyMinute()->withoutOverlapping(10);
Schedule::command('hr:mail-fetch')->everyTwoMinutes()->withoutOverlapping(10);
