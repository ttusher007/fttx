<?php

namespace App\Services\Olt;

use App\Models\Olt;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin HTTP client for the Python SSH/Telnet collector (dev_resources/python).
 *
 * The collector is transport only: it logs into an OLT over SSH or Telnet,
 * runs the commands we send, and returns the raw text of each. All parsing
 * happens in PHP (App\Services\Olt\Cli), so OID/command/parser tuning never
 * requires touching the Python service.
 *
 * It runs locally on the same server (default http://127.0.0.1:8800) and is
 * authenticated with a shared key. Credentials are taken from the OLT's SSH
 * fields and sent per request.
 */
class OltCollectorClient
{
    /**
     * Run a batch of commands in ONE login session and return each command's
     * raw output, in order.
     *
     * @param  string[]  $commands  data commands (each output is returned)
     * @param  string[]  $prep  commands run right after login whose output is not needed (enable, disable paging, …)
     * @param  array<string, mixed>  $options  transport tuning: protocol, port, char_delay, command_timeout, login_timeout, prompt_regex, enable_password
     * @return array{outputs: array<int, array{command: string, output: string, duration_ms?: int, error?: ?string}>, login_log?: string, prompt?: string, duration_ms?: int}
     */
    public function run(Olt $olt, array $commands, array $prep = [], array $options = []): array
    {
        $protocol = ($options['protocol'] ?? $olt->cli_protocol ?? 'ssh') === 'telnet' ? 'telnet' : 'ssh';
        $port = (int) ($options['port'] ?? ($protocol === $olt->cli_protocol ? $olt->cliPort() : ($protocol === 'telnet' ? 23 : ($olt->ssh_port ?: 22))));

        $payload = array_merge($this->payload($olt, $protocol, $port), [
            'vendor' => $olt->vendor,
            'commands' => array_values($commands),
            'prep' => array_values($prep),
            'options' => array_filter([
                'char_delay' => $options['char_delay'] ?? null,
                'command_timeout' => $options['command_timeout'] ?? null,
                'login_timeout' => $options['login_timeout'] ?? null,
                'prompt_regex' => $options['prompt_regex'] ?? null,
                'enable_password' => $options['enable_password'] ?? null,
            ], fn ($v) => $v !== null && $v !== ''),
        ]);

        return $this->post('/run', $payload);
    }

    /**
     * Run a single command and return its raw text (discovery helper).
     *
     * @return array{command: string, output: string}
     */
    public function raw(Olt $olt, string $command, string $protocol = 'ssh', ?int $port = null, array $prep = []): array
    {
        $result = $this->run($olt, [$command], $prep, ['protocol' => $protocol, 'port' => $port]);
        $first = $result['outputs'][0] ?? ['command' => $command, 'output' => ''];

        return ['command' => $first['command'] ?? $command, 'output' => (string) ($first['output'] ?? '')];
    }

    /** Liveness check — true if the collector service answers. */
    public function healthy(): bool
    {
        try {
            return $this->client()->timeout(3)->get($this->url('/health'))->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    /** Collector version/info, or null if unreachable. */
    public function info(): ?array
    {
        try {
            $res = $this->client()->timeout(3)->get($this->url('/health'));

            return $res->successful() ? $res->json() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Build the credential + targeting payload the collector expects.
     *
     * @return array<string, mixed>
     */
    private function payload(Olt $olt, string $protocol, int $port): array
    {
        if (blank($olt->ssh_username) || blank($olt->ssh_password)) {
            throw new RuntimeException("OLT #{$olt->id} has no CLI username/password set — fill them on the OLT edit page (CLI section).");
        }

        return [
            'host' => $olt->ip_address,
            'username' => $olt->ssh_username,
            'password' => $olt->ssh_password,            // decrypted by the model cast
            'protocol' => $protocol,
            'port' => $port,
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function post(string $path, array $payload): array
    {
        try {
            $response = $this->client()->post($this->url($path), $payload);
        } catch (ConnectionException $e) {
            $timeout = (int) config('services.olt_collector.timeout', 1500);
            throw new RuntimeException(
                "Collector not reachable or timed out after {$timeout}s at ".config('services.olt_collector.url').'. '
                .'Check `systemctl status olt-collector` on the server. '
                .$e->getMessage(),
            );
        }

        if ($response->failed()) {
            $detail = $response->json('detail') ?? $response->body();
            if (is_array($detail)) {
                $detail = json_encode($detail);
            }
            throw new RuntimeException("Collector error ({$response->status()}): {$detail}");
        }

        return $response->json() ?? [];
    }

    private function client(): PendingRequest
    {
        $key = config('services.olt_collector.key');

        if (blank($key)) {
            throw new RuntimeException('OLT_COLLECTOR_KEY is not set in .env — it must match the collector\'s COLLECTOR_API_KEY.');
        }

        return Http::withHeaders(['X-Collector-Key' => $key])
            ->timeout((int) config('services.olt_collector.timeout', 1500))
            ->acceptJson();
    }

    private function url(string $path): string
    {
        return rtrim((string) config('services.olt_collector.url'), '/').$path;
    }
}
