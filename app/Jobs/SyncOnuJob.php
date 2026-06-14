<?php

namespace App\Jobs;

use App\Models\Onu;
use App\Services\Olt\OltSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Targeted refresh of a single ONU (manual or API triggered).
 */
class SyncOnuJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $tries = 1;

    public function __construct(
        public int $onuId,
        public string $trigger = 'manual',
        public ?int $userId = null,
    ) {
        $this->onQueue(config('olt.sync.queue', 'olt-sync'));
    }

    public function handle(OltSyncService $service): void
    {
        $onu = Onu::with('olt')->find($this->onuId);

        if ($onu && $onu->olt) {
            $service->syncOnu($onu, $this->trigger, $this->userId);
        }
    }
}
