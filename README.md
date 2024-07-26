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
```