<?php

namespace App\Console\Commands;

use App\Jobs\EnrichOltCliJob;
use App\Models\Olt;
use App\Services\Olt\Cli\OltCliEnrichService;
use Illuminate\Console\Command;

class CliEnrichCommand extends Command
{
    protected $signature = 'olt:cli-enrich
        {olt? : OLT id to enrich (omit for all CLI-enabled OLTs)}
        {--due : Only dispatch OLTs whose CLI interval has elapsed}
        {--sync : Run inline (synchronously) instead of queueing}';

    protected $description = 'Pull optical power / customer MACs over SSH or Telnet (via the collector) for CLI-enabled OLTs.';

    public function handle(OltCliEnrichService $service): int
    {
        $query = Olt::query()->live()->where('cli_enabled', true);

        if ($id = $this->argument('olt')) {
            $query = Olt::query()->whereKey($id);
        }

        $olts = $query->get()->filter(fn (Olt $o) => $o->cliConfigured() || $this->argument('olt'));

        if ($this->option('due')) {
            $olts = $olts->filter->isDueForCliSync();
        }

        if ($olts->isEmpty()) {
            $this->info('No OLTs due for CLI enrichment.');

            return self::SUCCESS;
        }

        foreach ($olts as $olt) {
            if ($this->option('sync')) {
                $log = $service->enrich($olt, 'manual');
                $this->line(sprintf('  %-24s %s — %s', $olt->name, $log->status->label(), $log->message));
                foreach (($log->stats['notes'] ?? []) as $note) {
                    $this->line("      · {$note}");
                }
            } else {
                EnrichOltCliJob::dispatch($olt->id, 'schedule');
                $this->line("  Queued: {$olt->name}");
            }
        }

        $this->info("Dispatched {$olts->count()} CLI enrichment(s).");

        return self::SUCCESS;
    }
}
