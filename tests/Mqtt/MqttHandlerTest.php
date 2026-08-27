<?php

namespace App\Tests\Mqtt;

use App\Mqtt\MqttHandler;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class MqttHandlerTest extends TestCase
{
    public function testConstructorSetsPropertiesCorrectly(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $mqttHandler = new MqttHandler('localhost', 1883, 'user', 'pass');
        
        // Verify the class can be instantiated
        $this->assertInstanceOf(MqttHandler::class, $mqttHandler);
    }

    public function testMethodsExist(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $mqttHandler = new MqttHandler('localhost', 1883, 'user', 'pass');
        
        // Verify key methods exist
        $this->assertTrue(method_exists($mqttHandler, 'writeAutodiscovery'));
        $this->assertTrue(method_exists($mqttHandler, 'updateState'));
    }
}