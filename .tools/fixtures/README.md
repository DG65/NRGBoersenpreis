# Eingefrorene Quellantworten für den Prüfstand

Echte, unveränderte Antworten der beiden Quellen, abgerufen am 13.09.2026 —
damit `test-spot.php` ohne Netz und reproduzierbar gegen reale Daten prüft
(Sommerzeit-Tage, negative Preise, zwei Tage, Gebotszone AT, Stundenwerte).

| Datei | Abfrage |
|---|---|
| `ec-DE-LU-2026-09-12_13.json` | Energy-Charts `/price?bzn=DE-LU&start=2026-09-12&end=2026-09-13` (192 Viertelstunden) |
| `ec-DE-LU-2026-05-01.json` | dto. 01.05.2026 — 32 negative Viertelstunden, Minimum −499,99 EUR/MWh |
| `ec-DE-LU-2026-03-29.json` | dto. 29.03.2026 — Sommerzeitbeginn, 23-Stunden-Tag, 92 Viertelstunden |
| `ec-DE-LU-2025-10-26.json` | dto. 26.10.2025 — Sommerzeitende, 25-Stunden-Tag, 100 Viertelstunden |
| `ec-AT-2026-09-12.json` | Energy-Charts `/price?bzn=AT&start=2026-09-12&end=2026-09-12` |
| `aw-DE-2026-05-01.json` | aWATTar `api.awattar.de/v1/marketdata`, 01.05.2026 (24 Stundenwerte) |
| `aw-DE-2026-03-29.json` | dto. 29.03.2026 (23 Stundenwerte) |

Quellennennung Energy-Charts: Energy-Charts.info (Fraunhofer ISE), Daten
Bundesnetzagentur | SMARD.de, Lizenz CC BY 4.0 (steht auch in jeder Datei unter
`license_info`). aWATTar-Daten: aWATTar GmbH, öffentliche Marktdaten-API.
