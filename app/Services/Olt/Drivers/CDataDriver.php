<?php

namespace App\Services\Olt\Drivers;

/**
 * C-Data GPON/EPON OLTs (FD11xx / FD12xx / FD16xx series, enterprise 17409;
 * some newer firmwares use 34592).
 *
 * ONU online/offline, ports and system info come from standard MIBs via the
 * AbstractVendorDriver. ONU identity / optical enrichment is driven by the
 * config OID map (olt.vendors.cdata.pon_types.*), which still needs to be
 * confirmed against real hardware with `php artisan olt:snmp-debug {id}` — the
 * command ships a C-Data discovery probe. Fill the OIDs in config once the dump
 * shows which tree this firmware populates (no code change needed afterwards).
 */
class CDataDriver extends AbstractVendorDriver
{
    protected function vendorKey(): string
    {
        return 'cdata';
    }
}
