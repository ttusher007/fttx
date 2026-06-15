<div class="space-y-5">
    <div>
        <h2 class="text-lg font-semibold text-slate-800">ONU Browser</h2>
        <p class="text-sm text-slate-500">Search every ONU across all OLTs by serial number, MAC, or customer.</p>
    </div>

    <div class="card flex flex-col gap-3 p-4 sm:flex-row sm:items-center">
        <div class="relative flex-1">
            <svg class="pointer-events-none absolute left-3 top-2.5 h-4 w-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
            <input wire:model.live.debounce.300ms="search" placeholder="Serial number, MAC address, customer…" class="input pl-9">
        </div>
        <select wire:model.live="status" class="input sm:w-40">
            <option value="">All status</option>
            <option value="online">Online</option>
            <option value="offline">Offline</option>
            <option value="los">LOS</option>
            <option value="dying_gasp">Dying gasp</option>
        </select>
    </div>

    <div class="card overflow-hidden">
        <table class="hidden w-full text-sm lg:table">
            <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-400">
                <tr>
                    <th class="px-4 py-3">Serial / MAC</th>
                    <th class="px-4 py-3">OLT</th>
                    <th class="px-4 py-3">PON Port</th>
                    <th class="px-4 py-3">ONU Port</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3">↓OLT→ONU / ↑ONU→OLT</th>
                    <th class="px-4 py-3">Live since</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($onus as $onu)
                    <tr wire:key="o-{{ $onu->id }}" class="hover:bg-slate-50">
                        <td class="px-4 py-3">
                            <p class="font-medium text-slate-800">{{ $onu->serial_number ?: '—' }}</p>
                            <p class="text-xs text-slate-400">{{ $onu->mac_address ?: 'no MAC' }}</p>
                        </td>
                        <td class="px-4 py-3">
                            <a href="{{ route('olts.show', $onu->olt_id) }}" wire:navigate class="text-indigo-600 hover:text-indigo-500">{{ $onu->olt?->name }}</a>
                        </td>
                        <td class="px-4 py-3 text-slate-500">{{ $onu->port?->name ?: '—' }}</td>
                        <td class="px-4 py-3 text-slate-500">{{ $onu->name ?: $onu->onu_index }}</td>
                        <td class="px-4 py-3"><x-badge :color="$onu->status->color()">{{ $onu->status->label() }}</x-badge></td>
                        <td class="px-4 py-3">@include('livewire.olts.partials.power', ['onu' => $onu])</td>
                        <td class="px-4 py-3 text-slate-500">{{ $onu->online_since?->diffForHumans(null, true) ?? '—' }}</td>
                        <td class="px-4 py-3 text-right">
                            @can('onu.sync')<button wire:click="syncOnu({{ $onu->id }})" class="text-xs font-medium text-indigo-600 hover:text-indigo-500">Refresh</button>@endcan
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-4 py-10 text-center text-slate-400">No ONUs found.</td></tr>
                @endforelse
            </tbody>
        </table>

        <div class="divide-y divide-slate-100 lg:hidden">
            @forelse ($onus as $onu)
                <div wire:key="om-{{ $onu->id }}" class="p-4">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="truncate font-medium text-slate-800">{{ $onu->serial_number ?: '—' }}</p>
                            <p class="truncate text-xs text-slate-400">{{ $onu->mac_address ?: 'no MAC' }}</p>
                        </div>
                        <x-badge :color="$onu->status->color()">{{ $onu->status->label() }}</x-badge>
                    </div>
                    <div class="mt-2 flex items-center justify-between text-xs text-slate-500">
                        <div>
                            <a href="{{ route('olts.show', $onu->olt_id) }}" wire:navigate class="text-indigo-600">{{ $onu->olt?->name }}</a>
                            <span class="ml-1">· {{ $onu->port?->name ?: '—' }} · {{ $onu->name ?: $onu->onu_index }}</span>
                        </div>
                        <span>@include('livewire.olts.partials.power', ['onu' => $onu])</span>
                    </div>
                </div>
            @empty
                <p class="p-10 text-center text-slate-400">No ONUs found.</p>
            @endforelse
        </div>
    </div>

    <div>{{ $onus->links() }}</div>
</div>
