# NRG-Stack Börsenpreis — Hinweise für die Arbeit an diesem Repository

## Einordnung

- **Marke NRG-Stack.** Anzeigename „NRG-Stack Börsenpreis", Klasse/Modulname `NRGSpotPrice`,
  Präfix `SPOT_`, Repo `DG65/NRGSpotPrice` (kein Alt-Name, keine GitHub-Weiterleitung betroffen).
  Namen von Dietmar am 13.09.2026 freigegeben; ab der ersten echten Instanz eingefroren
  (Kernel-Reflection auf den Klassennamen).
- **Kickoff:** `/Users/dietmar/Nextcloud/Claude/Boersenpreis-Kickoff.md` (EMS-Sitzung,
  13.09.2026). Verbund-Konventionen: `SUITE.md` (nur lokal unter `Nextcloud/Claude/`).
  Hintergrund: `EMS-Anlagenstammdaten-Konzept.md` Abschnitte 3, 7E, 8.3.
- **Abgrenzung:** Tibber Grid Rewards liefert den Endkundenpreis (`basis='endkunde'`), dieses
  Modul den reinen Börsenpreis (`basis='spot'`). Das offizielle Symcon-Modul „Strompreis"
  (Präfix `SPX`) hat keinen Verbund-Vertrag und keinen Viertelstunden-Schwerpunkt.
- **Konsument:** EMS (nimmt Tibber, wenn vorhanden, sonst dieses Modul — EMS-Seite baut die
  EMS-Sitzung). Abstimmung per `mcp__ccd_session_mgmt__send_message` nur mit EMS.

## Vertrag `SPOT_GetPriceCurve(int $id): array` — contractVersion 1.0

Liste aufsteigend, je Slot: `start` (inkl.), `end` (EXKLUSIV), `price` (ct/kWh netto, negativ
erlaubt), `basis='spot'`, `netzentgelt='fehlt'`, `level=null`, `quelle`
(`energy-charts`/`awattar`), `aufloesung` (900/3600), `contractVersion`. Nur additiv erweitern
(neue Felder → Minor). Keine Parameter, keine PHP-Standardwerte (Stolperstein 8/20).

## Kernmechanik — nicht verhandelbar

1. **Immer Viertelstunden ausliefern.** EMS `parsePT15M()` ordnet jeden Preis nur der
   Viertelstunde seines `start` zu — ein Stundenslot ließe drei von vier leer. aWATTar-Stunden
   werden deshalb auf vier gleiche Viertelstunden verteilt, `aufloesung=3600` sagt es ehrlich.
2. **Fehlend ist nie 0** (Stolperstein 15): `null`-Preise der Quelle sind Lücken. Ohne Preis für
   jetzt: Status 201, „negativ jetzt" = Nein, der alte Preis bleibt (Float kann „unbekannt" nicht).
3. **Getter liest nur den Speicher.** Einzige Ausnahme: leerer Speicher → einmalig nachladen,
   gedrosselt (120 s) und nie während eines Ratenlimits.
4. **Ratenlimit respektieren** (HTTP 429, `Retry-After` Sekunden oder HTTP-Datum, 60 s … 1 h):
   `BlockedUntil` sperrt Timer, Knopf und Getter gleichermaßen.
5. **Abruf nur, wenn etwas fehlt** (`needsFetch()`/`scheduleFetch()`): heute fehlt → alle
   15 min; morgen fehlt → ab 12:45 + (InstanceID % 10) min alle 15 min; alles da → morgen 12:45+.
6. **Kalender per `mktime()`**, nie ±86400 (Stolperstein 18). Viertelstunden-Takt über
   900-s-Vielfache der Unixzeit (ein Delta, keine Tageslänge).
7. **Fehler:** Speicher bleibt, `LastError` fürs Formular, `IPS_LogMessage()` nur beim ersten
   Auftreten desselben Fehlers (kein Log-Sturm bei 15-min-Wiederholung).
8. **Quellen-/Zonenwechsel verwirft den Speicher** — keine gemischte Kurve.
9. **Sprachregel:** alles Nutzersichtbare deutsch, Datum TT.MM.JJJJ (Store-Checkliste 9b),
   keine eigene Anlage als Norm (Zone ist Einstellung, keine Anlagendaten im Modul).

## Quellen (Stand 13.09.2026, selbst geprüft)

- **Energy-Charts** `https://api.energy-charts.info/price?bzn=DE-LU&start=JJJJ-MM-TT&end=JJJJ-MM-TT`:
  `unix_seconds[]`, `price[]` (EUR/MWh), `license_info` (CC BY 4.0 für DE-LU/AT). Folgetag noch
  nicht da → nur heute (HTTP 200); ganzer Zeitraum ohne Daten → HTTP 404. ISO-Zeitangaben mit
  `+` im Parameter → HTTP 400 (deshalb nur Datum). Ratenlimit pro Minute (429 beobachtet von der
  Vorgänger-Recherche; eigener Dreifach-Abruf lief aus dem Cache der Quelle).
- **aWATTar** `https://api.awattar.de|at/v1/marketdata?start=ms&end=ms`: `data[]` mit
  `start_timestamp`/`end_timestamp` (ms), `marketprice` (Eur/MWh). Nur Stundenwerte, auch am
  23-Stunden-Tag. Fair Use ~100 Abfragen/Tag. Ohne Parameter nur ab jetzt.
- **ENTSO-E** (Viertelstunden, Token per E-Mail beantragen, 400 Abfragen/min): später, dann
  Token als Attribut nach der Credentials-Konvention.

## Prüfen

```
php .tools/test-spot.php          # 0 = alle Blöcke bestanden (läuft auch in der CI)
php .tools/check-standalone.php   # 0 = kein ungesicherter Fremdaufruf
```

`SpotTest` überschreibt `now()` und `httpGet()` (Warteschlange mit Antworten aus
`.tools/fixtures`, echte Quellantworten vom 13.09.2026). Live-Test in Symcon bleibt Pflicht vor
jedem Release: Installation nur manuell über die Modulverwaltung, Formular durchklicken, jeden
Button auf sichtbare Rückmeldung prüfen, einen Folgetag-Abruf nach 12:45 Uhr abwarten.

## Branch-Modell

`main` + `beta` tragen nur das Grundgerüst (LICENSE, README), gebaut und gepusht wird zuerst
auf **`ems-integration`** (SUITE.md „Verbund-weiter Arbeitsbranch", Dietmar 13.09.2026). Nach
Bewährung Merge nach `beta`; `beta`→`main` entscheidet Dietmar. `LICENSE_URL` zeigt auf `main`
(PolyForm von Anfang an).

**GitHub-Standard-Branch = `ems-integration`** (seit 13.09.2026): Die Modulverwaltung klont beim
Anlegen den Standard-Branch — lag er auf dem Grundgerüst-`main` ohne `library.json`, brach die
Installation mit „This repository seems corrupted. library.json is at least missing!
(Code: -32603)" ab (live bei Dietmar). Erst zurück auf `main` stellen, wenn `main` selbst ein
lauffähiges Modul trägt (`gh repo edit DG65/NRGSpotPrice --default-branch main`).

## Roadmap / bewusst nicht drin

- ENTSO-E als dritte Quelle (Token-Konto nötig, deshalb nicht Standard).
- Automatischer Quellenwechsel bei Ausfall (bewusst nicht: gemischte Auflösungen in einer Kurve).
- Keine Einstufung (`level`) und kein Endkundenpreis — beides Sache des Konsumenten bzw. Tibbers.
- Beweissicherung Einspeisung × Börsenpreis je Viertelstunde (Konzept 7F) gehört ins EMS.
