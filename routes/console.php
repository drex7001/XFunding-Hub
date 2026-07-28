<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command('funding:expire')->everyMinute()->withoutOverlapping();
Schedule::command('offers:expire')->everyMinute()->withoutOverlapping();
Schedule::command('deals:expire-fees')->everyMinute()->withoutOverlapping();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
