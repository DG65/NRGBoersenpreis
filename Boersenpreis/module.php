<?php

// ===========================================================================
// NRG-Stack Börsenpreis (Boersenpreis) — Day-Ahead-Börsenpreise ohne Tibber,
// ohne Konto, als Verbund-Vertrag SPOT_GetPriceCurve().
//
// WOZU:
//   Maßgeblich für viele Pflichten ist der Day-Ahead-Börsenpreis, NICHT der
//   eigene Endkundentarif — z. B. entfällt für PV-Anlagen mit Inbetriebnahme
//   ab 25.02.2025 die Einspeisevergütung in jeder Viertelstunde mit negativem
//   Börsenpreis (§ 51 EEG, Solarspitzengesetz). Wer einen festen Tarif hat,
//   braucht die Börsenpreise also trotzdem. Bisher gab es sie im Verbund nur
//   zurückgerechnet aus dem Tibber-Endpreis — und damit nur mit Tibber-Konto.
//
// WIE ES ARBEITET:
//   Ein Timer holt heute + morgen von der gewählten Quelle (Energy-Charts:
//   Viertelstunden; aWATTar: Stunden, hier auf vier gleiche Viertelstunden
//   verteilt), legt sie im Zwischenspeicher ab und fragt erst wieder, wenn
//   etwas fehlt: der Folgetag ab ca. 12:45 Uhr alle 15 Minuten, bis er da ist.
//   Ein zweiter Timer setzt zu jeder Viertelstunde die Anzeige-Variablen.
//   SPOT_GetPriceCurve() liest nur den Zwischenspeicher — kein Abruf pro
//   Aufruf (einzige Ausnahme: leerer Speicher, gedrosselt, wie bei Tibber).
//
// VERTRAG (contractVersion 1.1, gleiches Format wie TIBBERGR_GetPriceCurve):
//   Liste aufsteigend nach 'start', je Slot:
//   start (Unix, inkl.), end (Unix, EXKLUSIV), price (ct/kWh NETTO, reiner
//   Börsenpreis ohne Steuern/Umlagen/Netzentgelt, negativ erlaubt),
//   basis='spot', netzentgelt='fehlt', level=null (Einstufung macht der
//   Konsument), quelle, aufloesung (Sekunden je Originalwert: 900 oder 3600),
//   contractVersion. Lücken sind zulässig, fehlende Werte werden NIE als 0
//   geliefert (SUITE.md Stolperstein 15).
//   SPOT_GetPriceHistory($id, $from, $to) (seit 1.1): dieselben Slots für einen
//   beliebigen Zeitraum; Vergangenheit aus dem Archiv von „Börsenpreis jetzt"
//   (quelle 'archiv', Stufenverlauf ≤ 12 h), Bekanntes aus dem Zwischenspeicher.
//
// Eigenständig: setzt kein anderes Modul voraus und wird von keinem
// vorausgesetzt (Konsumenten wie EMS fragen hinter function_exists()).
// ===========================================================================

class Boersenpreis extends IPSModule
{
    private const LIBRARY_GUID = '{A5CA79FD-57C6-4F6E-A33B-61BCC2B0C9A7}';
    private const MODULE_GUID  = '{11BBF147-16A1-4332-82A3-29BB31154D03}';

    // 1.0 GetPriceCurve; 1.1 + GetPriceHistory() (gleiches Slot-Format, quelle 'archiv' für
    // aus dem Archiv rekonstruierte Viertelstunden) — rein additiv, gilt für beide Funktionen.
    private const CONTRACT_PRICECURVE = '1.1';

    // Symcon-Kernmodul Archive Control (im Verbund 38× verwendet, u. a. EMS/Dashboard).
    private const ARCHIVE_GUID      = '{43192F0B-135B-4CE7-A0A7-1475603F3060}';
    private const HISTORY_MAX_DAYS  = 400;   // Obergrenze je GetPriceHistory-Aufruf
    private const HOLD_MAX_SECONDS  = 43200; // Archivwert gilt höchstens 12 h weiter (Stillstand ≠ gleicher Preis)

    // Formular-Konvention (SUITE.md "Einheitliche Formular-Optik").
    private const NEWS_VERSION = '0.6.0';
    // Einheitliche Breite aller Eingabefelder: Symcon zeigt die Beschriftung IM Feld — Beschriftungen
    // kurz halten, Erklärungen als Label. Labels bleiben einfache Labels (Symcon bricht sie über die
    // volle Breite selbst um, wie in allen Verbund-Modulen). Überbreite entsteht durch NEBENEINANDER
    // stehende Eingabefelder (RowLayout) auf schmalen Bildschirmen — deshalb Felder untereinander.
    private const FIELD_WIDTH  = '400px';
    private const REPO_URL     = 'https://github.com/DG65/NRGBoersenpreis';
    private const LICENSE_URL  = 'https://github.com/DG65/NRGBoersenpreis/blob/main/LICENSE';
    private const PAYPAL_URL   = 'https://paypal.me/DietmarGureth';
    // Vorstellungs-Thread, von Dietmar am 16.09.2026 gepostet (ohne ?u=-Empfehlungsparameter).
    private const FORUM_THREAD_URL = 'https://community.symcon.de/t/beta-modul-nrg-stack-boersenpreis-day-ahead-boersenpreise-je-viertelstunde-negative-preise-erkennen-endpreise-fuer-den-symcon-energie-manager/144413';
    private const LOG_SENDER   = 'NRG-Stack Börsenpreis';

    private const SOURCE_ENERGYCHARTS = 0;
    private const SOURCE_AWATTAR      = 1;
    // EPEX-Spot-Day-Ahead-Ergebnisse über die ENTSO-E Transparency Platform — so bindet auch
    // Symcons eigenes Modul „Strompreis“ „EPEX Spot“ an. EPEX selbst gibt Daten nur mit Vertrag ab.
    private const SOURCE_ENTSOE       = 2;

    private const ENTSOE_URL       = 'https://web-api.tp.entsoe.eu/api';
    private const ENTSOE_TOKEN_URL = 'https://transparencyplatform.zendesk.com/hc/en-us/articles/12845911031188-How-to-get-security-token';
    private const ENTSOE_EIC       = [
        'DE-LU' => '10Y1001A1001A82H',
        'AT'    => '10YAT-APG------L',
    ];
    // Tibbers öffentliche Preisübersicht (wie in Symcons „Strompreis“): ohne Konto, je
    // Postleitzahl. Bestandteil „power“ = Börsenpreis (am 14.09.2026 gegen Energy-Charts
    // geprüft: alle 96 Viertelstunden ±0,005 ct), dazu „grid“ (Netzentgelt der PLZ) und
    // „taxes“ → Tibber-Endpreis. Kein dokumentierter API-Vertrag — kann sich ändern.
    private const SOURCE_TIBBER = 3;
    private const TIBBER_URL    = 'https://tibber.com/de/api/lookup/price-overview?postalCode=';

    // Tarifmodell für die Energie-Manager-Variable — gleiche Begriffe wie Tibber Grid Rewards
    // (spot/beschaffung/netzentgelt/steuernAbgaben, vat; abgestimmt 14.09.2026), aber VORWÄRTS
    // gerechnet: dort wird der Spotpreis als Rest aus Tibbers Endpreis gebildet, das geht nur bei
    // Tibbers „at cost“-Modell. Bundesweite Umlagen wie dort fest mit Stand — bei einer Änderung
    // hier UND bei Tibber (TAX_*) nachziehen.
    private const TAX_STAND       = '06/2026';
    private const TAX_STROMSTEUER = 2.05;   // ct/kWh netto
    private const TAX_OFFSHORE    = 0.941;
    private const TAX_KWK         = 0.446;
    private const TAX_STROMNEV19  = 1.56;
    private const VAT_PERCENT     = 19.0;
    // Tibber Grid Rewards: liefert den echten Tibber-Endkundenpreis des Nutzers (TIBBERGR_GetPriceCurve).
    private const TIBBERGR_GUID   = '{E92F62F4-88A6-4C6E-9F0D-E76C3B1C9A01}';

    private const EC_URL      = 'https://api.energy-charts.info/price';
    private const AWATTAR_URL = [
        'DE-LU' => 'https://api.awattar.de/v1/marketdata',
        'AT'    => 'https://api.awattar.at/v1/marketdata',
    ];
    // Gebotszonen, die beide Quellen liefern und deren Energy-Charts-Daten
    // unter CC BY 4.0 stehen (andere Zonen dort teils nur privat nutzbar).
    private const ZONES = [
        'DE-LU' => 'Deutschland/Luxemburg (DE-LU)',
        'AT'    => 'Österreich (AT)',
    ];

    private const HTTP_TIMEOUT       = 15;
    private const RETRY_SECONDS      = 900; // fehlender Tag: alle 15 min erneut
    private const SYNC_RETRY_SECONDS = 120; // Getter bei leerem Speicher: höchstens alle 2 min
    private const MIN_BLOCK_SECONDS  = 60;  // Ratenlimit ohne verwertbares Retry-After
    // Day-Ahead-Auktion 12:00 Uhr, Ergebnisse ab ca. 12:45 Uhr. Je Instanz ein
    // fester Versatz von 0–9 min, damit nicht alle Anlagen in derselben Minute fragen.
    private const PUBLISH_HOUR   = 12;
    private const PUBLISH_MINUTE = 45;

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyInteger('Source', self::SOURCE_ENERGYCHARTS);
        $this->RegisterPropertyString('BiddingZone', 'DE-LU');
        // Bis 0.4: einfache „Strompreis“-Rechnung für die Energie-Manager-Variable. Seit 0.5.0 ohne
        // Wirkung (ersetzt durch das Tarifmodell unten) — nur weiter registriert, damit bestehende
        // Instanzen keine entfernte Eigenschaft sehen (Migrationsvergleich, SUITE.md 9e).
        $this->RegisterPropertyFloat('MarketBase', 0.0);
        $this->RegisterPropertyFloat('MarketTax', 0.0);
        $this->RegisterPropertyFloat('MarketSurcharge', 0.0);
        // Nur für die Quelle Tibber; bewusst leer (keine Beispiel-PLZ als Vorgabe).
        $this->RegisterPropertyString('TibberPostalCode', '');
        // Tarif für die Energie-Manager-Variable (Panel „Symcon Energie Manager & Tarif“). Alles aus
        // bzw. 0 als Vorgabe: Netzentgelt, Konzessionsabgabe und Aufschlag sind je Netzgebiet,
        // Gemeinde und Anbieter verschieden — Beispielzahlen einer echten Anlage wären für jeden
        // anderen falsch (Lehre Tibber 2.8.1, keine eigene Anlage als Norm).
        $this->RegisterPropertyBoolean('UseTibberPrice', true);
        $this->RegisterPropertyBoolean('TariffEnabled', false);
        $this->RegisterPropertyFloat('TariffBeschaffung', 0.0);
        $this->RegisterPropertyFloat('TariffKonzession', 0.0);
        $this->RegisterPropertyFloat('NetzArbeitspreis', 0.0);
        $this->RegisterPropertyBoolean('Modul3Enabled', false);
        $this->RegisterPropertyFloat('NetzHT', 0.0);
        $this->RegisterPropertyFloat('NetzST', 0.0);
        $this->RegisterPropertyFloat('NetzNT', 0.0);
        // [{From:"HH:MM", To:"HH:MM", Band:"HT|ST|NT", Days:"all|weekday|weekend"}]
        $this->RegisterPropertyString('NetzWindows', '[]');
        $this->RegisterPropertyBoolean('Modul3Q1', true);
        $this->RegisterPropertyBoolean('Modul3Q2', true);
        $this->RegisterPropertyBoolean('Modul3Q3', true);
        $this->RegisterPropertyBoolean('Modul3Q4', true);

        // Zwischenspeicher bewusst als Attribut: geht er bei einem Modul-Resync
        // verloren, holt der nächste Abruf einfach alles neu.
        $this->RegisterAttributeString('PriceCache', '{}');
        $this->RegisterAttributeInteger('LastTry', 0);
        $this->RegisterAttributeInteger('BlockedUntil', 0);
        $this->RegisterAttributeString('LastError', '');
        $this->RegisterAttributeString('LoggedError', '');
        // Archivierung von „Börsenpreis jetzt" wird genau EINMAL eingeschaltet (Grundlage für
        // GetPriceHistory). Schaltet der Nutzer sie danach ab, bleibt sie aus.
        $this->RegisterAttributeBoolean('ArchiveInitDone', false);
        // ENTSO-E-Zugangsschlüssel: dauerhaft nötig, kein Handshake → Attribut, nie Property
        // (NRG-Stack-Credentials-Konvention). Klartext in der IPS-Datenbank, aber nie im
        // Formular, Log oder in Fehlermeldungen.
        $this->RegisterAttributeString('EntsoeToken', '');

        $this->RegisterAttributeBoolean('PurposeIntroGone', false);
        $this->RegisterAttributeString('SeenNews', '');
        $this->RegisterAttributeBoolean('ForumHintGone', false);
        $this->RegisterAttributeBoolean('DismissAdopted', false);

