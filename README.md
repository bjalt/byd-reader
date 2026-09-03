# Byd HVS Reader

This will read a Byd HVS via Modbus RTU and publish the data to a MQTT broker. Additionally, it will publish an
autoconfiguration message for Home Assistant to display the sensor data. This requires a Home Assistant instance with
MQTT integration enabled.

This project is based on the prior work of [BYD-Battery-Box-Infos](https://github.com/sarnau/BYD-Battery-Box-Infos)

## Configuration

```dotenv
MQTT_HOST=
MQTT_PORT=
MQTT_USERNAME=
MQTT_PASSWORD=
# Enable (1) / Disable (0) exporting data to csv file ./data.csv
CSV_EXPORT=
# Time in seconds to wait between reading the data from the HVS
INTERVAL=5
```

## Tests

```bash
composer install
./run-tests.sh
```

`run-tests.sh` goes through the Symfony CLI (`symfony php`) when it is available, so PHPUnit runs on the PHP version
named in `.php-version` rather than whatever `php` happens to be first in `PATH`.

The suite covers construction of the three classes and the zeroed payload `DataProvider` falls back to when the
battery cannot be reached. Nothing in it talks to a real gateway or broker, so a passing run is not evidence that a
change works against real hardware — verify that by running `php bin/console app:read` against a reachable gateway
and MQTT broker.
