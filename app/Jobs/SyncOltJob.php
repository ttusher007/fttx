<?php

namespace App\Jobs;

use App\Models\Olt;
use App\Services\Olt\OltSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Syncs a single OLT. Made non-overlapping per-OLT so a long-running sync never
 * stacks on top of itself when the scheduler fires again — essential when
 * continuously polling 200+ devices.
 */
class SyncOltJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(
        public int $oltId,
        public string $trigger = 'schedule',
        public ?int $userId = null,
    ) {
        $this->onQueue(config('olt.sync.queue', 'olt-sync'));
    }

    public function handle(OltSyncService $service): void
    {
        $olt = Olt::find($this->oltId);

        if ($olt) {
            $service->sync($olt, $this->trigger, $this->userId);
        }
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("olt-sync-{$this->oltId}"))
                ->releaseAfter(30)
                ->expireAfter((int) config('olt.sync.lock_seconds', 600)),
        ];
    }

    public function uniqueId(): string
    {
        return (string) $this->oltId;
    }
}
