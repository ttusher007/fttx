<?php

namespace App\Livewire\Diagnostics;

use App\Jobs\RunDiagnosticJob;
use App\Models\DiagnosticRun;
use App\Models\Olt;
use App\Services\Olt\OltCollectorClient;
use App\Services\Snmp\SnmpProbeCatalog;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * OLT Diagnostics: run SNMP walks, driver dry-runs, raw CLI commands and CLI
 * enrichment passes against a live OLT from the browser. Runs are queued
 * (RunDiagnosticJob) so long device walks never hit the web request timeout,
 * and their full output is kept so OID maps / parsers can be tuned remotely.
 */
#[Layout('components.layouts.app', ['title' => 'OLT Diagnostics'])]
class Index extends Component
{
    #[Url(as: 'olt')]
    public ?int $oltId = null;

    #[Url(as: 'run')]
    public ?int $selectedRunId = null;

    #[Url]
    public string $tab = 'snmp';

    // SNMP walk form
    public string $snmpPreset = 'all';

    public string $snmpOid = '';

    public int $snmpLimit = 50;

    public int $snmpTimeout = 0;      // seconds; 0 = default

    public int $snmpMaxRep = 0;        // 0 = default

    // Driver dry-run form
    public int $fetchLimit = 60;

    // CLI form
    public string $cliProtocol = '';

    public string $cliPort = '';

    public string $cliCommands = '';

    public bool $cliPrep = true;

    public string $cliCharDelay = '';

    // Enrich form
    public bool $enrichSave = false;

    public function mount(): void
    {
        Gate::authorize('olt.diagnose');

        if (! $this->oltId) {
            $this->oltId = Olt::where('is_simulated', false)->orderBy('name')->value('id') ?? Olt::orderBy('name')->value('id');
        }
        $this->syncDefaults();
    }

    public function updatedOltId(): void
    {
        $this->selectedRunId = null;
        $this->snmpPreset = 'all';
        $this->syncDefaults();
    }

    private function syncDefaults(): void
    {
        $olt = $this->olt();
        if (! $olt) {
            return;
        }
        $this->cliProtocol = $olt->cli_protocol ?: 'ssh';
        $this->cliPort = (string) $olt->cliPort();
        if ($this->cliCommands === '') {
            $this->cliCommands = match ($olt->vendor) {
                'huawei' => "display version\ndisplay board 0",
                'bdcom' => "show version\nshow interface brief",
                'vsol' => "show version\nshow interface brief",
                default => 'show version',
            };
        }
    }

    public function olt(): ?Olt
    {
        return $this->oltId ? Olt::find($this->oltId) : null;
    }

    public function runSnmp(): void
    {
        Gate::authorize('olt.diagnose');
        $this->validate([
            'snmpOid' => ['nullable', 'regex:/^\.?\d+(\.\d+)+$/'],
            'snmpLimit' => 'integer|min:0|max:100000',
            'snmpTimeout' => 'integer|min:0|max:120',
            'snmpMaxRep' => 'integer|min:0|max:100',
        ], ['snmpOid.regex' => 'Enter a numeric OID like 1.3.6.1.4.1.2011.6.128.1.1.2.51.1.4']);

        $title = $this->snmpOid !== '' ? 'Walk '.$this->snmpOid : ($this->snmpPreset === 'all' ? 'All vendor probes' : $this->snmpPreset);
        $this->queue(DiagnosticRun::KIND_SNMP, $title, [
            'oid' => trim($this->snmpOid, ". \t"),
            'preset' => $this->snmpPreset,
            'limit' => $this->snmpLimit,
            'timeout' => $this->snmpTimeout ?: null,
            'max_repetitions' => $this->snmpMaxRep ?: null,
        ]);
    }

    public function runFetch(): void
    {
        Gate::authorize('olt.diagnose');
        $this->queue('fetch', 'Driver dry-run', ['limit' => max(1, $this->fetchLimit)]);
    }

    public function runCli(): void
    {
        Gate::authorize('olt.diagnose');
        $commands = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $this->cliCommands) ?: [])));
        if (empty($commands)) {
            $this->addError('cliCommands', 'Enter at least one command.');

            return;
        }
        if (count($commands) > 60) {
            $this->addError('cliCommands', 'At most 60 commands per run.');

            return;
        }

        $this->queue(DiagnosticRun::KIND_CLI, 'CLI: '.mb_substr($commands[0], 0, 60).(count($commands) > 1 ? ' (+'.(count($commands) - 1).')' : ''), [
            'commands' => $commands,
            'protocol' => $this->cliProtocol === 'telnet' ? 'telnet' : 'ssh',
            'port' => (int) $this->cliPort ?: null,
            'prep' => $this->cliPrep,
            'char_delay' => $this->cliCharDelay !== '' ? (float) $this->cliCharDelay : null,
        ]);
    }

    public function runEnrich(): void
    {
        Gate::authorize('olt.diagnose');
        $this->queue(DiagnosticRun::KIND_ENRICH, 'CLI enrichment '.($this->enrichSave ? '(save)' : '(dry run)'), [
            'save' => $this->enrichSave,
        ]);
    }

    public function select(int $runId): void
    {
        $this->selectedRunId = $runId;
    }

    public function deleteRun(int $runId): void
    {
        Gate::authorize('olt.diagnose');
        DiagnosticRun::whereKey($runId)->delete();
        if ($this->selectedRunId === $runId) {
            $this->selectedRunId = null;
        }
    }

    public function pollRuns(): void
    {
        // no-op: re-render picks up status changes
    }

    private function queue(string $kind, string $title, array $params): void
    {
        $olt = $this->olt();
        if (! $olt) {
            $this->addError('oltId', 'Pick an OLT first.');

            return;
        }

        $run = DiagnosticRun::create([
            'olt_id' => $olt->id,
            'user_id' => auth()->id(),
            'kind' => $kind,
            'title' => $title,
            'params' => $params,
            'status' => 'pending',
        ]);

        RunDiagnosticJob::dispatch($run->id);
        $this->selectedRunId = $run->id;
        session()->flash('status', 'Diagnostic queued — the output appears below when the queue worker finishes it.');
    }

    public function render()
    {
        $olt = $this->olt();
        $runs = $olt
            ? $olt->diagnosticRuns()->with('user')->latest('id')->limit(30)->get()
            : collect();

        $selected = $this->selectedRunId ? $runs->firstWhere('id', $this->selectedRunId) ?? DiagnosticRun::find($this->selectedRunId) : $runs->first();
        $hasActive = $runs->contains(fn (DiagnosticRun $r) => ! $r->isFinished());

        $collector = app(OltCollectorClient::class);
        $collectorInfo = null;
        $collectorOk = false;
        try {
            $collectorInfo = $collector->info();
            $collectorOk = $collectorInfo !== null;
        } catch (\Throwable $e) {
            $collectorInfo = ['error' => $e->getMessage()];
        }

        return view('livewire.diagnostics.index', [
            'olt' => $olt,
            'olts' => Olt::orderBy('name')->get(['id', 'name', 'vendor', 'model', 'ip_address', 'is_simulated']),
            'presets' => $olt ? SnmpProbeCatalog::all($olt->vendor) : [],
            'runs' => $runs,
            'selected' => $selected,
            'hasActive' => $hasActive,
            'collectorOk' => $collectorOk,
            'collectorInfo' => $collectorInfo,
            'collectorUrl' => config('services.olt_collector.url'),
        ]);
    }
}
