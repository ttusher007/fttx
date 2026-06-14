<?php

namespace App\Livewire\Olts;

use App\Enums\SyncStatus;
use App\Jobs\SyncOnuJob;
use App\Models\Olt;
use App\Models\SyncLog;
use App\Services\Olt\OltConnectionTestService;
use App\Services\Olt\OltSyncService;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

#[Layout('components.layouts.app', ['title' => 'OLT Details'])]
class Show extends Component
{
    use WithPagination;

    public Olt $olt;

    public string $search = '';

    public string $statusFilter = '';

    public ?int $portFilter = null;

    public ?bool $connectionTestSuccess = null;

    public ?string $connectionTestMessage = null;

    /** @var array{success: bool, message: string, stats?: array<string, int>}|null */
    public ?array $syncResult = null;

    public ?string $syncProgressMessage = null;

    public function mount(Olt $olt): void
    {
        Gate::authorize('olt.view');
        $this->olt = $olt;
        $this->loadActiveSyncProgress();
    }

    public function updating($field): void
    {
        if (in_array($field, ['search', 'statusFilter', 'portFilter'])) {
            $this->resetPage();
        }
    }

    public function sync(OltSyncService $service): void
    {
        Gate::authorize('olt.sync');

        set_time_limit(300);

        $this->syncResult = null;
        $this->syncProgressMessage = 'Starting sync…';

        try {
            $log = $service->sync($this->olt->fresh(), 'manual', auth()->id());
            $this->olt->refresh();
            $this->applySyncResult($log);
        } catch (Throwable $e) {
            $this->syncResult = [
                'success' => false,
                'message' => 'Sync failed: '.$e->getMessage(),
            ];
        } finally {
            $this->syncProgressMessage = null;
        }
    }

    public function pollSyncProgress(): void
    {
        $this->olt->refresh();
        $this->loadActiveSyncProgress();
    }

    public function testConnection(OltConnectionTestService $tester): void
    {
        Gate::authorize('olt.view');

        $result = $tester->test($this->olt);
        $this->connectionTestSuccess = $result['success'];
        $this->connectionTestMessage = $result['message'];
    }

    public function syncOnu(int $onuId): void
    {
        Gate::authorize('onu.sync');
        SyncOnuJob::dispatch($onuId, 'manual', auth()->id());
        session()->flash('status', 'ONU refresh queued.');
    }

    public function render()
    {
        $onus = $this->olt->onus()
            ->with('port')
            ->search($this->search)
            ->when($this->statusFilter, fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->portFilter, fn ($q) => $q->where('olt_port_id', $this->portFilter))
            ->orderBy('olt_port_id')
            ->orderBy('onu_index')
            ->paginate(20);

        $activeSyncLog = $this->olt->syncLogs()
            ->where('status', SyncStatus::Running)
            ->latest('id')
            ->first();

        return view('livewire.olts.show', [
            'onus' => $onus,
            'ports' => $this->olt->ports()->orderBy('port_index')->get(),
            'activeSyncLog' => $activeSyncLog,
        ]);
    }

    private function loadActiveSyncProgress(): void
    {
        $log = $this->olt->syncLogs()
            ->where('status', SyncStatus::Running)
            ->latest('id')
            ->first();

        $this->syncProgressMessage = $log?->message;
    }

    private function applySyncResult(SyncLog $log): void
    {
        $this->syncResult = [
            'success' => in_array($log->status, [SyncStatus::Success, SyncStatus::Partial], true),
            'message' => $log->message,
            'stats' => $log->stats ?? [],
            'status' => $log->status->value,
            'duration_ms' => $log->duration_ms,
        ];
    }
}
