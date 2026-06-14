<?php

namespace App\Services\Olt;

use App\Models\Olt;
use App\Services\Snmp\SnmpClient;
use Throwable;

/**
 * Lightweight SNMP reachability check for an OLT. Used by the UI "Test
 * connection" action without triggering a full sync.
 */
class OltConnectionTestService
{
    /**
     * @return array{success: bool, message: string}
     */
    public function test(Olt $olt): array
    {
        if ($olt->shouldSimulate()) {
            return [
                'success' => true,
                'message' => 'Simulation mode is enabled — no live SNMP probe was performed.',
            ];
        }

        if (! extension_loaded('snmp')) {
            return [
                'success' => false,
                'message' => 'The PHP SNMP extension is not installed or enabled on this server. '
                    .'On Ubuntu/Debian run: sudo apt install php8.3-snmp && sudo systemctl restart php8.3-fpm. '
                    .'Then verify with: php -m | grep snmp',
            ];
        }

        if (blank($olt->ip_address)) {
            return [
                'success' => false,
                'message' => 'OLT IP address is not configured.',
            ];
        }

        $target = "{$olt->ip_address}:{$olt->snmp_port}";

        try {
            $client = SnmpClient::forOlt($olt);

            try {
                $std = config('olt.standard');
                $uptime = $client->get($std['sysUpTime']);
                $sysName = $client->get($std['sysName']);
                $sysDescr = $client->get($std['sysDescr']);

                if ($uptime === null) {
                    return [
                        'success' => false,
                        'message' => "Could not connect to {$target} via {$olt->snmp_version->label()}. Check the IP, SNMP port, community or SNMPv3 credentials, firewall rules, and that SNMP is enabled on the OLT.",
                    ];
                }

                $details = array_filter([
                    $sysName ? "Name: {$sysName}" : null,
                    $sysDescr ? 'Model: '.mb_substr($sysDescr, 0, 80) : null,
                ]);

                $message = "Connected successfully to {$target} via {$olt->snmp_version->label()}.";
                if ($details) {
                    $message .= ' '.implode(' · ', $details);
                }

                return [
                    'success' => true,
                    'message' => $message,
                ];
            } finally {
                $client->close();
            }
        } catch (Throwable $e) {
            return [
                'success' => false,
                'message' => $this->formatError($e, $olt, $target),
            ];
        }
    }

    private function formatError(Throwable $e, Olt $olt, string $target): string
    {
        $raw = trim($e->getMessage());
        $lower = strtolower($raw);

        if (str_contains($lower, 'timeout') || str_contains($lower, 'no response')) {
            return "Connection timed out reaching {$target}. Verify the OLT is online and UDP port {$olt->snmp_port} is reachable from this server.";
        }

        if (str_contains($lower, 'authentication') || str_contains($lower, 'authorization')) {
            return "SNMP authentication failed for {$target}. Check the community string or SNMPv3 username and passwords.";
        }

        if ($raw !== '') {
            return "Could not connect to {$target}: {$raw}";
        }

        return "Could not connect to {$target} via SNMP. Check network access and SNMP settings.";
    }
}
