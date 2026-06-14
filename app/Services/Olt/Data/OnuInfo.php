<?php

namespace App\Services\Olt\Data;

use App\Enums\OnuStatus;
use Carbon\CarbonInterface;

/**
 * Normalised, vendor-independent view of a single ONU. Every driver maps its
 * raw SNMP output into this shape so the persistence layer is vendor-agnostic.
 */
class OnuInfo
{
    public function __construct(
        public string $onuIndex,
        public ?string $portIndex = null,
        public ?string $serialNumber = null,
        public ?string $macAddress = null,
        public ?string $name = null,
        public ?string $description = null,
        public OnuStatus $status = OnuStatus::Unknown,
        public ?float $rxPower = null,
        public ?float $txPower = null,
        public ?float $distance = null,
        public ?CarbonInterface $onlineSince = null,
    ) {}
}
