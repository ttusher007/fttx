<?php

namespace Tests\Unit;

use App\Services\Olt\Drivers\HuaweiDriver;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class HuaweiDriverParsingTest extends TestCase
{
    private function call(string $method, mixed ...$args): mixed
    {
        $driver = new HuaweiDriver;
        $m = new ReflectionMethod($driver, $method);

        return $m->invoke($driver, ...$args);
    }

    public function test_date_and_time_hex_is_decoded(): void
    {
        $dt = $this->call('parseEventTime', '07EA060D172B2C002B06');

        $this->assertNotNull($dt);
        $this->assertSame('2026-06-13 23:43:44', $dt->format('Y-m-d H:i:s'));
        $this->assertSame('+06:00', $dt->format('P'));
    }

    public function test_online_since_uses_same_decoder_and_rejects_garbage(): void
    {
        $this->assertNotNull($this->call('parseOnlineSince', '07EA051C13343A002B06'));
        $this->assertNull($this->call('parseOnlineSince', '0'));
        $this->assertNull($this->call('parseOnlineSince', null));
        $this->assertNull($this->call('parseEventTime', '00000000000000000000'));
    }

    public function test_serial_hex_blob_is_rendered_as_vendor_prefix_plus_hex(): void
    {
        $this->assertSame('HWTC35B9519B', $this->call('normaliseSerial', '4857544335B9519B'));
        $this->assertSame('FTTH01230AD0', $this->call('normaliseSerial', '4654544801230AD0'));
        $this->assertSame('HWTC:12345678', $this->call('normaliseSerial', 'hwtc:12345678'));
    }

    public function test_down_cause_mapping(): void
    {
        $this->assertSame('Dying gasp (power off)', $this->call('mapDownCause', '13'));
        $this->assertSame('LOS', $this->call('mapDownCause', '1'));
        $this->assertNull($this->call('mapDownCause', '-1'));
        $this->assertSame('Code 99', $this->call('mapDownCause', '99'));
    }

    public function test_mac_normalisation_handles_sentinels_and_blobs(): void
    {
        $this->assertNull($this->call('normaliseMac', '-1'));
        $this->assertSame('00:E0:FC:12:34:56', $this->call('normaliseMac', '00E0FC123456'));
        $this->assertSame('00:E0:FC:12:34:56', $this->call('normaliseMac', '00:e0:fc:12:34:56'));
        $this->assertNull($this->call('normaliseMac', '000000000000'));
    }
}
