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
        /** Customer-side (router/CPE) MAC learned behind the ONU. */
        public ?string $macAddress = null,
        public ?string $name = null,
        public ?string $description = null,
        public OnuStatus $status = OnuStatus::Unknown,
        /** Downstream power received at the ONU (dBm). */
        public ?float $rxPower = null,
        /** Upstream power transmitted by the ONU (dBm). */
        public ?float $txPower = null,
        public ?float $distance = null,
        public ?CarbonInterface $onlineSince = null,
        /** Upstream power as received by the OLT from this ONU (dBm). */
        public ?float $oltRxPower = null,
        /** The ONU's own MAC address. */
        public ?string $onuMac = null,
        /** Number of MACs learned behind the ONU, when reported. */
        public ?int $macCount = null,
        /** snmp | fdb | cli — where macAddress came from. */
        public ?string $macSource = null,
        /** ONU hardware model / equipment id. */
        public ?string $model = null,
        public ?CarbonInterface $lastDownAt = null,
        public ?string $lastDownCause = null,
    ) {}

    /** Return a copy with some fields replaced (DTO is otherwise immutable by convention). */
    public function with(array $changes): self
    {
        $clone = clone $this;
        foreach ($changes as $key => $value) {
            $clone->{$key} = $value;
        }

        return $clone;
    }
}
