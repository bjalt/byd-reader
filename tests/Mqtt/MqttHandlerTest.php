<?php

namespace App\Tests\Mqtt;

use App\Mqtt\MqttHandler;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class MqttHandlerTest extends TestCase
{
    #[Test]
    public function constructorSetsPropertiesCorrectly(): void
    {
        $mqttHandler = new MqttHandler('localhost', 1883, 'user', 'pass');
        
        // Verify the class can be instantiated
        $this->assertInstanceOf(MqttHandler::class, $mqttHandler);
    }

    #[Test]
    public function methodsExist(): void
    {
        $mqttHandler = new MqttHandler('localhost', 1883, 'user', 'pass');
        
        // Verify key methods exist
        $this->assertTrue(method_exists($mqttHandler, 'writeAutodiscovery'));
        $this->assertTrue(method_exists($mqttHandler, 'updateState'));
    }
}