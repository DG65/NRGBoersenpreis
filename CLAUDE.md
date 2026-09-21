# NRG-Stack Börsenpreis — Hinweise für die Arbeit an diesem Repository

## Einordnung

- **Marke NRG-Stack.** Anzeigename „NRG-Stack Börsenpreis", Klasse/Modulname/Ordner
  `Boersenpreis`, Präfix `SPOT_`, Repo `DG65/NRGBoersenpreis`. **Am 14.09.2026 umbenannt**
  (Dietmar „überall Börsenpreis“) aus `NRGSpotPrice`/Repo `DG65/NRGSpotPrice` — GitHub leitet
  weiter, der alte Repo-Name wird NIE wiederverwendet. Präfix `SPOT_` und Modul-GUID bewusst
  unverändert (EMS/Dashboard-Code unberührt). Ab dem Store-Start eingefroren
  (Kernel-Reflection auf den Klassennamen).
- **Kickoff:** `/Users/dietmar/Nextcloud/Claude/Boersenpreis-Kickoff.md` (EMS-Sitzung,
  13.09.2026). Verbund-Konventionen: `SUITE.md` (nur lokal unter `Nextcloud/Claude/`).
  Hintergrund: `EMS-Anlagenstammdaten-Konzept.md` Abschnitte 3, 7E, 8.3.
- **Abgrenzung:** Tibber Grid Rewards liefert den Endkundenpreis (`basis='endkunde'`), dieses
  Modul den reinen Börsenpreis (`basis='spot'`). Das offizielle Symcon-Modul „Strompreis"
  (Präfix `SPX`) hat keinen Verbund-Vertrag und keinen Viertelstunden-Schwerpunkt.
- **Konsument:** EMS (nimmt Tibber, wenn vorhanden, sonst dieses Modul — EMS-Seite baut die
  EMS-Sitzung). Abstimmung per `mcp__ccd_session_mgmt__send_message` nur mit EMS.

## Vertrag `SPOT_GetPriceCurve(int $id): array` — contractVersion 1.1

