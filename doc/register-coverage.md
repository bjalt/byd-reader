# Register coverage — what `byd-reader` reads, and what it could

Gap analysis between the device interface documented in
[byd-modbus-interface.md](byd-modbus-interface.md) and what this daemon actually does today. Nothing here has been
implemented; this is a decision record, not a changelog.

The register map lives entirely in `DataProvider::configureRequest()` (`src/Byd/DataProvider.php`).

## Today

One FC3 request covering `0x0500`–`0x0513` (20 registers), 12 named values plus a derived `power`.

| Register | Key in payload | Published to MQTT? |
| --- | --- | --- |
| `0x0500` | `State of Charge` | ✅ |
| `0x0501` | `Max. cell voltage` | ❌ read, printed, dropped |
| `0x0502` | `Min. cell voltage` | ❌ read, printed, dropped |
| `0x0503` | `State of Health` | ❌ read, printed, dropped |
| `0x0504` | `Current` | ✅ |
| `0x0505` | `Battery Voltage` | ✅ |
| `0x0506` | `Max cell temp` | ❌ read, printed, dropped |
| `0x0507` | `Min cell temp` | ❌ read, printed, dropped |
| `0x0508` | `BMU TEMP` | ❌ read, printed, dropped |
| `0x0510` | `Output Voltage` | ❌ read, printed, dropped |
| `0x0511` | `Charge Cycles` | ❌ read, printed, dropped |
| `0x0513` | `Discharge Cycles` | ❌ read, printed, dropped |
| *derived* | `power` = `Current × Battery Voltage` | ✅ |

So **8 of the 12 values already read never leave the process.** `MqttHandler::updateState()` takes four scalars and
publishes four topics.

## Two defects in what is already read

### 1. `Charge Cycles` / `Discharge Cycles` are misread — correctness bug

They are not cycle counts. `0x0511`+`0x0512` and `0x0513`+`0x0514` are 32-bit totals of **charged and discharged
energy in units of 0.1 kWh**. The current code reads only the **low word** of each.

Consequences:

- The name is wrong, so anything downstream (the CSV, the console table) is mislabelled.
- The `/10` scaling is actually correct for kWh — but truncating to 16 bits means the value **wraps to zero every
  6553.5 kWh**, which for an HVS is on the order of once a year. A counter that silently restarts is worse than one
  that is absent, especially if it ever becomes a Home Assistant `TOTAL_INCREASING` sensor.

Fix shape: read as `uint32` across the register pairs. The `aldas` library's default endianness is already
`BIG_ENDIAN_LOW_WORD_FIRST` (`vendor/aldas/modbus-tcp-client/src/Utils/Endian.php:48`), which is exactly the low-word-first
order this device uses — so `->uint32(0x0511, ...)` needs no endian configuration. Extending the read to `0x0514`
makes the request 21 registers, still one request, still far below the 124-register split threshold.

### 2. `power` uses a different voltage than both reference implementations

`ReadCommand` publishes `power` computed as `Current × Battery Voltage` (`0x0505`, the internal cell-stack voltage).
Both sarnau and `byd_battery_box` compute it as `Current × Output Voltage` (`0x0510`, the pack terminal voltage).
Under load these diverge.

This is a judgement call rather than an outright bug — but it is worth making deliberately, since `0x0510` is
already being read and discarded.

## Candidate additions, cheapest first

### Tier 1 — same request, no extra round trip

Extending the existing builder to `0x0514` costs nothing at runtime.

| Register | Field | Why |
| --- | --- | --- |
| `0x050D` | **BMU error bitmask** | 16 named faults. Today a battery fault is completely invisible — the daemon reports a healthy-looking SoC while the BMU flags an error. Highest value per register in the whole map. |
| `0x0511`–`0x0514` | Total charge / discharge energy | fixes defect 1 above |
| `0x050A` | BMU firmware version | diagnostic |
| `0x050E` | Parameter table version | diagnostic |
| *derived* | Round-trip efficiency | `discharge_total / charge_total × 100` |

