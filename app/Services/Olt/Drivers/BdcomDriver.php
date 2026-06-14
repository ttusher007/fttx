<?php

namespace App\Services\Olt\Drivers;

use App\Enums\OnuStatus;

/**
 * BDCOM EPON/GPON OLTs (enterprise 3320).
 */
class BdcomDriver extends AbstractVendorDriver
{
    protected function vendorKey(): string
    {
        return 'bdcom';
    }

    protected function mapStatus(?string $raw): OnuStatus
    {
        // BDCOM onu oper status: 1 = up/online, 2 = down, 3 = los.
        return match ((int) $raw) {
            1 => OnuStatus::Online,
            2 => OnuStatus::Offline,
            3 => OnuStatus::Losi,
            default => OnuStatus::Unknown,
        };
    }
}
