<?php

namespace App\Tests\Byd;

use App\Byd\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class DataProviderTest extends TestCase
{
    /**
     * Every metric getData() promises, matching the register map in configureRequest().
     */
    private const METRICS = [
        'State of Charge',
        'Max. cell voltage',
        'Min. cell voltage',
        'State of Health',
        'Current',
        'Battery Voltage',
        'Max cell temp',
        'Min cell temp',
        'BMU TEMP',
        'Output Voltage',
        'Charge Cycles',
        'Discharge Cycles',
    ];

    public function testConstructorSetsPropertiesCorrectly(): void
    {
        $this->assertInstanceOf(DataProvider::class, new DataProvider());
    }

    /**
     * Port 1 on loopback refuses the connection immediately, so this drives the swallowed-exception
     * path deterministically -- no gateway, no network, and the same result wherever it runs.
     *
     * The daemon depends on this fallback: ReadCommand skips publishing when error is true, which is
     * what keeps Home Assistant on the last good value instead of showing a zeroed battery.
     */
    public function testGetDataFallsBackToZeroesWhenTheGatewayIsUnreachable(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');

        $dataProvider = new DataProvider('127.0.0.1', 1, 0.05);
        $dataProvider->setLogger($logger);

        $data = $dataProvider->getData();

        $this->assertTrue($data['error'], 'an unreachable gateway must be reported, not hidden');

        foreach (self::METRICS as $metric) {
            $this->assertArrayHasKey($metric, $data);
            $this->assertSame(0, $data[$metric], $metric . ' should be zeroed in the fallback payload');
        }

        $this->assertSame(0, $data['power'], 'power is derived from two zeroes');
        $this->assertCount(
            count(self::METRICS) + 2,
            $data,
            'fallback payload should carry exactly the metrics plus error and power'
        );
    }
}