        $this->RegisterTimer('Fetch', 0, 'SPOT_Update($_IPS[\'TARGET\']);');
        $this->RegisterTimer('Tick', 0, 'SPOT_Tick($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        if (IPS_GetKernelRunlevel() !== KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELMESSAGE);
            return;
        }

        $this->ensureProfiles();
        $this->adoptDismissFromSibling();
        $this->MaintainVariable('CurrentPrice', 'Börsenpreis jetzt', VARIABLETYPE_FLOAT, 'SPOT.CentKWh', 1, true);
        $this->MaintainVariable('NegativeNow', 'Negativer Börsenpreis jetzt', VARIABLETYPE_BOOLEAN, 'SPOT.YesNo', 2, true);
        $this->MaintainVariable('NextNegativeStart', 'Nächste negative Viertelstunde', VARIABLETYPE_INTEGER, '~UnixTimestamp', 3, true);
        $this->MaintainVariable('TomorrowAvailable', 'Preise für morgen veröffentlicht', VARIABLETYPE_BOOLEAN, 'SPOT.YesNo', 4, true);
        // Format wie Symcons Modul „Strompreis“ (Ident MarketData) — der Energie Manager wählt
        // diese Variable im Feld „Energiepreise“.
        $this->MaintainVariable('MarketData', 'Marktdaten (Energie Manager)', VARIABLETYPE_STRING, '~TextBox', 5, true);
        $this->ensureArchiving();

        // Quelle oder Gebotszone gewechselt: alte Preise gehören nicht mehr zur
        // Einstellung und dürfen nicht als aktuelle Kurve weitergereicht werden.
        $cache = $this->cache();
        $plzKey = $this->source() === self::SOURCE_TIBBER ? $this->postalCode() : '';
        if (isset($cache['source']) && ((int)$cache['source'] !== $this->source() || (string)$cache['zone'] !== $this->zone() || (string)($cache['plz'] ?? '') !== $plzKey)) {
            $this->WriteAttributeString('PriceCache', '{}');
            $this->WriteAttributeInteger('BlockedUntil', 0);
            $this->WriteAttributeString('LastError', '');
        }

