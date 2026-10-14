<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('analytics:expertis-report-email --send --cut=CORTE_14')
    ->dailyAt('14:00')
    ->days([1, 2, 3, 4, 5, 6])
    ->timezone((string) config('analytics.expertis_report.timezone'))
    ->withoutOverlapping(180)
    ->name('expertis-gestion-report-corte-14');

Schedule::command('analytics:expertis-report-email --send --cut=CORTE_20')
    ->dailyAt('20:00')
    ->days([1, 2, 3, 4, 5, 6])
    ->timezone((string) config('analytics.expertis_report.timezone'))
    ->withoutOverlapping(180)
    ->name('expertis-gestion-report-corte-20');

Schedule::command('analytics:expertis-client-report-email --send')
    ->dailyAt('07:00')
    ->timezone((string) config('analytics.expertis_report.timezone'))
    ->withoutOverlapping(180)
    ->name('expertis-client-report-0700');
