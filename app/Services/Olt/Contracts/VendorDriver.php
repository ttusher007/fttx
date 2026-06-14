<?php

namespace App\Services\Olt\Contracts;

use App\Models\Olt;
use App\Services\Olt\Data\OnuInfo;
use App\Services\Olt\Data\PortInfo;
use App\Services\Olt\Data\SystemInfo;

interface VendorDriver
{
    /**
     * Verify the OLT is reachable with the configured SNMP credentials.
     */
    public function probe(Olt $olt): bool;

    /**
     * System-level info (model/description/uptime).
     */
    public function fetchSystem(Olt $olt): SystemInfo;

    /**
     * All PON/uplink ports on the OLT.
     *
     * @return array<int, PortInfo>
     */
    public function fetchPorts(Olt $olt): array;

    /**
     * All ONUs registered on the OLT, normalised.
     *
     * @return array<int, OnuInfo>
     */
    public function fetchOnus(Olt $olt): array;

    /**
     * Refresh a single ONU by its vendor index (used for targeted syncs).
     */
    public function fetchOnu(Olt $olt, string $onuIndex): ?OnuInfo;
}
