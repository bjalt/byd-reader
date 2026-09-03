# BYD Battery-Box Premium — Modbus interface reference

Device-level reference for the Modbus interface exposed by the BYD Battery-Box Premium BMU. This documents the
*device*, not this project; for what `byd-reader` actually reads today and what is worth adding, see
[register-coverage.md](register-coverage.md).

**There is no vendor documentation for this interface.** Everything here is reverse-engineered by third parties.
Each block below carries a provenance note saying how well corroborated it is. See [References](#references).

## Transport

| Property | Value |
| --- | --- |
| Host | `192.168.16.254` (fixed, on the battery's own Ethernet interface) |
| Port | `8080` |
| Framing | **Modbus RTU over a raw TCP socket** — *not* Modbus/TCP |
| Unit ID | `1` |
| Functions used | FC3 (read holding registers), FC16 (write multiple registers) |

The RTU-over-TCP framing is the non-obvious part: frames carry a CRC16 and no MBAP header, exactly as on a serial
line, but the byte stream is carried over TCP. Libraries that speak Modbus/TCP natively need their framer swapped
(pymodbus: `ModbusRtuFramer`) or the packets converted by hand (this project: `RtuConverter::toRtu`/`fromRtu`).

The battery uses a fixed address on its own `192.168.16.0/24` subnet, so reaching it from a home LAN needs a static
route to `192.168.16.0/24` via whatever DHCP address the box's Wi-Fi/Ethernet module picked up. Sarnau's README has
per-router instructions for FRITZ!Box and Ubiquiti.

> ⚠️ **Only one client may talk to the box at a time.** A running poller will make BYD's own *Be Connect Plus*
> configuration tool fail to connect, and vice versa. Plan for this before adding a second consumer.

## Address ranges

Ranges that respond to FC3 without an exception. Everything else errors.

| Start | End | Contents | Decoded? |
| --- | --- | --- | --- |
| `0x0000` | `0x0066` | Hardware info and configuration | mostly |
| `0x0100` | `0x01FF` | unknown | no |
| `0x0400` | `0x0408` | unknown | no |
| `0x0500` | `0x0518` | Current status | mostly |
| `0x0550` | `0x0557` | BMS info request/response window | yes (handshake) |
| `0x05A0` | `0x05A7` | BMU/BMS event history request/response window | yes (handshake) |
| `0x05F0` | `0x0639` | unknown — probably firmware-update related | no |
| `0x0640` | `0x0689` | unknown | no |

> ⚠️ **Never write to `0x0010`–`0x0012`.** That is hardware configuration; sarnau warns that changing it may damage
> the unit. The only writes documented as safe are the request handshakes at `0x0550` and `0x05A0`.

---

## Block `0x0000` — hardware info and configuration

Static. Read once at startup; nothing here changes between polls.

| Register(s) | Field | Encoding |
| --- | --- | --- |
| `0x0000`–`0x0008` | BMU serial number | 9 registers = **18 bytes ASCII**, big-endian within each register |
| `0x000C` | BMU firmware version, area A | high byte `.` low byte |
| `0x000D` | BMU firmware version, area B | high byte `.` low byte |
| `0x000E` | BMS firmware version | high byte `.` low byte |
| `0x000F` | Active firmware area | high byte = BMU area, low byte = BMS area; `1` → `A`, otherwise `B` |
| `0x0010` | Pack topology + inverter | low nibble = module count; bits 4–7 = tower/BMS count; **high byte = inverter model id** |
| `0x0011` | Application + model | high byte = application id; low byte = battery model id |
| `0x0012` | Phase | high byte = phase id |
| `0x004B` | Address | raw |
| `0x004C` | BMU MCU type | raw |
| `0x004D` | BMS MCU type | raw |
| `0x0063` | Date | high byte = year − 2000, low byte = month |
| `0x0064` | Date/time | high byte = day, low byte = hour |
| `0x0065` | Time | high byte = minute, low byte = second |

Notes:

- The serial number is **18 bytes, i.e. 9 registers** (`0x0000`–`0x0008`). It is easy to misread the reference
  implementations here, because sarnau's helper takes a *byte* count (`readRegBytes(0, 18)`), not a register count.
- The **serial prefix selects the model family**, which in turn selects how to read the model id in `0x0011`:
  prefix `P03` or `E0P3` → HV family, modules in series, model list `['HVL','HVM','HVS']`; prefix `P02` or `P011`
  → LV family, modules in parallel, model list `['LVL','LVFlex(Lite)','LVS/LVS Lite']`.
- **Inverter id width is contested.** `byd_battery_box` reads the full high byte of `0x0010` (0–255); sarnau masks
  it to 4 bits (`(reg >> 8) & 0x0F`). Since the inverter table has 32 entries, the full byte is almost certainly
  correct and sarnau's mask is a latent bug.
- The date/time registers appear **unused** — sarnau notes Be Connect Plus just displays the host computer's clock.

### Enumerations

**Application** (`0x0011` high byte): `0` Off Grid · `1` On Grid · `2` Backup

**Phase** (`0x0012` high byte): `0` Single · `1` Three

**Inverter model** (`0x0010` high byte), in index order:

```
 0 Fronius HV            8 SUNTECH LV          16 SMA SBS2.5 HV        24 Raion LV
 1 Goodwe HV/Viessmann   9 Sungrow HV          17 Solis LV             25 KACO_NH
 2 Goodwe LV/Viessmann  10 KACO_HV             18 Solis HV             26 Solplanet
 3 KOSTAL HV            11 Studer LV           19 SMA STP 5.0-10.0 SE  27 Western HV
 4 Selectronic LV       12 SolarEdge LV        20 Deye LV              28 SOSEN
 5 SMA SBS3.7/5.0/6.0   13 Ingeteam HV         21 Phocos LV            29 Hoymiles LV
 6 SMA LV               14 Sungrow LV          22 GE HV                30 Hoymiles HV
 7 Victron LV           15 Schneider LV        23 Deye HV              31 SAJ HV
```

Not every inverter is valid for every battery model; sarnau's source lists the permitted combinations per model.

### Module specifications

Used to derive nameplate capacity as `towers × modules × capacity`.

| Model | kWh per module | Cells per module | Temp sensors per module |
| --- | --- | --- | --- |
| HVS | 2.56 | 32 | 12 |
| HVM | 2.76 | 16 | 8 |
| HVL | 4.00 | — | — |
| LVS | 4.00 | 16 | 8 |
| LVL | 8.68 | — | — |

*Provenance: `byd_battery_box`. The cell counts matter for parsing the per-cell block — see the caveat there.*

---

## Block `0x0500` — current status

The live telemetry block. 25 registers are readable (`0x0500`–`0x0518`); 21 (`0x0500`–`0x0514`) are decoded.

| Register(s) | Field | Type | Scale | Unit |
| --- | --- | --- | --- | --- |
| `0x0500` | State of charge | uint16 | 1 | % |
| `0x0501` | Max cell voltage | uint16 | ×0.01 | V |
| `0x0502` | Min cell voltage | uint16 | ×0.01 | V |
| `0x0503` | State of health | uint16 | 1 | % |
| `0x0504` | Current | **int16 (signed)** | ×0.1 | A |
| `0x0505` | Battery voltage | uint16 | ×0.01 | V |
| `0x0506` | Max cell temperature | int16 | 1 | °C |
| `0x0507` | Min cell temperature | int16 | 1 | °C |
| `0x0508` | BMU temperature | int16 | 1 | °C |
| `0x0509` | *unknown* | — | — | observed `0x0000` |
| `0x050A` | BMU firmware version | 2×uint8 | high `.` low | observed `0x031A` → V3.26 |
| `0x050B` | *unknown* | — | — | observed `0x0000` |
| `0x050C` | *unknown* | — | — | observed `0x0000` |
| `0x050D` | **Error bitmask** | uint16 | bitfield | see below; observed `0x0000` (no faults) |
| `0x050E` | Parameter table version | 2×uint8 | high `.` low | observed `0x0902` → v9.2 |
| `0x050F` | *unknown* | — | — | observed `0x0302`; version-shaped, but unconfirmed |
| `0x0510` | Output voltage | uint16 | ×0.01 | V |
| `0x0511`+`0x0512` | **Total charged energy** | uint32, low word first | ×0.1 | kWh |
| `0x0513`+`0x0514` | **Total discharged energy** | uint32, low word first | ×0.1 | kWh |
| `0x0515`–`0x0518` | *unknown / undecoded* | — | — | |

Derived values used by the reference implementations:

- `power = current × output_voltage` (W) — note both references use **output** voltage (`0x0510`), not battery
  voltage (`0x0505`). Sign of the current gives charge/discharge direction.
- `efficiency = discharge_total / charge_total × 100` (%) — round-trip efficiency.

> ⚠️ **`0x0511`/`0x0513` are 32-bit energy counters, not cycle counts.** Reading only the low word yields a value
> that wraps every 6553.5 kWh. Sarnau's script labels them "Charge Cycles"/"Discharge Cycles" and prints the low
> word unscaled; this appears to be a mislabelling in that source, and it has propagated into downstream projects.
> Two independent implementations read them as 32-bit totals in 0.1 kWh, and a first-hand read confirms it — see
> [Reference unit](#reference-unit) and [Provenance](#provenance-and-confidence).

### Reference unit

Values below were read first-hand on 2026-09-03 from the battery this project targets: an **HVS running BMU
firmware V3.26**, idle at 64 % SoC. They are one sample from one unit, not a specification — but they are the only
first-hand figures in this document, and they corroborate the third-party decodes.

A single FC3 request for `0x0500`, quantity 21, returned a 42-byte payload — so the whole decoded span is readable
in one round trip, without the address splitter chunking it.

```
01 03 2a 0040 0147 0147 0060 0007 7AE4 001B 001A 001A 0000 031A 0000
         0000 0000 0902 0302 7AB2 62B5 0000 58D4 0000 <crc>
```

| Register | Raw | Decoded |
| --- | --- | --- |
| `0x0500` | `0x0040` | 64 % state of charge |
| `0x0501` / `0x0502` | `0x0147` / `0x0147` | 3.27 V / 3.27 V — cells balanced |
| `0x0503` | `0x0060` | 96 % state of health |
| `0x0504` | `0x0007` | 0.7 A |
| `0x0505` | `0x7AE4` | 314.6 V battery |
| `0x0506`–`0x0508` | `0x001B` `0x001A` `0x001A` | 27 / 26 / 26 °C |
| `0x050A` | `0x031A` | BMU firmware V3.26 |
| `0x050D` | `0x0000` | no faults |
| `0x050E` | `0x0902` | parameter table v9.2 |
| `0x0510` | `0x7AB2` | 314.1 V output |
| `0x0511`+`0x0512` | `0x62B5` + `0x0000` | 25269 → **2526.9 kWh** charged |
| `0x0513`+`0x0514` | `0x58D4` + `0x0000` | 22740 → **2274.0 kWh** discharged |

**The energy totals decode as energy, confirmed.** Discharged ÷ charged = 2274.0 / 2526.9 = **exactly 90.0 %**, a
textbook round-trip efficiency for a home battery. Cycle counts would not produce that ratio, and reversing the
word order gives 165,602,918 kWh. This is the strongest available evidence that sarnau's "Cycles" label is wrong.

Note the high words are still `0x0000`: this unit has not yet passed 6553.5 kWh, so a 16-bit read of `0x0511`
alone still happens to return the correct value here. The wrap is a future failure, not a present one.

`0x0515`–`0x0518` were **not** read — the request stopped at `0x0514`, so this sample says nothing about them.

### `0x050D` — BMU error bitmask

`0` means no error. Each set bit is one fault:

| Bit | Meaning | Bit | Meaning |
| --- | --- | --- | --- |
| 0 | High temperature charging (cells) | 8 | Battery overvoltage |
| 1 | Low temperature charging (cells) | 9 | Battery undervoltage |
| 2 | Discharging overcurrent (cells) | 10 | Cell overvoltage |
| 3 | Charging overcurrent (cells) | 11 | Cell undervoltage |
| 4 | Main circuit failure | 12 | Voltage sensor failure |
| 5 | Short circuit | 13 | Temperature sensor failure |
| 6 | Cell imbalance | 14 | High temperature discharging (cells) |
| 7 | Current sensor error | 15 | Low temperature discharging (cells) |

---

## Block `0x0550` — BMS / per-cell detail

Not a plain read. This is a **request/response handshake requiring a write**, and it returns the per-cell data that
the BMU block only summarises.

### Protocol

1. **Write** (FC16) `[bms_id, 0x8100]` to `0x0550`. `bms_id` is the 1-based tower index (`0x0001` = BMS 1).
2. **Poll** `0x0551` until it reads `0x8801` (response ready). Sleep ~100 ms between polls.
3. **Read** 65 registers from `0x0558`, repeatedly. Each chunk's **first register is a length word** (always 128 =
   bytes in the payload) and must be skipped. `byd_battery_box` reads 4 chunks (260 registers, length words at
   indices 0, 65, 130, 195); sarnau reads 5.

### Payload layout

Offsets are indices into the concatenated 260-register array *including* the length words, as
`byd_battery_box` parses it. (Sarnau's indices are these minus one, because he strips each chunk's length word
before concatenating.)

| Offset | Field | Encoding |
| --- | --- | --- |
| 0, 65, 130, 195 | chunk length words | always 128 — skip |
| 1 | Max cell voltage | int16 ×0.001 V |
| 2 | Min cell voltage | int16 ×0.001 V |
| 3 | Cell ids for max/min voltage | high byte = max, low byte = min |
| 4 | Max cell temperature | int16 °C |
| 5 | Min cell temperature | int16 °C |
| 6 | Cell ids for max/min temperature | high byte = max, low byte = min |
| 7 … 7+n−1 | **Cell balancing flags**, one register per module | 16 bits = 16 cells; set bit = balancing |
| 15+16 | Charge total energy, this tower | uint32 low word first, ×0.001 kWh |
| 17+18 | Discharge total energy, this tower | uint32 low word first, ×0.001 kWh |
| 19 | SOC calibration | high byte |
| 20 | *unknown* | |
| 21 | Battery voltage | int16 ×0.1 V |
| 22 | *unknown* | observed `0` |
| 23 | Switch state (probable) | low byte; observed `1560` |
| 24 | Output voltage | int16 ×0.1 V |
| 25 | **State of charge** | int16 ×0.1 **%** — finer than the BMU's 1 % integer |
| 26 | State of health | int16 % |
| 27 | Current | int16 ×0.1 A |
| 28 | Warnings 1 | bitmask, see `BMS warnings` |
| 29 | Warnings 2 | bitmask, see `BMS warnings` |
| 30 | Warnings 3 | bitmask, see `BMS warnings 3` |
| 31 | BMS firmware version, area A | high `.` low |
| 32 | BMS firmware version, area B | high `.` low |
| 33 | Active firmware area | |
| 34–45 | *unknown* | observed values decode as printable ASCII — **probably a BMS serial number** (unverified) |
| 46 | Threshold table version A | high `.` low |
| 47 | Threshold table version B | high `.` low |
| 48 | **Errors** | bitmask, see `BMS errors` |
| 49–64, 66–129, 131–179 | **Per-cell voltages** | uint16, mV |
| 180–194, 196–212 | **Per-cell temperatures** | 2 × int8 per register, °C |

> ⚠️ **The per-cell block layout is only confirmed for 16-cell modules.** Both references index cell voltages with
> a stride of 16 registers per module (`base + m*16 + i`), which matches HVM/LVS. HVS modules have **32** cells, and
> `byd_battery_box` still uses a stride of 16 while looping over 32 cells — its own indexing appears inconsistent
> there. Anyone decoding this block on an HVS should verify the stride empirically before trusting per-cell values.

### BMS errors (offset 48)

| Bit | Meaning | Bit | Meaning |
| --- | --- | --- | --- |
| 0 | Cells voltage sensor failure | 8 | Main relay failure |
| 1 | Temperature sensor failure | 9 | Precharging failed |
| 2 | BIC communication failure | 10 | Heating device failure |
| 3 | Pack voltage sensor failure | 11 | Radiator failure |
| 4 | Current sensor failure | 12 | BIC balance failure |
| 5 | Charging MOS failure | 13 | Cells failure |
| 6 | Discharging MOS failure | 14 | PCB temperature sensor failure |
| 7 | Precharging MOS failure | 15 | Functional safety failure |

### BMS warnings (offsets 28 and 29 — same table for both)

| Bit | Meaning | Bit | Meaning |
| --- | --- | --- | --- |
| 0 | Battery overvoltage | 8 | Discharging low temperature (cells) |
| 1 | Battery undervoltage | 9 | Charging overcurrent (cells) |
| 2 | Cells overvoltage | 10 | Discharging overcurrent (cells) |
| 3 | Cells undervoltage | 11 | Charging overcurrent (hardware) |
| 4 | Cells imbalance | 12 | Short circuit |
| 5 | Charging high temperature (cells) | 13 | Inverse connection |
| 6 | Charging low temperature (cells) | 14 | Interlock switch abnormal |
| 7 | Discharging high temperature (cells) | 15 | Air switch abnormal |

### BMS warnings 3 (offset 30 — different table)

| Bit | Meaning | Bit | Meaning |
| --- | --- | --- | --- |
| 0 | Battery overvoltage | 8 | High temperature charging (cells) |
| 1 | Battery undervoltage | 9 | Low temperature charging (cells) |
| 2 | Cell overvoltage | 10 | Overcurrent discharging |
| 3 | Cell undervoltage | 11 | Overcurrent charging |
| 4 | Voltage sensor failure | 12 | Main circuit failure |
| 5 | Temperature sensor failure | 13 | Short circuit alarm |
| 6 | High temperature discharging (cells) | 14 | Cells imbalance |
| 7 | Low temperature discharging (cells) | 15 | Current sensor failure |

---

## Block `0x05A0` — BMU/BMS event history

Same handshake shape as `0x0550`, against a different window. Returns a timestamped event log.

### Protocol

1. **Write** (FC16) `[source_id, 0x8100]` to `0x05A0`, where `source_id` is `0x0000` for the BMU, `0x0001` for
   BMS 1, `0x0002` for BMS 2, and so on.
2. **Poll** `0x05A1` until it reads `0x8801`.
3. **Read** 65 registers from `0x05A8`, five times, skipping each chunk's leading length word (128).

### Entry layout

The payload is a flat byte array of **22 entries × 30 bytes**:

| Byte | Meaning |
| --- | --- |
| 0 | Event code (see tables below) |
| 1 | Year − 2000 |
| 2 | Month |
| 3 | Day |
| 4 | Hour |
| 5 | Minute |
| 6 | Second |
| 7–29 | Event payload, 23 bytes — meaning is event-specific and largely undecoded |

### BMU event codes

| Code | Meaning | Code | Meaning |
| --- | --- | --- | --- |
| `0x00` | Power ON | `0x67` | Firmware update failure |
| `0x01` | Power OFF | `0x68` | Firmware jumped into other section |
| `0x02` | Events record | `0x69` | Parameter table update |
| `0x04` | Start charging | `0x6A` | SN code changed |
| `0x05` | Stop charging | `0x6F` | Date/time calibration |
| `0x06` | Start discharging | `0x70` | BMS disconnected from BMU |
| `0x07` | Stop discharging | `0x71` | BMU firmware reset |
| `0x20` | System status changed | `0x72` | BMU watchdog reset |
| `0x21` | Erase BMS firmware | `0x73` | Precharge failed |
| `0x24` | Functional safety info | `0x74` | Address registration failed |
| `0x26` | SOP info | `0x75` | Parameter table load failed |
| `0x27` | BCU hardware fault | `0x76` | System timing log |
| `0x65` | Firmware start to update | `0x78` | Parameter table updating done |
| `0x66` | Firmware update successful | | |

### BMS event codes

| Code | Meaning | Code | Meaning |
| --- | --- | --- | --- |
| `0x00` | Power ON | `0x0F` | Precharge failure |
| `0x01` | Power OFF | `0x10` | Start end SOC calibration |
| `0x02` | Events record | `0x11` | Start balancing |
| `0x03` | Timing record | `0x12` | Stop balancing |
| `0x04` | Start charging | `0x13` | Address registered |
| `0x05` | Stop charging | `0x14` | System functional safety fault |
| `0x06` | Start discharging | `0x15` | Events additional info |
| `0x07` | Stop discharging | `0x65` | Start firmware update |
| `0x08` | SOC calibration rough | `0x66` | Firmware update finish |
| `0x09` | SOC calibration fine | `0x67` | Firmware update fails |
| `0x0A` | SOC calibration stop | `0x68` | Firmware jumped into other section |
| `0x0B` | CAN communication failed | `0x69` | Parameter table update |
| `0x0C` | Serial communication failed | `0x6A` | SN code changed |
| `0x0D` | Receive precharge command | `0x6B` | Current calibration |
| `0x0E` | Precharge successful | `0x6C` | Battery voltage calibration |
| | | `0x6D` | Pack voltage calibration |
| | | `0x6E` | SOC/SOH calibration |
| | | `0x6F` | Date/time calibration |

---

## Operational caveats

- **Single client only.** Concurrent access from a second application produces failures or garbage.
- **Do not write outside the documented handshake registers.** `0x0010`–`0x0012` in particular can brick the unit.
- **Polling cadence.** `byd_battery_box` refreshes the `0x0500` block every 30 s, but the per-cell (`0x0550`) and
  history (`0x05A0`) blocks only every 10 minutes — those are ~260 and ~325 registers per read plus a handshake
  poll loop, so they are much more expensive than the status block.
- **The handshake blocks require FC16 writes.** Any client that only implements FC3 cannot reach them at all.
- **Be Connect Plus credentials** are stored trivially obfuscated in its `Config.ini`; sarnau's README documents the
  decoding and the resulting accounts. Not reproduced here — see the source if you need it.

## Provenance and confidence

Three independent implementations were compared while writing this. Two speak this Modbus interface; the third
speaks BYD's *proprietary* Be Connect protocol, so where it agrees on a field's meaning that is genuinely
independent corroboration rather than a shared upstream assumption.

| Claim | Corroboration | Confidence |
| --- | --- | --- |
| Transport, unit id, address ranges | sarnau, `byd_battery_box` | high |
| `0x0500`–`0x0508`, `0x0510` scaling | sarnau, `byd_battery_box`, **plus a first-hand read** | high — values are physically plausible on a real unit |
| `0x0511`/`0x0513` are 32-bit energy totals in 0.1 kWh, **not cycle counts** | `byd_battery_box` (Modbus) **and** `python-bydhvs` (proprietary protocol), **plus a first-hand read** yielding exactly 90.0 % round-trip efficiency | high — sarnau's "Cycles" label is the outlier |
| `0x050D` error bit meanings | `byd_battery_box` only; a first-hand read confirms the register exists and reads `0` on a healthy pack, but **no fault has been observed**, so the bit-to-name mapping is still untested | medium |
| `0x050E` is a version pair, `0x0509`/`0x050B`/`0x050C` read zero | sarnau, `byd_battery_box`, first-hand read | medium-high |
| `0x0500` block hardware/config decode | sarnau, `byd_battery_box` (differ on inverter id width) | medium-high |
| `0x0550` handshake and field offsets | sarnau, `byd_battery_box` (differ on chunk count) | medium-high |
| Per-cell stride on **HVS** (32-cell) modules | neither source is self-consistent | **low — verify before use** |
| Offsets 34–45 as a BMS serial number | inferred from observed ASCII, unconfirmed | low |
| `0x05A0` history layout and event codes | sarnau; `byd_battery_box` ships a real captured log | medium-high |
| `0x0100`, `0x0400`, `0x05F0`, `0x0640` blocks | nobody has decoded these | unknown |

## References

- [sarnau/BYD-Battery-Box-Infos](https://github.com/sarnau/BYD-Battery-Box-Infos) — the original reverse-engineering
  write-up: address ranges, network setup, `Read_Modbus.py` covering all four decoded blocks.
- [redpomodoro/byd_battery_box](https://github.com/redpomodoro/byd_battery_box) — Home Assistant custom component
  over the same transport; the fullest decode, and the source for the bitmask label tables and module specs.
  See `custom_components/byd_battery_box/bydboxclient.py` and `bydbox_const.py`.
- [bbr111/python-bydhvs](https://github.com/bbr111/python-bydhvs) — implements BYD's proprietary Be Connect
  protocol rather than Modbus; used here as independent confirmation of the energy-total fields.
- [christianh17/ioBroker.bydhvs](https://github.com/christianh17/ioBroker.bydhvs) — ioBroker adapter, also on the
  proprietary protocol; `docs/byd-hexstructure.md` documents the packet structure.

*Interfaces reverse-engineered without vendor documentation can change with a firmware update. Re-verify against a
real device before relying on anything here.*
