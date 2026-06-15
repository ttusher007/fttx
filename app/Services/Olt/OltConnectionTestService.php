<?php

namespace App\Services\Olt;

use App\Enums\PonType;
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

                if ($ponNote = $this->autoDetectPonType($olt, $client, $sysDescr)) {
                    $message .= ' '.$ponNote;
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

    /**
     * Detect whether the OLT speaks GPON or EPON from its interface names
     * (ifDescr "GPON0/1" / "EPON0/1") with a sysDescr fallback, and persist it
     * to the OLT. A manually-chosen pon_type is never overwritten — only a null
     * value or a previously auto-detected one is updated. Returns a short note
     * for the test-result message, or null if nothing changed.
     */
    private function autoDetectPonType(Olt $olt, SnmpClient $client, ?string $sysDescr): ?string
    {
        // Respect an operator's manual choice.
        if ($olt->pon_type !== null && ! $olt->pon_type_auto_detected) {
            return null;
        }

        $detected = $this->sniffPonType($client, $sysDescr);
        if ($detected === null) {
            return null;
        }

        // Nothing to do if it already matches what we auto-set before.
        if ($olt->pon_type === $detected && $olt->pon_type_auto_detected) {
            return null;
        }

        $olt->forceFill([
            'pon_type' => $detected->value,
            'pon_type_auto_detected' => true,
        ])->save();

        return "PON type auto-detected as {$detected->label()} (saved).";
    }

    private function sniffPonType(SnmpClient $client, ?string $sysDescr): ?PonType
    {
        $gpon = 0;
        $epon = 0;

        foreach ($client->walk(config('olt.standard.ifDescr')) as $name) {
            if (preg_match('/^GPON\d+\/\d+$/i', trim($name))) {
                $gpon++;
            } elseif (preg_match('/^EPON\d+\/\d+$/i', trim($name))) {
                $epon++;
            }
        }

        if ($gpon === 0 && $epon === 0) {
            // Fall back to a hint in the system description.
            $d = strtoupper((string) $sysDescr);
            if (str_contains($d, 'GPON')) {
                return PonType::Gpon;
            }
            if (str_contains($d, 'EPON')) {
                return PonType::Epon;
            }

            return null;
        }

        return $gpon >= $epon ? PonType::Gpon : PonType::Epon;
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
