<div class="space-y-5" @if($olt->last_sync_status === null) wire:poll.10s @endif>
    {{-- Header --}}
    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div class="flex items-center gap-3">
            <a href="{{ route('olts.index') }}" wire:navigate class="rounded-lg p-2 text-slate-400 hover:bg-slate-100">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
            </a>
            <div>
                <div class="flex items-center gap-2">
                    <h2 class="text-lg font-semibold text-slate-800">{{ $olt->name }}</h2>
                    <x-badge :color="$olt->status->color()">{{ $olt->status->label() }}</x-badge>
                    @if ($olt->is_simulated)<x-badge color="amber">SIM</x-badge>@endif
                </div>
                <p class="text-sm text-slate-500">{{ $olt->ip_address }} · <span class="capitalize">{{ $olt->vendor }}</span> {{ $olt->model }}</p>
            </div>
        </div>
        <div class="flex flex-wrap gap-2">
            @can('olt.update')<a href="{{ route('olts.edit', $olt) }}" wire:navigate class="btn-secondary">Edit</a>@endcan
            <button wire:click="testConnection" wire:loading.attr="disabled" class="btn-secondary">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" wire:loading.class="animate-pulse" wire:target="testConnection"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                <span wire:loading.remove wire:target="testConnection">Test connection</span>
                <span wire:loading wire:target="testConnection">Testing…</span>
            </button>
            @can('olt.sync')
                <button wire:click="sync" wire:loading.attr="disabled" class="btn-primary">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" wire:loading.class="animate-spin" wire:target="sync"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    Sync now
                </button>
            @endcan
        </div>
    </div>

    @if ($connectionTestMessage)
        <div @class([
            'rounded-lg border px-4 py-3 text-sm',
            'border-emerald-200 bg-emerald-50 text-emerald-800' => $connectionTestSuccess,
            'border-red-200 bg-red-50 text-red-800' => ! $connectionTestSuccess,
        ])>
            {{ $connectionTestMessage }}
        </div>
    @endif

    {{-- Sync status line --}}
    <div class="card flex flex-col gap-1 p-4 text-sm sm:flex-row sm:items-center sm:justify-between">
        <span class="text-slate-500">
            Last sync:
            <span class="font-medium text-slate-700">{{ $olt->last_synced_at?->diffForHumans() ?? 'never' }}</span>
            @if ($olt->last_sync_duration_ms) · {{ $olt->last_sync_duration_ms }} ms @endif
        </span>
        <span class="text-slate-500">{{ $olt->last_sync_message }}</span>
    </div>

    {{-- Stats --}}
    <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <x-stat label="PON Ports" :value="$olt->port_count" tone="indigo" icon="olt" />
        <x-stat label="Total ONUs" :value="number_format($olt->onu_count)" tone="blue" icon="onu" />
        <x-stat label="Online" :value="number_format($olt->onu_online_count)" tone="emerald" icon="check" />
        <x-stat label="Offline" :value="number_format($olt->onu_offline_count)" tone="red" icon="alert" />
    </div>

    {{-- Ports --}}
    <div class="card p-5">
        <h3 class="mb-4 text-sm font-semibold text-slate-700">PON Ports</h3>
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6">
            @foreach ($ports as $port)
                @php $ppct = $port->onu_count ? round($port->onu_online_count / $port->onu_count * 100) : 0; @endphp
                <button wire:click="$set('portFilter', {{ $portFilter === $port->id ? 'null' : $port->id }})"
                        class="rounded-xl border p-3 text-left transition {{ $portFilter === $port->id ? 'border-indigo-400 bg-indigo-50' : 'border-slate-200 hover:border-slate-300' }}">
                    <p class="truncate text-xs font-medium text-slate-600">{{ $port->name ?: 'Port '.$port->port_index }}</p>
                    <p class="mt-1 text-sm font-semibold text-slate-800">{{ $port->onu_online_count }}/{{ $port->onu_count }}</p>
                    <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-slate-100">
                        <div class="h-full bg-emerald-500" style="width: {{ $ppct }}%"></div>
                    </div>
                </button>
            @endforeach
        </div>
    </div>

    {{-- ONU filters --}}
    <div class="card flex flex-col gap-3 p-4 sm:flex-row sm:items-center">
        <div class="relative flex-1">
            <svg class="pointer-events-none absolute left-3 top-2.5 h-4 w-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
            <input wire:model.live.debounce.300ms="search" placeholder="Search serial, MAC, customer…" class="input pl-9">
        </div>
        <select wire:model.live="statusFilter" class="input sm:w-40">
            <option value="">All ONUs</option>
            <option value="online">Online</option>
            <option value="offline">Offline</option>
            <option value="los">LOS</option>
            <option value="dying_gasp">Dying gasp</option>
        </select>
        @if ($portFilter)
            <button wire:click="$set('portFilter', null)" class="btn-secondary text-xs">Clear port filter</button>
        @endif
    </div>

    {{-- ONU list --}}
    <div class="card overflow-hidden">
        {{-- Desktop table --}}
        <table class="hidden w-full text-sm lg:table">
            <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-400">
                <tr>
                    <th class="px-4 py-3">Serial / MAC</th>
                    <th class="px-4 py-3">Port</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3">Rx / Tx (dBm)</th>
                    <th class="px-4 py-3">Distance</th>
                    <th class="px-4 py-3">Live since</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($onus as $onu)
                    <tr wire:key="onu-d-{{ $onu->id }}" class="hover:bg-slate-50">
                        <td class="px-4 py-3">
                            <p class="font-medium text-slate-800">{{ $onu->serial_number ?: '—' }}</p>
                            <p class="text-xs text-slate-400">{{ $onu->mac_address ?: 'no MAC' }} · {{ $onu->description }}</p>
                        </td>
                        <td class="px-4 py-3 text-slate-500">{{ $onu->port?->name ?: $onu->onu_index }}</td>
                        <td class="px-4 py-3"><x-badge :color="$onu->status->color()">{{ $onu->status->label() }}</x-badge></td>
                        <td class="px-4 py-3">
                            @include('livewire.olts.partials.power', ['onu' => $onu])
                        </td>
                        <td class="px-4 py-3 text-slate-500">{{ $onu->distance ? number_format($onu->distance).' m' : '—' }}</td>
                        <td class="px-4 py-3 text-slate-500">{{ $onu->online_since?->diffForHumans(null, true) ?? '—' }}</td>
                        <td class="px-4 py-3 text-right">
                            @can('onu.sync')
                                <button wire:click="syncOnu({{ $onu->id }})" class="text-xs font-medium text-indigo-600 hover:text-indigo-500">Refresh</button>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-8 text-center text-slate-400">No ONUs found.</td></tr>
                @endforelse
            </tbody>
        </table>

        {{-- Mobile cards --}}
        <div class="divide-y divide-slate-100 lg:hidden">
            @forelse ($onus as $onu)
                <div wire:key="onu-m-{{ $onu->id }}" class="p-4">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="truncate font-medium text-slate-800">{{ $onu->serial_number ?: '—' }}</p>
                            <p class="truncate text-xs text-slate-400">{{ $onu->mac_address ?: 'no MAC' }}</p>
                        </div>
                        <x-badge :color="$onu->status->color()">{{ $onu->status->label() }}</x-badge>
                    </div>
                    <div class="mt-2 grid grid-cols-3 gap-2 text-xs text-slate-500">
                        <span>Port<br><span class="font-medium text-slate-700">{{ $onu->port?->name ?: $onu->onu_index }}</span></span>
                        <span>Rx/Tx<br>@include('livewire.olts.partials.power', ['onu' => $onu])</span>
                        <span>Distance<br><span class="font-medium text-slate-700">{{ $onu->distance ? number_format($onu->distance).' m' : '—' }}</span></span>
                    </div>
                </div>
            @empty
                <p class="p-8 text-center text-slate-400">No ONUs found.</p>
            @endforelse
        </div>
    </div>

    <div>{{ $onus->links() }}</div>
</div>
