<?php

namespace App\Services\Olt\Cli\Contracts;

use App\Models\Olt;
use App\Services\Olt\Cli\CliEnrichResult;
use Illuminate\Support\Collection;

/**
 * A vendor CLI "profile": knows which commands to run on an OLT to obtain the
 * data SNMP can't provide, and how to parse the text that comes back into a
 * CliEnrichResult. Command templates live in config/olt.php (cli.vendors.*).
 */
interface CliProfile
{
    /** Commands to run right after login (enable, disable paging, …). */
    public function prepCommands(Olt $olt): array;

    /**
     * Data commands to run, in order, for the given PON ports.
     *
     * @param  Collection<int, \App\Models\OltPort>  $ports
     * @return string[]
     */
    public function commands(Olt $olt, Collection $ports): array;

    /**
     * Parse the raw outputs (same order as commands()) into enrichment rows.
     *
     * @param  array<int, array{command: string, output: string}>  $outputs
     * @param  Collection<int, \App\Models\OltPort>  $ports
     */
    public function parse(Olt $olt, Collection $ports, array $outputs): CliEnrichResult;

    /** Transport hints for the collector (char_delay, command_timeout, prompt_regex, …). */
    public function transportOptions(Olt $olt): array;
}
