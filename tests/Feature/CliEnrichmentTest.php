<?php

namespace Tests\Feature;

use App\Models\Olt;
use App\Models\Onu;
use App\Services\Olt\Cli\OltCliEnrichService;
use App\Services\Olt\OltSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Exercises the CLI enrichment pipeline end-to-end against a faked collector:
 * command generation, parsing, matching to ONUs and persistence — plus the
 * rule that a later SNMP sync must not wipe CLI-sourced values.
 */
class CliEnrichmentTest extends TestCase
{
    use RefreshDatabase;

    private const OPTICAL = <<<'TXT'
  ONT-ID  Rx power(dBm)  Tx power(dBm)  OLT Rx ONT power(dBm)  Temperature(C)
  1        -19.37         2.41           -22.80                 44
  2        -21.05         2.33           -24.11                 47
TXT;

    private const MAC = <<<'TXT'
  SRV-P BUNDLE TYPE MAC            MAC TYPE F /S /P  VPI  VCI   VLAN ID
      0     -   gpon 00e0-fc12-3456 dynamic 0 /1 /0  1    1     100
     12     -   gpon e4a7-c5b2-1234 dynamic 0 /1 /0  2    1     100
     13     -   gpon e4a7-c5b2-9999 dynamic 0 /1 /0  2    2     200
TXT;

    private function makeOlt(): Olt
    {
        config(['services.olt_collector.key' => 'test-key', 'services.olt_collector.url' => 'http://collector.test']);

        $olt = Olt::create([
            'name' => 'DU-OLT', 'ip_address' => '10.0.0.5', 'vendor' => 'huawei', 'model' => 'MA5683T',
            'snmp_version' => 'v2c', 'snmp_community' => 'public', 'status' => 'active',
            'ssh_username' => 'root', 'ssh_password' => 'admin', 'cli_enabled' => true, 'cli_protocol' => 'telnet',
        ]);
        $port = $olt->ports()->create(['port_index' => '4194304000', 'name' => 'GPON 0/1/0']);
        $olt->ports()->create(['port_index' => '4194304256', 'name' => 'GPON 0/1/1']);

        $olt->onus()->create(['onu_index' => '4194304000.1', 'olt_port_id' => $port->id, 'name' => 'GPON 0/1/0:1', 'serial_number' => 'HWTC00000001', 'status' => 'online']);
        $olt->onus()->create(['onu_index' => '4194304000.2', 'olt_port_id' => $port->id, 'name' => 'GPON 0/1/0:2', 'serial_number' => 'HWTC00000002', 'status' => 'online']);
        $olt->onus()->create(['onu_index' => '4194304000.3', 'olt_port_id' => $port->id, 'name' => 'GPON 0/1/0:3', 'serial_number' => 'HWTC00000003', 'status' => 'offline']);

        return $olt;
    }

    public function test_huawei_profile_builds_commands_and_persists_parsed_values(): void
    {
        $olt = $this->makeOlt();

        Http::fake(function ($request) {
            $body = $request->data();
            $this->assertSame('telnet', $body['protocol']);
            $this->assertSame(23, $body['port']);
            $this->assertSame('root', $body['username']);
            $this->assertContains('interface gpon 0/1', $body['commands']);
            $this->assertContains('display ont optical-info 0 all', $body['commands']);
            $this->assertContains('display ont optical-info 1 all', $body['commands']);
            $this->assertContains('display mac-address port 0/1/0', $body['commands']);
            $this->assertContains('undo interactive', $body['prep']);

            $outputs = [];
            foreach ($body['commands'] as $cmd) {
                $outputs[] = [
                    'command' => $cmd,
                    'output' => match (true) {
                        $cmd === 'display ont optical-info 0 all' => self::OPTICAL,
                        $cmd === 'display mac-address port 0/1/0' => self::MAC,
                        default => '',
                    },
                    'duration_ms' => 10,
                    'error' => null,
                ];
            }

            return Http::response(['outputs' => $outputs, 'prompt' => 'MA5683T(config)#', 'duration_ms' => 1234]);
        });

        $log = app(OltCliEnrichService::class)->enrich($olt, 'manual');

        $this->assertSame('success', $log->status->value, $log->message);

        $onu1 = Onu::where('onu_index', '4194304000.1')->first();
        $onu2 = Onu::where('onu_index', '4194304000.2')->first();
        $onu3 = Onu::where('onu_index', '4194304000.3')->first();

        $this->assertEquals(-19.37, (float) $onu1->rx_power);
        $this->assertEquals(2.41, (float) $onu1->tx_power);
        $this->assertEquals(-22.8, (float) $onu1->olt_rx_power);
        $this->assertSame('00:E0:FC:12:34:56', $onu1->mac_address);
        $this->assertSame('cli', $onu1->mac_source);
        $this->assertNotNull($onu1->cli_synced_at);

        $this->assertSame('E4:A7:C5:B2:12:34', $onu2->mac_address);
        $this->assertSame(2, $onu2->mac_count);
        $this->assertEquals(-24.11, (float) $onu2->olt_rx_power);

        $this->assertNull($onu3->rx_power);
        $this->assertNull($onu3->mac_address);

        $olt->refresh();
        $this->assertSame('success', $olt->cli_last_status);
        $this->assertNotNull($olt->cli_last_synced_at);
    }

