<?php

namespace Tests\Unit;

use App\Services\Olt\Cli\Profiles\HuaweiCliProfile;
use PHPUnit\Framework\TestCase;

class HuaweiCliProfileTest extends TestCase
{
    private const OPTICAL = <<<'TXT'
  -----------------------------------------------------------------------------
  ONT-ID  Rx power(dBm)  Tx power(dBm)  OLT Rx ONT power(dBm)  Temperature(C)  Voltage(V)  Bias current(mA)
  -----------------------------------------------------------------------------
  0        -19.37         2.41           -22.80                 44              3.28        12
  1        -21.05         2.33           -24.11                 47              3.30        11
  2        -              -              -                      -               -           -
  15       -17.80         2.90           -20.10                 45              3.27        10
  -----------------------------------------------------------------------------
  Total: 4, online: 3
TXT;

    private const OPTICAL_REORDERED = <<<'TXT'
  ONT-ID  Temperature(C)  Tx power(dBm)  Rx power(dBm)
  3       44              2.41           -19.37
TXT;

    private const OPTICAL_BLOCK = <<<'TXT'
  ONT-ID                      : 7
  Rx optical power(dBm)       : -18.55
  Tx optical power(dBm)       : 2.31
  OLT Rx ONT optical power(dBm): -23.10
TXT;

    private const MAC = <<<'TXT'
  -------------------------------------------------------------------------
  SRV-P BUNDLE TYPE MAC            MAC TYPE F /S /P  VPI  VCI   VLAN ID
  INDEX INDEX
  -------------------------------------------------------------------------
      0     -   gpon 00e0-fc12-3456 dynamic 0 /1 /0  0    1     100
     12     -   gpon e4a7-c5b2-1234 dynamic 0 /1 /0  1    1     100
     13     -   gpon E4A7-C5B2-9999 dynamic 0 /1 /0  1    2     200
     20     -   gpon 1c3b-f3aa-0001 dynamic 0 /1 /0  15   1     100
     21     -   eth  0025-9e00-0001 dynamic 0 /8 /0  -    -     100
  -------------------------------------------------------------------------
  Total: 5
TXT;

    public function test_parses_optical_table(): void
    {
        $rows = HuaweiCliProfile::parseOptical(self::OPTICAL);

        $this->assertSame([0, 1, 15], array_keys($rows));
        $this->assertSame(['rx_power' => -19.37, 'tx_power' => 2.41, 'olt_rx_power' => -22.8], $rows[0]);
        $this->assertSame(-20.1, $rows[15]['olt_rx_power']);
    }

    public function test_parses_optical_with_reordered_columns(): void
    {
        $rows = HuaweiCliProfile::parseOptical(self::OPTICAL_REORDERED);

        $this->assertSame(-19.37, $rows[3]['rx_power']);
        $this->assertSame(2.41, $rows[3]['tx_power']);
        $this->assertNull($rows[3]['olt_rx_power']);
    }

    public function test_parses_optical_block_format(): void
    {
        $rows = HuaweiCliProfile::parseOptical(self::OPTICAL_BLOCK);

        $this->assertSame(['rx_power' => -18.55, 'tx_power' => 2.31, 'olt_rx_power' => -23.1], $rows[7]);
    }

    public function test_parses_mac_table(): void
    {
        $rows = HuaweiCliProfile::parseMacTable(self::MAC);

        $this->assertCount(5, $rows);
        $this->assertSame('00:E0:FC:12:34:56', $rows[0]['mac']);
        $this->assertSame('0/1/0', $rows[0]['fsp']);
        $this->assertSame(0, $rows[0]['ont_id']);
        $this->assertSame(1, $rows[1]['ont_id']);
        $this->assertSame(15, $rows[3]['ont_id']);
        $this->assertNull($rows[4]['ont_id']); // uplink row has "-" in the VPI column
        $this->assertSame('dynamic', $rows[0]['type']);
    }
}
