# NRG-Stack Börsenpreis (exchange price)

![Symcon](https://img.shields.io/badge/Symcon-PHPModule-blue)
![Module version](https://img.shields.io/badge/Module_version-0.10.0-blue)
![Symcon version](https://img.shields.io/badge/Symcon_version-9.0%2B-blue)
![License](https://img.shields.io/badge/License-PolyForm_Noncommercial_1.0.0-lightgrey)
[![Check Style](https://github.com/DG65/NRGBoersenpreis/actions/workflows/check-style.yml/badge.svg)](https://github.com/DG65/NRGBoersenpreis/actions/workflows/check-style.yml)
[![PayPal](https://img.shields.io/badge/PayPal-Me-blue?logo=paypal)](https://paypal.me/DietmarGureth)

*Deutsche Fassung: [README.md](README.md)*

**Day-ahead exchange prices for Symcon — no account, no particular electricity supplier.**
The module fetches the prices of the day-ahead auction for today and tomorrow (per quarter
hour, bidding zone Germany/Luxembourg or Austria) and provides them as variables, to your own
scripts and to other NRG-Stack modules.

## What is it for?

Some rules depend on the **exchange price**, not on your own tariff. The most important
example: for PV systems in Germany commissioned on or after 25 February 2025
(„Solarspitzengesetz“), the feed-in tariff is dropped for every quarter hour with a negative
day-ahead exchange price (§ 51 EEG). So even with a fixed tariff you need the exchange prices —
so that the [NRG-Stack EMS](https://github.com/DG65/NRGEMS), for example, can spot exactly those
quarter hours and route the energy into the battery, the heat pump or the car instead of the
grid.

This is not legal advice: which rules apply to a particular system depends on the commissioning
date, among other things — the EMS classifies that from its plant data, this module only
provides the prices.

## Sources

| Source | Resolution | Registration | Notes |
|---|---|---|---|
| **Energy-Charts** (Fraunhofer ISE), default | quarter hour | none | Licence CC BY 4.0, data Bundesnetzagentur \| SMARD.de. Limits requests per minute — the module usually asks only 1–5 times a day and respects every pause (HTTP 429 + `Retry-After`). |
| **EPEX Spot (via ENTSO-E)** | quarter hour | free access key | The day-ahead results of the EPEX Spot power exchange, published on the ENTSO-E Transparency Platform. Key: create an account at transparency.entsoe.eu, then request „Restful API access“ by e-mail ([guide](https://transparencyplatform.zendesk.com/hc/en-us/articles/12845911031188-How-to-get-security-token)). Directly from EPEX Spot the data is only available with a paid contract. |
| **Tibber price overview** | quarter hour | none, postal code only | Tibber's public price overview. The exchange price part is used for all values; in addition Tibber's retail price incl. VAT for that postal code — that is what the variable for the Symcon Energy Manager uses. Germany only. Not an officially documented interface; the postal code is sent to Tibber. |
| **aWATTar** | hour | none | Free within fair use. Hourly values are spread over four equal quarter hours; a single negative quarter hour can disappear in the hourly average. |

The ENTSO-E access key is stored as an attribute — never in the form, the log or in error
messages (Symcon does not encrypt attributes, but they live outside the configuration).

## Symcon Energy Manager

The variable **„Market data (Energy Manager)“** (ident `MarketData`) provides the prices in the
format of Symcon's „Strompreis“ module: `[{"start": unix, "end": unix, "price": ct/kWh}, …]`,
from the current quarter hour for up to 24 hours, rewritten every quarter hour. In the Symcon
Energy Manager select this variable under **„Energiepreise“**.

The price in it is your **retail price** per quarter hour; the first matching source wins:

1. **Tibber Grid Rewards installed** (can be switched off): your real Tibber price from there.
2. **Your own tariff** (panel „Symcon Energy Manager & tariff“): (exchange price + supplier
   surcharge + grid fee + concession levy + nationwide levies) × 1.19. The grid fee either as a
   flat energy rate or time-variable under **§ 14a module 3** — peak/standard/off-peak rate with
   the time windows from your grid operator, per window for all days, Mon–Fri or Sat–Sun, valid
   per calendar quarter. Levies included automatically (as of 06/2026: electricity tax 2.05,
   offshore grid levy 0.941, CHP levy 0.446, § 19 StromNEV levy 1.56 ct/kWh net).
3. Source **Tibber price overview**: Tibber's retail price for your postal code.
4. Otherwise the plain exchange price.

The wording matches the tariff breakdown of Tibber Grid Rewards (exchange price, procurement,
grid fee, taxes and levies). The tariff model only affects this variable; the contract and all
other variables stay the plain exchange price. The module does not know public holidays, they
count as their weekday.

## How the module works

- After creation it fetches immediately, then **only when something is missing**: tomorrow's
  prices are set in the auction at 12:00 and are usually available from about 12:45 (each
  instance has a fixed offset of up to 9 minutes). Until they arrive the module asks every
  15 minutes, afterwards not until the next day.
- Every quarter hour the variables are set from the cache — without a fetch.
- Errors (source unreachable, no data, rate limit) leave the existing prices untouched; they
  are shown in the form and logged once in the message log.
- All time stamps are real Unix time. Days with a daylight saving change have 92 or 100 quarter
  hours; nothing is derived from fixed day lengths.

## Variables

| Ident | Name | Type |
|---|---|---|
| `CurrentPrice` | Exchange price now | float, ct/kWh |
| `NegativeNow` | Negative exchange price now | bool (yes/no) |
| `NextNegativeStart` | Next negative quarter hour | integer, Unix time (0 = none known; the current one counts) |
| `TomorrowAvailable` | Prices for tomorrow published | bool (yes/no) |
| `MarketData` | Market data (Energy Manager) | string, JSON `[{start, end, price}]` — retail price for the Symcon Energy Manager, see above |

The look back (`SPOT_GetPriceHistory`, chart of past days in the NRG-Stack dashboard) comes from
the archive of „Exchange price now“. Archiving only happens when the checkbox **„Archive price
history“** in the panel „Exchange prices“ is ticked (default: off); the module then switches
archiving on once and never off again.

## Contract for scripts and other modules

```php
$curve = SPOT_GetPriceCurve($id);   // contractVersion '1.1'
// [[ 'start' => int (unix, inclusive), 'end' => int (unix, EXCLUSIVE),
//    'price' => float ct/kWh NET (plain exchange price, may be negative),
//    'basis' => 'spot', 'netzentgelt' => 'fehlt', 'level' => null,
//    'quelle' => 'energy-charts'|'entsoe'|'tibber'|'awattar',
//    'aufloesung' => 900|3600,          // seconds per original value
//    'contractVersion' => '1.1' ], …]
```

- Ascending by `start`, today + tomorrow (once published), quarter-hour grid.
- Same format as `TIBBERGR_GetPriceCurve` — a consumer treats both sources alike and tells them
  apart by `basis`: `spot` is the wholesale price, **not** the retail price (no taxes, levies or
  grid fees). It is not suitable on its own for comparing costs with your purchase price.
- `level` is always `null` — the classification (cheap/expensive) is up to the consumer.
- Gaps are allowed; a missing value is never returned as `0`.
- `aufloesung = 3600` means: spread from an hourly value, not genuinely quarter-hourly.
- The getter only reads the cache (with an empty cache it reloads once, rate limited).

```php
$past = SPOT_GetPriceHistory($id, strtotime('-7 days'), time());   // since 1.1
```

- The same entries for any period (`from` inclusive, `to` exclusive, rounded to quarter hours,
  at most 400 days).
- Past quarter hours come from the Symcon archive of „Exchange price now“ (`quelle = 'archiv'`).
  The archive only stores changes, so a value applies until the next one — at most 12 hours;
  longer gaps (for example while Symcon was off) stay empty.
- Whatever the cache knows (today, tomorrow) comes from there exactly.

```php
echo SPOT_Update($id);   // fetch immediately, result as text
```

The price curve with the negative quarter hours is shown by the
[NRG-Stack dashboard](https://github.com/DG65/NRGDashboard) in PV monitoring (tab „Strompreis“).
This module deliberately has no tile of its own.

The module requires no other module and is required by none.

## Languages

The configuration form, all messages and the variable names are available in German and English
(Symcon option „Locale“). German remains the reference wording; the English texts are in
`Boersenpreis/locale.json`.

## Checking without Symcon

```
php .tools/test-spot.php          # test bench against real, frozen source responses
php .tools/check-standalone.php   # no unguarded foreign call (function_exists guards)
```

Both also run in GitHub Actions CI (`check-style.yml`). The test bench covers negative prices,
a missing next day, rate limits, 23/25-hour days, spreading hourly values, both languages and
compatibility with the EMS price evaluation.

## Licence

PolyForm Noncommercial 1.0.0, see [LICENSE](LICENSE) — free for private and non-commercial use;
commercial use requires a separate licence from the rights holder (contact: dietmar@gureth.eu).
Donations welcome: [paypal.me/DietmarGureth](https://paypal.me/DietmarGureth).

Price data: Energy-Charts.info (Fraunhofer ISE), data Bundesnetzagentur | SMARD.de, CC BY 4.0;
ENTSO-E Transparency Platform (EPEX Spot day-ahead results); Tibber (public price overview);
aWATTar GmbH.

---

Part of the **NRG-Stack** by DG65 — independent energy modules for Symcon that find each other
automatically but never require one another.
