<div class="space-y-5" @if($hasActive) wire:poll.3s="pollRuns" @endif>
    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h2 class="text-lg font-semibold text-slate-800">OLT Diagnostics</h2>
            <p class="text-sm text-slate-500">Walk SNMP tables, dry-run the vendor driver, or run CLI commands on a live OLT. Runs are queued and their full output is kept here.</p>
        </div>
        <div class="flex items-center gap-2 text-xs">
            <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 font-medium ring-1 ring-inset {{ $collectorOk ? 'bg-emerald-50 text-emerald-700 ring-emerald-600/20' : 'bg-red-50 text-red-700 ring-red-600/20' }}"
                  title="{{ $collectorOk ? json_encode($collectorInfo) : ($collectorInfo['error'] ?? 'Collector not reachable at '.$collectorUrl) }}">
                <span class="h-1.5 w-1.5 rounded-full {{ $collectorOk ? 'bg-emerald-500' : 'bg-red-500' }}"></span>
                CLI collector {{ $collectorOk ? 'online' : 'offline' }}
                @if ($collectorOk && !empty($collectorInfo['version'])) · v{{ $collectorInfo['version'] }} @endif
            </span>
        </div>
    </div>

    @if (session('status'))
        <div class="rounded-lg border border-indigo-200 bg-indigo-50 px-4 py-3 text-sm text-indigo-800">{{ session('status') }}</div>
    @endif

    {{-- OLT picker --}}
    <div class="card p-4">
        <label class="label">OLT</label>
        <select wire:model.live="oltId" class="input">
            @foreach ($olts as $o)
                <option value="{{ $o->id }}">{{ $o->name }} — {{ $o->ip_address }} · {{ ucfirst($o->vendor) }} {{ $o->model }}{{ $o->is_simulated ? ' (SIM)' : '' }}</option>
            @endforeach
        </select>
        @error('oltId') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        @if ($olt)
            <p class="mt-2 text-xs text-slate-500">
                PON type: <span class="font-medium">{{ $olt->pon_type?->label() ?? 'auto' }}</span>
                · SNMP {{ $olt->snmp_version->value }} :{{ $olt->snmp_port }}
                · CLI: {{ $olt->cli_enabled ? 'enabled' : 'disabled' }} ({{ strtoupper($olt->cli_protocol ?? 'ssh') }}, user {{ $olt->ssh_username ?: 'not set' }})
                · <a href="{{ route('olts.show', $olt) }}" wire:navigate class="text-indigo-600">open OLT</a>
                · <a href="{{ route('olts.edit', $olt) }}" wire:navigate class="text-indigo-600">edit</a>
            </p>
        @endif
    </div>

    {{-- Tabs --}}
    <div class="card overflow-hidden">
        <div class="flex overflow-x-auto border-b border-slate-200 text-sm">
            @foreach (['snmp' => 'SNMP walk', 'fetch' => 'Driver dry-run', 'cli' => 'CLI command', 'enrich' => 'CLI enrichment'] as $key => $label)
                <button wire:click="$set('tab', '{{ $key }}')"
                        class="shrink-0 border-b-2 px-4 py-3 font-medium transition {{ $tab === $key ? 'border-indigo-600 text-indigo-700' : 'border-transparent text-slate-500 hover:text-slate-700' }}">
                    {{ $label }}
                </button>
            @endforeach
        </div>

        <div class="p-4 sm:p-5">
            @if ($tab === 'snmp')
                <form wire:submit="runSnmp" class="space-y-4">
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <div>
                            <label class="label">Preset probe (vendor catalogue)</label>
                            <select wire:model="snmpPreset" class="input">
                                <option value="all">All probes for this vendor ({{ count($presets) }} tables)</option>
                                @foreach ($presets as $label => $oid)
                                    <option value="{{ $label }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            <p class="mt-1 text-xs text-slate-400">Ignored when a custom OID is given.</p>
                        </div>
                        <div>
                            <label class="label">Custom OID (numeric)</label>
                            <input wire:model="snmpOid" class="input font-mono" placeholder="1.3.6.1.4.1.2011.6.128.1.1.2.51.1.4">
                            @error('snmpOid') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <div class="grid grid-cols-3 gap-4">
                        <div>
                            <label class="label">Rows per table (0 = all)</label>
                            <input wire:model="snmpLimit" type="number" min="0" class="input">
                        </div>
                        <div>
                            <label class="label">Timeout (s, 0 = default)</label>
                            <input wire:model="snmpTimeout" type="number" min="0" max="120" class="input">
                        </div>
                        <div>
                            <label class="label">GETBULK size (0 = default)</label>
                            <input wire:model="snmpMaxRep" type="number" min="0" max="100" class="input">
                        </div>
                    </div>
                    <div class="flex justify-end">
                        <button type="submit" class="btn-primary" wire:loading.attr="disabled">Run SNMP walk</button>
                    </div>
                </form>
            @elseif ($tab === 'fetch')
                <form wire:submit="runFetch" class="space-y-4">
                    <p class="text-sm text-slate-600">Runs the configured vendor driver exactly like a sync would (ports + ONUs), without saving anything. Shows every parsed field per ONU plus driver notes (empty tables, fallbacks, timeouts).</p>
                    <div class="w-48">
                        <label class="label">ONUs to list</label>
                        <input wire:model="fetchLimit" type="number" min="1" max="5000" class="input">
                    </div>
                    <div class="flex justify-end">
                        <button type="submit" class="btn-primary" wire:loading.attr="disabled">Run driver dry-run</button>
                    </div>
                </form>
            @elseif ($tab === 'cli')
                <form wire:submit="runCli" class="space-y-4">
                    @if (! $collectorOk)
                        <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                            The Python collector is not reachable at <code>{{ $collectorUrl }}</code>. Install/start it on the server first (dev_resources/python/README.md).
                        </div>
                    @endif
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <div>
                            <label class="label">Protocol</label>
                            <select wire:model="cliProtocol" class="input">
                                <option value="ssh">SSH</option>
                                <option value="telnet">Telnet</option>
                            </select>
                        </div>
                        <div>
                            <label class="label">Port</label>
                            <input wire:model="cliPort" type="number" class="input" placeholder="22 / 23">
                        </div>
                        <div>
                            <label class="label">Per-character delay (s, telnet quirks)</label>
                            <input wire:model="cliCharDelay" class="input" placeholder="auto">
                        </div>
                    </div>
                    <div>
                        <label class="label">Commands (one per line, run in one session, in order)</label>
                        <textarea wire:model="cliCommands" rows="5" class="input font-mono"></textarea>
                        @error('cliCommands') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <label class="flex items-center gap-2 text-sm text-slate-600">
                        <input wire:model="cliPrep" type="checkbox" class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                        Run the vendor prep commands first (enable, disable paging, …) — from config/olt.php cli.vendors.{{ $olt?->vendor }}.prep
                    </label>
                    <div class="flex justify-end">
                        <button type="submit" class="btn-primary" wire:loading.attr="disabled">Run CLI commands</button>
                    </div>
                </form>
            @else
                <form wire:submit="runEnrich" class="space-y-4">
                    <p class="text-sm text-slate-600">Runs the vendor CLI profile (optical power + customer MACs per PON port) through the collector and shows the parsed result next to the raw output. Tick "save" to write the values to the ONUs like the scheduled job does.</p>
                    <label class="flex items-center gap-2 text-sm text-slate-600">
                        <input wire:model="enrichSave" type="checkbox" class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                        Save parsed values to the database
                    </label>
                    <div class="flex justify-end">
                        <button type="submit" class="btn-primary" wire:loading.attr="disabled">Run CLI enrichment</button>
                    </div>
                </form>
            @endif
        </div>
    </div>

    {{-- Runs + output --}}
    <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
        <div class="card overflow-hidden lg:col-span-1">
            <div class="border-b border-slate-200 px-4 py-3 text-sm font-semibold text-slate-700">Recent runs</div>
            <div class="max-h-[32rem] divide-y divide-slate-100 overflow-y-auto">
                @forelse ($runs as $run)
                    <button wire:click="select({{ $run->id }})" wire:key="run-{{ $run->id }}"
                            class="flex w-full items-start justify-between gap-2 px-4 py-3 text-left hover:bg-slate-50 {{ $selected?->id === $run->id ? 'bg-indigo-50' : '' }}">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium text-slate-800">{{ $run->title }}</p>
                            <p class="truncate text-xs text-slate-400">{{ $run->summary ?: ucfirst($run->status).'…' }}</p>
                            <p class="text-xs text-slate-400">{{ $run->created_at->diffForHumans() }} @if($run->duration_ms) · {{ number_format($run->duration_ms / 1000, 1) }}s @endif @if($run->user) · {{ $run->user->name }} @endif</p>
                        </div>
                        <x-badge :color="match($run->status) { 'success' => 'emerald', 'failed' => 'red', 'running' => 'blue', default => 'slate' }">{{ $run->kind }} · {{ $run->status }}</x-badge>
                    </button>
                @empty
                    <p class="p-6 text-center text-sm text-slate-400">No runs yet for this OLT.</p>
                @endforelse
            </div>
        </div>

        <div class="card overflow-hidden lg:col-span-2">
            @if ($selected)
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 px-4 py-3">
                    <div class="min-w-0">
                        <p class="truncate text-sm font-semibold text-slate-700">{{ $selected->title }}</p>
                        <p class="truncate text-xs text-slate-400">
                            {{ $selected->summary }}
                            @if (!empty($selected->params)) · <span class="font-mono">{{ \Illuminate\Support\Str::limit(json_encode(array_filter($selected->params, fn ($v) => $v !== null && $v !== '' && $v !== false)), 160) }}</span> @endif
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        @if (! $selected->isFinished())
                            <span class="inline-flex items-center gap-1 text-xs text-blue-700"><svg class="h-3.5 w-3.5 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>{{ ucfirst($selected->status) }} — needs <code>queue:work</code></span>
                        @endif
                        <button wire:click="deleteRun({{ $selected->id }})" wire:confirm="Delete this run and its output?" class="text-xs font-medium text-red-600 hover:text-red-500">Delete</button>
                    </div>
                </div>
                <pre class="max-h-[40rem] overflow-auto whitespace-pre bg-slate-900 p-4 text-xs leading-relaxed text-slate-100">{{ $selected->output ?: ($selected->isFinished() ? '(no output)' : 'Waiting for the queue worker… output will stream here.') }}</pre>
            @else
                <p class="p-10 text-center text-sm text-slate-400">Select a run to see its output.</p>
            @endif
        </div>
    </div>
</div>
