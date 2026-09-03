<?php

namespace App\Byd;
use Exception;
use ModbusTcpClient\Composer\Read\ReadRegistersBuilder;
use ModbusTcpClient\Composer\Read\Register\ReadRegisterRequest;
use ModbusTcpClient\Composer\Request;
use ModbusTcpClient\Network\BinaryStreamConnection;
use ModbusTcpClient\Packet\RtuConverter;
use ModbusTcpClient\Utils\Packet;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;

class DataProvider implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * Bit meanings of the BMU error bitmask at 0x050D, documented in doc/byd-modbus-interface.md.
     * Provenance there rates these medium confidence: one source, not corroborated.
     */
    private const ERROR_FLAGS = [
        0 => 'High temperature charging (cells)',
        1 => 'Low temperature charging (cells)',
        2 => 'Discharging overcurrent (cells)',
        3 => 'Charging overcurrent (cells)',
        4 => 'Main circuit failure',
        5 => 'Short circuit',
        6 => 'Cell imbalance',
        7 => 'Current sensor error',
        8 => 'Battery overvoltage',
        9 => 'Battery undervoltage',
        10 => 'Cell overvoltage',
        11 => 'Cell undervoltage',
        12 => 'Voltage sensor failure',
        13 => 'Temperature sensor failure',
        14 => 'High temperature discharging (cells)',
        15 => 'Low temperature discharging (cells)',
    ];

    /**
     * Defaults are the deployed gateway, so autowiring resolves without any binding in services.yaml.
     * Pass explicit values to point at something else -- a test, or a second battery. Named modbus*
     * rather than $host/$port so a future global bind: for the MQTT scalars cannot capture them.
     */
    public function __construct(
        private string $modbusHost = '192.168.16.254',
        private int $modbusPort = 8080,
        private float $modbusReadTimeoutSec = 0.5,
    ) {
    }

    public function getData(): array
    {
        $readData = [
            'error' => false
        ];
        $connection = $this->buildConnection();
        $fc3requests = $this->configureRequest();

        try {
            /** @var $request ReadRegisterRequest */
            foreach ($fc3requests as $request) {
                $this->logger->info('Packet to be sent (in hex): ' . $request->getRequest()->toHex());
                $rtuPacket = RtuConverter::toRtu($request->getRequest());

                $binaryData = $connection->connect()->sendAndReceive($rtuPacket);
                $this->logger->info('RTU Binary received (in hex):   ' . unpack('H*', $binaryData)[1]);

                $tcpResponsePacket = RtuConverter::fromRtu($binaryData);

                $readData = $request->parse($tcpResponsePacket);
                $readData['error'] = false;
            }
        } catch (Exception $exception) {
            $this->logger->error('Error: ' . $exception->getMessage());
            $readData = [
                'State of Charge' => 0,
                'Max. cell voltage' => 0,
                'Min. cell voltage' => 0,
                'State of Health' => 0,
                'Current' => 0,
                'Battery Voltage' => 0,
                'Max cell temp' => 0,
                'Min cell temp' => 0,
                'BMU TEMP' => 0,
                'Error Bitmask' => 0,
                'Output Voltage' => 0,
                'Total Charged Energy' => 0,
                'Total Discharged Energy' => 0,
                'error' => true
            ];
        } finally {
            $connection->close();
        }

        // Derived from Output Voltage (0x0510), the pack terminal voltage, rather than Battery Voltage
        // (0x0505), the internal cell-stack voltage -- matching both reference implementations. The two
        // diverge under load, so this changes the published figure slightly.
        $readData['power'] = $readData['Current'] * $readData['Output Voltage'];
        $readData['Errors'] = self::describeErrors((int) $readData['Error Bitmask']);

        return $readData;
    }

    /**
     * Renders the BMU error bitmask as the fault names Home Assistant displays.
     * Public and static so it can be tested without a socket.
     */
    public static function describeErrors(int $bitmask): string
    {
        if ($bitmask === 0) {
            return 'Normal';
        }

        $faults = [];
        foreach (self::ERROR_FLAGS as $bit => $description) {
            if (($bitmask & (1 << $bit)) !== 0) {
                $faults[] = $description;
            }
        }

        return implode('; ', $faults);
    }

    private function buildConnection(): BinaryStreamConnection
    {
        $connection = BinaryStreamConnection::getBuilder()
            ->setPort($this->modbusPort)
            ->setHost($this->modbusHost)
            ->setReadTimeoutSec($this->modbusReadTimeoutSec)
            ->setIsCompleteCallback(function ($binaryData, $streamIndex) {
                return Packet::isCompleteLengthRTU($binaryData);
            })
            ->setLogger($this->logger)
            ->build();

        return $connection;
    }

    /**
     * @return array<Request>
     */
    private function configureRequest(): array
    {

        $unitID = 1;
        $fc3requests = ReadRegistersBuilder::newReadHoldingRegisters('no_address', $unitID)
        ->int16(0x0500, 'State of Charge', function ($value, $address, $response) {
            return $value; // optional: transform value after extraction
        })
        ->int16(0x0501, 'Max. cell voltage', function ($value, $address, $response) {
            return $value / 100; // optional: transform value after extraction
        })
        ->int16(0x0502, 'Min. cell voltage', function ($value, $address, $response) {
            return $value / 100; // optional: transform value after extraction
        })
        ->int16(0x0503, 'State of Health', function ($value, $address, $response) {
            return $value; // optional: transform value after extraction
        })
        ->int16(0x0504, 'Current', function ($value, $address, $response) {
            return $value / 10; // optional: transform value after extraction
        }) // or whatever data type that value is in that register
        ->int16(0x0505, 'Battery Voltage', function ($value, $address, $response) {
            return $value / 100; // optional: transform value after extraction
        }) // or whatever data type that value is in that register
        ->int16(0x0506, 'Max cell temp', function ($value, $address, $response) {
            return $value; // optional: transform value after extraction
        })
        ->int16(0x0507, 'Min cell temp', function ($value, $address, $response) {
            return $value; // optional: transform value after extraction
        })
        ->int16(0x0508, 'BMU TEMP', function ($value, $address, $response) {
            return $value; // optional: transform value after extraction
        })
        ->uint16(0x050D, 'Error Bitmask', function ($value, $address, $response) {
            return $value; // raw bitfield; decoded into names by describeErrors()
        })
        ->int16(0x0510, 'Output Voltage', function ($value, $address, $response) {
            return $value / 100; // optional: transform value after extraction
        }) // or whatever data type that value is in that register
        // 0x0511 and 0x0513 are the low words of 32-bit lifetime counters in 0.1 kWh -- NOT cycle
        // counts, despite what they were called here until now. Reading only the low word wrapped the
        // value to zero every 6553.5 kWh. The device puts the low word first, which is exactly this
        // library's default endianness (Endian::BIG_ENDIAN_LOW_WORD_FIRST), so no $endian argument.
        ->uint32(0x0511, 'Total Charged Energy', function ($value, $address, $response) {
            return $value / 10;
        })
        ->uint32(0x0513, 'Total Discharged Energy', function ($value, $address, $response) {
            return $value / 10;
        })

        //->uint16(2, 'address2_value')
        // See `ReadRegistersBuilder.php` for available data type methods
        ->build(); // returns array of ReadHoldingRegistersRequest requests

        return $fc3requests;
    }
}