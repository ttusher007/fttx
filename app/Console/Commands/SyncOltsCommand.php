<?php

namespace App\Console\Commands;

use App\Jobs\SyncOltJob;
use App\Models\Olt;
use App\Services\Olt\OltSyncService;
use Illuminate\Console\Command;

class SyncOltsCommand extends Command
{
    protected $signature = 'olt:sync
        {olt? : OLT id to sync (omit to sync all)}
        {--due : Only dispatch OLTs whose interval has elapsed}
        {--sync : Run inline (synchronously) instead of queueing}';

    protected $description = 'Sync one or all OLTs over SNMP (queued by default).';

    public function handle(OltSyncService $service): int
    {
        $query = Olt::query()->live();

        if ($id = $this->argument('olt')) {
            $query->whereKey($id);
        }

        $olts = $query->get();

        if ($this->option('due')) {
            $olts = $olts->filter->isDueForSync();
        }

        if ($olts->isEmpty()) {
            $this->info('No OLTs due for sync.');

            return self::SUCCESS;
        }

        foreach ($olts as $olt) {
            if ($this->option('sync')) {
                $log = $service->sync($olt, 'manual');
                $this->line(sprintf('  %-24s %s — %s', $olt->name, $log->status->label(), $log->message));
            } else {
                SyncOltJob::dispatch($olt->id, 'schedule');
                $this->line("  Queued: {$olt->name}");
            }
        }

        $this->info("Dispatched {$olts->count()} OLT sync(s).");

        return self::SUCCESS;
    }
}
