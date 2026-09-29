<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Daily interest run (docs/07 "Scheduled processing"): generate due interest periods, refresh their
 * statuses and detect overdue loans. Idempotent, so a re-run or catch-up after downtime is safe.
 * Runs just after midnight in the application time zone (APP_TIMEZONE), the business date policy.
 */
Schedule::command('loans:process-interest')
    ->dailyAt('00:05')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/scheduler.log'))
    ->onFailure(fn () => Log::error('Scheduled interest processing failed. See storage/logs/scheduler.log.'));
