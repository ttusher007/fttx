<?php

namespace App\Livewire\ApiClients;

use App\Models\ApiClient;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app', ['title' => 'API Keys'])]
class Index extends Component
{
    public bool $showModal = false;

    public string $name = '';

    public array $abilities = ['onu.lookup'];

    public int $rateLimit = 120;

    // Shown exactly once after creation.
    public ?string $newKey = null;

    public ?string $newSecret = null;

    public array $allAbilities = [
        'onu.lookup' => 'Look up ONU by serial / MAC',
        'sync.request' => 'Request OLT / ONU sync',
    ];

    public function openModal(): void
    {
        Gate::authorize('api.manage');
        $this->reset(['name', 'abilities', 'rateLimit', 'newKey', 'newSecret']);
        $this->abilities = ['onu.lookup'];
        $this->rateLimit = 120;
        $this->showModal = true;
    }

    public function create(): void
    {
        Gate::authorize('api.manage');

        $this->validate([
            'name' => 'required|string|max:100',
            'abilities' => 'required|array|min:1',
            'rateLimit' => 'required|integer|min:1|max:10000',
        ]);

        [$client, $secret] = ApiClient::issue($this->name, $this->abilities, $this->rateLimit, auth()->id());

        $this->newKey = $client->key;
        $this->newSecret = $secret;
    }

    public function toggle(int $id): void
    {
        Gate::authorize('api.manage');
        $client = ApiClient::findOrFail($id);
        $client->update(['is_active' => ! $client->is_active]);
    }

    public function delete(int $id): void
    {
        Gate::authorize('api.manage');
        ApiClient::whereKey($id)->delete();
        session()->flash('status', 'API key revoked.');
    }

    public function render()
    {
        return view('livewire.api-clients.index', [
            'clients' => ApiClient::latest()->get(),
        ]);
    }
}
