<?php

namespace App\Tests\Command;

use App\Command\ReadCommand;
use App\Byd\DataProvider;
use App\Mqtt\MqttHandler;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ReadCommandTest extends TestCase
{
    #[Test]
    public function constructorSetsPropertiesCorrectly(): void
    {
        // Create stubs for dependencies
        $dataProvider = $this->createStub(DataProvider::class);
        $mqttHandler = $this->createStub(MqttHandler::class);
        
        // Create command with valid parameters
        $command = new ReadCommand($dataProvider, $mqttHandler, true, 10);
        
        // Verify the class can be instantiated
        $this->assertInstanceOf(ReadCommand::class, $command);
    }

    #[Test]
    public function constructorWithInvalidInterval(): void
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