**1.1 (0.2.0):** `SPOT_GetPriceHistory(int $id, int $from, int $to): array`, gleiche Slots,
Vergangenheit aus dem Archiv von `CurrentPrice` (`quelle='archiv'`, Stufenverlauf, höchstens
12 h je Wert — Archiv speichert nur Änderungen und kann Stillstand nicht von gleichem Preis
unterscheiden), Zwischenspeicher hat Vorrang, max. 400 Tage. Archivierung wird genau einmal
eingeschaltet (`ArchiveInitDone`), danach Nutzer-Hoheit. Wunsch Dashboard, Entscheidung
Dietmar 13.09.2026 („über das Archiv").


Liste aufsteigend, je Slot: `start` (inkl.), `end` (EXKLUSIV), `price` (ct/kWh netto, negativ
erlaubt), `basis='spot'`, `netzentgelt='fehlt'`, `level=null`, `quelle`
(`energy-charts`/`entsoe`/`tibber`/`awattar`, im Rückblick zusätzlich `archiv`), `aufloesung`
(900/3600), `contractVersion`. Nur additiv erweitern
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
   Auftreten desselben Fehlers (kein Log-Sturm bei 15-min-Wiederholung). **Kein Warnstatus**
   dafür (SUITE.md 9d, seit 0.2.1): Status nur 102 (Preis für jetzt da) oder 201 (fehlt).
8. **Quellen-/Zonen-/PLZ-Wechsel verwirft den Speicher** — keine gemischte Kurve.
9. **Sprachregel:** alles Nutzersichtbare deutsch, Datum TT.MM.JJJJ (Store-Checkliste 9b),
   keine eigene Anlage als Norm (Zone ist Einstellung, keine Anlagendaten im Modul).
10. **Archiv tageweise** (SUITE.md 9g): `archivedSlots()` fragt je Kalendertag ab, den Vorwert
    nur im 12-h-Fenster, nie ab Zeitstempel 0. `false` = kein Logging → keine Historie.
11. **Formular** (Live-Funde 14.09.2026): Labels sind einfache Labels (Symcon bricht selbst über
    die volle Breite um — kein harter Umbruch, 0.5.1 war falsch). Eingabefelder nie nebeneinander
    (RowLayout erzwingt auf schmalen Bildschirmen Überbreite), Breite `FIELD_WIDTH` 400 px,
    Beschriftung ≤ 26 Zeichen (sie steht IM Feld), Erklärung als Label. Kein negatives
    `minimum` bei NumberSpinnern (Konsole zeigte für 0 das Minimum an). „Wozu dieses Modul?“
    bleibt unsichtbar im Formular, Knopf im Doku-Panel blendet es wieder ein; Ausblenden über
    Instanzen geteilt (`GetDismissState`/`AdoptDismissState`, gibt selbst nie weiter).

## Quellen (Stand 13.09.2026, selbst geprüft)

- **Energy-Charts** `https://api.energy-charts.info/price?bzn=DE-LU&start=JJJJ-MM-TT&end=JJJJ-MM-TT`:
  `unix_seconds[]`, `price[]` (EUR/MWh), `license_info` (CC BY 4.0 für DE-LU/AT). Folgetag noch
  nicht da → nur heute (HTTP 200); ganzer Zeitraum ohne Daten → HTTP 404. ISO-Zeitangaben mit
  `+` im Parameter → HTTP 400 (deshalb nur Datum). Ratenlimit pro Minute (429 beobachtet von der
  Vorgänger-Recherche; eigener Dreifach-Abruf lief aus dem Cache der Quelle).
- **aWATTar** `https://api.awattar.de|at/v1/marketdata?start=ms&end=ms`: `data[]` mit
  `start_timestamp`/`end_timestamp` (ms), `marketprice` (Eur/MWh). Nur Stundenwerte, auch am
  23-Stunden-Tag. Fair Use ~100 Abfragen/Tag. Ohne Parameter nur ab jetzt.
- **EPEX Spot über ENTSO-E** (seit 0.3.0, Dietmar 14.09.2026 „EPEX Spot als Quelle“):
  `web-api.tp.entsoe.eu/api?securityToken=…&documentType=A44&periodStart/End=JJJJMMTTHHMM (UTC)
  &in/out_Domain=EIC` (DE-LU `10Y1001A1001A82H`, AT `10YAT-APG------L`). XML
  `Publication_MarketDocument`, je Periode kleinste `classificationSequence…position` (wie
  Symcons „Strompreis“), A03 → weggelassene Punkte (auch am Ende) = Vorwert, PT15M/PT60M.
  „Keine Daten“ = HTTP 400 + `Acknowledgement_MarketDocument` mit Reason-Text. Token im Attribut
  `EntsoeToken` (Credentials-Konvention), `SetEntsoeToken()` per PasswordTextBox ohne Property,
  Token wird aus `httpGet`-Fehlertexten ersetzt (***). Direkt-Abruf bei epexspot.com bewusst
  NICHT: EPEX gibt Marktdaten für externe Nutzung nur mit Vertrag ab (General Conditions of Data
  Use). Kein echter Token in dieser Sitzung — Prüfstand nutzt aus Energy-Charts-Preisen erzeugtes
  A44-XML (Format nach Symcons Test-Fixture + ENTSO-E-Doku); Live-Test mit echtem Schlüssel offen.
- **Tibber-Preisübersicht** (seit 0.4.0, Dietmar 14.09.2026 „Tibber-Preis ohne Tibberzugang“):
  `https://tibber.com/de/api/lookup/price-overview?postalCode=PLZ` (öffentlich, undokumentiert,
  genutzt auch von symcon/Strompreis). `energy.today/tomorrowQuarterHours[]` mit date/hour/
  minute (Ortszeit ohne Zeitzone!), `priceIncludingVat`, `priceComponents[power|grid|taxes]`
  (EUR/kWh). `power` = Börsenpreis (±0,005 ct gegen Energy-Charts, 14.09.2026) → Vertrag;
  Endpreis inkl. MwSt als `retail` im Cache → NUR Variable MarketData (Tarif-Felder entfallen).
  PLZ-Property leer als Vorgabe, Cache enthält `plz` (Wechsel → verwerfen). Zeitzuordnung:
  lückenlose Folge (vorig + Raster = gleiche Wanduhrzeit) vor mktime() — sonst landet die
  doppelte 02:xx-Stunde falsch. Nur DE-LU. `retail` bewusst nicht im Verbund-Vertrag
  (Endpreis einer PLZ ≠ Vertrag des Nutzers; EMS nimmt Bezugspreis nur aus Tibber-Modul/manuell).
- **Tarif für den Energie Manager** (seit 0.5.0, Dietmar „wie in Tibber anpacken“, mit der
  Tibber-Sitzung abgestimmt 14.09.2026): gleiche Begriffe wie `TIBBERGR_GetPriceCurve.components`
  (spot/beschaffung/netzentgelt/steuernAbgaben, vat), aber VORWÄRTS gerechnet — Tibbers
  „spot als Rest“ geht nur bei Tibbers at-cost-Modell. Keine Abhängigkeit: Tibber Grid Rewards
  wird nur optional gefragt (`tibberGridRewardsInfo()`, function_exists + try/catch, nur
  basis=endkunde, nur Major 1) und hat dann Vorrang (Tibber kennt den echten Preis; vermeidet
  doppelte Netzentgelt-Eingabe). Umlagen `TAX_*` fest mit Stand wie bei Tibber — bei Änderung
  BEIDE Module nachziehen. Modul-3-Fenster mit `Days` (all/weekday/weekend) — bei Tibber offene
  Lücke, hier gelöst; Feiertage nicht. Offen (Tibber-Hinweis): gemeinsames Komponenten-Vokabular
  ggf. über EMS/SUITE.md verbundweit formalisieren. Alte Properties MarketBase/Tax/Surcharge
  seit 0.5.0 ohne Wirkung, nur registriert (kein Migrationsbruch).
  **Seit 0.7.0 (SUITE.md „Verbund-Verbindungen sichtbar“ + „Wert kommt automatisch“, 21.09.2026):**
  `marketSourceStatus()` zeigt ✅ Instanz/Name/Vertrag/Endpreis jetzt/Horizont, ℹ️ nicht gefunden
  bzw. abgewählt, ⚠️ Problem oder mehrere Instanzen (Property `TibberInstance`, 0 = automatisch bei
  genau einer — bei mehreren NIE raten, dann wird keine genutzt). Liefert Tibber, sind die
  `TARIFF_FIELDS` ausgeblendet und `TibberAutoLine` (🔗) zeigt Preis und Quelle. Häkchen, Instanz
  und Quelle rufen per `onChange` `SPOT_UIRefreshMarket` auf (Zeile folgt der Auswahl, nicht dem
  Speicherstand). Prüfstand Block 18b prüft das ausgelieferte JSON rekursiv.
- **Symcon Energie Manager** (seit 0.3.0): Variable `MarketData` im Format von
  symcon/Strompreis (`NormalizeAndReduce`): `[{start,end,price ct/kWh}]`, ab laufender
  Viertelstunde ≤ 96 Einträge (24 h). Preis seit 0.5.0 nach der Reihenfolge Tibber Grid Rewards →
  eigener Tarif → Tibber-Preisübersicht → reiner Börsenpreis (siehe oben); die einfache
  „Strompreis“-Rechnung aus 0.3/0.4 ist entfallen. Format dort nicht dokumentiert — Referenz ist
  der Quellcode von symcon/Strompreis (Commit 75f71d6, 08.07.2026).

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

`main` trägt nur das Grundgerüst (LICENSE, README). **`beta` = Store-Kanal Beta („NRG-Stack Börsenpreis“),
seit 16.09.2026 per Fast-Forward auf 0.6.1 nachgezogen (Dietmar „ja“).** Gebaut und gepusht wird zuerst
auf **`ems-integration`** (SUITE.md „Verbund-weiter Arbeitsbranch", Dietmar 13.09.2026). Nach
Bewährung Merge nach `beta`; `beta`→`main` entscheidet Dietmar. `LICENSE_URL` zeigt auf `main`
(PolyForm von Anfang an).

**GitHub-Standard-Branch = `ems-integration`** (seit 13.09.2026): Die Modulverwaltung klont beim
Anlegen den Standard-Branch — lag er auf dem Grundgerüst-`main` ohne `library.json`, brach die
Installation mit „This repository seems corrupted. library.json is at least missing!
(Code: -32603)" ab (live bei Dietmar). Erst zurück auf `main` stellen, wenn `main` selbst ein
lauffähiges Modul trägt (`gh repo edit DG65/NRGBoersenpreis --default-branch main`).

## Roadmap / bewusst nicht drin

- ENTSO-E ist seit 0.3.0 Quelle, aber nicht Standard (Token-Konto nötig); Live-Test mit echtem
  Schlüssel steht aus.
- Automatischer Quellenwechsel bei Ausfall (bewusst nicht: gemischte Auflösungen in einer Kurve).
- Keine Einstufung (`level`) und kein Endkundenpreis — beides Sache des Konsumenten bzw. Tibbers.
- **Keine eigene Kachel** (Dietmar 13.09.2026): Die Anzeige der Preiskurve (heute/morgen,
  negative Viertelstunden) übernimmt NRGDashboard über `SPOT_GetPriceCurve`. Dieses Modul
  liefert nur Daten, Variablen und das Konfigurationsformular.
- Beweissicherung Einspeisung × Börsenpreis je Viertelstunde (Konzept 7F) gehört ins EMS.
