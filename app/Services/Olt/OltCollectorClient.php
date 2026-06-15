<?php

namespace App\Services\Olt;

use App\Models\Olt;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin HTTP client for the Python SSH/Telnet collector (dev_resources/python).
 *
 * The collector logs into an OLT over SSH/Telnet and returns CPE MACs / optical
 * power that SNMP can't expose on old firmware. It runs locally on the same
 * server (default http://127.0.0.1:8800) and is authenticated with a shared
 * key. Credentials are taken from the OLT's SSH fields and sent per request.
 */
class OltCollectorClient
{
    /**
     * Run an arbitrary command on the OLT and return the raw text (discovery).
     *
     * @return array{command?:string, output:string}
     */
    public function raw(Olt $olt, string $command, string $protocol = 'ssh', ?int $port = null): array
    {
        return $this->post('/raw', $this->payload($olt, $protocol, $port, [
            'command' => $command,
        ]));
    }

    /**
     * Parsed ONT optical power (Rx/Tx dBm).
     *
     * @return array{command:string, rows:array<int,array<string,mixed>>, raw:string}
     */
    public function optical(Olt $olt, ?string $frameSlotPort = null, ?int $ontId = null, string $protocol = 'ssh', ?int $port = null): array
    {
        return $this->post('/onu/optical', $this->payload($olt, $protocol, $port, array_filter([
            'frame_slot_port' => $frameSlotPort,
            'ont_id' => $ontId,
        ], fn ($v) => $v !== null)));
    }

    /**
     * Parsed CPE/user MAC addresses learned behind the ONUs.
     *
     * @return array{command:string, rows:array<int,array<string,mixed>>, raw:string}
     */
    public function mac(Olt $olt, ?string $frameSlotPort = null, ?int $ontId = null, string $protocol = 'ssh', ?int $port = null): array
    {
        return $this->post('/onu/mac', $this->payload($olt, $protocol, $port, array_filter([
            'frame_slot_port' => $frameSlotPort,
            'ont_id' => $ontId,
        ], fn ($v) => $v !== null)));
    }

    /** Liveness check — true if the collector service answers. */
    public function healthy(): bool
    {
        try {
            return $this->client()->get($this->url('/health'))->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Build the credential + targeting payload the collector expects.
     *
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    private function payload(Olt $olt, string $protocol, ?int $port, array $extra): array
    {
        if (blank($olt->ssh_username) || blank($olt->ssh_password)) {
            throw new RuntimeException("OLT #{$olt->id} has no SSH username/password set — fill them on the OLT edit page.");
        }

        $protocol = $protocol === 'telnet' ? 'telnet' : 'ssh';

        return array_merge([
            'host' => $olt->ip_address,
            'username' => $olt->ssh_username,
            'password' => $olt->ssh_password,            // decrypted by the model cast
            'protocol' => $protocol,
            'port' => $port ?? ($protocol === 'telnet' ? 23 : (int) ($olt->ssh_port ?: 22)),
        ], $extra);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function post(string $path, array $payload): array
    {
        $response = $this->client()->post($this->url($path), $payload);

        if ($response->failed()) {
            $detail = $response->json('detail') ?? $response->body();
            throw new RuntimeException("Collector error ({$response->status()}): {$detail}");
        }

        return $response->json();
    }

    private function client(): PendingRequest
    {
        $key = config('services.olt_collector.key');

        if (blank($key)) {
            throw new RuntimeException('OLT_COLLECTOR_KEY is not set in .env — it must match the collector\'s COLLECTOR_API_KEY.');
        }

        return Http::withHeaders(['X-Collector-Key' => $key])
            ->timeout((int) config('services.olt_collector.timeout', 120))
            ->acceptJson();
    }

    private function url(string $path): string
    {
        return rtrim((string) config('services.olt_collector.url'), '/').$path;
    }
}
