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
                'Output Voltage' => 0,
                'Charge Cycles' => 0,
                'Discharge Cycles' => 0,
                'error' => true
            ];
        } finally {
            $connection->close();
        }

        $readData['power'] = $readData['Current'] * $readData['Battery Voltage'];

        return $readData;
    }

    private function buildConnection(): BinaryStreamConnection
    {
        $connection = BinaryStreamConnection::getBuilder()
            ->setPort(8080)
            ->setHost('192.168.16.254')
            ->setReadTimeoutSec(0.5)
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
        ->int16(0x0510, 'Output Voltage', function ($value, $address, $response) {
            return $value / 100; // optional: transform value after extraction
        }) // or whatever data type that value is in that register
        ->int16(0x0511, 'Charge Cycles', function ($value, $address, $response) {
            return $value; // optional: transform value after extraction
        })
        ->uint16(0x0513, 'Discharge Cycles', function ($value, $address, $response) {
            return $value;
        })

        //->uint16(2, 'address2_value')
        // See `ReadRegistersBuilder.php` for available data type methods
        ->build(); // returns array of ReadHoldingRegistersRequest requests

        return $fc3requests;
    }
}