<?php

namespace App\Services\Olt\Drivers;

/**
 * Fallback driver for unmapped vendors. Pulls system info and PON ports via
 * standard MIBs; ONU enumeration is empty until a vendor OID map is supplied.
 */
class GenericDriver extends AbstractVendorDriver
{
    protected function vendorKey(): string
    {
        return 'generic';
    }
}
