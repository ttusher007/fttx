<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Continuously sync OLTs that are due. Runs every minute; the `--due` filter
// respects each OLT's per-device interval, and SyncOltJob's WithoutOverlapping
// middleware prevents any single OLT from stacking up.
Schedule::command('olt:sync --due')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();
