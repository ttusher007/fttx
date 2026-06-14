<div class="space-y-6">
    {{-- Stat cards --}}
    <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <x-stat label="Total OLTs" :value="number_format($oltTotal)" tone="indigo" icon="olt" sub="{{ $oltActive }} active" />
        <x-stat label="Total ONUs" :value="number_format($onuTotal)" tone="blue" icon="onu" sub="across all PON ports" />
        <x-stat label="Online ONUs" :value="number_format($onuOnline)" tone="emerald" icon="check" sub="{{ $onlinePct }}% of fleet" />
        <x-stat label="Offline / LOS" :value="number_format($onuOffline)" tone="red" icon="alert" sub="{{ number_format($onuLos) }} in LOS" />
    </div>

    {{-- Fleet health bar --}}
    <div class="card p-5">
        <div class="flex items-center justify-between">
            <h3 class="text-sm font-semibold text-slate-700">Fleet Health</h3>
            <span class="text-sm font-medium text-slate-500">{{ $onlinePct }}% online</span>
        </div>
        <div class="mt-3 flex h-3 overflow-hidden rounded-full bg-slate-100">
            <div class="bg-emerald-500" style="width: {{ $onuTotal ? ($onuOnline / $onuTotal * 100) : 0 }}%"></div>
            <div class="bg-red-400" style="width: {{ $onuTotal ? ($onuOffline / $onuTotal * 100) : 0 }}%"></div>
        </div>
        <div class="mt-2 flex gap-4 text-xs text-slate-500">
            <span class="flex items-center gap-1"><span class="h-2 w-2 rounded-full bg-emerald-500"></span> Online {{ number_format($onuOnline) }}</span>
            <span class="flex items-center gap-1"><span class="h-2 w-2 rounded-full bg-red-400"></span> Offline {{ number_format($onuOffline) }}</span>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        {{-- Vendor breakdown --}}
        <div class="card p-5 lg:col-span-2">
            <h3 class="mb-4 text-sm font-semibold text-slate-700">ONUs by Vendor</h3>
            <div class="space-y-4">
                @forelse ($byVendor as $row)
                    @php $pct = $row->onus > 0 ? round($row->online / $row->onus * 100) : 0; @endphp
                    <div>
                        <div class="mb-1 flex items-center justify-between text-sm">
                            <span class="font-medium capitalize text-slate-700">{{ $row->vendor }}</span>
                            <span class="text-slate-400">{{ number_format($row->onus) }} ONUs · {{ $row->olts }} OLTs</span>
                        </div>
                        <div class="h-2 overflow-hidden rounded-full bg-slate-100">
                            <div class="h-full rounded-full bg-indigo-500" style="width: {{ $pct }}%"></div>
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-slate-400">No OLTs yet.</p>
                @endforelse
            </div>
        </div>

        {{-- Attention list --}}
        <div class="card p-5">
            <h3 class="mb-4 text-sm font-semibold text-slate-700">Needs Attention</h3>
            <ul class="space-y-3">
                @forelse ($attention as $olt)
                    @php $pct = $olt->onu_count ? round($olt->onu_online_count / $olt->onu_count * 100) : 0; @endphp
                    <li>
                        <a href="{{ route('olts.show', $olt) }}" wire:navigate class="flex items-center justify-between gap-2 rounded-lg p-2 hover:bg-slate-50">
                            <span class="min-w-0">
                                <span class="block truncate text-sm font-medium text-slate-700">{{ $olt->name }}</span>
                                <span class="block text-xs text-slate-400">{{ $olt->onu_online_count }}/{{ $olt->onu_count }} online</span>
                            </span>
                            <x-badge :color="$pct >= 90 ? 'emerald' : ($pct >= 70 ? 'amber' : 'red')">{{ $pct }}%</x-badge>
                        </a>
                    </li>
                @empty
                    <li class="text-sm text-slate-400">No data yet — run a sync.</li>
                @endforelse
            </ul>
        </div>
    </div>

    {{-- Recent syncs --}}
    <div class="card overflow-hidden">
        <div class="border-b border-slate-100 px-5 py-4">
            <h3 class="text-sm font-semibold text-slate-700">Recent Sync Activity</h3>
        </div>
        <div class="divide-y divide-slate-100">
            @forelse ($recentSyncs as $log)
                <div class="flex items-center justify-between gap-3 px-5 py-3">
                    <div class="min-w-0">
                        <p class="truncate text-sm font-medium text-slate-700">{{ $log->olt?->name ?? '—' }}</p>
                        <p class="truncate text-xs text-slate-400">{{ $log->message }}</p>
                    </div>
                    <div class="flex shrink-0 items-center gap-3">
                        <span class="hidden text-xs text-slate-400 sm:block">{{ $log->created_at->diffForHumans() }}</span>
                        <x-badge :color="$log->status->color()">{{ $log->status->label() }}</x-badge>
                    </div>
                </div>
            @empty
                <p class="px-5 py-6 text-center text-sm text-slate-400">No sync activity yet.</p>
            @endforelse
        </div>
    </div>
</div>
