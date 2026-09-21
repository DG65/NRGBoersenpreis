# Changelog

Alle nennenswerten Änderungen an diesem Modul werden hier dokumentiert.
Format angelehnt an [Keep a Changelog](https://keepachangelog.com/de/1.0.0/).

## [0.7.0] - 2026-09-21

### Changed
- **Verbindung zu Tibber Grid Rewards sichtbar** (SUITE.md „Verbund-Verbindungen im Formular
  sichtbar machen“, verbundweit ab 21.09.2026): Die Statuszeile im Panel „Symcon Energie Manager &
  Tarif“ nennt jetzt ✅ Instanz, Name, Vertragsversion, den übernommenen Endpreis und bis wann Tibber
  Preise liefert; ℹ️ „nicht gefunden“ bzw. „abgewählt“ ausdrücklich mit dem, was dann gilt; ⚠️ bei
  unbrauchbarer Antwort (auch: keine Slots mit basis „endkunde“).
- **Zeile folgt der Auswahl:** „Preis von Tibber Grid Rewards nutzen“, „Eigenen Tarif einrechnen“,
  „Tibber-Instanz“ und „Quelle“ frischen Statuszeile und Felder per `onChange` sofort auf
  (`SPOT_UIRefreshMarket`), nicht erst nach „Übernehmen“.
- **Wert kommt automatisch: Eingabefelder ersetzt** (SUITE.md, 21.09.2026): Liefert Tibber Grid
  Rewards den Endpreis, sind die Tarif-Felder ausgeblendet und eine 🔗-Zeile zeigt Preis und Quelle.
  Kein Wert wird per `UpdateFormField('value')` in ein Feld geschrieben.

### Fixed
- **Mehrere Tibber-Instanzen:** Bisher nahm das Modul stillschweigend die erste. Jetzt ⚠️ mit
  Auswahlfeld „Tibber-Instanz“ (neue Property `TibberInstance`, 0 = automatisch bei genau einer);
  bis zur Auswahl wird keine genutzt.

## [0.6.1] - 2026-09-16

### Changed
- Forum-Hinweis („💬 Feedback im Symcon-Forum“) verweist jetzt auf den veröffentlichten
  Vorstellungs-Thread im Symcon-Forum; die Warnzeile „Platzhalter-Link“ ist entfallen.

## [0.6.0] - 2026-09-14

### Changed
- **Umbenannt auf „Börsenpreis“** (Dietmar): Repo `DG65/NRGBoersenpreis` (vormals `NRGSpotPrice` —
  der alte Name wird nie wiederverwendet, GitHub leitet weiter), Klasse/Modulname `Boersenpreis`,
  Modulordner `Boersenpreis/`. **Unverändert:** Modul-GUID, Präfix `SPOT_` und alle Verträge — EMS,
  Dashboard und Tibber finden das Modul weiter.

### Fixed
- Texte wieder über die volle Formularbreite (Dietmar: „in jedem Modul über die komplette
  Formularbreite“). Der harte Umbruch nach 80 Zeichen aus 0.5.1 war der falsche Weg — Labels sind
  wieder einfache Labels wie in allen Verbund-Modulen, Symcon bricht sie selbst um. Die Überbreite
  auf schmalen Bildschirmen kam von nebeneinander stehenden Eingabefeldern (Hoch-/Standard-/
  Niedertarif, ENTSO-E-Schlüssel + Knopf): jetzt untereinander, Feldbreite 400 px.
- Doku- und Hilfetexte vor dem Store-Start durchgesehen (Bitte EMS/Dietmar): „Neu in Version“
  ohne veraltete Grundpreis/Steuer/Aufschlag-Zeile und mit den 0.6-Neuerungen, Variablenliste im
  Doku-Panel und README um „Marktdaten (Energie Manager)“ ergänzt, README-Hinweis zur Archivierung
  aktualisiert (schaltet das Modul selbst ein), „Wozu dieses Modul?“ nennt den Nutzen ohne EMS
  (Symcon Energie Manager).
- Forum-Hinweis auf MeterHubs Muster umgestellt (EMS/Dietmar): Panel „💬  Feedback im
  Symcon-Forum“ mit Knopf zum Forums-Thread — bis der Vorstellungs-Thread live ist, als
  Platzhalter mit sichtbarem Warnhinweis.

## [0.5.1] - 2026-09-14

### Fixed
- Abgeschnittene Beschriftungen im Formular (Live-Fund Dietmar): Symcon zeigt sie im Eingabefeld
  selbst. Alle Eingabefelder jetzt einheitlich 600 px breit, Beschriftungen kurz, Erklärungen als
  Hinweiszeile (z. B. Postleitzahl, „alle Beträge netto“), Quellen-Auswahl mit kürzeren Einträgen.
  Prüfstand begrenzt Feldbeschriftungen auf 26 und Auswahl-Einträge auf 50 Zeichen.
- Lange Hinweiszeilen liefen rechts aus dem Bild — Symcon bricht Labels nicht selbst um. Alle
  Labels (auch Statuszeilen und Hilfe-Fenster) werden jetzt an Wortgrenzen auf höchstens
  80 Zeichen je Zeile umbrochen; Schalter-Beschriftungen gekürzt (Erklärung als Hinweiszeile).
- „Aufschlag des Anbieters“ zeigte −20,000, obwohl 0 gespeichert war (die Konsole zeigte bei
  negativem Minimum das Minimum an) — Minimum jetzt 0.

### Added
- **„Wozu dieses Modul?“ wieder anzeigen:** Knopf im Panel „Dokumentation & Hilfe“. Einmal
  weggeklickt war der Zweck-Text bisher nirgends mehr zu finden (Live-Fund Dietmar: Panel bei ihm
  ausgeblendet, „Neu“ und „Rückmeldungen“ noch da). Das Panel bleibt dafür immer im Formular und
  wird nur unsichtbar geschaltet — der Knopf blendet es sofort wieder ein.
- Ausblenden von „Wozu dieses Modul?“, „Neu in Version“ und „Rückmeldungen“ gilt jetzt für alle
  Börsenpreis-Instanzen (SUITE.md-Konvention 14.09.2026, Muster MeterHub): Bestätigen gibt den
  Zustand an die Geschwister weiter (`SPOT_AdoptDismissState`, gibt selbst nie weiter), eine neue
  Instanz übernimmt ihn einmalig (`SPOT_GetDismissState`). „Neu“ versionsscharf, nie
  zurückgestuft, nie wieder eingeblendet; defekte Geschwister-Instanzen stören nicht.

## [0.5.0] - 2026-09-14

### Changed
- **Tarif für den Symcon Energie Manager wie bei Tibber aufgebaut** (Dietmar: „zu dünn“;
  abgestimmt mit der Tibber-Grid-Rewards-Sitzung). Preis in „Marktdaten (Energie Manager)“ je
  Viertelstunde, erste Quelle gewinnt:
  1. **Tibber Grid Rewards installiert** (abwählbar): dessen echter Tibber-Endpreis
     (`TIBBERGR_GetPriceCurve`, nur `basis='endkunde'`, nur Vertrag 1.x — sonst sichtbare Meldung).
  2. **Eigener Tarif** (neu): (Börsenpreis + Aufschlag des Anbieters + Netzentgelt + Konzessionsabgabe
     + bundesweite Umlagen) × 1,19 — gleiche Begriffe wie Tibber (spot/beschaffung/netzentgelt/
     steuernAbgaben), aber vorwärts gerechnet. Netzentgelt als Arbeitspreis oder zeitvariabel nach
     **§ 14a Modul 3** (Hoch-/Standard-/Niedertarif, Zeitfenster aus dem Preisblatt, je Fenster
     „alle Tage“/„Mo–Fr“/„Sa–So“, Gültigkeit je Kalenderquartal). Umlagen fest mit Stand 06/2026
     (Stromsteuer 2,05, Offshore 0,941, KWK 0,446, §19-StromNEV 1,56 ct/kWh netto).
  3. Quelle Tibber-Preisübersicht: Tibbers Endpreis für die Postleitzahl.
  4. Sonst der reine Börsenpreis.
- Statuszeile im Panel zeigt, woher der Preis gerade kommt. Alle Tarif-Felder aus bzw. 0 als
  Vorgabe (keine Beispielwerte einer echten Anlage). Die Felder Grundpreis/Steuer/Aufschlag aus
  0.3/0.4 entfallen im Formular (weiter registriert, ohne Wirkung — kein Migrationsbruch).

## [0.4.0] - 2026-09-14

### Added
- **Quelle „Tibber-Preisübersicht“** — Tibbers öffentliche Preisübersicht, wie in Symcons Modul
  „Strompreis“: ohne Tibber-Konto, nur mit Postleitzahl (Feld leer als Vorgabe, wird an Tibber
  übertragen). Liefert je Viertelstunde den Börsenpreis (Bestandteil „power“, am 14.09.2026 gegen
  Energy-Charts geprüft: alle 96 Viertelstunden ±0,005 ct), Netzentgelt und Abgaben der
  Postleitzahl sowie den Endpreis. Vertrag, „Börsenpreis jetzt“ und die übrigen Variablen nutzen
  den Börsenpreisanteil (`quelle = 'tibber'`); **die Variable „Marktdaten (Energie Manager)“
  bekommt Tibbers Endpreis inkl. MwSt** — der Tibber-Preis ohne Tibber-Zugang, Grundpreis/Steuer/
  Aufschlag entfallen dann. Nur Gebotszone DE-LU. Tibber nennt Ortszeit ohne Zeitzone; die
  doppelte Stunde am 25-Stunden-Tag wird über die lückenlose Folge richtig zugeordnet.
  Keine offiziell dokumentierte Tibber-Schnittstelle.

## [0.3.0] - 2026-09-14

### Added
- **Quelle „EPEX Spot (über ENTSO-E)“** — die Day-Ahead-Ergebnisse der Strombörse EPEX Spot,
  veröffentlicht auf der ENTSO-E Transparency Platform, in Viertelstunden (so bindet auch Symcons
  Modul „Strompreis“ „EPEX Spot“ an; direkt bei EPEX gibt es die Daten nur mit Vertrag).
  Kostenloser, persönlicher Zugangsschlüssel nötig: wird im Attribut gespeichert (nie Property,
  nie im Formular, Log oder in Fehlermeldungen), Eingabe per Passwortfeld + „Schlüssel speichern“
  (`SPOT_SetEntsoeToken()`). Auswertung des A44-XML: mehrere Zeitreihen je Tag (kleinste Position
  gilt), Kurventyp A03 (weggelassene gleiche Werte werden aufgefüllt, auch am Periodenende),
  PT15M/PT60M, „keine Daten“-Quittung. Vertragsfeld `quelle = 'entsoe'`.
- **Schnittstelle zum Symcon Energie Manager:** neue Variable „Marktdaten (Energie Manager)“
  (Ident `MarketData`) im Format von Symcons Modul „Strompreis“ — `[{start, end, price}]`, ct/kWh,
  ab der laufenden Viertelstunde bis zu 24 Stunden, zu jeder Viertelstunde und nach jedem Abruf
  neu geschrieben. Im Energie Manager unter „Energiepreise“ auswählen. Optional Grundpreis,
  Steuer und Aufschlag des eigenen dynamischen Tarifs (Rechnung wie „Strompreis“, Standard 0 =
  reiner Börsenpreis); wirkt nur auf diese Variable, Vertrag und übrige Variablen bleiben netto.

## [0.2.1] - 2026-09-14

### Changed
- Store-Review-Checkliste (SUITE.md 9c/9d/9g) für den Store-Start nachgezogen:
  - **Status:** Ein gescheiterter Abruf bei gültigen Preisen (Ratenlimit, Quelle kurz weg) setzt
    keinen Warnstatus 202 mehr — Watchdogs sehen nur den Instanzstatus. Die Instanz bleibt 102,
    der Fehler steht im Formular und einmal im Meldungsprotokoll. 201 nur, wenn für die laufende
    Viertelstunde kein Preis vorliegt.
  - **Archiv:** `GetPriceHistory` fragt das Archiv tageweise ab und sucht den Wert vor Beginn nur
    im 12-Stunden-Fenster, nie „seit Epoche“ (Grenze von ~50 000 gelesenen Werten).
  - **Neuladen:** Rückgaben von `ReadAttribute…`/`ReadProperty…` werden gecastet, der
    Kernel-Start prüft zusätzlich, ob die Instanz existiert.

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