        $this->refresh(false);
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        // Instanz kann zwischen zwei Modul-Updates kurz fehlen (SUITE.md 9c).
        if ($Message === IPS_KERNELMESSAGE && isset($Data[0]) && $Data[0] === KR_READY && IPS_InstanceExists($this->InstanceID)) {
            $this->ApplyChanges();
        }
    }

    // -----------------------------------------------------------------
    // Öffentlicher Vertrag
    // -----------------------------------------------------------------

    /**
     * SPOT_GetPriceCurve($id): array — Day-Ahead-Börsenpreise heute + morgen
     * (sobald veröffentlicht), Format siehe Dateikopf. Liest nur den
     * Zwischenspeicher; ist er leer, wird einmalig gedrosselt nachgeladen,
     * damit der erste Aufruf einer frischen Instanz nicht leer zurückkommt.
     */
    public function GetPriceCurve(): array
    {
        $cache = $this->cache();
        if (count($cache['slots'] ?? []) === 0) {
            $now = $this->now();
            if ($now - $this->ReadAttributeInteger('LastTry') >= self::SYNC_RETRY_SECONDS && $this->ReadAttributeInteger('BlockedUntil') <= $now) {
                $this->refresh(true);
                $cache = $this->cache();
            }
        }
        $todayStart = $this->dayStart($this->now(), 0);
        $quelle = $this->cacheQuelle($cache);
        $out = [];
        foreach ($cache['slots'] ?? [] as $s) {
            if ((int)$s['end'] <= $todayStart) {
                continue; // gestern — gehört nicht mehr zu "heute + morgen"
            }
            $out[] = $this->contractSlot((int)$s['start'], (int)$s['end'], (float)$s['price'], $quelle, (int)$s['res']);
        }
        return $out;
    }

    /**
     * SPOT_GetPriceHistory($id, int $from, int $to): array — Börsenpreise je Viertelstunde mit
     * start in [$from, $to), gleiches Slot-Format wie GetPriceCurve (Vertrag 1.1).
     *
     * Vergangenheit kommt aus dem Symcon-Archiv der Variable „Börsenpreis jetzt": Das Archiv
     * speichert nur Änderungen, deshalb gilt ein Wert bis zum nächsten (Stufenverlauf, höchstens
     * 12 h) — quelle 'archiv', aufloesung 900. Viertelstunden, die der Zwischenspeicher kennt (heute,
     * morgen, ggf. gestern), kommen exakt von dort und haben Vorrang. Vor dem ersten
     * Archiveintrag und in der Zukunft ohne veröffentlichte Preise gibt es KEINE Einträge (nie
     * erfunden, nie 0). Kein Abruf bei der Quelle. Höchstens 400 Tage je Aufruf.
     */
    public function GetPriceHistory(int $from, int $to): array
    {
        $from = intdiv($from, 900) * 900;
        $to = intdiv($to + 899, 900) * 900;
        $from = max($from, strtotime('-' . self::HISTORY_MAX_DAYS . ' days', $to));
        if ($to <= $from) {
            return [];
        }
        $slots = $this->archivedSlots($from, $to);
        $cache = $this->cache();
        $quelle = $this->cacheQuelle($cache);
        foreach ($cache['slots'] ?? [] as $s) {
            if ($s['start'] >= $from && $s['start'] < $to) {
                $slots[(int)$s['start']] = $this->contractSlot((int)$s['start'], (int)$s['end'], (float)$s['price'], $quelle, (int)$s['res']);
            }
        }
        ksort($slots);
        return array_values($slots);
    }

    // -----------------------------------------------------------------
    // Aktionen (Formular-Button, Timer, Skripte)
    // -----------------------------------------------------------------

    /** SPOT_Update($id): string — Preise jetzt abrufen, mit Ergebnistext (auch Timer-Einstieg). */
    public function Update(): string
    {
        return $this->refresh(true);
    }

    /**
     * SPOT_SetEntsoeToken($id, string $Token): string — ENTSO-E-Zugangsschlüssel speichern
     * (leer = löschen). Schreibt nur das Attribut, leert das Eingabefeld, ruft bei gewählter
     * Quelle ENTSO-E sofort ab. Der Schlüssel erscheint nie im Rückgabetext.
     */
    public function SetEntsoeToken(string $Token): string
    {
        $Token = trim($Token);
        $this->WriteAttributeString('EntsoeToken', $Token);
        $this->WriteAttributeString('LoggedError', '');
        $this->UpdateFormField('EntsoeTokenInput', 'value', '');
        $this->UpdateFormField('EntsoeTokenStatus', 'caption', $this->entsoeTokenStatus());
        if ($Token === '') {
            return '🗑 ENTSO-E-Zugangsschlüssel gelöscht.';
        }
        if ($this->source() !== self::SOURCE_ENTSOE) {
            return '✅ Zugangsschlüssel gespeichert. Er wird genutzt, sobald die Quelle „EPEX Spot (über ENTSO-E)“ gewählt und übernommen ist.';
        }
        return "✅ Zugangsschlüssel gespeichert.\n" . $this->refresh(true);
    }

    /** Formular: Felder für den ENTSO-E-Schlüssel nur bei dieser Quelle zeigen (ändert nichts Gespeichertes). */
    public function UIChangeSource(int $Source): void
    {
        foreach (['EntsoeTokenStatus', 'EntsoeTokenInput', 'EntsoeTokenButton', 'EntsoeTokenGuide'] as $name) {
            $this->UpdateFormField($name, 'visible', $Source === self::SOURCE_ENTSOE);
        }
        $this->UpdateFormField('TibberPostalCode', 'visible', $Source === self::SOURCE_TIBBER);
        $this->UpdateFormField('TibberPostalHint', 'visible', $Source === self::SOURCE_TIBBER);
    }

    /** Timer-Einstieg: zu jeder Viertelstunde die Anzeige-Variablen aus dem Zwischenspeicher setzen. */
    public function Tick()
    {
        $this->applyCurrent();
        $this->scheduleTick();
    }

    // -----------------------------------------------------------------
    // Abruf und Zeitplanung
    // -----------------------------------------------------------------

    /**
     * Holt die Preise, wenn nötig ($force: auf jeden Fall — aber nie gegen ein
     * laufendes Ratenlimit der Quelle), setzt Variablen/Status und plant den
     * nächsten Abruf. Rückgabe: Ergebnistext für den Nutzer.
     */
    private function refresh(bool $force): string
    {
        $now = $this->now();
        $text = '';
        $blocked = $this->ReadAttributeInteger('BlockedUntil');
        if ($blocked > $now) {
            $text = '⏳ Die Quelle hat um eine Pause gebeten (Ratenlimit) — nächster Abruf frühestens ' . date('H:i:s', $blocked) . ' Uhr. Vorhandene Preise bleiben gültig.';
        } elseif ($force || $this->needsFetch($now)) {
            $text = $this->fetch($now);
        }
        $this->applyCurrent();
        $this->scheduleFetch();
        $this->scheduleTick();
        if ($text === '') {
            $text = '✅ ' . $this->cacheSummary() . ' Kein Abruf nötig.';
        }
        $this->UpdateFormField('FetchStatus', 'caption', $this->fetchStatusLine());
        $this->UpdateFormField('PriceSummary', 'caption', $this->priceSummary());
        $this->UpdateFormField('MarketSourceStatus', 'caption', $this->marketSourceStatus());
        return $text;
    }

    private function needsFetch(int $now): bool
    {
        if (!$this->hasDay($now, 0)) {
            return true;
        }
        return !$this->hasDay($now, 1) && $now >= $this->publishTs($now, 0);
    }

    /** Nächster sinnvoller Abrufzeitpunkt — der Timer feuert nur, wenn wirklich etwas fehlt. */
    private function scheduleFetch(): void
    {
        $now = $this->now();
        $blocked = $this->ReadAttributeInteger('BlockedUntil');
        if ($blocked > $now) {
            $next = $blocked + 5;
        } elseif (!$this->hasDay($now, 0)) {
            $next = $now + self::RETRY_SECONDS;
        } elseif (!$this->hasDay($now, 1)) {
            $publish = $this->publishTs($now, 0);
            $next = $now < $publish ? $publish : $now + self::RETRY_SECONDS;
        } else {
            $next = $this->publishTs($now, 1); // morgen gegen 12:45 Uhr den übernächsten Tag holen
        }
        $this->SetTimerInterval('Fetch', max(10, $next - $now) * 1000);
    }

    private function scheduleTick(): void
    {
        $now = $this->now();
        // Viertelstundengrenzen fallen in Mitteleuropa auf volle 900-s-Vielfache
        // der Unixzeit (Zeitzonen-Versatz = ganze Stunden) — ein Delta, keine Tageslänge.
        $next = (intdiv($now, 900) + 1) * 900 + 2;
        $this->SetTimerInterval('Tick', ($next - $now) * 1000);
    }

    /** Ein Abruf bei der eingestellten Quelle. Rückgabe: Ergebnistext. Fehler lassen den Zwischenspeicher unangetastet. */
    private function fetch(int $now): string
    {
        $this->WriteAttributeInteger('LastTry', $now);
        $source = $this->source();
        $zone = $this->zone();
        $todayStart = $this->dayStart($now, 0);
        $token = '';
        $plz = '';
        if ($source === self::SOURCE_TIBBER) {
            $sourceName = 'Tibber';
            $plz = $this->postalCode();
            if ($zone !== 'DE-LU') {
                return $this->fail('Die Tibber-Preisübersicht gibt es nur für Deutschland (Gebotszone DE-LU).');
            }
            if (!preg_match('/^\d{5}$/', $plz)) {
                return $this->fail('Für die Tibber-Preisübersicht fehlt eine gültige Postleitzahl (5 Ziffern, Panel „Datenquelle“).');
            }
            $url = self::TIBBER_URL . $plz;
        } elseif ($source === self::SOURCE_ENTSOE) {
            $sourceName = 'ENTSO-E';
            $token = trim((string)$this->ReadAttributeString('EntsoeToken'));
            if ($token === '') {
                return $this->fail('Für „EPEX Spot (über ENTSO-E)“ ist noch kein Zugangsschlüssel hinterlegt (Panel „Datenquelle“).');
            }
            // ENTSO-E erwartet UTC im Format JJJJMMTTHHMM; heute 00:00 bis übermorgen 00:00 Ortszeit.
            $url = self::ENTSOE_URL . '?securityToken=' . rawurlencode($token) . '&documentType=A44'
                . '&periodStart=' . gmdate('YmdHi', $todayStart) . '&periodEnd=' . gmdate('YmdHi', $this->dayStart($now, 2))
                . '&out_Domain=' . self::ENTSOE_EIC[$zone] . '&in_Domain=' . self::ENTSOE_EIC[$zone];
        } elseif ($source === self::SOURCE_AWATTAR) {
            $sourceName = 'aWATTar';
            $url = self::AWATTAR_URL[$zone] . '?start=' . ($todayStart * 1000) . '&end=' . ($this->dayStart($now, 2) * 1000);
        } else {
            $sourceName = 'Energy-Charts';
            $url = self::EC_URL . '?bzn=' . rawurlencode($zone) . '&start=' . date('Y-m-d', $todayStart) . '&end=' . date('Y-m-d', $this->dayStart($now, 1));
        }

        $r = $this->httpGet($url);
        if ($token !== '') {
            // PHP-Warnungen von file_get_contents enthalten die URL — Schlüssel nie weitergeben.
            $r['error'] = str_replace([$token, rawurlencode($token)], '***', (string)$r['error']);
        }
        $status = (int)$r['status'];
        if ($status === 429) {
            $wait = $this->retryAfterSeconds($r['headers']['retry-after'] ?? '', $now);
            $this->WriteAttributeInteger('BlockedUntil', $now + $wait);
            return $this->fail($sourceName . ' meldet ein Ratenlimit (HTTP 429) — Pause bis ' . date('H:i:s', $now + $wait) . ' Uhr.');
        }
        // ENTSO-E beantwortet „keine Daten“ mit HTTP 400 und einem Quittungs-XML samt Grund.
        if ($source === self::SOURCE_ENTSOE && ($status === 200 || $status === 400)) {
            $parsed = $this->parseEntsoe((string)$r['body']);
        } elseif ($status !== 200) {
            $why = $status === 0 ? 'nicht erreichbar' . ($r['error'] !== '' ? ' (' . $r['error'] . ')' : '')
                : ($status === 404 ? 'hat für diesen Zeitraum keine Daten (HTTP 404)'
                : ($status === 401 ? 'lehnt den Zugangsschlüssel ab (HTTP 401) — bitte Schlüssel prüfen' : 'antwortet mit HTTP ' . $status));
            return $this->fail($sourceName . ' ' . $why . '.');
        } else {
            $data = json_decode((string)$r['body'], true);
            if (!is_array($data)) {
                return $this->fail($sourceName . ' lieferte keine lesbaren Daten (kein JSON).');
            }
            $parsed = $source === self::SOURCE_AWATTAR ? $this->parseAwattar($data)
                : ($source === self::SOURCE_TIBBER ? $this->parseTibber($data) : $this->parseEnergyCharts($data));
        }
        if ($parsed['error'] !== '') {
            return $this->fail($sourceName . ': ' . $parsed['error']);
        }
        $slots = array_values(array_filter($parsed['slots'], function ($s) use ($todayStart) {
            return $s['end'] > $todayStart;
        }));
        if (count($slots) === 0) {
            return $this->fail($sourceName . ' lieferte keine Preise für heute oder morgen.');
        }

        $this->WriteAttributeString('PriceCache', json_encode([
            'fetchedAt' => $now,
            'source'    => $source,
            'zone'      => $zone,
            'plz'       => $plz,
            'license'   => $parsed['license'],
            'slots'     => $slots,
        ]));
        $this->WriteAttributeString('LastError', '');
        $this->WriteAttributeString('LoggedError', '');
        $this->SendDebug('Abruf', count($slots) . ' Viertelstunden von ' . $sourceName . ' (' . $zone . ')', 0);

        $text = '✅ ' . $this->cacheSummary();
        if (!$this->hasDay($now, 1)) {
            $publish = $this->publishTs($now, 0);
            $text .= "\nℹ️ Morgen ist noch nicht veröffentlicht (Day-Ahead-Auktion 12 Uhr, Ergebnisse ab ca. 12:45 Uhr) — nächster Versuch "
                . date('H:i', $now < $publish ? $publish : $now + self::RETRY_SECONDS) . ' Uhr.';
        }
        return $text;
    }

    /** Fehler festhalten: Attribut fürs Formular, dauerhaftes Log nur beim ersten Auftreten (SUITE.md „Sichtbare Rückmeldung", Regel 5). */
    private function fail(string $message): string
    {
        $this->WriteAttributeString('LastError', date('d.m.Y H:i', $this->now()) . ' Uhr: ' . $message);
        if ($this->ReadAttributeString('LoggedError') !== $message) {
            IPS_LogMessage(self::LOG_SENDER, 'Abruf fehlgeschlagen: ' . $message);
            $this->WriteAttributeString('LoggedError', $message);
        }
        $this->SendDebug('Abruf', 'Fehler: ' . $message, 0);
        $keep = count($this->cache()['slots'] ?? []) > 0 ? ' Vorhandene Preise bleiben gültig.' : ' Noch keine Preise vorhanden.';
        return '⚠️ Abruf fehlgeschlagen: ' . $message . $keep;
    }

    private function retryAfterSeconds(string $header, int $now): int
    {
        $header = trim($header);
        if ($header !== '' && ctype_digit($header)) {
            $wait = (int)$header;
        } elseif ($header !== '' && ($ts = strtotime($header)) !== false) {
            $wait = $ts - $now;
        } else {
            $wait = self::MIN_BLOCK_SECONDS;
        }
        return min(3600, max(self::MIN_BLOCK_SECONDS, $wait));
    }

    /**
     * HTTP-GET mit Statuscode und Antwort-Kopfzeilen (für 404/429/Retry-After).
     * Bewusst PHP-Streams statt Sys_GetURLContent: das liefert weder Status noch
     * Kopfzeilen. Testbar überschreibbar.
     *
     * @return array ['status' => int (0 = keine Antwort), 'headers' => [kleingeschrieben => Wert], 'body' => string, 'error' => string]
     */
    protected function httpGet(string $url): array
    {
        $lib = @IPS_GetLibrary(self::LIBRARY_GUID);
        $ua = 'NRG-Stack-Boersenpreis/' . (is_array($lib) ? ($lib['Version'] ?? '0') : '0') . ' (+' . self::REPO_URL . ')';
        $ctx = stream_context_create(['http' => [
            'method'        => 'GET',
            'timeout'       => self::HTTP_TIMEOUT,
            'ignore_errors' => true, // Antwortkörper und Status auch bei 4xx/5xx lesen
            'header'        => "Accept: application/json\r\nUser-Agent: " . $ua . "\r\n",
        ]]);
        $body = @file_get_contents($url, false, $ctx);
        $raw = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : ($http_response_header ?? []);
        if ($body === false && count($raw) === 0) {
            $err = error_get_last();
            return ['status' => 0, 'headers' => [], 'body' => '', 'error' => (string)($err['message'] ?? 'keine Antwort')];
        }
        [$status, $headers] = $this->parseResponseHeaders($raw);
        return ['status' => $status, 'headers' => $headers, 'body' => (string)$body, 'error' => ''];
    }

    /** Statuszeile + Kopfzeilen; nach Weiterleitungen stehen mehrere Antworten hintereinander — die letzte zählt. */
    private function parseResponseHeaders(array $raw): array
    {
        $status = 0;
        $headers = [];
        foreach ($raw as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $status = (int)$m[1];
                $headers = [];
            } elseif (strpos($line, ':') !== false) {
                [$k, $v] = explode(':', $line, 2);
                $headers[strtolower(trim($k))] = trim($v);
            }
        }
        return [$status, $headers];
    }

    // -----------------------------------------------------------------
    // Parser je Quelle — Rückgabe ['slots' => [[start,end,price,res]], 'license' => string, 'error' => string]
    // -----------------------------------------------------------------

    /**
     * Energy-Charts /price: {unix_seconds: [...], price: [...], unit: "EUR / MWh", license_info: "..."}.
     * Viertelstunden seit 01.10.2025 (vorher Stunden) — die Auflösung wird aus
     * dem Abstand der Zeitstempel bestimmt, nicht angenommen. null-Preise sind
     * Lücken und werden ausgelassen, nie als 0 geführt.
     */
    private function parseEnergyCharts(array $d): array
    {
        $ts = $d['unix_seconds'] ?? null;
        $pr = $d['price'] ?? null;
        if (!is_array($ts) || !is_array($pr) || count($ts) !== count($pr)) {
            return ['slots' => [], 'license' => '', 'error' => 'unerwartetes Antwortformat (unix_seconds/price fehlen oder sind ungleich lang).'];
        }
        $unit = strtolower(str_replace(' ', '', (string)($d['unit'] ?? '')));
        if ($unit !== '' && $unit !== 'eur/mwh') {
            return ['slots' => [], 'license' => '', 'error' => 'unerwartete Einheit „' . $d['unit'] . '" (erwartet EUR/MWh).'];
        }
        $res = $this->resolutionOf($ts);
        $slots = [];
        foreach ($ts as $i => $start) {
            if (!is_numeric($start) || !is_numeric($pr[$i] ?? null)) {
                continue;
            }
            $start = (int)$start;
            $slots[$start] = ['start' => $start, 'end' => $start + $res, 'price' => $this->ctPerKwh((float)$pr[$i]), 'res' => $res];
        }
        ksort($slots);
        return ['slots' => array_values($slots), 'license' => (string)($d['license_info'] ?? ''), 'error' => ''];
    }

    /**
     * aWATTar /v1/marketdata: {data: [{start_timestamp: ms, end_timestamp: ms, marketprice: EUR/MWh, unit: "Eur/MWh"}]}.
     * Liefert Stundenwerte. Verbraucher im Verbund ordnen jeden Preis der
     * Viertelstunde seines 'start' zu (EMS parsePT15M) — ein Stundenslot ließe
     * dort drei von vier Viertelstunden leer. Deshalb auf vier gleiche
     * Viertelstunden verteilen; 'res' behält die echte Auflösung (3600).
     */
    private function parseAwattar(array $d): array
    {
        $rows = $d['data'] ?? null;
        if (!is_array($rows)) {
            return ['slots' => [], 'license' => '', 'error' => 'unerwartetes Antwortformat (data fehlt).'];
        }
        $slots = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !is_numeric($row['start_timestamp'] ?? null) || !is_numeric($row['end_timestamp'] ?? null) || !is_numeric($row['marketprice'] ?? null)) {
                continue;
            }
            $unit = strtolower(str_replace(' ', '', (string)($row['unit'] ?? 'Eur/MWh')));
            if ($unit !== 'eur/mwh') {
                return ['slots' => [], 'license' => '', 'error' => 'unerwartete Einheit „' . $row['unit'] . '" (erwartet Eur/MWh).'];
            }
            $s = intdiv((int)$row['start_timestamp'], 1000);
            $e = intdiv((int)$row['end_timestamp'], 1000);
            $res = $e - $s;
            if ($res <= 0) {
                continue;
            }
            $price = $this->ctPerKwh((float)$row['marketprice']);
            $step = ($res % 900 === 0) ? 900 : $res;
            for ($t = $s; $t < $e; $t += $step) {
                $slots[$t] = ['start' => $t, 'end' => $t + $step, 'price' => $price, 'res' => $res];
            }
        }
        ksort($slots);
        return ['slots' => array_values($slots), 'license' => '', 'error' => ''];
    }

    /**
     * Tibber-Preisübersicht: energy.todayQuarterHours/tomorrowQuarterHours (ersatzweise …Hours)
     * mit date/hour/minute (Ortszeit OHNE Zeitzone), priceIncludingVat (EUR/kWh) und
     * priceComponents[type=power|grid|taxes]. Vertragspreis = „power“ (Börsenpreis); je Slot
     * zusätzlich 'retail' = Tibber-Endpreis inkl. MwSt in ct/kWh, NUR für die Energie-Manager-
     * Variable. Sommerzeit: Folgt ein Eintrag zeitlich lückenlos auf den vorigen (vorig + Raster
     * zeigt dieselbe Wanduhrzeit), gilt dieser Zeitpunkt — so landet die doppelte 02:xx-Stunde
     * am 25-Stunden-Tag richtig in der Winterzeit; sonst mktime().
     */
    private function parseTibber(array $d): array
    {
        $e = $d['energy'] ?? null;
        if (!is_array($e)) {
            return ['slots' => [], 'license' => '', 'error' => 'unerwartetes Antwortformat (energy fehlt).'];
        }
        $slots = [];
        foreach ([['todayQuarterHours', 'todayHours'], ['tomorrowQuarterHours', 'tomorrowHours']] as [$qKey, $hKey]) {
            $rows = $e[$qKey] ?? [];
            $res = 900;
            if (!is_array($rows) || count($rows) === 0) {
                $rows = $e[$hKey] ?? [];
                $res = 3600;
            }
            if (!is_array($rows)) {
                continue;
            }
            $last = null;
            foreach ($rows as $row) {
                $date = explode('-', (string)($row['date'] ?? ''));
                if (count($date) !== 3 || !is_numeric($row['hour'] ?? null) || !is_numeric($row['priceIncludingVat'] ?? null)) {
                    continue;
                }
                $power = null;
                foreach ($row['priceComponents'] ?? [] as $c) {
                    if (($c['type'] ?? '') === 'power' && is_numeric($c['priceExcludingVat'] ?? null)) {
                        $power = (float)$c['priceExcludingVat'];
                    }
                }
                if ($power === null) {
                    continue; // ohne Börsenpreisanteil kein Eintrag — nie den Endpreis als Börsenpreis ausgeben
                }
                $h = (int)$row['hour'];
                $min = (int)($row['minute'] ?? 0);
                $expected = $last !== null ? $last + $res : null;
                if ($expected !== null && (int)date('G', $expected) === $h && (int)date('i', $expected) === $min && date('Y-m-d', $expected) === (string)$row['date']) {
                    $ts = $expected;
                } else {
                    $ts = mktime($h, $min, 0, (int)$date[1], (int)$date[2], (int)$date[0]);
                }
                $last = $ts;
                $retail = round((float)$row['priceIncludingVat'] * 100, 4);
                for ($t = $ts; $t < $ts + $res; $t += 900) {
                    $slots[$t] = ['start' => $t, 'end' => $t + 900, 'price' => round($power * 100, 4), 'res' => $res, 'retail' => $retail];
                }
            }
        }
        if (count($slots) === 0) {
            return ['slots' => [], 'license' => '', 'error' => 'lieferte keine Preise (Postleitzahl bekannt?).'];
        }
        ksort($slots);
        return ['slots' => array_values($slots), 'license' => '', 'error' => ''];
    }

    /**
     * ENTSO-E A44 (Day-Ahead-Preise, XML „Publication_MarketDocument“). Je Liefertag können
     * mehrere TimeSeries kommen — wie Symcons „Strompreis“ gilt die mit der kleinsten
     * classificationSequence-Position. Kurventyp A03 lässt Punkte weg, deren Preis sich nicht
     * ändert: fehlende Positionen (auch am Periodenende) übernehmen den vorigen Preis.
     * Stundenwerte (PT60M) werden wie bei aWATTar auf Viertelstunden verteilt.
     * „Keine Daten“ kommt als „Acknowledgement_MarketDocument“ mit Grund.
     */
    private function parseEntsoe(string $body): array
    {
        $err = function (string $m) {
            return ['slots' => [], 'license' => '', 'error' => $m];
        };
        $prev = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if ($xml === false) {
            return $err('keine lesbaren Daten (kein XML).');
        }
        if ($xml->getName() === 'Acknowledgement_MarketDocument') {
            $reason = trim((string)($xml->xpath('//*[local-name()="Reason"]/*[local-name()="text"]')[0] ?? ''));
            return $err(stripos($reason, 'No matching data') !== false
                ? 'hat für diesen Zeitraum noch keine Daten.'
                : 'meldet: ' . ($reason !== '' ? mb_substr($reason, 0, 200) : 'Anfrage abgelehnt') . '.');
        }
        $chosen = [];
        foreach ($xml->xpath('//*[local-name()="TimeSeries"]') ?: [] as $ts) {
            $currency = strtoupper((string)($ts->xpath('./*[local-name()="currency_Unit.name"]')[0] ?? ''));
            $unit = strtoupper((string)($ts->xpath('./*[local-name()="price_Measure_Unit.name"]')[0] ?? ''));
            if ($currency !== 'EUR' || $unit !== 'MWH') {
                return $err('unerwartete Einheit „' . $currency . '/' . $unit . '“ (erwartet EUR/MWH).');
            }
            $pos = (int)($ts->xpath('./*[local-name()="classificationSequence_AttributeInstanceComponent.position"]')[0] ?? 0);
            foreach ($ts->xpath('./*[local-name()="Period"]') ?: [] as $period) {
                $start = strtotime((string)($period->xpath('./*[local-name()="timeInterval"]/*[local-name()="start"]')[0] ?? ''));
                $end = strtotime((string)($period->xpath('./*[local-name()="timeInterval"]/*[local-name()="end"]')[0] ?? ''));
                if ($start === false || $end === false || $end <= $start) {
                    continue;
                }
                if (!isset($chosen[$start]) || $pos < $chosen[$start]['pos']) {
                    $chosen[$start] = ['pos' => $pos, 'period' => $period, 'end' => $end];
                }
            }
        }
        $slots = [];
        foreach ($chosen as $start => $c) {
            $resText = (string)($c['period']->xpath('./*[local-name()="resolution"]')[0] ?? '');
            $res = ['PT15M' => 900, 'PT30M' => 1800, 'PT60M' => 3600][$resText] ?? 0;
            if ($res === 0) {
                return $err('unerwartete Auflösung „' . $resText . '“.');
            }
            $byPos = [];
            foreach ($c['period']->xpath('./*[local-name()="Point"]') ?: [] as $pt) {
                $p = (int)($pt->xpath('./*[local-name()="position"]')[0] ?? 0);
                $amount = (string)($pt->xpath('./*[local-name()="price.amount"]')[0] ?? '');
                if ($p > 0 && is_numeric($amount)) {
                    $byPos[$p] = (float)$amount;
                }
            }
            $count = intdiv($c['end'] - $start, $res);
            $price = null;
            for ($p = 1; $p <= $count; $p++) {
                if (isset($byPos[$p])) {
                    $price = $byPos[$p];
                }
                if ($price === null) {
                    continue; // vor dem ersten Punkt nichts erfinden
                }
                $t0 = $start + ($p - 1) * $res;
                for ($t = $t0; $t < $t0 + $res; $t += 900) {
                    $slots[$t] = ['start' => $t, 'end' => $t + 900, 'price' => $this->ctPerKwh($price), 'res' => $res];
                }
            }
        }
        if (count($slots) === 0) {
            return $err('lieferte keine Preise (keine TimeSeries).');
        }
        ksort($slots);
        return ['slots' => array_values($slots), 'license' => '', 'error' => ''];
    }

    /** Häufigster Abstand zweier Zeitstempel (Sekunden); ein einzelner Wert gilt als Viertelstunde. */
    private function resolutionOf(array $ts): int
    {
        $count = [];
        $prev = null;
        foreach ($ts as $t) {
            if (!is_numeric($t)) {
                continue;
            }
            if ($prev !== null && (int)$t > $prev) {
                $delta = (int)$t - $prev;
                $count[$delta] = ($count[$delta] ?? 0) + 1;
            }
            $prev = (int)$t;
        }
        if (count($count) === 0) {
            return 900;
        }
        arsort($count);
        return (int)array_key_first($count);
    }

    /** EUR/MWh → ct/kWh (1 EUR/MWh = 0,1 ct/kWh). */
    private function ctPerKwh(float $eurPerMwh): float
    {
        return round($eurPerMwh / 10, 4);
    }

    // -----------------------------------------------------------------
    // Anzeige-Variablen und Status
    // -----------------------------------------------------------------

    private function applyCurrent(): void
    {
        $now = $this->now();
        $current = null;
        $nextNeg = 0;
        foreach ($this->cache()['slots'] ?? [] as $s) {
            if ($s['start'] <= $now && $now < $s['end']) {
                $current = $s;
            }
            if ($nextNeg === 0 && $s['end'] > $now && $s['price'] < 0) {
                $nextNeg = (int)$s['start'];
            }
        }
        if ($current !== null) {
            $this->setIfChanged('CurrentPrice', (float)$current['price']);
        }
        // Ohne Preis für jetzt KEIN „negativ" behaupten; der Preis selbst bleibt
        // stehen (eine Float-Variable kann „unbekannt" nicht ausdrücken) — der
        // Instanzstatus 201 sagt, dass er nicht aktuell ist.
        $this->setIfChanged('NegativeNow', $current !== null && $current['price'] < 0);
        $this->setIfChanged('NextNegativeStart', $nextNeg);
        $this->setIfChanged('TomorrowAvailable', $this->hasDay($now, 1));
        $this->setIfChanged('MarketData', $this->marketDataJson($now));

        // Ein gescheiterter Abruf bei gültigen Preisen (z. B. Ratenlimit) ist kein Instanzfehler:
        // Watchdogs sehen nur den Status (SUITE.md 9d). Er steht im Formular und einmal im Log.
        $this->SetStatus($current === null ? 201 : 102);
    }

    // -----------------------------------------------------------------
    // Archiv (Grundlage für GetPriceHistory)
    // -----------------------------------------------------------------

    private function archiveID(): int
    {
        $list = IPS_GetInstanceListByModuleID(self::ARCHIVE_GUID);
        return count($list) > 0 ? (int)$list[0] : 0;
    }

    /** Archivierung von „Börsenpreis jetzt" einmalig einschalten — danach Nutzer-Hoheit. */
    private function ensureArchiving(): void
    {
        if ($this->ReadAttributeBoolean('ArchiveInitDone') || !function_exists('AC_SetLoggingStatus')) {
            return;
        }
        $arch = $this->archiveID();
        $vid = IPS_GetObjectIDByIdent('CurrentPrice', $this->InstanceID);
        if ($arch <= 0 || $vid === false) {
            return; // kein Archiv (noch) — beim nächsten Übernehmen erneut versuchen
        }
        if (!AC_GetLoggingStatus($arch, (int)$vid)) {
            AC_SetLoggingStatus($arch, (int)$vid, true);
            IPS_ApplyChanges($arch);
            $this->SendDebug('Archiv', 'Archivierung von „Börsenpreis jetzt" eingeschaltet', 0);
        }
        $this->WriteAttributeBoolean('ArchiveInitDone', true);
    }

    /**
     * Viertelstunden [$from, $to) aus dem Archiv als Stufenverlauf, höchstens bis zur laufenden
     * Viertelstunde. Rückgabe: [start => Vertrags-Slot].
     */
    private function archivedSlots(int $from, int $to): array
    {
        if (!function_exists('AC_GetLoggedValues')) {
            return [];
        }
        $arch = $this->archiveID();
        $vid = IPS_GetObjectIDByIdent('CurrentPrice', $this->InstanceID);
        if ($arch <= 0 || $vid === false) {
            return [];
        }
        $vid = (int)$vid;
        // Tageweise abfragen (SUITE.md 9g: > 50 000 intern gelesene Werte → false), nie „seit
        // Epoche“. false heißt hier „für diese Variable kein Logging“ (@ unterdrückt nur die
        // Warnung dazu) — keine Historie, kein Fehler; ein Tag hat höchstens ~100 Änderungen.
        $rows = [];
        for ($day = $this->dayStart($from, 0); $day < $to; $day = $this->dayStart($day, 1)) {
            $chunk = @AC_GetLoggedValues($arch, $vid, max($from, $day), min($to, $this->dayStart($day, 1)) - 1, 0);
            if (is_array($chunk)) {
                $rows = array_merge($rows, $chunk);
            } else {
                $this->SendDebug('Archiv', 'Keine Archivwerte lesbar für ' . date('d.m.Y', $day), 0);
            }
        }
        // Wert vor Beginn: nur im Gültigkeitsfenster (12 h) suchen — älter zählt ohnehin nicht.
        $before = @AC_GetLoggedValues($arch, $vid, $from - self::HOLD_MAX_SECONDS, $from - 1, 1);
        $current = null;
        $since = 0;
        if (is_array($before) && count($before) > 0) {
            $current = (float)$before[0]['Value'];
            $since = intdiv((int)$before[0]['TimeStamp'], 900) * 900;
        }

        // Jeder Archivwert gehört zu der Viertelstunde, in der er geschrieben wurde.
        $bySlot = [];
        foreach ($rows as $r) {
            $bySlot[intdiv((int)$r['TimeStamp'], 900) * 900][] = $r;
        }
        foreach ($bySlot as &$list) {
            usort($list, function ($a, $b) {
                return $a['TimeStamp'] <=> $b['TimeStamp'];
            });
            $list = (float)end($list)['Value'];
        }
        unset($list);

        $out = [];
        $last = min($to, intdiv($this->now(), 900) * 900 + 900);
        for ($t = $from; $t < $last; $t += 900) {
            if (isset($bySlot[$t])) {
                $current = $bySlot[$t];
                $since = $t;
            }
            // Ein Archivwert gilt bis zum nächsten — aber höchstens 12 h: Das Archiv kann einen
            // gleichbleibenden Preis nicht von einem Stillstand (Symcon aus) unterscheiden, und
            // über Stunden identische Viertelstundenpreise kommen praktisch nicht vor.
            if ($current !== null && $t - $since < self::HOLD_MAX_SECONDS) {
                $out[$t] = $this->contractSlot($t, $t + 900, $current, 'archiv', 900);
            }
        }
        return $out;
    }

    private function contractSlot(int $start, int $end, float $price, string $quelle, int $res): array
    {
        return [
            'start'           => $start,
            'end'             => $end,
            'price'           => $price,
            'basis'           => 'spot',
            'netzentgelt'     => 'fehlt',
            'level'           => null,
            'quelle'          => $quelle,
            'aufloesung'      => $res,
            'contractVersion' => self::CONTRACT_PRICECURVE,
        ];
    }

    private function cacheQuelle(array $cache): string
    {
        return [self::SOURCE_AWATTAR => 'awattar', self::SOURCE_ENTSOE => 'entsoe', self::SOURCE_TIBBER => 'tibber'][(int)($cache['source'] ?? $this->source())] ?? 'energy-charts';
    }

    /**
     * Inhalt der Variable „Marktdaten (Energie Manager)“ im Format von Symcons Modul
     * „Strompreis“: [{"start": Unix, "end": Unix, "price": ct/kWh brutto}, …], ab der laufenden
     * Viertelstunde höchstens 24 Stunden (wie dort). Preis je Viertelstunde, erste Quelle gewinnt:
     *   1. Tibber Grid Rewards installiert (und nicht abgewählt): der echte Tibber-Endpreis,
     *   2. eigener Tarif eingeschaltet: (Börsenpreis + Beschaffung + Netzentgelt + Steuern/Abgaben) × MwSt,
     *   3. Quelle Tibber-Preisübersicht: Tibbers Endpreis für die Postleitzahl,
     *   4. sonst der reine Börsenpreis.
     * Nur diese Variable rechnet so; Vertrag und übrige Variablen bleiben der reine Börsenpreis.
     */
    private function marketDataJson(int $now): string
    {
        $tibber = $this->tibberGridRewardsInfo()['curve'];
        $tariff = (bool)$this->ReadPropertyBoolean('TariffEnabled');
        $out = [];
        foreach ($this->cache()['slots'] ?? [] as $s) {
            if ($s['end'] <= $now) {
                continue;
            }
            if (count($out) >= 96) {
                break;
            }
            $price = $this->tibberPriceAt($tibber, (int)$s['start']);
            if ($price === null) {
                $price = $tariff ? $this->tariffPrice($s) : (isset($s['retail']) ? (float)$s['retail'] : (float)$s['price']);
            }
            $out[] = ['start' => (int)$s['start'], 'end' => (int)$s['end'], 'price' => round($price, 4)];
        }
        return json_encode($out);
    }

    /**
     * Endkundenpreis von Tibber Grid Rewards, falls installiert und nicht abgewählt — Tibber kennt
     * den echten Preis des Nutzers genauer als jede eigene Rechnung (Empfehlung der Tibber-Sitzung,
     * 14.09.2026). Nur Slots mit basis 'endkunde', nur Vertrags-Hauptversion 1 (Update-Meldepflicht).
     * Rückgabe: ['id' => Instanz, 'curve' => [[start, end, price brutto ct/kWh], …], 'problem' => Text].
     */
    private function tibberGridRewardsInfo(): array
    {
        $info = ['id' => 0, 'curve' => [], 'problem' => ''];
        if (!$this->ReadPropertyBoolean('UseTibberPrice') || !function_exists('TIBBERGR_GetPriceCurve')) {
            return $info;
        }
        $list = IPS_GetInstanceListByModuleID(self::TIBBERGR_GUID);
        if (count($list) === 0) {
            return $info;
        }
        $info['id'] = (int)$list[0];
        try {
            $curve = TIBBERGR_GetPriceCurve($info['id']);
        } catch (Throwable $e) {
            $info['problem'] = 'Tibber Grid Rewards (#' . $info['id'] . ') antwortet nicht: ' . $e->getMessage();
            return $info;
        }
        if (!is_array($curve) || count($curve) === 0) {
            $info['problem'] = 'Tibber Grid Rewards (#' . $info['id'] . ') liefert gerade keine Preise.';
            return $info;
        }
        $version = (string)($curve[0]['contractVersion'] ?? '1.0');
        if ((int)explode('.', $version)[0] !== 1) {
            $info['problem'] = 'Dieses Modul benötigt eine Aktualisierung, um Tibber Grid Rewards zu nutzen (Vertrag 1.x erwartet, ' . $version . ' geliefert).';
            return $info;
        }
        foreach ($curve as $c) {
            if (!is_array($c) || !is_numeric($c['start'] ?? null) || !is_numeric($c['end'] ?? null) || !is_numeric($c['price'] ?? null)
                || ($c['basis'] ?? 'endkunde') !== 'endkunde') {
                continue;
            }
            $info['curve'][] = [(int)$c['start'], (int)$c['end'], (float)$c['price']];
        }
        return $info;
    }

    private function tibberPriceAt(array $curve, int $t): ?float
    {
        foreach ($curve as [$start, $end, $price]) {
            if ($start <= $t && $t < $end) {
                return $price;
            }
        }
        return null;
    }

    /** Eigener Tarif, vorwärts: (spot + beschaffung + netzentgelt + steuernAbgaben) × (1 + MwSt), ct/kWh brutto. */
    private function tariffPrice(array $slot): float
    {
        $net = (float)$slot['price'] + (float)$this->ReadPropertyFloat('TariffBeschaffung')
            + $this->netzentgeltAt((int)$slot['start']) + $this->steuernAbgaben();
        return $net * (1 + self::VAT_PERCENT / 100);
    }

    private function steuernAbgaben(): float
    {
        return (float)$this->ReadPropertyFloat('TariffKonzession')
            + self::TAX_STROMSTEUER + self::TAX_OFFSHORE + self::TAX_KWK + self::TAX_STROMNEV19;
    }

    /**
     * Netzentgelt (ct/kWh netto) für einen Zeitpunkt: § 14a Modul 3 (HT/ST/NT nach Zeitfenstern),
     * wenn eingeschaltet und im Kalenderquartal gültig — Fenster je „alle Tage“, „Mo–Fr“ oder
     * „Sa–So“ (Wochenende war bei Tibber eine offene Lücke). Sonst, und außerhalb jedes Fensters,
     * der Arbeitspreis. „Bis 00:00“ heißt Tagesende; Fenster über Mitternacht sind erlaubt.
     */
    private function netzentgeltAt(int $t): float
    {
        $arbeitspreis = (float)$this->ReadPropertyFloat('NetzArbeitspreis');
        if (!$this->ReadPropertyBoolean('Modul3Enabled') || !$this->ReadPropertyBoolean('Modul3Q' . (int)ceil((int)date('n', $t) / 3))) {
            return $arbeitspreis;
        }
        $windows = json_decode((string)$this->ReadPropertyString('NetzWindows'), true);
        $minute = (int)date('G', $t) * 60 + (int)date('i', $t);
        $weekend = (int)date('N', $t) >= 6;
        foreach (is_array($windows) ? $windows : [] as $w) {
            $days = (string)($w['Days'] ?? 'all');
            if (($days === 'weekday' && $weekend) || ($days === 'weekend' && !$weekend)) {
                continue;
            }
            $from = $this->minutesOf((string)($w['From'] ?? ''));
            $to = $this->minutesOf((string)($w['To'] ?? ''));
            if ($from === null || $to === null) {
                continue;
            }
            if ($to === 0) {
                $to = 1440;
            }
            $hit = $from <= $to ? ($minute >= $from && $minute < $to) : ($minute >= $from || $minute < $to);
            if ($hit) {
                $band = ['HT' => 'NetzHT', 'ST' => 'NetzST', 'NT' => 'NetzNT'][(string)($w['Band'] ?? '')] ?? '';
                return $band !== '' ? (float)$this->ReadPropertyFloat($band) : $arbeitspreis;
            }
        }
        return $arbeitspreis;
    }

    private function minutesOf(string $hhmm): ?int
    {
        return preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', trim($hhmm), $m) ? (int)$m[1] * 60 + (int)$m[2] : null;
    }

    /** Eine Zeile fürs Formular: woher der Preis in „Marktdaten (Energie Manager)“ gerade kommt. */
    private function marketSourceStatus(): string
    {
        $info = $this->tibberGridRewardsInfo();
        $tariff = (bool)$this->ReadPropertyBoolean('TariffEnabled');
        $fallback = $tariff ? 'deinem eigenen Tarif'
            : ($this->source() === self::SOURCE_TIBBER ? 'Tibbers Endpreis aus der Tibber-Preisübersicht (PLZ ' . $this->postalCode() . ')' : 'dem reinen Börsenpreis');
        if (count($info['curve']) > 0) {
            return '✅ Preis aus Tibber Grid Rewards (#' . $info['id'] . ') — dein echter Tibber-Endpreis, soweit Tibber ihn schon kennt; sonst aus ' . $fallback . '.';
        }
        $line = $tariff ? '🧾 Preis aus deinem eigenen Tarif (Börsenpreis + Aufschlag + Netzentgelt + Steuern und Abgaben, inkl. 19 % MwSt).'
            : ($this->source() === self::SOURCE_TIBBER ? '🏷 Endpreis aus der Tibber-Preisübersicht für PLZ ' . $this->postalCode() . ' (inkl. MwSt).'
            : 'ℹ️ Reiner Börsenpreis (netto) — für einen echten Endpreis unten den eigenen Tarif einschalten.');
        return ($info['problem'] !== '' ? '⚠️ ' . $info['problem'] . "\n" : '') . $line;
    }

    private function entsoeTokenStatus(): string
    {
        $t = trim((string)$this->ReadAttributeString('EntsoeToken'));
        return $t === ''
            ? 'ℹ️ Noch kein ENTSO-E-Zugangsschlüssel hinterlegt.'
            : '✅ ENTSO-E-Zugangsschlüssel hinterlegt (endet auf …' . mb_substr($t, -4) . ').';
    }

    private function setIfChanged(string $ident, $value): void
    {
        if ($this->GetValue($ident) !== $value) {
            $this->SetValue($ident, $value);
        }
    }

    private function ensureProfiles(): void
    {
        if (!IPS_VariableProfileExists('SPOT.CentKWh')) {
            IPS_CreateVariableProfile('SPOT.CentKWh', VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileDigits('SPOT.CentKWh', 2);
            IPS_SetVariableProfileText('SPOT.CentKWh', '', ' ct/kWh');
        }
        if (!IPS_VariableProfileExists('SPOT.YesNo')) {
            IPS_CreateVariableProfile('SPOT.YesNo', VARIABLETYPE_BOOLEAN);
            IPS_SetVariableProfileAssociation('SPOT.YesNo', 0, 'Nein', '', -1);
            IPS_SetVariableProfileAssociation('SPOT.YesNo', 1, 'Ja', '', -1);
        }
    }

    // -----------------------------------------------------------------
    // Helfer
    // -----------------------------------------------------------------

    /** Testbar überschreibbar (Prüfstand ohne Symcon). */
    protected function now(): int
    {
        return time();
    }

    private function source(): int
    {
        $s = (int)$this->ReadPropertyInteger('Source');
        return in_array($s, [self::SOURCE_AWATTAR, self::SOURCE_ENTSOE, self::SOURCE_TIBBER], true) ? $s : self::SOURCE_ENERGYCHARTS;
    }

    private function zone(): string
    {
        $zone = (string)$this->ReadPropertyString('BiddingZone');
        return isset(self::ZONES[$zone]) ? $zone : 'DE-LU';
    }

    private function postalCode(): string
    {
        return trim((string)$this->ReadPropertyString('TibberPostalCode'));
    }

    private function cache(): array
    {
        // (string): während eines Neuladens liefert das SDK false statt '' (SUITE.md 9c).
        $cache = json_decode((string)$this->ReadAttributeString('PriceCache'), true);
        return is_array($cache) ? $cache : [];
    }

    /** Mitternacht des Tages $ts + $offset Tage — per Kalender, nie per ±86400 (SUITE.md Stolperstein 18). */
    private function dayStart(int $ts, int $offset): int
    {
        return mktime(0, 0, 0, (int)date('n', $ts), (int)date('j', $ts) + $offset, (int)date('Y', $ts));
    }

    private function publishTs(int $ts, int $offset): int
    {
        return mktime(self::PUBLISH_HOUR, self::PUBLISH_MINUTE + $this->InstanceID % 10, 0, (int)date('n', $ts), (int)date('j', $ts) + $offset, (int)date('Y', $ts));
    }

    /** Liegt mindestens ein Preis für den Tag $ts + $offset vor? */
    private function hasDay(int $ts, int $offset): bool
    {
        return count($this->daySlots($ts, $offset)) > 0;
    }

    private function daySlots(int $ts, int $offset): array
    {
        $from = $this->dayStart($ts, $offset);
        $to = $this->dayStart($ts, $offset + 1);
        return array_values(array_filter($this->cache()['slots'] ?? [], function ($s) use ($from, $to) {
            return $s['start'] >= $from && $s['start'] < $to;
        }));
    }

    private function sourceName(int $source): string
    {
        return [self::SOURCE_AWATTAR => 'aWATTar', self::SOURCE_ENTSOE => 'EPEX Spot (ENTSO-E)', self::SOURCE_TIBBER => 'Tibber'][$source] ?? 'Energy-Charts';
    }

    private function cacheSummary(): string
    {
        $now = $this->now();
        $today = count($this->daySlots($now, 0));
        $tomorrow = count($this->daySlots($now, 1));
        $cache = $this->cache();
        if ($today + $tomorrow === 0) {
            return 'Noch keine Preise vorhanden.';
        }
        $neg = 0;
        foreach (array_merge($this->daySlots($now, 0), $this->daySlots($now, 1)) as $s) {
            if ($s['price'] < 0) {
                $neg++;
            }
        }
        return ($today + $tomorrow) . ' Viertelstunden (' . ($tomorrow > 0 ? 'heute + morgen' : 'nur heute') . ') von '
            . $this->sourceName((int)($cache['source'] ?? $this->source())) . ', ' . ($neg === 0 ? 'keine negativ.' : 'davon ' . $neg . ' negativ.');
    }

    private function fmtCt(float $v): string
    {
        return number_format($v, 2, ',', '.') . ' ct/kWh';
    }

    // -----------------------------------------------------------------
    // Formular
    // -----------------------------------------------------------------

    public function AckPurposeIntro(): void
    {
        $this->WriteAttributeBoolean('PurposeIntroGone', true);
        $this->UpdateFormField('PurposeIntroPanel', 'visible', false);
        $this->propagateDismiss();
    }

    /** SPOT_ShowPurposeIntro($id): string — „Wozu dieses Modul?“ wieder einblenden (nur diese Instanz). */
    public function ShowPurposeIntro(): string
    {
        $this->WriteAttributeBoolean('PurposeIntroGone', false);
        $this->UpdateFormField('PurposeIntroPanel', 'visible', true);
        return '👋 „Wozu dieses Modul?“ steht wieder ganz oben im Formular.';
    }

    public function AckNews(): void
    {
        $this->WriteAttributeString('SeenNews', self::NEWS_VERSION);
        $this->UpdateFormField('NewsPanel', 'visible', false);
        $this->propagateDismiss();
    }

    public function AckForumHint(): void
    {
        $this->WriteAttributeBoolean('ForumHintGone', true);
        $this->UpdateFormField('ForumHintPanel', 'visible', false);
        $this->propagateDismiss();
    }

    /**
     * SPOT_GetDismissState($id): string — Ausblende-Zustand dieser Instanz als JSON
     * {purpose, news, forum}. Attribute sind von außen nicht lesbar, deshalb diese Funktion
     * (SUITE.md „Ausblenden über mehrere Instanzen desselben Moduls teilen“, 14.09.2026).
     */
    public function GetDismissState(): string
    {
        return json_encode([
            'purpose' => (bool)$this->ReadAttributeBoolean('PurposeIntroGone'),
            'news'    => (string)$this->ReadAttributeString('SeenNews'),
            'forum'   => (bool)$this->ReadAttributeBoolean('ForumHintGone'),
        ]);
    }

    /**
     * SPOT_AdoptDismissState($id, string $State): void — Ausblende-Zustand einer Geschwister-
     * Instanz übernehmen. Gibt selbst NIE weiter (Ping-Pong strukturell ausgeschlossen, Muster
     * MeterHub 0.29.1). Blendet nur aus, blendet nie wieder ein; „Neu in Version“ versionsscharf.
     */
    public function AdoptDismissState(string $State): void
    {
        $s = json_decode($State, true);
        if (!is_array($s)) {
            return;
        }
        if (!empty($s['purpose'])) {
            $this->WriteAttributeBoolean('PurposeIntroGone', true);
        }
        if (($s['news'] ?? '') !== '' && version_compare((string)$s['news'], (string)$this->ReadAttributeString('SeenNews'), '>')) {
            $this->WriteAttributeString('SeenNews', (string)$s['news']);
        }
        if (!empty($s['forum'])) {
            $this->WriteAttributeBoolean('ForumHintGone', true);
        }
    }

    /** Eigenen Ausblende-Zustand an alle anderen Instanzen dieses Moduls geben (nur Übernahme-Schritt). */
    private function propagateDismiss(): void
    {
        $state = $this->GetDismissState();
        foreach ($this->siblingInstances() as $sibling) {
            try {
                SPOT_AdoptDismissState($sibling, $state);
            } catch (Throwable $e) {
                $this->SendDebug('Ausblenden', 'Instanz #' . $sibling . ' nicht erreichbar: ' . $e->getMessage(), 0);
            }
        }
    }

    /** Neue Instanz: einmalig den Ausblende-Zustand einer vorhandenen Geschwister-Instanz übernehmen. */
    private function adoptDismissFromSibling(): void
    {
        if ($this->ReadAttributeBoolean('DismissAdopted')) {
            return;
        }
        $this->WriteAttributeBoolean('DismissAdopted', true);
        foreach ($this->siblingInstances() as $sibling) {
            try {
                $this->AdoptDismissState((string)SPOT_GetDismissState($sibling));
                return;
            } catch (Throwable $e) {
                continue;
            }
        }
    }

    private function siblingInstances(): array
    {
        return array_values(array_filter(IPS_GetInstanceListByModuleID(self::MODULE_GUID), function ($id) {
            return (int)$id !== $this->InstanceID;
        }));
    }

    /** Eine Kopfzeile: letzter Abruf, Ergebnis, nächster Versuch. */
    private function fetchStatusLine(): string
    {
        $cache = $this->cache();
        $now = $this->now();
        $lastTry = $this->ReadAttributeInteger('LastTry');
        $error = $this->ReadAttributeString('LastError');
        $blocked = $this->ReadAttributeInteger('BlockedUntil');
        if ($lastTry === 0) {
            $line = 'ℹ️ Noch kein Abruf.';
        } elseif ($error !== '') {
            $line = '⚠️ ' . $error;
        } else {
            $line = '✅ Zuletzt abgerufen ' . date('d.m.Y H:i:s', (int)($cache['fetchedAt'] ?? $lastTry)) . ' Uhr — ' . $this->cacheSummary();
        }
        if ($blocked > $now) {
            $line .= "\n⏳ Ratenlimit der Quelle: nächster Abruf frühestens " . date('H:i:s', $blocked) . ' Uhr.';
        } elseif ($this->hasDay($now, 0) && !$this->hasDay($now, 1)) {
            $publish = $this->publishTs($now, 0);
            $line .= "\nℹ️ Preise für morgen noch nicht veröffentlicht — nächster Versuch " . date('H:i', $now < $publish ? $publish : max($now, $lastTry) + self::RETRY_SECONDS) . ' Uhr.';
        }
        return $line;
    }

    /** Kurzübersicht je Tag: Anzahl, Spanne, Mittel, negative Viertelstunden. */
    private function priceSummary(): string
    {
        $now = $this->now();
        $lines = [];
        foreach ([0 => 'Heute', 1 => 'Morgen'] as $offset => $label) {
            $slots = $this->daySlots($now, $offset);
            if (count($slots) === 0) {
                $lines[] = $label . ': ' . ($offset === 1 ? 'noch nicht veröffentlicht.' : 'keine Preise vorhanden.');
                continue;
            }
            $prices = array_column($slots, 'price');
            $neg = array_values(array_filter($slots, function ($s) {
                return $s['price'] < 0;
            }));
            $line = $label . ': ' . count($slots) . ' Viertelstunden, ' . $this->fmtCt(min($prices)) . ' bis ' . $this->fmtCt(max($prices))
                . ', Mittel ' . $this->fmtCt(array_sum($prices) / count($prices)) . '.';
            if (count($neg) > 0) {
                $line .= ' ⚡ ' . count($neg) . ' negative Viertelstunde(n), erste ' . date('H:i', (int)$neg[0]['start']) . ' Uhr, letzte endet ' . date('H:i', (int)end($neg)['end']) . ' Uhr.';
            } else {
                $line .= ' Keine negativen Preise.';
            }
            $lines[] = $line;
        }
        $res = $this->cache()['slots'][0]['res'] ?? 900;
        if ((int)$res === 3600) {
            $lines[] = 'ℹ️ Die Quelle liefert Stundenwerte — jede Stunde ist auf vier gleiche Viertelstunden verteilt.';
        }
        return implode("\n", $lines);
    }

    private function sourceInfo(): string
    {
        if ($this->source() === self::SOURCE_TIBBER) {
            return 'Tibber-Preisübersicht (öffentlich, ohne Tibber-Konto): Für deine Postleitzahl nennt Tibber je Viertelstunde den Börsenpreis, das Netzentgelt und die Abgaben. Alle Werte und der Verbund-Vertrag nutzen den Börsenpreisanteil; die Variable „Marktdaten (Energie Manager)“ bekommt Tibbers Endpreis inkl. Mehrwertsteuer — so wie in Symcons Modul „Strompreis“. '
                . 'Die Postleitzahl wird dabei an Tibber übertragen. Keine offiziell dokumentierte Schnittstelle, sie kann sich ändern. Der Endpreis gilt für einen Tibber-Standardkunden an dieser Postleitzahl — ob dein Tarif oder zeitvariable Netzentgelte (§ 14a Modul 3) davon abweichen, weiß nur dein Vertrag.';
        }
        if ($this->source() === self::SOURCE_ENTSOE) {
            return 'EPEX Spot über ENTSO-E: die Day-Ahead-Ergebnisse der Strombörse EPEX Spot, veröffentlicht auf der ENTSO-E Transparency Platform — Viertelstunden, kostenlos, aber mit eigenem Zugangsschlüssel (Konto auf transparency.entsoe.eu anlegen, dann per E-Mail an transparency@entsoe.eu mit Betreff „Restful API access“ beantragen). '
                . 'Quellennennung: ENTSO-E Transparency Platform. Direkt bei EPEX Spot gibt es die Daten nur mit kostenpflichtigem Vertrag.';
        }
        if ($this->source() === self::SOURCE_AWATTAR) {
            return 'aWATTar: kostenlos im Rahmen fairer Nutzung (laut Anbieter etwa 100 Abfragen am Tag — dieses Modul braucht meist 1–5). '
                . 'Liefert nur Stundenwerte: eine einzelne negative Viertelstunde kann im Stundenmittel verschwinden. Für die Viertelstunden-Pflichten (§ 51 EEG) ist Energy-Charts die bessere Wahl.';
        }
        $license = (string)($this->cache()['license'] ?? '');
        return 'Energy-Charts (Fraunhofer ISE): ohne Anmeldung, Viertelstunden. Die Quelle begrenzt die Abfragen pro Minute — das Modul fragt meist nur 1–5-mal am Tag und hält sich an jede Pause, um die die Quelle bittet. '
            . 'Quellennennung: Energy-Charts.info' . ($license !== '' ? ' — ' . $license : ' — Lizenz CC BY 4.0, Daten Bundesnetzagentur | SMARD.de') . '.';
    }

    /**
     * Anders als in der Vorlage immer im Formular, nur unsichtbar geschaltet: So kann der Knopf im
     * Doku-Panel es ohne Neuladen wieder einblenden (Dietmar vermisste den Zweck-Text, 14.09.2026 —
     * er war einmal weggeklickt und danach nirgends mehr zu finden).
     */
    private function PurposeIntro(): array
    {
        return [
            'type' => 'ExpansionPanel', 'name' => 'PurposeIntroPanel', 'expanded' => true,
            'visible' => !$this->ReadAttributeBoolean('PurposeIntroGone'),
            'caption' => '👋  Wozu dieses Modul?',
            'items' => [
                ['type' => 'Label', 'caption' => 'Dieses Modul holt die Day-Ahead-Börsenpreise für heute und morgen (Viertelstunden, Gebotszone Deutschland/Luxemburg oder Österreich) — ohne Konto, ohne Stromvertrag bei einem bestimmten Anbieter — und stellt sie Variablen, Skripten und anderen NRG-Stack-Modulen bereit.'],
                ['type' => 'Label', 'caption' => 'Der Nutzen: Für manche Pflichten zählt der Börsenpreis, nicht dein Tarif. Beispiel: Neue PV-Anlagen bekommen in Viertelstunden mit negativem Börsenpreis keine Einspeisevergütung. Mit diesem Modul kann z. B. das NRG-Stack EMS genau diese Viertelstunden erkennen und die Einspeisung vermeiden — auch mit festem Stromtarif.'],
                ['type' => 'Label', 'caption' => 'Auch ohne weitere NRG-Stack-Module nützlich: Symcons eigener Energie Manager bekommt von hier die Preise der nächsten 24 Stunden — als deinen Endpreis, wenn du deinen Tarif einträgst — und plant damit z. B. günstiges Laden. Wer einen dynamischen Tarif über das Modul NRG-Stack Tibber Grid Rewards hat, bekommt dort den eigenen Endkundenpreis; beides kann nebeneinander laufen.'],
                ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => 'SPOT_AckPurposeIntro($id);'],
            ],
        ];
    }

    private function NewsBanner(): ?array
    {
        if ($this->ReadAttributeString('SeenNews') === self::NEWS_VERSION) {
            return null;
        }
        return [
            'type' => 'ExpansionPanel', 'name' => 'NewsPanel', 'expanded' => true,
            'caption' => '🆕  Neu in Version ' . self::NEWS_VERSION,
            'items' => [
                ['type' => 'Label', 'caption' => '• Neuer Name: Das Modul heißt jetzt überall „Börsenpreis“ (Repo DG65/NRGBoersenpreis). Funktionen, Variablen und die Anbindung an andere Module bleiben unverändert.'],
                ['type' => 'Label', 'caption' => '• „Wozu dieses Modul?“ lässt sich im Panel „Dokumentation & Hilfe“ wieder einblenden. Weggeklickte Hinweise gelten jetzt für alle Börsenpreis-Instanzen. Texte nutzen wieder die volle Formularbreite.'],
                ['type' => 'Label', 'caption' => '• Seit 0.5: Tarif für den Symcon Energie Manager (Panel „Symcon Energie Manager & Tarif“) — mit Tibber Grid Rewards dein echter Tibber-Preis, sonst dein Endpreis aus Börsenpreis, Aufschlag, Netzentgelt (auch nach § 14a Modul 3, getrennt für Werktage und Wochenende), Konzessionsabgabe, Umlagen und Mehrwertsteuer.'],
                ['type' => 'Label', 'caption' => '• Seit 0.3/0.4: weitere Quellen „EPEX Spot (über ENTSO-E)“ (mit kostenlosem Zugangsschlüssel) und „Tibber-Preisübersicht“ (ohne Konto, per Postleitzahl) sowie die Variable „Marktdaten (Energie Manager)“ für Symcons Energie Manager.'],
                ['type' => 'Label', 'caption' => '• Seit 0.2: Preisverlauf aus dem Archiv (SPOT_GetPriceHistory), Anzeige im NRG-Stack Dashboard (PV-Monitoring, Reiter „Strompreis“).'],
                ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => 'SPOT_AckNews($id);'],
            ],
        ];
    }

    private function DocPanel(): array
    {
        $lib    = @IPS_GetLibrary(self::LIBRARY_GUID);
        $verTxt = (is_array($lib) && isset($lib['Version']))
            ? 'ℹ️ NRG-Stack Börsenpreis Version ' . $lib['Version'] . ' (Build ' . ($lib['Build'] ?? '?') . ')'
            : 'ℹ️ NRG-Stack Börsenpreis';
        return [
            'type' => 'ExpansionPanel', 'expanded' => false,
            'caption' => '📖  Dokumentation & Hilfe',
            'items' => [
                ['type' => 'Label', 'caption' => $verTxt],
                ['type' => 'Button', 'caption' => '👋 „Wozu dieses Modul?“ wieder anzeigen', 'onClick' => 'echo SPOT_ShowPurposeIntro($id);'],
                ['type' => 'Label', 'caption' => 'Was geliefert wird: der Day-Ahead-Börsenpreis der gewählten Gebotszone je Viertelstunde, umgerechnet in ct/kWh, NETTO — ohne Steuern, Umlagen und Netzentgelt. Negative Preise werden genau so weitergegeben. Das ist nicht dein Endkundenpreis.'],
                ['type' => 'Label', 'caption' => 'Wann abgerufen wird: nach dem Anlegen einmal sofort, danach nur, wenn etwas fehlt. Die Preise für morgen entstehen in der Day-Ahead-Auktion um 12 Uhr und stehen meist ab ca. 12:45 Uhr bereit; bis sie da sind, fragt das Modul alle 15 Minuten. Bittet die Quelle um eine Pause (Ratenlimit), wartet das Modul genau so lange.'],
                ['type' => 'Label', 'caption' => 'Variablen: „Börsenpreis jetzt" (ct/kWh), „Negativer Börsenpreis jetzt" (Ja/Nein), „Nächste negative Viertelstunde" (Beginn, 0 = keine bekannt), „Preise für morgen veröffentlicht" (Ja/Nein), „Marktdaten (Energie Manager)“ (Preise der nächsten 24 Stunden für Symcons Energie Manager, siehe unten). Aktualisierung zu jeder Viertelstunde. „Börsenpreis jetzt" wird archiviert — das Modul schaltet das einmalig ein; wer es abschaltet, verliert nur den Rückblick in SPOT_GetPriceHistory().'],
                ['type' => 'Label', 'caption' => 'Anzeige: Die Preiskurve für heute und morgen mit den negativen Viertelstunden zeigt das NRG-Stack Dashboard (PV-Monitoring, Reiter „Strompreis"). Die Variablen lassen sich zusätzlich per Verknüpfung im Objektbaum in den Bereich des WebFronts legen.'],
                ['type' => 'Label', 'caption' => 'Symcon Energie Manager: „Marktdaten (Energie Manager)“ enthält [{start, end, price}] ab der laufenden Viertelstunde für bis zu 24 Stunden, price in ct/kWh — dasselbe Format wie Symcons Modul „Strompreis“, aber mit deinem Endpreis: echter Tibber-Preis aus Tibber Grid Rewards, sonst dein eigener Tarif, sonst Tibbers Preis für deine Postleitzahl (Quelle Tibber-Preisübersicht), sonst der reine Börsenpreis. Welcher gerade gilt, zeigt die Statuszeile im Panel „Symcon Energie Manager & Tarif“.'],
                ['type' => 'Label', 'caption' => 'Skripte und andere Module: SPOT_GetPriceCurve(<InstanzID>) liefert eine Liste aller Viertelstunden von heute und (sobald veröffentlicht) morgen — je Eintrag start, end (exklusiv, Unixzeit), price (ct/kWh), basis „spot", netzentgelt „fehlt", level (immer leer), quelle, aufloesung (900 = Viertelstunde, 3600 = aus Stundenwert verteilt) und contractVersion. Lücken sind möglich, fehlende Werte stehen nie als 0 drin.'],
                ['type' => 'Label', 'caption' => 'SPOT_GetPriceHistory(<InstanzID>, von, bis) liefert dieselben Einträge für einen beliebigen Zeitraum (Unixzeit, bis exklusiv, höchstens 400 Tage). Vergangene Viertelstunden stammen aus dem Archiv (quelle „archiv", Stufenverlauf: ein Wert gilt bis zum nächsten, höchstens 12 Stunden — längere Lücken, z. B. während Symcon aus war, bleiben leer); vor dem ersten Archiveintrag gibt es keine Einträge.'],
                ['type' => 'Label', 'caption' => 'SPOT_Update(<InstanzID>) ruft sofort ab und liefert das Ergebnis als Text. Zeitumstellung: Tage mit 23 bzw. 25 Stunden haben 92 bzw. 100 Viertelstunden — alle Zeitstempel sind echte Unixzeit, nichts wird aus festen Tageslängen errechnet.'],
                ['type' => 'Label', 'caption' => 'Keine Rechtsberatung: Welche Pflichten für deine Anlage bei negativen Preisen gelten, hängt u. a. vom Inbetriebnahmedatum ab. Das NRG-Stack EMS ordnet das über seine Anlagendaten ein; dieses Modul liefert nur die Preise.'],
            ],
        ];
    }

    private function SourcePanel(): array
    {
        $entsoe = $this->source() === self::SOURCE_ENTSOE;
        $tibber = $this->source() === self::SOURCE_TIBBER;
        $zones = [];
        foreach (self::ZONES as $value => $caption) {
            $zones[] = ['caption' => $caption, 'value' => $value];
        }
        return [
            'type' => 'ExpansionPanel', 'expanded' => true,
            'caption' => '🔌  Datenquelle',
            'items' => [
                ['type' => 'Select', 'name' => 'Source', 'caption' => 'Quelle', 'width' => self::FIELD_WIDTH, 'onChange' => 'SPOT_UIChangeSource($id, $Source);', 'options' => [
                    ['caption' => 'Energy-Charts (Fraunhofer ISE) — empfohlen', 'value' => self::SOURCE_ENERGYCHARTS],
                    ['caption' => 'EPEX Spot über ENTSO-E — mit Zugangsschlüssel', 'value' => self::SOURCE_ENTSOE],
                    ['caption' => 'Tibber-Preisübersicht — per Postleitzahl', 'value' => self::SOURCE_TIBBER],
                    ['caption' => 'aWATTar — nur Stundenwerte', 'value' => self::SOURCE_AWATTAR],
                ]],
                ['type' => 'ValidationTextBox', 'name' => 'TibberPostalCode', 'visible' => $tibber, 'caption' => 'Postleitzahl', 'validate' => '^[0-9]{5}$', 'width' => self::FIELD_WIDTH],
                ['type' => 'Label', 'name' => 'TibberPostalHint', 'visible' => $tibber, 'caption' => 'ℹ️ Die Postleitzahl bestimmt Netzentgelt und Abgaben in Tibbers Endpreis und wird an Tibber übertragen.'],
                ['type' => 'Label', 'name' => 'EntsoeTokenStatus', 'visible' => $entsoe, 'caption' => $this->entsoeTokenStatus()],
                ['type' => 'PasswordTextBox', 'name' => 'EntsoeTokenInput', 'visible' => $entsoe, 'caption' => 'ENTSO-E-Zugangsschlüssel', 'width' => self::FIELD_WIDTH],
                ['type' => 'Button', 'name' => 'EntsoeTokenButton', 'visible' => $entsoe, 'caption' => '🔑 Schlüssel speichern', 'onClick' => 'echo SPOT_SetEntsoeToken($id, $EntsoeTokenInput);'],
                ['type' => 'Button', 'name' => 'EntsoeTokenGuide', 'visible' => $entsoe, 'caption' => 'Anleitung: Zugangsschlüssel beantragen', 'onClick' => "echo '" . self::ENTSOE_TOKEN_URL . "';", 'link' => true],
                ['type' => 'Select', 'name' => 'BiddingZone', 'caption' => 'Gebotszone', 'width' => self::FIELD_WIDTH, 'options' => $zones],
                ['type' => 'PopupButton', 'caption' => 'Welche Quelle und Gebotszone soll ich wählen?', 'width' => '500px', 'popup' => [
                    'caption' => 'Welche Quelle und Gebotszone soll ich wählen?',
                    'items' => [
                        ['type' => 'Label', 'caption' => 'Gebotszone: die Zone deines Netzanschlusses — Deutschland und Luxemburg bilden gemeinsam „DE-LU", Österreich ist „AT". Der Preis ist in der ganzen Zone gleich.'],
                        ['type' => 'Label', 'caption' => 'Energy-Charts (empfohlen): Viertelstundenwerte direkt aus der Day-Ahead-Auktion, frei nutzbar mit Quellennennung. Genau das braucht man für Regeln, die je Viertelstunde gelten, z. B. die Vergütungsregel bei negativen Preisen.'],
                        ['type' => 'Label', 'caption' => 'EPEX Spot (über ENTSO-E): dieselben Börsenergebnisse der EPEX Spot, direkt von der europäischen Transparenzplattform der Netzbetreiber, ebenfalls in Viertelstunden. Braucht einen kostenlosen, persönlichen Zugangsschlüssel — sinnvoll als unabhängige zweite Quelle oder wenn du ohnehin ein ENTSO-E-Konto hast.'],
                        ['type' => 'Label', 'caption' => 'Tibber-Preisübersicht: ohne Konto, nur mit deiner Postleitzahl. Liefert denselben Börsenpreis und zusätzlich Tibbers Endpreis für diese Postleitzahl — den nutzt die Variable für den Symcon Energie Manager, damit er nach dem echten Tibber-Preis planen kann, auch ohne das Modul Tibber Grid Rewards.'],
                        ['type' => 'Label', 'caption' => 'aWATTar: Ersatzquelle, falls Energy-Charts einmal nicht erreichbar ist. Liefert nur Stundenwerte; eine einzelne negative Viertelstunde kann darin untergehen.'],
                        ['type' => 'Label', 'caption' => 'Nach einem Wechsel verwirft das Modul die alten Preise und holt beim Übernehmen sofort neu.'],
                    ],
                ]],
                ['type' => 'Label', 'name' => 'SourceInfo', 'caption' => $this->sourceInfo()],
                ['type' => 'Button', 'caption' => '🔄 Preise jetzt abrufen', 'onClick' => 'echo SPOT_Update($id);'],
                ['type' => 'Label', 'name' => 'FetchStatus', 'caption' => $this->fetchStatusLine()],
            ],
        ];
    }

    private function PricePanel(): array
    {
        return [
            'type' => 'ExpansionPanel', 'expanded' => true,
            'caption' => '📈  Börsenpreise',
            'items' => [
                ['type' => 'Label', 'name' => 'PriceSummary', 'caption' => $this->priceSummary()],
                ['type' => 'PopupButton', 'caption' => 'Warum der Börsenpreis und nicht mein Stromtarif?', 'width' => '500px', 'popup' => [
                    'caption' => 'Warum der Börsenpreis und nicht mein Stromtarif?',
                    'items' => [
                        ['type' => 'Label', 'caption' => 'Dein Tarif ist das, was du für Strom aus dem Netz bezahlst — mit Steuern, Umlagen und Netzentgelt. Der Börsenpreis ist der reine Großhandelspreis der Day-Ahead-Auktion.'],
                        ['type' => 'Label', 'caption' => 'Einige Regeln knüpfen ausdrücklich an den Börsenpreis an, egal welchen Tarif du hast — z. B. die Einspeisevergütung bei negativen Preisen oder künftig die Vermarktung von Neuanlagen. Für Kostenvergleiche mit deinem Bezugspreis ist der Börsenpreis dagegen allein nicht geeignet.'],
                    ],
                ]],
                ['type' => 'PopupButton', 'caption' => 'Was bedeuten negative Preise für meine Vergütung?', 'width' => '500px', 'popup' => [
                    'caption' => 'Was bedeuten negative Preise für meine Vergütung?',
                    'items' => [
                        ['type' => 'Label', 'caption' => 'Für PV-Anlagen mit Inbetriebnahme ab 25.02.2025 (Solarspitzengesetz) entfällt die Einspeisevergütung in jeder Viertelstunde, in der der Day-Ahead-Börsenpreis negativ ist (§ 51 EEG). Die ausgefallene Zeit wird am Ende der Förderdauer teilweise angehängt.'],
                        ['type' => 'Label', 'caption' => 'Ältere Anlagen sind je nach Größe und Inbetriebnahmejahr anders oder gar nicht betroffen. Eine Einordnung für deine Anlage liefert das NRG-Stack EMS über seine Anlagendaten. Keine Rechtsberatung.'],
                        ['type' => 'Label', 'caption' => 'Dieses Modul zeigt, wann negative Viertelstunden anstehen — ein EMS kann die Energie dann z. B. in die Batterie, die Wärmepumpe oder das Auto lenken statt ins Netz.'],
                    ],
                ]],
            ],
        ];
    }

    private function TariffPanel(): array
    {
        $vid = IPS_GetObjectIDByIdent('MarketData', $this->InstanceID);
        $ct = function (float $v) {
            return number_format($v, 3, ',', '.');
        };
        $quarter = function (int $q, string $months) {
            return ['type' => 'CheckBox', 'name' => 'Modul3Q' . $q, 'caption' => 'Q' . $q . ' (' . $months . ')'];
        };
        return [
            'type' => 'ExpansionPanel', 'expanded' => false,
            'caption' => '⚡  Symcon Energie Manager & Tarif',
            'items' => [
                ['type' => 'Label', 'caption' => 'Die Variable „Marktdaten (Energie Manager)“' . ($vid !== false ? ' (ID ' . $vid . ')' : '') . ' liefert die Preise ab jetzt für bis zu 24 Stunden im Format von Symcons Modul „Strompreis“. Im Symcon Energie Manager unter „Energiepreise“ diese Variable auswählen — dann plant er z. B. das günstige Laden nach deinem Endpreis.'],
                ['type' => 'Label', 'name' => 'MarketSourceStatus', 'caption' => $this->marketSourceStatus()],
                ['type' => 'CheckBox', 'name' => 'UseTibberPrice', 'caption' => 'Preis von Tibber Grid Rewards nutzen'],
                ['type' => 'Label', 'caption' => 'ℹ️ Nur wenn das Modul Tibber Grid Rewards installiert ist: dann kommt dein echter Tibber-Endpreis von dort (empfohlen für Tibber-Kunden).'],
                ['type' => 'CheckBox', 'name' => 'TariffEnabled', 'caption' => 'Eigenen Tarif einrechnen'],
                ['type' => 'Label', 'caption' => 'z. B. für einen anderen dynamischen Tarif. Alle Beträge netto in ct/kWh — die Mehrwertsteuer rechnet das Modul selbst hinzu.'],
                // Minimum 0: Bei negativem Minimum zeigte die Konsole für den Wert 0 das Minimum an (−20, Live-Fund 14.09.2026).
                ['type' => 'NumberSpinner', 'name' => 'TariffBeschaffung', 'caption' => 'Aufschlag des Anbieters', 'suffix' => ' ct/kWh', 'digits' => 3, 'minimum' => 0, 'maximum' => 100, 'width' => self::FIELD_WIDTH],
                ['type' => 'NumberSpinner', 'name' => 'TariffKonzession', 'caption' => 'Konzessionsabgabe', 'suffix' => ' ct/kWh', 'digits' => 3, 'minimum' => 0, 'maximum' => 5, 'width' => self::FIELD_WIDTH],
                ['type' => 'NumberSpinner', 'name' => 'NetzArbeitspreis', 'caption' => 'Netzentgelt (Arbeitspreis)', 'suffix' => ' ct/kWh', 'digits' => 3, 'minimum' => 0, 'maximum' => 50, 'width' => self::FIELD_WIDTH],
                ['type' => 'Label', 'caption' => 'Bundesweit gleich und fest eingerechnet (Stand ' . self::TAX_STAND . ', netto): Stromsteuer ' . $ct(self::TAX_STROMSTEUER) . ' · Offshore-Netzumlage ' . $ct(self::TAX_OFFSHORE) . ' · KWK-Umlage ' . $ct(self::TAX_KWK) . ' · §19-StromNEV-Umlage ' . $ct(self::TAX_STROMNEV19) . ' ct/kWh; auf die Summe ' . (int)self::VAT_PERCENT . ' % Mehrwertsteuer.'],
                ['type' => 'CheckBox', 'name' => 'Modul3Enabled', 'caption' => 'Netzentgelt nach § 14a Modul 3'],
                ['type' => 'NumberSpinner', 'name' => 'NetzHT', 'caption' => 'Netzentgelt Hochtarif', 'suffix' => ' ct/kWh', 'digits' => 3, 'minimum' => 0, 'maximum' => 50, 'width' => self::FIELD_WIDTH],
                ['type' => 'NumberSpinner', 'name' => 'NetzST', 'caption' => 'Netzentgelt Standardtarif', 'suffix' => ' ct/kWh', 'digits' => 3, 'minimum' => 0, 'maximum' => 50, 'width' => self::FIELD_WIDTH],
                ['type' => 'NumberSpinner', 'name' => 'NetzNT', 'caption' => 'Netzentgelt Niedertarif', 'suffix' => ' ct/kWh', 'digits' => 3, 'minimum' => 0, 'maximum' => 50, 'width' => self::FIELD_WIDTH],
                [
                    'type' => 'List', 'name' => 'NetzWindows', 'caption' => 'Zeitfenster laut Preisblatt deines Netzbetreibers', 'rowCount' => 6, 'add' => true, 'delete' => true,
                    'columns' => [
                        ['caption' => 'Von', 'name' => 'From', 'width' => '110px', 'add' => '00:00', 'edit' => ['type' => 'ValidationTextBox', 'validate' => '^([01][0-9]|2[0-3]):[0-5][0-9]$']],
                        ['caption' => 'Bis', 'name' => 'To', 'width' => '110px', 'add' => '00:00', 'edit' => ['type' => 'ValidationTextBox', 'validate' => '^([01][0-9]|2[0-3]):[0-5][0-9]$']],
                        ['caption' => 'Stufe', 'name' => 'Band', 'width' => '190px', 'add' => 'ST', 'edit' => ['type' => 'Select', 'options' => [
                            ['caption' => 'Hochtarif (HT)', 'value' => 'HT'],
                            ['caption' => 'Standardtarif (ST)', 'value' => 'ST'],
                            ['caption' => 'Niedertarif (NT)', 'value' => 'NT'],
                        ]]],
                        ['caption' => 'Tage', 'name' => 'Days', 'width' => 'auto', 'add' => 'all', 'edit' => ['type' => 'Select', 'options' => [
                            ['caption' => 'Alle Tage', 'value' => 'all'],
                            ['caption' => 'Montag–Freitag', 'value' => 'weekday'],
                            ['caption' => 'Samstag und Sonntag', 'value' => 'weekend'],
                        ]]],
                    ],
                ],
                ['type' => 'RowLayout', 'items' => [$quarter(1, 'Jan–Mär'), $quarter(2, 'Apr–Jun'), $quarter(3, 'Jul–Sep'), $quarter(4, 'Okt–Dez')]],
                ['type' => 'Label', 'caption' => 'Außerhalb der Zeitfenster und in Quartalen ohne Häkchen gilt der Arbeitspreis. „Bis 00:00“ heißt Tagesende, Fenster über Mitternacht sind erlaubt. Feiertage kennt das Modul nicht — sie zählen wie ihr Wochentag.'],
                ['type' => 'PopupButton', 'caption' => 'Wo finde ich Netzentgelt und Konzessionsabgabe?', 'width' => '500px', 'popup' => [
                    'caption' => 'Wo finde ich Netzentgelt und Konzessionsabgabe?',
                    'items' => [
                        ['type' => 'Label', 'caption' => 'Netzentgelt und die § 14a-Modul-3-Zeitfenster stehen im Preisblatt deines Netzbetreibers (auf dessen Webseite, meist „Netzentgelte“ bzw. „Preisblatt Netznutzung“) und auf deiner Stromrechnung. Den Netzbetreiber findest du auf der Rechnung oder über deine Postleitzahl.'],
                        ['type' => 'Label', 'caption' => 'Die Konzessionsabgabe hängt von deiner Gemeinde ab (in Deutschland meist zwischen 1,32 und 2,39 ct/kWh netto) und steht ebenfalls auf der Rechnung. Den Aufschlag nennt dein Anbieter in seinen Tarifbedingungen.'],
                        ['type' => 'Label', 'caption' => 'Alle Werte netto eintragen — die Mehrwertsteuer rechnet das Modul selbst hinzu. Ist Tibber Grid Rewards installiert, brauchst du hier nichts einzutragen: dann kommt dein echter Tibber-Preis von dort.'],
                    ],
                ]],
                ['type' => 'PopupButton', 'caption' => 'Wie binde ich den Energie Manager an?', 'width' => '500px', 'popup' => [
                    'caption' => 'Wie binde ich den Energie Manager an?',
                    'items' => [
                        ['type' => 'Label', 'caption' => '1. Im Symcon Energie Manager das Feld „Energiepreise“ öffnen und die Variable „Marktdaten (Energie Manager)“ dieser Instanz auswählen.'],
                        ['type' => 'Label', 'caption' => '2. Woher der Preis kommt, zeigt die Statuszeile oben: echter Tibber-Preis (Tibber Grid Rewards), dein eigener Tarif, Tibbers Preis für deine Postleitzahl oder der reine Börsenpreis.'],
                        ['type' => 'Label', 'caption' => '3. Die Variable wird zu jeder Viertelstunde und nach jedem Abruf neu geschrieben; vergangene Viertelstunden fallen heraus.'],
                    ],
                ]],
            ],
        ];
    }

    private function ForumHint(): ?array
    {
        if ($this->ReadAttributeBoolean('ForumHintGone')) {
            return null;
        }
        // Muster MeterHub (EMS-Vorgabe 14.09.2026): eigenes Panel; Thread live seit 16.09.2026.
        return [
            'type' => 'ExpansionPanel', 'name' => 'ForumHintPanel', 'expanded' => true,
            'caption' => '💬  Feedback im Symcon-Forum',
            'items' => [
                ['type' => 'Label', 'caption' => 'Börsenpreis ist neu — Rückmeldungen, gerade zu den Quellen und zum Tarif für den Energie Manager, sind ausdrücklich willkommen im Community-Thread.'],
                ['type' => 'Button', 'caption' => 'Zum Forums-Thread', 'onClick' => "echo '" . self::FORUM_THREAD_URL . "';", 'link' => true],
                ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => 'SPOT_AckForumHint($id);'],
            ],
        ];
    }

    /**
     * Lizenz-/Unterstützungs-Hinweis — Wortlaut verbundweit identisch
     * (SUITE.md "Einheitliche Formular-Optik", Variante A). Bewusst NICHT
     * dismissible, ganz unten nach dem Forum-Hinweis.
     */
    private function LicenseHint(): array
    {
        return [
            'type' => 'ExpansionPanel', 'expanded' => false,
            'caption' => '🧡  Über dieses Modul',
            'items' => [
                ['type' => 'Label', 'caption' => 'Entstanden aus echter Begeisterung für die eigene Anlage — und ein paar durchgetippten Abenden. Trotzdem: Software-Hobby hin oder her, das hier ist geistiges Eigentum und echte Arbeit steckt drin.'],
                ['type' => 'Label', 'caption' => 'Lizenz: PolyForm Noncommercial 1.0.0 — privat und nicht-kommerziell frei nutzbar, für den gewerblichen Einsatz braucht es eine gesonderte Lizenz vom Rechteinhaber.'],
                ['type' => 'Button', 'caption' => 'Lizenztext ansehen', 'onClick' => "echo '" . self::LICENSE_URL . "';", 'link' => true],
                ['type' => 'Label', 'caption' => 'Gewerbliche Nutzung oder Fragen zur Lizenz? Einfach melden: dietmar@gureth.eu'],
                ['type' => 'Label', 'caption' => 'Gefällt dir das Modul und du möchtest trotzdem etwas dalassen? Über eine kleine Spende freue ich mich — völlig freiwillig, keine Gegenleistung nötig.'],
                ['type' => 'Button', 'caption' => '☕  Spenden via PayPal', 'onClick' => "echo '" . self::PAYPAL_URL . "';", 'link' => true],
            ],
        ];
    }

    public function GetConfigurationForm()
    {
        $base   = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        $status = $base['status'] ?? [];

        $elements = array_values(array_filter(array_merge(
            [$this->PurposeIntro(), $this->NewsBanner(), $this->DocPanel()],
            [$this->SourcePanel(), $this->PricePanel(), $this->TariffPanel()],
            [$this->ForumHint(), $this->LicenseHint()]
        )));

        return json_encode(['elements' => $elements, 'actions' => [], 'status' => $status]);
    }
}
