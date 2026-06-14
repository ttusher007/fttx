<?php

namespace App\Services\Olt\Drivers;

use App\Enums\OnuStatus;

/**
 * Huawei SmartAX MA5600T / MA5800 series (hwGpon* MIBs, enterprise 2011).
 */
class HuaweiDriver extends AbstractVendorDriver
{
    protected function vendorKey(): string
    {
        return 'huawei';
    }

    protected function mapStatus(?string $raw): OnuStatus
    {
        // hwGponDeviceOntControlRunStatus: 1 = online, 2 = offline.
        return match ((int) $raw) {
            1 => OnuStatus::Online,
            2 => OnuStatus::Offline,
            default => OnuStatus::Unknown,
        };
    }
}
