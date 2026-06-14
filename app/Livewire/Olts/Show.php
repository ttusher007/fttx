<?php

namespace App\Livewire\Olts;

use App\Jobs\SyncOltJob;
use App\Jobs\SyncOnuJob;
use App\Models\Olt;
use App\Services\Olt\OltConnectionTestService;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

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

    public function mount(Olt $olt): void
    {
        Gate::authorize('olt.view');
        $this->olt = $olt;
    }

    public function updating($field): void
    {
        if (in_array($field, ['search', 'statusFilter', 'portFilter'])) {
            $this->resetPage();
        }
    }

    public function sync(): void
    {
        Gate::authorize('olt.sync');
        SyncOltJob::dispatch($this->olt->id, 'manual', auth()->id());
        session()->flash('status', 'Sync queued for '.$this->olt->name.'.');
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

        return view('livewire.olts.show', [
            'onus' => $onus,
            'ports' => $this->olt->ports()->orderBy('port_index')->get(),
        ]);
    }
}
