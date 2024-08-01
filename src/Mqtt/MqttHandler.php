<?php declare(strict_types=1);

namespace App\Mqtt;
use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\MqttClient;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;

class MqttHandler implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private MqttClient $mqtt;
    private ConnectionSettings $connectionSettings;

    public function __construct(
        string $host,
        int $port,
        string $username,
        string $password,
    )
    {
        $clientId = 'byd-reader';

        $this->connectionSettings = (new ConnectionSettings)
            ->setUsername($username)
            ->setPassword($password);
        $this->mqtt = new MqttClient($host, $port, $clientId);
    }

    public function writeAutodiscovery(): void
    {
        $powerTopic = 'homeassistant/sensor/byd-battery/power/config';
        $powerMessage = json_encode([
            'name' => 'BYD Battery Power',
            'unique_id' => 'byd-battery-power',
            'state_topic' => 'home/energy/byd-battery/power/state',
            'unit_of_measurement' => 'W',
            'device_class' => 'power',
            'state_class' => 'measurement',
            'device' => [
                'identifiers' => 'byd-battery',
                'name' => 'BYD Battery',
                'model' => 'HVS',
                'manufacturer' => 'BYD'
            ]
        ], JSON_THROW_ON_ERROR);

        $currentTopic = 'homeassistant/sensor/byd-battery/current/config';
        $currentMessage = json_encode([
            'name' => 'BYD Battery Current',
            'unique_id' => 'byd-battery-current',
            'state_topic' => 'home/energy/byd-battery/current/state',
            'unit_of_measurement' => 'A',
            'device_class' => 'current',
            'state_class' => 'measurement',
            'device' => [
                'identifiers' => 'byd-battery',
            ]
        ], JSON_THROW_ON_ERROR);

        $voltageTopic = 'homeassistant/sensor/byd-battery/voltage/config';
        $voltageMessage = json_encode([
            'name' => 'BYD Battery Voltage',
            'unique_id' => 'byd-battery-voltage',
            'state_topic' => 'home/energy/byd-battery/voltage/state',
            'unit_of_measurement' => 'V',
            'device_class' => 'voltage',
            'state_class' => 'measurement',
            'device' => [
                'identifiers' => 'byd-battery',
            ]
        ], JSON_THROW_ON_ERROR);

        $this->connect();
        try {
            $this->mqtt->publish($powerTopic, $powerMessage, 0, true);
            $this->mqtt->publish($currentTopic, $currentMessage, 0, true);
            $this->mqtt->publish($voltageTopic, $voltageMessage, 0, true);
        } catch (\Throwable $exception) {
            $this->logger->error('Failed to write autodiscovery', ['exception' => $exception]);
        }
        $this->disconnect();
    }

    public function updateState(float $power, float $current, float $voltage): void
    {
        $this->connect();
        try {
            $this->mqtt->publish('home/energy/byd-battery/power/state', (string) $power);
            $this->mqtt->publish('home/energy/byd-battery/current/state', (string) $current);
            $this->mqtt->publish('home/energy/byd-battery/voltage/state', (string) $voltage);
        } catch (\Throwable $exception) {
            $this->logger->error('Failed to update state', ['exception' => $exception]);
        }

        $this->disconnect();
    }

    private function connect(): void
    {
        if ($this->mqtt->isConnected()) {
            return;
        }

        if ($this->mqtt->getHost() === '') {
            $this->logger->warning('MQTT host is not set, skipping connection');

            return;
        }
        try {
            $this->mqtt->connect($this->connectionSettings);
        } catch (\Throwable $exception) {
            $this->logger->error('Failed to connect to MQTT broker', ['exception' => $exception]);
        }
    }

    private function disconnect(): void
    {
        if (!$this->mqtt->isConnected()) {
            return;
        }

        $this->mqtt->disconnect();
    }
}