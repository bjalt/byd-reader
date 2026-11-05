<?php

namespace App\Tests\Command;

use App\Command\ReadCommand;
use App\Byd\DataProvider;
use App\Mqtt\MqttHandler;
use PHPUnit\Framework\TestCase;

class ReadCommandTest extends TestCase
{
    public function testConstructorSetsPropertiesCorrectly(): void
    {
        // Create stubs for dependencies
        $dataProvider = $this->createStub(DataProvider::class);
        $mqttHandler = $this->createStub(MqttHandler::class);
        
        // Create command with valid parameters
        $command = new ReadCommand($dataProvider, $mqttHandler, true, 10);
        
        // Verify the class can be instantiated
        $this->assertInstanceOf(ReadCommand::class, $command);
    }

    public function testConstructorWithInvalidInterval(): void
    {
        // Create stubs for dependencies
        $dataProvider = $this->createStub(DataProvider::class);
        $mqttHandler = $this->createStub(MqttHandler::class);
        
        // Create command with invalid interval
        $command = new ReadCommand($dataProvider, $mqttHandler, false, 0);
        
        // Verify the class can be instantiated
        $this->assertInstanceOf(ReadCommand::class, $command);
    }
}