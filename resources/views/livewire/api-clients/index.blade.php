<div class="space-y-5">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h2 class="text-lg font-semibold text-slate-800">API Keys</h2>
            <p class="text-sm text-slate-500">Credentials for external systems to query ONU data.</p>
        </div>
        @can('api.manage')
            <button wire:click="openModal" class="btn-primary">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                New key
            </button>
        @endcan
    </div>

    {{-- Endpoint reference --}}
    <div class="card p-5">
        <h3 class="mb-2 text-sm font-semibold text-slate-700">Endpoints</h3>
        <div class="space-y-2 text-xs text-slate-500">
            <p><code class="rounded bg-slate-100 px-1.5 py-0.5 font-semibold text-slate-700">POST /api/v1/onu/lookup</code> — body <code>{ "serial_number": "..." }</code> or <code>{ "mac_address": "..." }</code></p>
            <p><code class="rounded bg-slate-100 px-1.5 py-0.5 font-semibold text-slate-700">POST /api/v1/sync/olt</code> — body <code>{ "olt_id": 1 }</code></p>
            <p><code class="rounded bg-slate-100 px-1.5 py-0.5 font-semibold text-slate-700">POST /api/v1/sync/onu</code> — body <code>{ "serial_number": "..." }</code></p>
            <p class="pt-1">Auth headers: <code class="rounded bg-slate-100 px-1.5 py-0.5">X-Api-Key</code> &amp; <code class="rounded bg-slate-100 px-1.5 py-0.5">X-Api-Secret</code></p>
        </div>
    </div>

    {{-- List --}}
    <div class="card overflow-hidden">
        <div class="divide-y divide-slate-100">
            @forelse ($clients as $client)
                <div wire:key="ac-{{ $client->id }}" class="flex flex-col gap-2 p-4 sm:flex-row sm:items-center sm:justify-between">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            <p class="font-medium text-slate-800">{{ $client->name }}</p>
                            <x-badge :color="$client->is_active ? 'emerald' : 'zinc'">{{ $client->is_active ? 'Active' : 'Disabled' }}</x-badge>
                        </div>
                        <p class="mt-0.5 font-mono text-xs text-slate-400">{{ $client->key }}</p>
                        <p class="mt-1 text-xs text-slate-400">
                            {{ implode(', ', $client->abilities ?? []) }} · {{ $client->rate_limit }}/min
                            @if ($client->last_used_at) · last used {{ $client->last_used_at->diffForHumans() }} @endif
                        </p>
                    </div>
                    @can('api.manage')
                        <div class="flex shrink-0 gap-2">
                            <button wire:click="toggle({{ $client->id }})" class="btn-secondary text-xs">{{ $client->is_active ? 'Disable' : 'Enable' }}</button>
                            <button wire:click="delete({{ $client->id }})" wire:confirm="Revoke this key permanently?" class="btn-secondary px-2.5 text-red-600 hover:bg-red-50">Revoke</button>
                        </div>
                    @endcan
                </div>
            @empty
                <p class="p-8 text-center text-slate-400">No API keys yet.</p>
            @endforelse
        </div>
    </div>

    {{-- Create modal --}}
    @if ($showModal)
        <div class="fixed inset-0 z-50 flex items-end justify-center bg-slate-900/50 p-4 sm:items-center" wire:key="modal">
            <div class="card w-full max-w-lg p-6">
                @if ($newSecret)
                    <h3 class="text-base font-semibold text-slate-800">Key created</h3>
                    <p class="mt-1 text-sm text-amber-600">Copy the secret now — it will not be shown again.</p>
                    <div class="mt-4 space-y-3">
                        <div>
                            <label class="label">API Key</label>
                            <input readonly value="{{ $newKey }}" class="input font-mono text-xs" onclick="this.select()">
                        </div>
                        <div>
                            <label class="label">API Secret</label>
                            <input readonly value="{{ $newSecret }}" class="input font-mono text-xs" onclick="this.select()">
                        </div>
                    </div>
                    <div class="mt-6 flex justify-end">
                        <button wire:click="$set('showModal', false)" class="btn-primary">Done</button>
                    </div>
                @else
                    <h3 class="text-base font-semibold text-slate-800">New API key</h3>
                    <div class="mt-4 space-y-4">
                        <div>
                            <label class="label">Name</label>
                            <input wire:model="name" class="input" placeholder="Billing system">
                            @error('name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="label">Abilities</label>
                            <div class="space-y-2">
                                @foreach ($allAbilities as $key => $label)
                                    <label class="flex items-center gap-2 text-sm text-slate-600">
                                        <input type="checkbox" wire:model="abilities" value="{{ $key }}" class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                                        {{ $label }} <span class="text-xs text-slate-400">({{ $key }})</span>
                                    </label>
                                @endforeach
                            </div>
                            @error('abilities') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="label">Rate limit (requests/min)</label>
                            <input wire:model="rateLimit" type="number" class="input">
                        </div>
                    </div>
                    <div class="mt-6 flex justify-end gap-3">
                        <button wire:click="$set('showModal', false)" class="btn-secondary">Cancel</button>
                        <button wire:click="create" class="btn-primary">Create key</button>
                    </div>
                @endif
            </div>
        </div>
    @endif
</div>
