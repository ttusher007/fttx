<?php

namespace App\Services\Olt\Cli\Profiles;

/** BDCOM (P33xx / GP36xx) CLI — experimental, see GenericTableCliProfile. */
class BdcomCliProfile extends GenericTableCliProfile
{
    protected function vendorKey(): string
    {
        return 'bdcom';
    }
}
