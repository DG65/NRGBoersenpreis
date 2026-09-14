# NRG-Stack Börsenpreis

![Symcon](https://img.shields.io/badge/Symcon-PHPModul-blue)
![Modul Version](https://img.shields.io/badge/Modul_Version-0.3.0-blue)
![Symcon Version](https://img.shields.io/badge/Symcon_Version-9.0%2B-blue)
![License](https://img.shields.io/badge/License-PolyForm_Noncommercial_1.0.0-lightgrey)
[![Check Style](https://github.com/DG65/NRGSpotPrice/actions/workflows/check-style.yml/badge.svg)](https://github.com/DG65/NRGSpotPrice/actions/workflows/check-style.yml)
[![PayPal](https://img.shields.io/badge/PayPal-Me-blue?logo=paypal)](https://paypal.me/DietmarGureth)

**Day-Ahead-Börsenpreise für IP-Symcon — ohne Konto, ohne bestimmten Stromanbieter.**
Das Modul holt die Preise der Day-Ahead-Auktion für heute und morgen (je Viertelstunde,
Gebotszone Deutschland/Luxemburg oder Österreich) und stellt sie als Variablen, für eigene
Skripte und für andere NRG-Stack-Module bereit.

## Wozu?

Einige Regeln knüpfen am **Börsenpreis** an, nicht am eigenen Stromtarif. Das wichtigste
Beispiel: Für PV-Anlagen mit Inbetriebnahme ab 25.02.2025 (Solarspitzengesetz) entfällt die
Einspeisevergütung in jeder Viertelstunde mit negativem Day-Ahead-Börsenpreis (§ 51 EEG).
Wer einen festen Tarif hat, braucht die Börsenpreise also trotzdem — damit z. B. das
[NRG-Stack EMS](https://github.com/DG65/NRGEMS) genau diese Viertelstunden erkennt und die
Energie in Batterie, Wärmepumpe oder Auto lenkt statt ins Netz. Mit der geplanten EEG-Novelle
2027 (Neuanlagen ohne feste Vergütung) wird der Börsenpreis für viele Anlagen zur zentralen
Größe.

Keine Rechtsberatung: Welche Pflichten für eine bestimmte Anlage gelten, hängt u. a. vom
Inbetriebnahmedatum ab — das EMS ordnet das über seine Anlagendaten ein, dieses Modul liefert
nur die Preise.

## Quellen

| Quelle | Auflösung | Anmeldung | Hinweise |
|---|---|---|---|
| **Energy-Charts** (Fraunhofer ISE), Standard | Viertelstunde | keine | Lizenz CC BY 4.0, Daten Bundesnetzagentur \| SMARD.de. Begrenzt die Abfragen pro Minute — das Modul fragt meist nur 1–5-mal am Tag und hält sich an jede Pause (HTTP 429 + `Retry-After`). |
| **EPEX Spot (über ENTSO-E)** | Viertelstunde | kostenloser Zugangsschlüssel | Die Day-Ahead-Ergebnisse der Strombörse EPEX Spot, veröffentlicht auf der ENTSO-E Transparency Platform (so bindet auch Symcons Modul „Strompreis“ „EPEX Spot“ an). Schlüssel: Konto auf transparency.entsoe.eu, dann per E-Mail „Restful API access“ beantragen ([Anleitung](https://transparencyplatform.zendesk.com/hc/en-us/articles/12845911031188-How-to-get-security-token)). Direkt bei EPEX Spot gibt es die Daten nur mit kostenpflichtigem Vertrag. |
| **aWATTar** | Stunde | keine | Kostenlos im Rahmen fairer Nutzung. Stundenwerte werden auf vier gleiche Viertelstunden verteilt; eine einzelne negative Viertelstunde kann im Stundenmittel verschwinden. |

Der ENTSO-E-Zugangsschlüssel wird als Attribut gespeichert — nie im Formular, im Log oder in
Fehlermeldungen (IP-Symcon verschlüsselt Attribute nicht, sie liegen aber außerhalb der
Konfiguration).

## Symcon Energie Manager

Die Variable **„Marktdaten (Energie Manager)“** (Ident `MarketData`) liefert die Preise im Format
von Symcons Modul „Strompreis“: `[{"start": Unix, "end": Unix, "price": ct/kWh}, …]`, ab der
laufenden Viertelstunde für bis zu 24 Stunden, zu jeder Viertelstunde neu geschrieben. Im Symcon
Energie Manager unter **„Energiepreise“** diese Variable auswählen.

Standard ist der reine Börsenpreis. Wer einen dynamischen Tarif hat, der sich am Börsenpreis
orientiert, trägt im Panel „Symcon Energie Manager“ Grundpreis, Steuer und Aufschlag ein —
gerechnet wird wie bei „Strompreis“: Grundpreis + Börsenpreis × (1 + Steuer) × (1 + Aufschlag).
Das wirkt nur auf diese Variable; Vertrag und übrige Variablen bleiben der reine Börsenpreis.

## So arbeitet das Modul

- Nach dem Anlegen wird sofort abgerufen, danach **nur, wenn etwas fehlt**: Die Preise für
  morgen entstehen in der Auktion um 12 Uhr und stehen meist ab ca. 12:45 Uhr bereit (je
  Instanz ein fester Versatz von bis zu 9 Minuten). Bis sie da sind, fragt das Modul alle
  15 Minuten, danach erst am nächsten Tag wieder.
- Zu jeder Viertelstunde werden die Variablen aus dem Zwischenspeicher gesetzt — ohne Abruf.
- Fehler (Quelle nicht erreichbar, keine Daten, Ratenlimit) lassen die vorhandenen Preise
  unangetastet; sie stehen im Formular und einmal dauerhaft im Meldungsprotokoll.
- Alle Zeitstempel sind echte Unixzeit. Tage mit Zeitumstellung haben 92 bzw. 100
  Viertelstunden, nichts wird aus festen Tageslängen errechnet.

## Variablen

| Ident | Name | Typ |
|---|---|---|
| `CurrentPrice` | Börsenpreis jetzt | Float, ct/kWh |
| `NegativeNow` | Negativer Börsenpreis jetzt | Bool (Ja/Nein) |
| `NextNegativeStart` | Nächste negative Viertelstunde | Integer, Unixzeit (0 = keine bekannt; die laufende zählt mit) |
| `TomorrowAvailable` | Preise für morgen veröffentlicht | Bool (Ja/Nein) |

Für einen Verlauf einfach die Archivierung von „Börsenpreis jetzt" einschalten.

## Vertrag für Skripte und andere Module

```php
$curve = SPOT_GetPriceCurve($id);   // contractVersion '1.1'
// [[ 'start' => int (Unix, inkl.), 'end' => int (Unix, EXKLUSIV),
//    'price' => float ct/kWh NETTO (reiner Börsenpreis, negativ möglich),
//    'basis' => 'spot', 'netzentgelt' => 'fehlt', 'level' => null,
//    'quelle' => 'energy-charts'|'entsoe'|'awattar',
//    'aufloesung' => 900|3600,          // Sekunden je Originalwert
//    'contractVersion' => '1.0' ], …]
```

- Liste aufsteigend nach `start`, heute + morgen (sobald veröffentlicht), Viertelstunden-Raster.
- Gleiches Format wie `TIBBERGR_GetPriceCurve` — ein Konsument behandelt beide Quellen gleich
  und unterscheidet über `basis`: `spot` ist der Großhandelspreis, **nicht** der Endkundenpreis
  (keine Steuern, Umlagen, Netzentgelte). Für Kostenvergleiche mit dem Bezugspreis ist er
  allein nicht geeignet.
- `level` ist immer `null` — die Einstufung (günstig/teuer) trifft der Konsument.
- Lücken sind zulässig; ein fehlender Wert steht nie als `0` in der Liste.
- `aufloesung = 3600` heißt: aus einem Stundenwert verteilt, nicht echt viertelstündlich.
- Der Getter liest nur den Zwischenspeicher (bei leerem Speicher einmalig gedrosselt nachladen).

```php
$past = SPOT_GetPriceHistory($id, strtotime('-7 days'), time());   // seit 1.1
```

- Dieselben Einträge für einen beliebigen Zeitraum (`from` inklusiv, `to` exklusiv, auf
  Viertelstunden gerundet, höchstens 400 Tage).
- Vergangene Viertelstunden stammen aus dem Symcon-Archiv von „Börsenpreis jetzt"
  (`quelle = 'archiv'`). Das Archiv speichert nur Änderungen, ein Wert gilt deshalb bis zum
  nächsten — höchstens 12 Stunden, längere Lücken (z. B. Symcon aus) bleiben leer.
- Was der Zwischenspeicher kennt (heute, morgen), kommt exakt von dort.
- Vor dem ersten Archiveintrag gibt es keine Einträge. Die Archivierung schaltet das Modul
  einmalig ein; wer sie abschaltet, verliert nur diesen Rückblick.

```php
echo SPOT_Update($id);   // sofort abrufen, Ergebnis als Text
```

Die Preiskurve mit den negativen Viertelstunden zeigt das
[NRG-Stack Dashboard](https://github.com/DG65/NRGDashboard) im PV-Monitoring (Reiter
„Strompreis"). Dieses Modul hat bewusst keine eigene Kachel.

Das Modul setzt kein anderes Modul voraus und wird von keinem vorausgesetzt.

## Prüfen ohne Symcon

```
php .tools/test-spot.php          # Prüfstand gegen echte, eingefrorene Quellantworten
php .tools/check-standalone.php   # kein ungesicherter Fremdaufruf (function_exists-Wächter)
```

Beides läuft auch in der GitHub-Actions-CI (`check-style.yml`). Der Prüfstand deckt u. a.
negative Preise, fehlenden Folgetag, Ratenlimit, 23-/25-Stunden-Tage, die Verteilung von
Stundenwerten und die Verträglichkeit mit der EMS-Preisauswertung ab.

## Lizenz

PolyForm Noncommercial 1.0.0, siehe [LICENSE](LICENSE) — privat und nicht-kommerziell frei
nutzbar, für den gewerblichen Einsatz ist eine gesonderte Lizenz vom Rechteinhaber nötig
(Kontakt: dietmar@gureth.eu). Spenden willkommen: [paypal.me/DietmarGureth](https://paypal.me/DietmarGureth).

Preisdaten: Energy-Charts.info (Fraunhofer ISE), Daten Bundesnetzagentur | SMARD.de, CC BY 4.0;
ENTSO-E Transparency Platform (EPEX-Spot-Day-Ahead-Ergebnisse); aWATTar GmbH.

---

Teil des **NRG-Stack** von DG65 — eigenständige Energie-Module für IP-Symcon, die sich
gegenseitig automatisch finden, aber keines voraussetzen.
