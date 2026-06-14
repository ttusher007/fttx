<?php

namespace App\Livewire\Onus;

use App\Jobs\SyncOnuJob;
use App\Models\Onu;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app', ['title' => 'ONU Browser'])]
class Index extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $status = '';

    public function updating($field): void
    {
        if (in_array($field, ['search', 'status'])) {
            $this->resetPage();
        }
    }

    public function syncOnu(int $id): void
    {
        Gate::authorize('onu.sync');
        SyncOnuJob::dispatch($id, 'manual', auth()->id());
        session()->flash('status', 'ONU refresh queued.');
    }

    public function render()
    {
        $onus = Onu::query()
            ->with(['olt', 'port'])
            ->search($this->search)
            ->when($this->status, fn ($q) => $q->where('status', $this->status))
            ->latest('last_synced_at')
            ->paginate(25);

        return view('livewire.onus.index', ['onus' => $onus]);
    }
}
