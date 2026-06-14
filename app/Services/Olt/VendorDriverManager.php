<?php

namespace App\Services\Olt;

use App\Models\Olt;
use App\Services\Olt\Contracts\VendorDriver;
use App\Services\Olt\Drivers\GenericDriver;

class VendorDriverManager
{
    /**
     * Resolve the driver for an OLT based on its vendor, falling back to the
     * generic driver for any unmapped vendor.
     */
    public function for(Olt $olt): VendorDriver
    {
        return $this->forVendor($olt->vendor);
    }

    public function forVendor(?string $vendor): VendorDriver
    {
        $vendor = strtolower(trim((string) $vendor));
        $class = config("olt.vendors.{$vendor}.driver", GenericDriver::class);

        return app($class);
    }

    /**
     * @return array<string, string> vendor key => display label
     */
    public function availableVendors(): array
    {
        return [
            'huawei' => 'Huawei',
            'bdcom' => 'BDCOM',
            'vsol' => 'VSOL',
            'generic' => 'Generic / Other',
        ];
    }
}
