<?php

namespace App\Livewire\Olts;

use App\Jobs\SyncOltJob;
use App\Models\Olt;
use App\Services\Olt\VendorDriverManager;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app', ['title' => 'OLTs'])]
class Index extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $vendor = '';

    #[Url]
    public string $status = '';

    public function updating($field): void
    {
        if (in_array($field, ['search', 'vendor', 'status'])) {
            $this->resetPage();
        }
    }

    public function sync(int $id): void
    {
        Gate::authorize('olt.sync');

        SyncOltJob::dispatch($id, 'manual', auth()->id());

        session()->flash('status', 'Sync queued. Data will refresh shortly.');
    }

    public function delete(int $id): void
    {
        Gate::authorize('olt.delete');

        Olt::whereKey($id)->delete();

        session()->flash('status', 'OLT deleted.');
    }

    public function render()
    {
        $olts = Olt::query()
            ->when($this->search, fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', "%{$this->search}%")
                ->orWhere('ip_address', 'like', "%{$this->search}%")
                ->orWhere('model', 'like', "%{$this->search}%")))
            ->when($this->vendor, fn ($q) => $q->where('vendor', $this->vendor))
            ->when($this->status, fn ($q) => $q->where('status', $this->status))
            ->orderBy('name')
            ->paginate(12);

        return view('livewire.olts.index', [
            'olts' => $olts,
            'vendors' => app(VendorDriverManager::class)->availableVendors(),
        ]);
    }
}
