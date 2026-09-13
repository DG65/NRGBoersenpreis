# Changelog

Alle nennenswerten Änderungen an diesem Modul werden hier dokumentiert.
Format angelehnt an [Keep a Changelog](https://keepachangelog.com/de/1.0.0/).

## [0.2.0] - 2026-09-13

### Added
- **Vertrag 1.1: `SPOT_GetPriceHistory($id, $from, $to)`** — Börsenpreise je Viertelstunde für
  einen beliebigen Zeitraum (höchstens 400 Tage), gleiches Slot-Format wie `GetPriceCurve`.
  Vergangenheit aus dem Archiv von „Börsenpreis jetzt" (`quelle='archiv'`, Stufenverlauf, ein
  Wert gilt höchstens 12 h weiter — längere Lücken bleiben leer), Bekanntes exakt aus dem
  Zwischenspeicher. Nichts vor dem ersten Archiveintrag, nichts erfunden, kein Abruf bei der
  Quelle. Wunsch des NRG-Stack Dashboards (Rückblick auf negative Preise), Entscheidung Dietmar.
- Archivierung von „Börsenpreis jetzt" wird einmalig eingeschaltet (auch bei bestehenden
  Instanzen); schaltet der Nutzer sie ab, bleibt sie aus.

### Changed
- `contractVersion` von `GetPriceCurve` 1.0 → 1.1 (rein additiv, keine Feldänderung).
- Doku/Neu-Panel nennen die Anzeige im NRG-Stack Dashboard (PV-Monitoring, Reiter „Strompreis").

## [0.1.1] - 2026-09-13

### Fixed
- Hilfe-Knopf „Was bedeuten negative Preise für meine Vergütung?" (vorher 63 Zeichen) lief über
  den Knopfrand; alle drei Hilfe-Knöpfe einheitlich 500 px breit, Prüfstand begrenzt Fragen auf
  50 Zeichen (Live-Fund Dietmar).

## [0.1.0] - 2026-09-13

### Added
- Erstes Release. Day-Ahead-Börsenpreise für heute und morgen, ohne Konto und unabhängig vom
  Stromanbieter — Gebotszone DE-LU oder AT.
- **Quellen:** Energy-Charts (Fraunhofer ISE, Viertelstunden, Standard) und aWATTar
  (Stundenwerte, auf vier gleiche Viertelstunden verteilt). Auflösung wird aus den Daten
  gemessen, nicht angenommen; Einheit EUR/MWh wird geprüft und in ct/kWh umgerechnet.
- **Verbund-Vertrag `SPOT_GetPriceCurve()` 1.0** im Format von `TIBBERGR_GetPriceCurve`
  (`basis='spot'`, `netzentgelt='fehlt'`, `level=null`), zusätzlich `quelle` und `aufloesung`.
  Liest nur den Zwischenspeicher; fehlende Werte sind Lücken, nie 0.
- **Sparsamer Abruf:** nur wenn etwas fehlt — Folgetag ab ca. 12:45 Uhr (je Instanz bis zu
  9 min Versatz) alle 15 min, danach Ruhe bis zum nächsten Tag. Ratenlimit (HTTP 429,
  `Retry-After` als Sekunden oder Datum) wird eingehalten, auch vom Knopf und vom Getter.
- Variablen „Börsenpreis jetzt", „Negativer Börsenpreis jetzt", „Nächste negative
  Viertelstunde", „Preise für morgen veröffentlicht", zu jeder Viertelstunde aktualisiert.
- Formular nach Verbund-Konvention (Zweck, Neu, Doku, Datenquelle mit Status-Kopfzeile,
  Preisübersicht je Tag, Rückmeldungen, Über dieses Modul), Hilfe-Knöpfe als konkrete Fragen,
  Quellennennung CC BY 4.0 aus der Antwort der Quelle.
- Prüfstand `.tools/test-spot.php` gegen echte, eingefrorene Quellantworten (negative Preise bis
  −499,99 EUR/MWh, 23-/25-Stunden-Tag, fehlender Folgetag, 404/429/Netzfehler, EMS-Verträglichkeit),
  `.tools/check-standalone.php`, beides in der CI. PolyForm-Noncommercial-Lizenz.

### Herkunft
- Entscheidung Dietmar 13.09.2026: Börsenpreise ohne Tibber aus einem eigenen kleinen Modul
  (Konzept `EMS-Anlagenstammdaten-Konzept.md`, Punkt 8.3; Kickoff `Boersenpreis-Kickoff.md`).
