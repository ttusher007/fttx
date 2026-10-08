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

// CLI (SSH/Telnet) enrichment for OLTs that have it enabled — much slower
// than SNMP, so it has its own (longer) per-OLT interval.
Schedule::command('olt:cli-enrich --due')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

// Keep the diagnostics table small.
Schedule::call(function () {
    \App\Models\DiagnosticRun::where('created_at', '<', now()->subDays(14))->delete();
})->daily();
