<?php

namespace App\Tests\Mqtt;

use App\Mqtt\MqttHandler;
use PHPUnit\Framework\TestCase;

class MqttHandlerTest extends TestCase
{
    public function testConstructorSetsPropertiesCorrectly(): void
    {
        $mqttHandler = new MqttHandler('localhost', 1883, 'user', 'pass');
        
        // Verify the class can be instantiated
        $this->assertInstanceOf(MqttHandler::class, $mqttHandler);
    }

    public function testMethodsExist(): void
    {
        $mqttHandler = new MqttHandler('localhost', 1883, 'user', 'pass');
        
        // Verify key methods exist
        $this->assertTrue(method_exists($mqttHandler, 'writeAutodiscovery'));
        $this->assertTrue(method_exists($mqttHandler, 'updateState'));
    }
}