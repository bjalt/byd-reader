# Register coverage — what `byd-reader` reads, and what it could

Gap analysis between the device interface documented in
[byd-modbus-interface.md](byd-modbus-interface.md) and what this daemon actually does.

The register map lives entirely in `DataProvider::configureRequest()` (`src/Byd/DataProvider.php`); the Home
Assistant sensor list lives in `MqttHandler::SENSORS` (`src/Mqtt/MqttHandler.php`).

## Today

One FC3 request covering `0x0500`–`0x0514` (21 registers), 13 named values plus two derived ones. Everything read
now reaches Home Assistant in some form.

| Register(s) | Key in payload | Published as |
| --- | --- | --- |
| `0x0500` | `State of Charge` | `state-of-charge` |
| `0x0501` | `Max. cell voltage` | `max-cell-voltage` |
| `0x0502` | `Min. cell voltage` | `min-cell-voltage` |
| `0x0503` | `State of Health` | `state-of-health` |
| `0x0504` | `Current` | `current` |
| `0x0505` | `Battery Voltage` | `voltage` |
| `0x0506` | `Max cell temp` | `max-cell-temperature` |
| `0x0507` | `Min cell temp` | `min-cell-temperature` |
| `0x0508` | `BMU TEMP` | `bmu-temperature` |
| `0x050D` | `Error Bitmask` | *raw value kept in the payload and CSV; published decoded, see below* |
| `0x0510` | `Output Voltage` | `output-voltage` |
| `0x0511`+`0x0512` | `Total Charged Energy` | `total-charged-energy` |
| `0x0513`+`0x0514` | `Total Discharged Energy` | `total-discharged-energy` |
| *derived* | `power` = `Current × Output Voltage` | `power` |
| *derived* | `Errors` = `describeErrors(Error Bitmask)` | `errors` |

14 Home Assistant sensors. The raw bitmask stays in the payload because it is compact and stable in the CSV
history; the string is what Home Assistant displays.

## Resolved

**`Charge Cycles` / `Discharge Cycles` were misread.** They were never cycle counts — `0x0511`+`0x0512` and
`0x0513`+`0x0514` are 32-bit totals of charged and discharged energy in units of 0.1 kWh, and the code read only
the low word of each, so the value wrapped to zero every 6553.5 kWh. Now read as `uint32` pairs and renamed. The
`aldas` default endianness (`BIG_ENDIAN_LOW_WORD_FIRST`) already matches the device's word order, so no `$endian`
argument is needed — verified against a synthetic response, not just read off the docs.

**The BMU error bitmask is now read and published.** `0x050D` was the single highest-value register not being
read: a battery fault used to be entirely invisible while the daemon reported a healthy-looking SoC.

**`power` now uses output voltage.** It was computed as `Current × Battery Voltage` (`0x0505`, the internal
cell-stack voltage); it is now `Current × Output Voltage` (`0x0510`, the pack terminal voltage), matching sarnau and
`byd_battery_box`. The two diverge under load, so historical values in Home Assistant sit on a slightly different
basis than everything recorded from this change onward. On the reference unit at 0.7 A the difference was
220.22 W vs 219.87 W.

## Candidate additions

### Static hardware info (`0x0000` block), read once

Serial number, model, firmware versions, tower/module counts, configured inverter, application mode, and derived
nameplate capacity. Natural fit for Home Assistant **device** metadata rather than per-interval sensors.

> ⚠️ **This hits a latent bug documented below.** Adding these addresses to the existing `ReadRegistersBuilder`
> spans `0x0000`→`0x0514` = 1301 registers. `AddressSplitter` chunks purely on span (`AddressSplitter.php:19`,
> `MAX_REGISTERS_PER_MODBUS_REQUEST = 124`), so it emits ~11 requests — and `getData()` **assigns** `$readData`
> inside the per-request loop instead of merging, so only the last chunk survives. A second address block needs
> either the merge fixed or a separate builder.

### Per-cell detail (`0x0550` block)

Individual cell voltages and temperatures, per-cell balancing flags, SoC at 0.1 % resolution, per-tower energy
totals, and the BMS warning/error bitmasks. See the interface reference for the full payload layout.

Structural change, not an increment:

- Requires **FC16 writes**, which this daemon does not do at all today.
- Requires a handshake poll loop against `0x0551`.
- ~260 registers per tower per read. `byd_battery_box` polls this every 10 minutes against 30 s for the status block.
- The per-cell stride is **unverified for HVS 32-cell modules** — see the caveat in the interface reference.

### Event history (`0x05A0` block)

Timestamped BMU/BMS event log: power on/off, charge/discharge transitions, precharge failures, BMS disconnects,
watchdog resets, firmware updates. Same handshake cost as the block above. Genuinely useful for post-mortems,
awkward to map onto Home Assistant sensors — better suited to a log line or a separate command than to MQTT state
topics.

### Undecoded ranges

`0x0100`, `0x0400`, `0x05F0` and `0x0640` all respond to reads, and nobody has published a decode. Anything found
there would be new information for every project listed in the references, not just this one.

## Cross-cutting concerns

- **`catch (Exception)` will not save you.** `parse()` can return an `ErrorResponse`, on which the next line raises
  a PHP `Error` — not an `Exception` — killing the daemon. Every candidate above adds address ranges that are more
  likely to produce an error response, so this gap gets sharper as coverage grows. Worth closing *before* the
  `0x0000` block.
- **The all-zero fallback array is hand-maintained.** `getData()`'s `catch` block lists every metric key literally.
  A new reading must be added there too, or the error path returns a payload with a different shape than the
  success path — which `DataProviderTest::testGetDataFallsBackToZeroesWhenTheGatewayIsUnreachable` asserts against
  exactly, and will fail on.
- **CSV column count is not stable across versions.** `ReadCommand` splats the whole payload into `data.csv`, which
  has no header row. Every reading added shifts the columns of subsequent rows relative to older ones.
- **Sensor slugs are load-bearing.** A slug feeds the `unique_id`; renaming one orphans the Home Assistant entity
  and creates a duplicate beside it.
- **Single-client constraint.** The battery accepts one connection at a time. Longer or more frequent reads widen
  the window in which BYD's Be Connect Plus tool cannot connect.
- **Do not add `declare(strict_types=1)`** to `DataProvider.php` while extending it — see `CLAUDE.md` for why the
  coercive-mode behaviour is load-bearing on the read path.

## Suggested order from here

1. Harden the error path (`ErrorResponse` → PHP `Error`), then add the static hardware block.
2. Per-cell detail and event history only if cell-level visibility or event forensics are actually wanted.