    public function test_snmp_sync_keeps_cli_values_when_snmp_has_no_optical_or_mac(): void
    {
        $olt = $this->makeOlt();
        Onu::where('onu_index', '4194304000.1')->update([
            'rx_power' => -19.37, 'tx_power' => 2.41, 'olt_rx_power' => -22.8,
            'mac_address' => '00:E0:FC:12:34:56', 'mac_source' => 'cli', 'mac_count' => 1,
        ]);

        // Drive a sync with a fake driver that returns the same ONUs but no optical / MAC data.
        $driver = new class extends \App\Services\Olt\Drivers\HuaweiDriver
        {
            public function probe(Olt $olt): bool
            {
                return true;
            }

            public function fetchSystem(Olt $olt): \App\Services\Olt\Data\SystemInfo
            {
                return new \App\Services\Olt\Data\SystemInfo('MA5683T');
            }

            public function fetchPorts(Olt $olt): array
            {
                return [new \App\Services\Olt\Data\PortInfo('4194304000', 'GPON 0/1/0')];
            }

            public function fetchOnus(Olt $olt): array
            {
                return [
                    new \App\Services\Olt\Data\OnuInfo('4194304000.1', '4194304000', 'HWTC00000001', null, 'GPON 0/1/0:1', null, \App\Enums\OnuStatus::Online),
                    new \App\Services\Olt\Data\OnuInfo('4194304000.2', '4194304000', 'HWTC00000002', null, 'GPON 0/1/0:2', null, \App\Enums\OnuStatus::Offline),
                ];
            }
        };
        $manager = new class($driver) extends \App\Services\Olt\VendorDriverManager
        {
            public function __construct(private $driver) {}

            public function for(Olt $olt): \App\Services\Olt\Contracts\VendorDriver
            {
                return $this->driver;
            }
        };

        $log = (new OltSyncService($manager))->sync($olt, 'manual');
        $this->assertSame('success', $log->status->value, $log->message);

        $onu1 = Onu::where('onu_index', '4194304000.1')->first();
        $this->assertEquals(-19.37, (float) $onu1->rx_power, 'CLI optical value must survive an SNMP sync without optical data');
        $this->assertSame('00:E0:FC:12:34:56', $onu1->mac_address);
        $this->assertSame('cli', $onu1->mac_source);
        $this->assertSame('online', $onu1->status->value);

        // ONU 3 was not reported by SNMP any more → pruned.
        $this->assertNull(Onu::where('onu_index', '4194304000.3')->first());
    }

    public function test_offline_onu_keeps_last_known_mac_across_syncs(): void
    {
        $olt = $this->makeOlt();
        $olt->update(['cli_enabled' => false]);
        Onu::where('onu_index', '4194304000.1')->update(['mac_address' => 'AA:BB:CC:DD:EE:01', 'mac_source' => 'fdb', 'mac_count' => 1]);

        $driver = new class extends \App\Services\Olt\Drivers\BdcomDriver
        {
            public function probe(Olt $olt): bool
            {
                return true;
            }

            public function fetchSystem(Olt $olt): \App\Services\Olt\Data\SystemInfo
            {
                return new \App\Services\Olt\Data\SystemInfo('GP3600');
            }

            public function fetchPorts(Olt $olt): array
            {
                return [new \App\Services\Olt\Data\PortInfo('4194304000', 'GPON0/1')];
            }

            public function fetchOnus(Olt $olt): array
            {
                return [
                    // Offline now: no MAC in the FDB, no optical reading.
                    new \App\Services\Olt\Data\OnuInfo('4194304000.1', '4194304000', 'HWTC00000001', null, 'GPON0/1:1', null, \App\Enums\OnuStatus::Offline),
                    // Online with a fresh FDB MAC.
                    new \App\Services\Olt\Data\OnuInfo('4194304000.2', '4194304000', 'HWTC00000002', 'AA:BB:CC:DD:EE:02', 'GPON0/1:2', null, \App\Enums\OnuStatus::Online, -20.5, 2.1, 640.0, null, -23.0, null, 1, 'fdb', 'XPON'),
                ];
            }
        };
        $manager = new class($driver) extends \App\Services\Olt\VendorDriverManager
        {
            public function __construct(private $driver) {}

            public function for(Olt $olt): \App\Services\Olt\Contracts\VendorDriver
            {
                return $this->driver;
            }
        };

        (new OltSyncService($manager))->sync($olt, 'manual');

        $onu1 = Onu::where('onu_index', '4194304000.1')->first();
        $this->assertSame('AA:BB:CC:DD:EE:01', $onu1->mac_address, 'last-known router MAC is kept for offline ONUs');
        $this->assertNull($onu1->rx_power);

        $onu2 = Onu::where('onu_index', '4194304000.2')->first();
        $this->assertSame('AA:BB:CC:DD:EE:02', $onu2->mac_address);
        $this->assertEquals(-23.0, (float) $onu2->olt_rx_power);
        $this->assertSame('XPON', $onu2->model);
    }
}
