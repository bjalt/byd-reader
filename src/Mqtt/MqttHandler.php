<?php declare(strict_types=1);

namespace App\Mqtt;
use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\MqttClient;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;

class MqttHandler implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const CONFIG_TOPIC = 'homeassistant/sensor/byd-battery/%s/config';
    private const STATE_TOPIC = 'home/energy/byd-battery/%s/state';

    /** The one sensor whose discovery payload carries the full device block. */
    private const DEVICE_SENSOR = 'power';

    /** Home Assistant rejects a state payload longer than this. */
    private const MAX_STATE_LENGTH = 255;

    /**
     * Every sensor published to Home Assistant, keyed by the slug used in both topic families and in
     * the unique id. 'source' is the DataProvider payload key feeding the state topic.
     *
     * Slugs and names of the first four are load-bearing: changing one orphans the existing entity in
     * Home Assistant and creates a duplicate alongside it.
     */
    private const SENSORS = [
        'power' => ['source' => 'power', 'name' => 'Power', 'unit' => 'W', 'device_class' => 'power', 'state_class' => 'measurement'],
        'current' => ['source' => 'Current', 'name' => 'Current', 'unit' => 'A', 'device_class' => 'current', 'state_class' => 'measurement'],
        'voltage' => ['source' => 'Battery Voltage', 'name' => 'Voltage', 'unit' => 'V', 'device_class' => 'voltage', 'state_class' => 'measurement'],
        'state-of-charge' => ['source' => 'State of Charge', 'name' => 'State of Charge', 'unit' => '%', 'device_class' => 'battery', 'state_class' => 'measurement'],
        'state-of-health' => ['source' => 'State of Health', 'name' => 'State of Health', 'unit' => '%', 'device_class' => null, 'state_class' => 'measurement'],
        'output-voltage' => ['source' => 'Output Voltage', 'name' => 'Output Voltage', 'unit' => 'V', 'device_class' => 'voltage', 'state_class' => 'measurement'],
        'max-cell-voltage' => ['source' => 'Max. cell voltage', 'name' => 'Max Cell Voltage', 'unit' => 'V', 'device_class' => 'voltage', 'state_class' => 'measurement'],
        'min-cell-voltage' => ['source' => 'Min. cell voltage', 'name' => 'Min Cell Voltage', 'unit' => 'V', 'device_class' => 'voltage', 'state_class' => 'measurement'],
        'max-cell-temperature' => ['source' => 'Max cell temp', 'name' => 'Max Cell Temperature', 'unit' => '°C', 'device_class' => 'temperature', 'state_class' => 'measurement'],
        'min-cell-temperature' => ['source' => 'Min cell temp', 'name' => 'Min Cell Temperature', 'unit' => '°C', 'device_class' => 'temperature', 'state_class' => 'measurement'],
        'bmu-temperature' => ['source' => 'BMU TEMP', 'name' => 'BMU Temperature', 'unit' => '°C', 'device_class' => 'temperature', 'state_class' => 'measurement'],
        'total-charged-energy' => ['source' => 'Total Charged Energy', 'name' => 'Total Charged Energy', 'unit' => 'kWh', 'device_class' => 'energy', 'state_class' => 'total_increasing'],
        'total-discharged-energy' => ['source' => 'Total Discharged Energy', 'name' => 'Total Discharged Energy', 'unit' => 'kWh', 'device_class' => 'energy', 'state_class' => 'total_increasing'],
        'errors' => ['source' => 'Errors', 'name' => 'Errors', 'unit' => null, 'device_class' => null, 'state_class' => null],
    ];

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
        $this->connect();
        try {
            foreach (self::SENSORS as $slug => $sensor) {
                $this->mqtt->publish(
                    sprintf(self::CONFIG_TOPIC, $slug),
                    $this->autodiscoveryPayload($slug, $sensor),
                    0,
                    true
                );
            }
        } catch (\Throwable $exception) {
            $this->logger->error('Failed to write autodiscovery', ['exception' => $exception]);
        }
        $this->disconnect();
    }

    /**
     * Takes the whole DataProvider payload rather than a scalar per sensor -- at fourteen sensors a
     * positional signature stops being readable, and every caller had the full array anyway.
     *
     * @param array<string, float|bool|string> $data
     */
    public function updateState(array $data): void
    {
        $this->connect();
        try {
            foreach (self::SENSORS as $slug => $sensor) {
                if (!array_key_exists($sensor['source'], $data)) {
                    continue;
                }

                $this->mqtt->publish(
                    sprintf(self::STATE_TOPIC, $slug),
                    mb_substr((string) $data[$sensor['source']], 0, self::MAX_STATE_LENGTH)
                );
            }
        } catch (\Throwable $exception) {
            $this->logger->error('Failed to update state', ['exception' => $exception]);
        }

        $this->disconnect();
    }

    /**
     * @param array{source: string, name: string, unit: ?string, device_class: ?string, state_class: ?string} $sensor
     */
    private function autodiscoveryPayload(string $slug, array $sensor): string
    {
        $payload = [
            'name' => 'BYD Battery ' . $sensor['name'],
            'unique_id' => 'byd-battery-' . $slug,
            'state_topic' => sprintf(self::STATE_TOPIC, $slug),
        ];

        foreach (['unit_of_measurement' => 'unit', 'device_class' => 'device_class', 'state_class' => 'state_class'] as $field => $key) {
            if ($sensor[$key] !== null) {
                $payload[$field] = $sensor[$key];
            }
        }

        // Only one payload carries the full device metadata; the rest repeat the identifier so Home
        // Assistant groups every sensor onto the same device.
        $payload['device'] = $slug === self::DEVICE_SENSOR
            ? [
                'identifiers' => 'byd-battery',
                'name' => 'BYD Battery',
                'model' => 'HVS',
                'manufacturer' => 'BYD',
            ]
            : ['identifiers' => 'byd-battery'];

        return json_encode($payload, JSON_THROW_ON_ERROR);
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