### Tier 2 — publish what is already read

No Modbus change at all: wire the 8 dropped values through to MQTT. Per `CLAUDE.md`, each new Home Assistant sensor
is three coordinated edits — a config payload in `writeAutodiscovery()`, a publish in `updateState()`, and a new
parameter on the `updateState()` signature plus its caller in `ReadCommand::readAndWriteToFile()`. The current
four-positional-scalar signature will get unwieldy at twelve; passing the payload array is probably the better shape.

### Tier 3 — static hardware info (`0x0000` block), read once

Serial number, model, firmware versions, tower/module counts, configured inverter, application mode, and derived
nameplate capacity. Natural fit for Home Assistant **device** metadata rather than per-interval sensors.

> ⚠️ **This one hits a latent bug already documented in `CLAUDE.md`.** Adding these addresses to the existing
> `ReadRegistersBuilder` spans `0x0000`→`0x0514` = 1301 registers. `AddressSplitter` chunks purely on span
> (`AddressSplitter.php:19`, `MAX_REGISTERS_PER_MODBUS_REQUEST = 124`), so it emits ~11 requests — and `getData()`
> **assigns** `$readData` inside the per-request loop instead of merging, so only the last chunk survives. Any second
> address block needs either the merge fixed or a separate builder.

### Tier 4 — per-cell detail (`0x0550` block)

Individual cell voltages and temperatures, per-cell balancing flags, SoC at 0.1 % resolution, per-tower energy
totals, and the BMS warning/error bitmasks. See the interface reference for the full payload layout.

Structural change, not an increment:

- Requires **FC16 writes**, which this daemon does not do at all today.
- Requires a handshake poll loop against `0x0551`.
- ~260 registers per tower per read. `byd_battery_box` polls this every 10 minutes against 30 s for the status block.
- The per-cell stride is **unverified for HVS 32-cell modules** — see the caveat in the interface reference.

### Tier 5 — event history (`0x05A0` block)

Timestamped BMU/BMS event log: power on/off, charge/discharge transitions, precharge failures, BMS disconnects,
watchdog resets, firmware updates. Same handshake cost as tier 4. Genuinely useful for post-mortems, awkward to map
onto Home Assistant sensors — better suited to a log line or a separate command than to MQTT state topics.

## Cross-cutting concerns

- **`catch (Exception)` will not save you.** `CLAUDE.md` already notes that `parse()` can return an `ErrorResponse`,
  on which the next line raises a PHP `Error` — not an `Exception` — killing the daemon. Every tier above adds
  address ranges that are more likely to produce an error response, so this gap gets sharper as coverage grows.
  Worth closing *before* tier 3.
- **The all-zero fallback array is hand-maintained.** `getData()`'s `catch` block lists all twelve keys literally
  (`src/Byd/DataProvider.php:54-68`). Every new reading must be added there too, or the error path returns a payload
  with a different shape than the success path — which
  `DataProviderTest::testGetDataFallsBackToZeroesWhenTheGatewayIsUnreachable` asserts against exactly.
- **Single-client constraint.** The battery accepts one connection at a time. Longer or more frequent reads widen
  the window in which BYD's Be Connect Plus tool cannot connect.
- **Do not add `declare(strict_types=1)`** to `DataProvider.php` while extending it — see `CLAUDE.md` for why the
  coercive-mode behaviour is load-bearing on the read path.

## Suggested order

1. Fix `0x0511`/`0x0513` to `uint32` and rename to total charge/discharge energy — a correctness fix, not a feature.
2. Add `0x050D` error bitmask. One register, highest diagnostic value in the map.
3. Publish the 8 already-read-but-unpublished metrics (tier 2).
4. Harden the error path (`ErrorResponse` → PHP `Error`), then tier 3.
5. Tiers 4 and 5 only if cell-level visibility or event forensics are actually wanted.

Steps 1 and 2 are the same request and can land together.
