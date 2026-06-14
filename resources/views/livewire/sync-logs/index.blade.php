<div class="space-y-5" wire:poll.15s>
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h2 class="text-lg font-semibold text-slate-800">Sync Logs</h2>
            <p class="text-sm text-slate-500">Audit trail of every OLT and ONU sync.</p>
        </div>
        <div class="flex gap-2">
            <select wire:model.live="trigger" class="input sm:w-36">
                <option value="">All triggers</option>
                <option value="schedule">Schedule</option>
                <option value="manual">Manual</option>
                <option value="api">API</option>
            </select>
            <select wire:model.live="status" class="input sm:w-36">
                <option value="">All status</option>
                <option value="success">Success</option>
                <option value="partial">Partial</option>
                <option value="failed">Failed</option>
                <option value="running">Running</option>
            </select>
        </div>
    </div>

    <div class="card overflow-hidden">
        <div class="divide-y divide-slate-100">
            @forelse ($logs as $log)
                <div wire:key="log-{{ $log->id }}" class="flex items-center justify-between gap-3 p-4">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            <p class="truncate font-medium text-slate-800">{{ $log->olt?->name ?? 'Unknown OLT' }}</p>
                            <x-badge color="slate">{{ $log->type }}</x-badge>
                            <x-badge color="indigo">{{ $log->trigger }}</x-badge>
                        </div>
                        <p class="mt-0.5 truncate text-xs text-slate-400">{{ $log->message }}</p>
                        <p class="mt-0.5 text-xs text-slate-400">
                            {{ $log->created_at->diffForHumans() }}
                            @if ($log->duration_ms) · {{ $log->duration_ms }} ms @endif
                            @if ($log->triggeredBy) · by {{ $log->triggeredBy->name }} @endif
                            @if (!empty($log->stats['onus'])) · {{ number_format($log->stats['onus']) }} ONUs @endif
                        </p>
                    </div>
                    <x-badge :color="$log->status->color()">{{ $log->status->label() }}</x-badge>
                </div>
            @empty
                <p class="p-10 text-center text-slate-400">No sync logs yet.</p>
            @endforelse
        </div>
    </div>

    <div>{{ $logs->links() }}</div>
</div>
