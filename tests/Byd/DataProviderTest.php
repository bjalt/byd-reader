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
        'Error Bitmask',
        'Output Voltage',
        'Total Charged Energy',
        'Total Discharged Energy',
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
        $this->assertSame('Normal', $data['Errors'], 'a zeroed bitmask decodes to Normal');
        $this->assertCount(
            count(self::METRICS) + 3,
            $data,
            'fallback payload should carry exactly the metrics plus error, power and Errors'
        );
    }

    /**
     * The error bitmask is the one piece of pure logic in this class, so it is the one piece that can
     * be tested properly. Bit meanings come from doc/byd-modbus-interface.md.
     *
     * @dataProvider errorBitmasks
     */
    public function testDescribeErrorsDecodesTheBitmask(int $bitmask, string $expected): void
    {
        $this->assertSame($expected, DataProvider::describeErrors($bitmask));
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function errorBitmasks(): iterable
    {
        yield 'no faults' => [0b0, 'Normal'];
        yield 'lowest bit' => [0b1, 'High temperature charging (cells)'];
        yield 'highest bit' => [1 << 15, 'Low temperature discharging (cells)'];
        yield 'short circuit only' => [1 << 5, 'Short circuit'];
        yield 'two faults keep register order' => [
            (1 << 5) | (1 << 2),
            'Discharging overcurrent (cells); Short circuit',
        ];
        yield 'every bit set' => [0xFFFF, implode('; ', [
            'High temperature charging (cells)',
            'Low temperature charging (cells)',
            'Discharging overcurrent (cells)',
            'Charging overcurrent (cells)',
            'Main circuit failure',
            'Short circuit',
            'Cell imbalance',
            'Current sensor error',
            'Battery overvoltage',
            'Battery undervoltage',
            'Cell overvoltage',
            'Cell undervoltage',
            'Voltage sensor failure',
            'Temperature sensor failure',
            'High temperature discharging (cells)',
            'Low temperature discharging (cells)',
        ])];
    }
}
