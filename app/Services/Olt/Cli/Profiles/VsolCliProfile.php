<?php

namespace App\Services\Olt\Cli\Profiles;

/** VSOL (V1600D / V1600G) CLI — experimental, see GenericTableCliProfile. */
class VsolCliProfile extends GenericTableCliProfile
{
    protected function vendorKey(): string
    {
        return 'vsol';
    }
}
