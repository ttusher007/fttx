<div class="space-y-5">
    {{-- Header + actions --}}
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h2 class="text-lg font-semibold text-slate-800">Optical Line Terminals</h2>
            <p class="text-sm text-slate-500">{{ $olts->total() }} device(s) registered</p>
        </div>
        @can('olt.create')
            <a href="{{ route('olts.create') }}" wire:navigate class="btn-primary">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                Add OLT
            </a>
        @endcan
    </div>

    {{-- Filters --}}
    <div class="card flex flex-col gap-3 p-4 sm:flex-row sm:items-center">
        <div class="relative flex-1">
            <svg class="pointer-events-none absolute left-3 top-2.5 h-4 w-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
            <input wire:model.live.debounce.300ms="search" type="text" placeholder="Search name, IP, model…" class="input pl-9">
        </div>
        <select wire:model.live="vendor" class="input sm:w-44">
            <option value="">All vendors</option>
            @foreach ($vendors as $key => $label)
                <option value="{{ $key }}">{{ $label }}</option>
            @endforeach
        </select>
        <select wire:model.live="status" class="input sm:w-40">
            <option value="">All status</option>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
            <option value="maintenance">Maintenance</option>
        </select>
    </div>

    {{-- Cards grid (mobile-friendly) --}}
    <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
        @forelse ($olts as $olt)
            @php $pct = $olt->onu_count ? round($olt->onu_online_count / $olt->onu_count * 100) : 0; @endphp
            <div class="card flex flex-col p-5" wire:key="olt-{{ $olt->id }}">
                <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0">
                        <a href="{{ route('olts.show', $olt) }}" wire:navigate class="block truncate font-semibold text-slate-800 hover:text-indigo-600">{{ $olt->name }}</a>
                        <p class="text-xs text-slate-400">{{ $olt->ip_address }} · {{ $olt->model ?: '—' }}</p>
                    </div>
                    <x-badge :color="$olt->status->color()">{{ $olt->status->label() }}</x-badge>
                </div>

                <div class="mt-3 flex items-center gap-2">
                    <x-badge color="indigo" class="capitalize">{{ $olt->vendor }}</x-badge>
                    @if ($olt->is_simulated)<x-badge color="amber">SIM</x-badge>@endif
                    @if ($olt->last_sync_status)
                        <x-badge :color="$olt->last_sync_status === 'success' ? 'emerald' : ($olt->last_sync_status === 'failed' ? 'red' : 'amber')">{{ ucfirst($olt->last_sync_status) }}</x-badge>
                    @endif
                </div>

                <div class="mt-4 grid grid-cols-3 gap-2 text-center">
                    <div class="rounded-lg bg-slate-50 py-2">
                        <p class="text-lg font-semibold text-slate-800">{{ $olt->port_count }}</p>
                        <p class="text-[11px] uppercase tracking-wide text-slate-400">Ports</p>
                    </div>
                    <div class="rounded-lg bg-slate-50 py-2">
                        <p class="text-lg font-semibold text-slate-800">{{ number_format($olt->onu_count) }}</p>
                        <p class="text-[11px] uppercase tracking-wide text-slate-400">ONUs</p>
                    </div>
                    <div class="rounded-lg bg-slate-50 py-2">
                        <p class="text-lg font-semibold text-emerald-600">{{ $pct }}%</p>
                        <p class="text-[11px] uppercase tracking-wide text-slate-400">Online</p>
                    </div>
                </div>

                <p class="mt-3 text-xs text-slate-400">
                    Last sync: {{ $olt->last_synced_at?->diffForHumans() ?? 'never' }}
                </p>

                <div class="mt-4 flex gap-2 border-t border-slate-100 pt-4">
                    @can('olt.sync')
                        <button wire:click="sync({{ $olt->id }})" wire:loading.attr="disabled" class="btn-secondary flex-1 text-xs">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                            Sync
                        </button>
                    @endcan
                    @can('olt.update')
                        <a href="{{ route('olts.edit', $olt) }}" wire:navigate class="btn-secondary flex-1 text-xs">Edit</a>
                    @endcan
                    @can('olt.delete')
                        <button wire:click="delete({{ $olt->id }})" wire:confirm="Delete {{ $olt->name }} and all its ONUs?" class="btn-secondary px-2.5 text-red-600 hover:bg-red-50">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                        </button>
                    @endcan
                </div>
            </div>
        @empty
            <div class="card col-span-full p-10 text-center text-slate-400">
                No OLTs match your filters.
            </div>
        @endforelse
    </div>

    <div>{{ $olts->links() }}</div>
</div>
