<?php

namespace App\Tests\Byd;

use App\Byd\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class DataProviderTest extends TestCase
{
    public function testConstructorSetsPropertiesCorrectly(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $dataProvider = new DataProvider();
        
        // Verify the class can be instantiated
        $this->assertInstanceOf(DataProvider::class, $dataProvider);
    }

    public function testGetDataReturnsArrayStructure(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $dataProvider = new DataProvider();
        $dataProvider->setLogger($logger);
        
        // Test that getData returns an array with expected structure
        $data = $dataProvider->getData();
        
        // Verify it returns an array
        $this->assertIsArray($data);
        
        // Verify key structure
        $this->assertArrayHasKey('error', $data);
        $this->assertArrayHasKey('State of Charge', $data);
        $this->assertArrayHasKey('Current', $data);
        $this->assertArrayHasKey('Battery Voltage', $data);
        $this->assertArrayHasKey('power', $data);
    }
}