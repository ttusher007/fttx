<?php

namespace Tests\Unit;

use App\Services\Snmp\BridgeFdbResolver;
use PHPUnit\Framework\TestCase;

class BridgeFdbResolverTest extends TestCase
{
    public function test_mac_from_decimal_index_parts(): void
    {
        $this->assertSame('00:E0:FC:12:34:56', BridgeFdbResolver::macFromIndexParts(['0', '224', '252', '18', '52', '86']));
        $this->assertNull(BridgeFdbResolver::macFromIndexParts(['1', '2', '3']));
        $this->assertNull(BridgeFdbResolver::macFromIndexParts(['1', '2', '3', '4', '5', '999']));
    }

    public function test_normalise_mac_accepts_hex_blob_and_notations(): void
    {
        $this->assertSame('00:E0:FC:12:34:56', BridgeFdbResolver::normaliseMac('00E0FC123456'));
        $this->assertSame('00:E0:FC:12:34:56', BridgeFdbResolver::normaliseMac('00e0-fc12-3456'));
        $this->assertSame('00:E0:FC:12:34:56', BridgeFdbResolver::normaliseMac('00:e0:fc:12:34:56'));
        $this->assertNull(BridgeFdbResolver::normaliseMac('-1'));
        $this->assertNull(BridgeFdbResolver::normaliseMac(''));
    }
}
