<?php

namespace App\Jobs;

use App\Models\Olt;
use App\Services\Olt\Cli\OltCliEnrichService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * CLI (SSH/Telnet) enrichment for one OLT: optical power and customer MACs
 * that SNMP cannot provide on some firmwares. Slow (minutes), so it runs on
 * its own interval and never overlaps itself.
 */
class EnrichOltCliJob implements ShouldQueue
{
    use Queueable;

    public int $timeout;

    public int $tries = 1;

    public function __construct(
        public int $oltId,
        public string $trigger = 'schedule',
        public ?int $userId = null,
    ) {
        $this->timeout = (int) config('olt.cli.job_timeout', 1500);
        $this->onQueue(config('olt.cli.queue', 'olt-sync'));
    }

    public function handle(OltCliEnrichService $service): void
    {
        $olt = Olt::find($this->oltId);

        if ($olt && ! $olt->shouldSimulate()) {
            $service->enrich($olt, $this->trigger, $this->userId);
        }
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("olt-cli-{$this->oltId}"))
                ->releaseAfter(60)
                ->expireAfter((int) config('olt.cli.lock_seconds', 1800)),
        ];
    }
}
