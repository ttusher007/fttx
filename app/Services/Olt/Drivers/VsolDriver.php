<?php

namespace App\Services\Olt\Drivers;

/**
 * VSOL GPON OLTs (Broadcom-based firmware, enterprise 37950).
 */
class VsolDriver extends AbstractVendorDriver
{
    protected function vendorKey(): string
    {
        return 'vsol';
    }
}
