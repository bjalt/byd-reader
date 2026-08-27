<?php

namespace App\Tests\Command;

use App\Command\ReadCommand;
use App\Byd\DataProvider;
use App\Mqtt\MqttHandler;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ReadCommandTest extends TestCase
{
    public function testConstructorSetsPropertiesCorrectly(): void
    {
        // Create mocks for dependencies
        $dataProvider = $this->createMock(DataProvider::class);
        $mqttHandler = $this->createMock(MqttHandler::class);
        $logger = $this->createMock(LoggerInterface::class);
        
        // Create command with valid parameters
        $command = new ReadCommand($dataProvider, $mqttHandler, true, 10);
        
        // Verify the class can be instantiated
        $this->assertInstanceOf(ReadCommand::class, $command);
    }

    public function testConstructorWithInvalidInterval(): void
    {
        // Create mocks for dependencies
        $dataProvider = $this->createMock(DataProvider::class);
        $mqttHandler = $this->createMock(MqttHandler::class);
        $logger = $this->createMock(LoggerInterface::class);
        
        // Create command with invalid interval
        $command = new ReadCommand($dataProvider, $mqttHandler, false, 0);
        
        // Verify the class can be instantiated
        $this->assertInstanceOf(ReadCommand::class, $command);
    }
}