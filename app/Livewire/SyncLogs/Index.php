<?php

namespace App\Livewire\SyncLogs;

use App\Models\SyncLog;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app', ['title' => 'Sync Logs'])]
class Index extends Component
{
    use WithPagination;

    #[Url]
    public string $status = '';

    #[Url]
    public string $trigger = '';

    public function mount(): void
    {
        Gate::authorize('log.view');
    }

    public function updating($field): void
    {
        if (in_array($field, ['status', 'trigger'])) {
            $this->resetPage();
        }
    }

    public function render()
    {
        $logs = SyncLog::query()
            ->with(['olt', 'triggeredBy'])
            ->when($this->status, fn ($q) => $q->where('status', $this->status))
            ->when($this->trigger, fn ($q) => $q->where('trigger', $this->trigger))
            ->latest('id')
            ->paginate(25);

        return view('livewire.sync-logs.index', ['logs' => $logs]);
    }
}
