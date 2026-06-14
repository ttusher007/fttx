<?php

namespace App\Services\Olt\Data;

class SystemInfo
{
    public function __construct(
        public ?string $description = null,
        public ?string $name = null,
        public ?string $location = null,
        public ?int $uptimeTicks = null,
    ) {}
}
