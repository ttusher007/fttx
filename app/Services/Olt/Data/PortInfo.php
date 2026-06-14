<?php

namespace App\Services\Olt\Data;

class PortInfo
{
    public function __construct(
        public string $portIndex,
        public ?string $name = null,
        public ?string $adminStatus = null,
        public ?string $operStatus = null,
    ) {}
}
