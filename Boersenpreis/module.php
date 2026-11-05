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
    /** Sprache der Oberfläche, einmal je Durchlauf ermittelt (null = noch nicht gefragt). */
    private ?bool $istDeutsch = null;

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
    // „Was ist Neu“ je Version (SUITE.md „NEWS_VERSIONS-Array“, verbundweit ab 23.09.2026):
    // Schlüssel = Version ohne Beta-/Build-Zusatz, Wert = Zeilen. Der Banner zeigt genau die Lücke
    // zwischen zuletzt bestätigter und installierter Version. Die alte, über mehrere Versionen
    // gewachsene Liste bleibt als EIN Block unter ihrer letzten NEWS_VERSION-Nummer (0.6.0).
    private const NEWS_VERSIONS = [
        '0.6.0' => [
            'New name: the module is now called „Börsenpreis“ everywhere (repository DG65/NRGBoersenpreis). Functions, variables and the links to other modules stay unchanged.',
            '„What is this module for?“ can be shown again from the „Documentation & help“ panel. Dismissed notes now apply to all Börsenpreis instances. Texts use the full form width again.',
            'Since 0.5: tariff for the Symcon Energy Manager (panel „Symcon Energy Manager & tariff“) — with Tibber Grid Rewards your real Tibber price, otherwise your retail price from exchange price, supplier surcharge, grid fee (including § 14a module 3, separately for weekdays and weekends), concession levy, levies and VAT.',
            'Since 0.3/0.4: additional sources „EPEX Spot (via ENTSO-E)“ (with a free access key) and „Tibber price overview“ (no account, by postal code) plus the variable „Market data (Energy Manager)“ for Symcon\'s Energy Manager.',
            'Since 0.2: price history from the archive (SPOT_GetPriceHistory), shown in the NRG-Stack dashboard (PV monitoring, tab „Strompreis“).',
        ],
        '0.6.1' => [
            'The button „To the forum thread“ in the panel „💬  Feedback in the Symcon forum“ now leads to the published introduction thread.',
        ],
        '0.7.0' => [
            'Panel „Symcon Energy Manager & tariff“: the status line now shows exactly whether Tibber Grid Rewards was found, which instance, which contract version and which retail price it currently delivers — and it follows your checkboxes immediately, even before „Apply“.',
            'If your retail price comes automatically from Tibber Grid Rewards, the tariff fields are hidden and a green 🔗 line shows the price taken over. With several Tibber instances you pick the right one instead of the module guessing.',
        ],
        '0.8.0' => [
            'New checkbox „Archive price history“ in the panel „Exchange prices“ (default: off). Only with the box ticked does the module switch on archiving of „Exchange price now“ — the basis for looking back at past days. A status line says what currently applies. Anyone already archiving keeps everything; archiving is never switched off automatically.',
        ],
        '0.9.0' => [
            '🪟 New: choose the form layout yourself — in the panel „Form: layout“ you decide which sections are expanded when the form opens, in which order they appear and whether you hide one of them. Hidden sections keep working, only the display is missing.',
        ],
        '0.10.0' => [
            'The module now speaks German and English: form, messages, variable names and status lines follow the Symcon language setting (option „Locale“). Numbers and dates follow the language as well. German stays the reference wording.',
        ],
    ];
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
    // 🔗-Zeilen (automatisch übernommen) grün, sonst Standardfarbe (SUITE.md, Commit 64b5d1f).
    private const COLOR_AUTO = 0x2E8B3D;
    // Tarif-Eingaben im Panel „Symcon Energie Manager & Tarif“: ausgeblendet, solange der Endpreis
    // automatisch von Tibber Grid Rewards kommt (SUITE.md „Wert kommt automatisch“, 21.09.2026).
    private const TARIFF_FIELDS = [
        'TariffEnabled', 'TariffIntro', 'TariffBeschaffung', 'TariffKonzession', 'NetzArbeitspreis', 'TariffTaxInfo',
        'Modul3Enabled', 'NetzHT', 'NetzST', 'NetzNT', 'NetzWindows', 'Modul3Quarters', 'Modul3Hint', 'TariffHelp',
    ];

    private const EC_URL      = 'https://api.energy-charts.info/price';
    private const AWATTAR_URL = [
        'DE-LU' => 'https://api.awattar.de/v1/marketdata',
        'AT'    => 'https://api.awattar.at/v1/marketdata',
    ];
    // Gebotszonen, die beide Quellen liefern und deren Energy-Charts-Daten
    // unter CC BY 4.0 stehen (andere Zonen dort teils nur privat nutzbar).
    private const ZONES = [
        'DE-LU' => 'Germany/Luxembourg (DE-LU)',
        'AT'    => 'Austria (AT)',
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
        // Store-Review-Checkliste 9k (28.09.2026): archivbezogene Schalter stehen als Vorgabe AUS,
        // der Nutzer stimmt bewusst zu. Einmal eingeschaltete Archivierung wird NIE wieder abgeschaltet.
        // Reihenfolge/Aufklapp-Zustand der Fachpanels (SUITE.md „🪟 Formular: Aufbau", 29.10.2026)
        $this->RegisterPropertyString('FormLayout', '[]');
        $this->RegisterPropertyBoolean('ArchivePrice', false);
        $this->RegisterPropertyBoolean('UseTibberPrice', true);
        // 0 = automatisch (genau eine Instanz); bei mehreren wird nicht geraten (SUITE.md, 21.09.2026).
        $this->RegisterPropertyInteger('TibberInstance', 0);
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
        $this->MaintainVariable('CurrentPrice', $this->t('Exchange price now'), VARIABLETYPE_FLOAT, 'SPOT.CentKWh', 1, true);
        $this->MaintainVariable('NegativeNow', $this->t('Negative exchange price now'), VARIABLETYPE_BOOLEAN, 'SPOT.YesNo', 2, true);
        $this->MaintainVariable('NextNegativeStart', $this->t('Next negative quarter hour'), VARIABLETYPE_INTEGER, '~UnixTimestamp', 3, true);
        $this->MaintainVariable('TomorrowAvailable', $this->t('Prices for tomorrow published'), VARIABLETYPE_BOOLEAN, 'SPOT.YesNo', 4, true);
        // Format wie Symcons Modul „Strompreis“ (Ident MarketData) — der Energie Manager wählt
        // diese Variable im Feld „Energiepreise“.
        $this->MaintainVariable('MarketData', $this->t('Market data (Energy Manager)'), VARIABLETYPE_STRING, '~TextBox', 5, true);
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
            return $this->t('🗑 ENTSO-E access key deleted.');
        }
        if ($this->source() !== self::SOURCE_ENTSOE) {
            return $this->t('✅ Access key saved. It is used as soon as the source "EPEX Spot (via ENTSO-E)" is selected and applied.');
        }
        return $this->t('✅ Access key saved.') . "\n" . $this->refresh(true);
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
            $text = $this->t('⏳ The source asked for a pause (rate limit) — next fetch at %s at the earliest. Existing prices remain valid.', date('H:i:s', $blocked));
        } elseif ($force || $this->needsFetch($now)) {
            $text = $this->fetch($now);
        }
        $this->applyCurrent();
        $this->scheduleFetch();
        $this->scheduleTick();
        if ($text === '') {
            $text = '✅ ' . $this->cacheSummary() . ' ' . $this->t('No fetch needed.');
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
                return $this->fail($this->t('The Tibber price overview is available for Germany only (bidding zone DE-LU).'));
            }
            if (!preg_match('/^\d{5}$/', $plz)) {
                return $this->fail($this->t('The Tibber price overview needs a valid postal code (5 digits, panel "Data source").'));
            }
            $url = self::TIBBER_URL . $plz;
        } elseif ($source === self::SOURCE_ENTSOE) {
            $sourceName = 'ENTSO-E';
            $token = trim((string)$this->ReadAttributeString('EntsoeToken'));
            if ($token === '') {
                return $this->fail($this->t('No access key stored yet for "EPEX Spot (via ENTSO-E)" (panel "Data source").'));
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
            return $this->fail($this->t('%s reports a rate limit (HTTP 429) — pausing until %s.', $sourceName, date('H:i:s', $now + $wait)));
        }
        // ENTSO-E beantwortet „keine Daten“ mit HTTP 400 und einem Quittungs-XML samt Grund.
        if ($source === self::SOURCE_ENTSOE && ($status === 200 || $status === 400)) {
            $parsed = $this->parseEntsoe((string)$r['body']);
        } elseif ($status !== 200) {
            if ($status === 0) {
                $why = $r['error'] !== ''
                    ? $this->t('not reachable (%s)', $r['error'])
                    : $this->t('not reachable');
            } elseif ($status === 404) {
                $why = $this->t('has no data for this period (HTTP 404)');
            } elseif ($status === 401) {
                $why = $this->t('rejects the access key (HTTP 401) — please check the key');
            } else {
                $why = $this->t('answers with HTTP %s', $status);
            }
            return $this->fail($sourceName . ' ' . $why . '.');
        } else {
            $data = json_decode((string)$r['body'], true);
            if (!is_array($data)) {
                return $this->fail($this->t('%s returned no readable data (not JSON).', $sourceName));
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
            return $this->fail($this->t('%s returned no prices for today or tomorrow.', $sourceName));
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
            $text .= "\n" . $this->t('ℹ️ Tomorrow is not published yet (day-ahead auction at 12:00, results from about 12:45) — next attempt at %s.', date('H:i', $now < $publish ? $publish : $now + self::RETRY_SECONDS));
        }
        return $text;
    }

    /**
     * Fehler festhalten: Attribut fürs Formular, dauerhaftes Log nur beim ersten Auftreten
     * (SUITE.md „Sichtbare Rückmeldung", Regel 5). Logging immer über $this->LogMessage()
     * (Absender = Instanz), nie IPS_LogMessage — Store-Review-Checkliste 15 (12.10.2026).
     */
    private function fail(string $message): string
    {
        $this->WriteAttributeString('LastError', $this->t('at %s: %s', date('d.m.Y H:i', $this->now()), $message));
        if ($this->ReadAttributeString('LoggedError') !== $message) {
            $this->LogMessage('Abruf fehlgeschlagen: ' . $message, KL_WARNING);
            $this->WriteAttributeString('LoggedError', $message);
        }
        $this->SendDebug('Abruf', 'Fehler: ' . $message, 0);
        $keep = count($this->cache()['slots'] ?? []) > 0
            ? $this->t('Existing prices remain valid.')
            : $this->t('No prices available yet.');
        return $this->t('⚠️ Fetch failed: %s %s', $message, $keep);
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
            return ['status' => 0, 'headers' => [], 'body' => '', 'error' => (string)($err['message'] ?? $this->t('no answer'))];
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
            return ['slots' => [], 'license' => '', 'error' => $this->t('unexpected response format (unix_seconds/price missing or of different length).')];
        }
        $unit = strtolower(str_replace(' ', '', (string)($d['unit'] ?? '')));
        if ($unit !== '' && $unit !== 'eur/mwh') {
            return ['slots' => [], 'license' => '', 'error' => $this->t('unexpected unit "%s" (EUR/MWh expected).', $d['unit'])];
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
            return ['slots' => [], 'license' => '', 'error' => $this->t('unexpected response format (data missing).')];
        }
        $slots = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !is_numeric($row['start_timestamp'] ?? null) || !is_numeric($row['end_timestamp'] ?? null) || !is_numeric($row['marketprice'] ?? null)) {
                continue;
            }
            $unit = strtolower(str_replace(' ', '', (string)($row['unit'] ?? 'Eur/MWh')));
            if ($unit !== 'eur/mwh') {
                return ['slots' => [], 'license' => '', 'error' => $this->t('unexpected unit "%s" (Eur/MWh expected).', $row['unit'])];
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
            return ['slots' => [], 'license' => '', 'error' => $this->t('returned no prices (is the postal code known?).')];
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
            return $err($this->t('no readable data (not XML).'));
        }
        if ($xml->getName() === 'Acknowledgement_MarketDocument') {
            $reason = trim((string)($xml->xpath('//*[local-name()="Reason"]/*[local-name()="text"]')[0] ?? ''));
            if (stripos($reason, 'No matching data') !== false) {
                return $err($this->t('has no data for this period yet.'));
            }
            return $err($this->t('reports: %s.', $reason !== '' ? mb_substr($reason, 0, 200) : $this->t('request rejected')));
        }
        $chosen = [];
        foreach ($xml->xpath('//*[local-name()="TimeSeries"]') ?: [] as $ts) {
            $currency = strtoupper((string)($ts->xpath('./*[local-name()="currency_Unit.name"]')[0] ?? ''));
            $unit = strtoupper((string)($ts->xpath('./*[local-name()="price_Measure_Unit.name"]')[0] ?? ''));
            if ($currency !== 'EUR' || $unit !== 'MWH') {
                return $err($this->t('unexpected unit "%s/%s" (EUR/MWH expected).', $currency, $unit));
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
                return $err($this->t('unexpected resolution "%s".', $resText));
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
            return $err($this->t('returned no prices (no TimeSeries).'));
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

    /**
     * Archivierung von „Börsenpreis jetzt" einschalten — nur wenn der Nutzer sie im Formular
     * angehakt hat (Store-Review-Checkliste 9k: archivbezogene Schalter als Vorgabe AUS), und dann
     * genau einmal. Abgeschaltet wird die Archivierung nie vom Modul: Wer den Haken wieder
     * entfernt, behält seine bisherige Historie und entscheidet selbst im Archiv-Dialog.
     */
    private function ensureArchiving(): void
    {
        if (!$this->ReadPropertyBoolean('ArchivePrice') || $this->ReadAttributeBoolean('ArchiveInitDone')
            || !function_exists('AC_SetLoggingStatus')) {
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
     * Rückgabe: ['id' => Instanz, 'curve' => [[start, end, price brutto ct/kWh], …], 'problem' => Text,
     * 'state' => off|missing|ambiguous|problem|ok, 'list' => gefundene Instanzen, 'version' => Vertrag].
     * $use/$chosen überschreiben die gespeicherten Einstellungen (Formular folgt der Auswahl vor „Übernehmen“).
     */
    private function tibberGridRewardsInfo(?bool $use = null, ?int $chosen = null): array
    {
        $info = ['id' => 0, 'curve' => [], 'problem' => '', 'state' => 'off', 'list' => [], 'version' => ''];
        if (!($use ?? $this->ReadPropertyBoolean('UseTibberPrice'))) {
            return $info;
        }
        $info['state'] = 'missing';
        if (!function_exists('TIBBERGR_GetPriceCurve')) {
            return $info;
        }
        $info['list'] = array_map('intval', IPS_GetInstanceListByModuleID(self::TIBBERGR_GUID));
        if (count($info['list']) === 0) {
            return $info;
        }
        $chosen = $chosen ?? $this->ReadPropertyInteger('TibberInstance');
        if (count($info['list']) > 1 && !in_array($chosen, $info['list'], true)) {
            $info['state'] = 'ambiguous';
            return $info;
        }
        $info['state'] = 'problem';
        $info['id'] = count($info['list']) > 1 ? $chosen : $info['list'][0];
        try {
            $curve = TIBBERGR_GetPriceCurve($info['id']);
        } catch (Throwable $e) {
            $info['problem'] = $this->t('Tibber Grid Rewards (#%s) does not answer: %s', $info['id'], $e->getMessage());
            return $info;
        }
        if (!is_array($curve) || count($curve) === 0) {
            $info['problem'] = $this->t('Tibber Grid Rewards (#%s) currently returns no prices.', $info['id']);
            return $info;
        }
        $version = (string)($curve[0]['contractVersion'] ?? '1.0');
        $info['version'] = $version;
        if ((int)explode('.', $version)[0] !== 1) {
            $info['problem'] = $this->t('This module needs an update to use Tibber Grid Rewards (contract 1.x expected, %s delivered).', $version);
            return $info;
        }
        foreach ($curve as $c) {
            if (!is_array($c) || !is_numeric($c['start'] ?? null) || !is_numeric($c['end'] ?? null) || !is_numeric($c['price'] ?? null)
                || ($c['basis'] ?? 'endkunde') !== 'endkunde') {
                continue;
            }
            $info['curve'][] = [(int)$c['start'], (int)$c['end'], (float)$c['price']];
        }
        if (count($info['curve']) === 0) {
            $info['problem'] = $this->t('Tibber Grid Rewards (#%s) returns no retail price (basis "endkunde").', $info['id']);
            return $info;
        }
        $info['state'] = 'ok';
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
    /**
     * Statuszeile der Verbindung zu Tibber Grid Rewards + was für den Energie Manager tatsächlich gilt
     * (SUITE.md „Verbund-Verbindungen im Formular sichtbar machen“, 21.09.2026): ✅ mit Instanz, Name,
     * Vertragsversion und übernommenem Wert, ⚠️ verbunden aber unbrauchbar / mehrdeutig, ℹ️ nicht
     * gefunden oder abgewählt. Parameter = Formularauswahl vor „Übernehmen“ (onChange).
     */
    private function marketSourceStatus(?array $info = null, ?bool $tariff = null, ?int $source = null): string
    {
        $info = $info ?? $this->tibberGridRewardsInfo();
        $tariff = $tariff ?? (bool)$this->ReadPropertyBoolean('TariffEnabled');
        $source = $source ?? $this->source();
        if ($tariff) {
            $own = $this->t('🧾 Price from your own tariff (exchange price + supplier surcharge + grid fee + taxes and levies, incl. 19 % VAT).');
        } elseif ($source === self::SOURCE_TIBBER) {
            $own = $this->t('🏷 Retail price from the Tibber price overview for postal code %s (incl. VAT).', $this->postalCode());
        } else {
            $own = $this->t('ℹ️ Plain exchange price (net) — for a real retail price switch on your own tariff below.');
        }
        switch ($info['state']) {
            case 'ok':
                $now = $this->tibberPriceAt($info['curve'], $this->now());
                $until = max(array_map(fn($c) => $c[1], $info['curve']));
                $jetzt = $now !== null
                    ? $this->t('retail price now %s ct/kWh', $this->num($now, 2))
                    : $this->t('no Tibber price for right now');
                return $this->t('✅ Price taken from Tibber Grid Rewards (#%s "%s", contract %s): %s, Tibber prices until %s.', $info['id'], IPS_GetName($info['id']), $info['version'], $jetzt, $this->dt($until))
                    . "\n" . $this->t('For quarter hours without a Tibber price: %s', $own);
            case 'ambiguous':
                return $this->t('⚠️ Several instances of Tibber Grid Rewards found (#%s) — please pick one under "Tibber instance" below; until then none is used.', implode(', #', $info['list'])) . "\n" . $own;
            case 'problem':
                return '⚠️ ' . $info['problem'] . "\n" . $own;
            case 'missing':
                return $this->t('ℹ️ Tibber Grid Rewards not found — this applies:') . "\n" . $own;
            default:
                return $this->t('ℹ️ Tibber Grid Rewards switched off — this applies:') . "\n" . $own;
        }
    }

    /** 🔗-Zeile, wenn der Endpreis automatisch von Tibber kommt (ersetzt die Tarif-Felder, SUITE.md 21.09.2026). */
    private function tibberAutoLine(array $info): string
    {
        if ($info['state'] !== 'ok') {
            return '';
        }
        $now = $this->tibberPriceAt($info['curve'], $this->now());
        $wert = $now !== null
            ? $this->t('%s ct/kWh now', $this->num($now, 2))
            : $this->t('from the next Tibber quarter hour on');
        return $this->t('🔗 Retail price: %s (automatically from Tibber Grid Rewards #%s). The tariff fields are not needed for this and are hidden. To calculate your own tariff instead, switch off "Use price from Tibber Grid Rewards".', $wert, $info['id']);
    }

    /**
     * SPOT_UIRefreshMarket($id, …): Statuszeile, 🔗-Zeile und Tarif-Felder folgen der Auswahl im
     * Formular, bevor „Übernehmen“ geklickt ist (onChange von Häkchen, Tibber-Instanz und Quelle).
     */
    public function UIRefreshMarket(bool $UseTibberPrice, bool $TariffEnabled, int $TibberInstance, int $Source): void
    {
        $info = $this->tibberGridRewardsInfo($UseTibberPrice, $TibberInstance);
        $this->UpdateFormField('MarketSourceStatus', 'caption', $this->marketSourceStatus($info, $TariffEnabled, $Source));
        $auto = $this->tibberAutoLine($info);
        $this->UpdateFormField('TibberAutoLine', 'caption', $auto);
        $this->UpdateFormField('TibberAutoLine', 'visible', $auto !== '');
        $this->UpdateFormField('TibberAutoLine', 'color', $auto !== '' ? self::COLOR_AUTO : -1);
        $this->UpdateFormField('TibberInstance', 'visible', $UseTibberPrice && count($info['list']) > 1);
        foreach (self::TARIFF_FIELDS as $name) {
            $this->UpdateFormField($name, 'visible', $auto === '');
        }
    }

    /**
     * Zustand der Archivierung von „Börsenpreis jetzt" — die Voraussetzung für den Rückblick
     * (SPOT_GetPriceHistory, Diagramm vergangener Tage im NRG-Stack Dashboard).
     */
    private function archiveStatus(): string
    {
        $arch = $this->archiveID();
        $vid = IPS_GetObjectIDByIdent('CurrentPrice', $this->InstanceID);
        if (!function_exists('AC_GetLoggingStatus') || $arch <= 0 || $vid === false) {
            return $this->t('ℹ️ No archive found — without an archive there is no look back at past days.');
        }
        if (AC_GetLoggingStatus($arch, (int)$vid)) {
            return $this->t('✅ "Exchange price now" is being archived — that is where the look back at past days comes from (SPOT_GetPriceHistory, chart in the NRG-Stack dashboard). The module never switches archiving off by itself.');
        }
        return $this->t('ℹ️ "Exchange price now" is not being archived. Without an archive the contract only returns today and tomorrow, no past day. Tick the box and click "Apply", then the module switches archiving on once.');
    }

    private function entsoeTokenStatus(): string
    {
        $t = trim((string)$this->ReadAttributeString('EntsoeToken'));
        return $t === ''
            ? $this->t('ℹ️ No ENTSO-E access key stored yet.')
            : $this->t('✅ ENTSO-E access key stored (ending in …%s).', mb_substr($t, -4));
    }

    /**
     * Nutzersichtbaren Text übersetzen (Quelltext englisch, Übersetzung in locale.json —
     * SUITE.md Konvention 9). Weitere Argumente werden wie bei sprintf eingesetzt (%s), damit
     * zusammengesetzte Sätze in der Übersetzung umgestellt werden können.
     */
    private function t(string $text, ...$args): string
    {
        $out = $this->Translate($text);
        if ($args === []) {
            return $out;
        }
        $formatted = @vsprintf($out, array_map(fn($a) => is_scalar($a) || $a === null ? (string)$a : (string)json_encode($a), $args));
        return $formatted === false ? $out : $formatted;
    }

    /** Läuft die Oberfläche auf Deutsch? (Einmal fragen statt Zahl/Datum je Text neu zu prüfen.) */
    private function german(): bool
    {
        // Einmal je Durchlauf fragen (Stolperstein 24: nicht hunderte Translate()-Aufrufe je Lauf).
        if ($this->istDeutsch === null) {
            $this->istDeutsch = $this->t('Yes') === 'Ja';
        }
        return $this->istDeutsch;
    }

    /** Zahl in der Sprache des Nutzers: 1.234,56 (de) bzw. 1,234.56 (en). */
    private function num(float $value, int $digits): string
    {
        return $this->german() ? number_format($value, $digits, ',', '.') : number_format($value, $digits, '.', ',');
    }

    /** Datum/Uhrzeit in der Sprache des Nutzers (de: TT.MM.JJJJ HH:MM, en: YYYY-MM-DD HH:MM). */
    private function dt(int $ts, string $what = 'datetime'): string
    {
        $date = $this->german() ? 'd.m.Y' : 'Y-m-d';
        if ($what === 'date') {
            return date($date, $ts);
        }
        if ($what === 'time') {
            return date('H:i', $ts);
        }
        if ($what === 'timesec') {
            return date('H:i:s', $ts);
        }
        return date($date . ' H:i', $ts);
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
            IPS_SetVariableProfileAssociation('SPOT.YesNo', 0, $this->t('No'), '', -1);
            IPS_SetVariableProfileAssociation('SPOT.YesNo', 1, $this->t('Yes'), '', -1);
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
            return $this->t('No prices available yet.');
        }
        $neg = 0;
        foreach (array_merge($this->daySlots($now, 0), $this->daySlots($now, 1)) as $s) {
            if ($s['price'] < 0) {
                $neg++;
            }
        }
        $span = $tomorrow > 0
            ? $this->t('today + tomorrow')
            : $this->t('today only');
        $negText = $neg === 0
            ? $this->t('none negative.')
            : $this->t('%s of them negative.', $neg);
        return $this->t('%s quarter hours (%s) from %s, %s', $today + $tomorrow, $span, $this->sourceName((int)($cache['source'] ?? $this->source())), $negText);
    }

    private function fmtCt(float $v): string
    {
        return $this->num($v, 2) . ' ct/kWh';
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
        return $this->t('👋 "What is this module for?" is back at the top of the form.');
    }

    public function AckNews(): void
    {
        // Die tatsächlich installierte Bibliotheksversion merken, nicht den letzten
        // NEWS_VERSIONS-Schlüssel (SUITE.md 23.09.2026): So zeigt ein späteres Update den Banner
        // sicher wieder, auch wenn zwischen beiden Versionen kein Eintrag lag.
        $lib = @IPS_GetLibrary(self::LIBRARY_GUID);
        $ver = is_array($lib) ? $this->baseVersion((string)($lib['Version'] ?? '')) : '';
        // Höchste bekannte News-Version über version_compare bestimmen, NIE über die Reihenfolge
        // im Array (array_key_last) — ein neuer Eintrag an falscher Stelle würde sonst stillschweigend
        // eine ältere Version merken und den Hinweis dauerhaft zeigen (Fund im Prüfstand 27.10.2026).
        $hoechste = '';
        foreach (array_keys(self::NEWS_VERSIONS) as $k) {
            if ($hoechste === '' || version_compare((string)$k, $hoechste, '>')) {
                $hoechste = (string)$k;
            }
        }
        if ($ver === '' || version_compare($ver, $hoechste, '<')) {
            $ver = $hoechste;
        }
        $this->WriteAttributeString('SeenNews', $ver);
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
            $line = $this->t('ℹ️ No fetch yet.');
        } elseif ($error !== '') {
            $line = '⚠️ ' . $error;
        } else {
            $line = $this->t('✅ Last fetched %s — %s', $this->dt((int)($cache['fetchedAt'] ?? $lastTry)) . date(':s', (int)($cache['fetchedAt'] ?? $lastTry)), $this->cacheSummary());
        }
        if ($blocked > $now) {
            $line .= "\n" . $this->t('⏳ Rate limit of the source: next fetch at %s at the earliest.', date('H:i:s', $blocked));
        } elseif ($this->hasDay($now, 0) && !$this->hasDay($now, 1)) {
            $publish = $this->publishTs($now, 0);
            $line .= "\n" . $this->t('ℹ️ Tomorrow\'s prices are not published yet — next attempt at %s.', date('H:i', $now < $publish ? $publish : max($now, $lastTry) + self::RETRY_SECONDS));
        }
        return $line;
    }

    /** Kurzübersicht je Tag: Anzahl, Spanne, Mittel, negative Viertelstunden. */
    private function priceSummary(): string
    {
        $now = $this->now();
        $lines = [];
        foreach ([0 => $this->t('Today'), 1 => $this->t('Tomorrow')] as $offset => $label) {
            $slots = $this->daySlots($now, $offset);
            if (count($slots) === 0) {
                $lines[] = $label . ': ' . ($offset === 1
                    ? $this->t('not published yet.')
                    : $this->t('no prices available.'));
                continue;
            }
            $prices = array_column($slots, 'price');
            $neg = array_values(array_filter($slots, function ($s) {
                return $s['price'] < 0;
            }));
            $line = $label . ': ' . $this->t('%s quarter hours, %s to %s, average %s.', count($slots), $this->fmtCt(min($prices)), $this->fmtCt(max($prices)), $this->fmtCt(array_sum($prices) / count($prices)));
            if (count($neg) > 0) {
                $line .= ' ' . $this->t('⚡ %s negative quarter hour(s), first at %s, last ends %s.', count($neg), date('H:i', (int)$neg[0]['start']), date('H:i', (int)end($neg)['end']));
            } else {
                $line .= ' ' . $this->t('No negative prices.');
            }
            $lines[] = $line;
        }
        $res = $this->cache()['slots'][0]['res'] ?? 900;
        if ((int)$res === 3600) {
            $lines[] = $this->t('ℹ️ The source returns hourly values — each hour is spread over four equal quarter hours.');
        }
        return implode("\n", $lines);
    }

    private function sourceInfo(): string
    {
        if ($this->source() === self::SOURCE_TIBBER) {
            return $this->t('Tibber price overview (public, no Tibber account): for your postal code Tibber gives the exchange price, the grid fee and the levies per quarter hour. All values and the federation contract use the exchange price part; the variable "Market data (Energy Manager)" gets Tibber\'s retail price incl. VAT — just like in Symcon\'s "Strompreis" module. Your postal code is sent to Tibber. This is not an officially documented interface and may change. The retail price applies to a standard Tibber customer at this postal code — whether your own tariff or time-variable grid fees (§ 14a module 3) differ is something only your contract knows.');
        }
        if ($this->source() === self::SOURCE_ENTSOE) {
            return $this->t('EPEX Spot via ENTSO-E: the day-ahead results of the EPEX Spot power exchange, published on the ENTSO-E Transparency Platform — quarter hours, free of charge, but with your own access key (create an account at transparency.entsoe.eu, then request the key by e-mail to transparency@entsoe.eu with the subject "Restful API access"). Attribution: ENTSO-E Transparency Platform. Directly from EPEX Spot the data is only available with a paid contract.');
        }
        if ($this->source() === self::SOURCE_AWATTAR) {
            return $this->t('aWATTar: free within fair use (about 100 requests per day according to the provider — this module usually needs 1–5). Returns hourly values only: a single negative quarter hour can disappear in the hourly average. For the quarter-hour rules (§ 51 EEG) Energy-Charts is the better choice.');
        }
        $license = (string)($this->cache()['license'] ?? '');
        return $this->t('Energy-Charts (Fraunhofer ISE): no registration, quarter hours. The source limits requests per minute — this module usually asks only 1–5 times a day and respects every pause the source asks for. Attribution: Energy-Charts.info')
            . ($license !== '' ? ' — ' . $license : ' — ' . $this->t('licence CC BY 4.0, data Bundesnetzagentur | SMARD.de')) . '.';
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
            'caption' => $this->t('👋  What is this module for?'),
            'items' => [
                ['type' => 'Label', 'caption' => $this->t('This module fetches the day-ahead exchange prices for today and tomorrow (quarter hours, bidding zone Germany/Luxembourg or Austria) — without an account and without a contract with a particular supplier — and provides them as variables, to scripts and to other NRG-Stack modules.')],
                ['type' => 'Label', 'caption' => $this->t('The point: some rules depend on the exchange price, not on your tariff. For example, new PV systems receive no feed-in tariff during quarter hours with a negative exchange price. With this module the NRG-Stack EMS, for instance, can spot exactly those quarter hours and avoid feeding in — even with a fixed electricity tariff.')],
                ['type' => 'Label', 'caption' => $this->t('Useful even without other NRG-Stack modules: Symcon\'s own Energy Manager gets the prices for the next 24 hours from here — as your retail price if you enter your tariff — and uses them to plan cheap charging, for example. With a dynamic tariff through the NRG-Stack Tibber Grid Rewards module you get your real retail price from there; both can run side by side.')],
                ['type' => 'Button', 'caption' => $this->t('Understood – do not show again'), 'onClick' => 'SPOT_AckPurposeIntro($id);'],
            ],
        ];
    }

    private function NewsBanner(): ?array
    {
        $seen = (string)$this->ReadAttributeString('SeenNews');
        $pending = [];
        foreach (self::NEWS_VERSIONS as $ver => $lines) {
            if ($seen === '' || version_compare((string)$ver, $seen, '>')) {
                $pending[(string)$ver] = $lines;
            }
        }
        if (count($pending) === 0) {
            return null;
        }
        uksort($pending, 'version_compare');
        $items = [];
        $multi = count($pending) > 1;
        foreach ($pending as $ver => $lines) {
            if ($multi) {
                $items[] = ['type' => 'Label', 'caption' => $this->t('Version %s:', $ver)];
            }
            foreach ($lines as $line) {
                $items[] = ['type' => 'Label', 'caption' => '• ' . $this->t($line)];
            }
        }
        $items[] = ['type' => 'Button', 'caption' => $this->t('Understood – do not show again'), 'onClick' => 'SPOT_AckNews($id);'];
        return [
            'type' => 'ExpansionPanel', 'name' => 'NewsPanel', 'expanded' => true,
            'caption' => $this->t('🆕  New up to version %s', array_key_last($pending)),
            'items' => $items,
        ];
    }

    /** Version ohne Beta-/Build-Zusatz — Vergleichsbasis für NEWS_VERSIONS (SUITE.md 23.09.2026). */
    private function baseVersion(string $v): string
    {
        return preg_replace('/-.*$/', '', $v) ?? $v;
    }

    private function DocPanel(): array
    {
        $lib    = @IPS_GetLibrary(self::LIBRARY_GUID);
        $verTxt = (is_array($lib) && isset($lib['Version']))
            ? $this->t('ℹ️ NRG-Stack Börsenpreis version %s (build %s)', $lib['Version'], $lib['Build'] ?? '?')
            : 'ℹ️ NRG-Stack Börsenpreis';
        return [
            'type' => 'ExpansionPanel', 'expanded' => false,
            'caption' => $this->t('📖  Documentation & help'),
            'items' => [
                ['type' => 'Label', 'caption' => $verTxt],
                ['type' => 'Button', 'caption' => $this->t('👋 Show "What is this module for?" again'), 'onClick' => 'echo SPOT_ShowPurposeIntro($id);'],
                ['type' => 'Label', 'caption' => $this->t('What you get: the day-ahead exchange price of the chosen bidding zone per quarter hour, converted to ct/kWh, NET — without taxes, levies and grid fee. Negative prices are passed on exactly as they are. This is not your retail price.')],
                ['type' => 'Label', 'caption' => $this->t('When it fetches: once right after creation, then only when something is missing. Tomorrow\'s prices are set in the day-ahead auction at 12:00 and are usually available from about 12:45; until then the module asks every 15 minutes. If the source asks for a pause (rate limit), the module waits exactly that long.')],
                ['type' => 'Label', 'caption' => $this->t('Variables: "Exchange price now" (ct/kWh), "Negative exchange price now" (yes/no), "Next negative quarter hour" (start, 0 = none known), "Prices for tomorrow published" (yes/no), "Market data (Energy Manager)" (prices for the next 24 hours for Symcon\'s Energy Manager, see below). Updated every quarter hour. "Exchange price now" is archived if you tick the box in the panel "Exchange prices"; the module then switches it on once and never off again.')],
                ['type' => 'Label', 'caption' => $this->t('Display: the price curve for today and tomorrow with the negative quarter hours is shown by the NRG-Stack dashboard (PV monitoring, tab "Strompreis"). The variables can also be linked into the WebFront area in the object tree.')],
                ['type' => 'Label', 'caption' => $this->t('Symcon Energy Manager: "Market data (Energy Manager)" contains [{start, end, price}] from the current quarter hour for up to 24 hours, price in ct/kWh — the same format as Symcon\'s "Strompreis" module, but with your retail price: the real Tibber price from Tibber Grid Rewards, otherwise your own tariff, otherwise Tibber\'s price for your postal code (source: Tibber price overview), otherwise the plain exchange price. The status line in the panel "Symcon Energy Manager & tariff" shows which one currently applies.')],
                ['type' => 'Label', 'caption' => $this->t('Scripts and other modules: SPOT_GetPriceCurve(<instance ID>) returns a list of all quarter hours of today and (once published) tomorrow — per entry start, end (exclusive, Unix time), price (ct/kWh), basis "spot", netzentgelt "fehlt", level (always empty), quelle, aufloesung (900 = quarter hour, 3600 = spread from an hourly value) and contractVersion. Gaps are possible; missing values are never returned as 0.')],
                ['type' => 'Label', 'caption' => $this->t('SPOT_GetPriceHistory(<instance ID>, from, to) returns the same entries for any period (Unix time, "to" exclusive, at most 400 days). Past quarter hours come from the archive (quelle "archiv", step curve: a value applies until the next one, at most 12 hours — longer gaps, for example while Symcon was switched off, stay empty); before the first archive entry there are no entries.')],
                ['type' => 'Label', 'caption' => $this->t('SPOT_Update(<instance ID>) fetches immediately and returns the result as text. Daylight saving: days with 23 or 25 hours have 92 or 100 quarter hours — all time stamps are real Unix time, nothing is derived from fixed day lengths.')],
                ['type' => 'Label', 'caption' => $this->t('Not legal advice: which rules apply to your system during negative prices depends on the commissioning date, among other things. The NRG-Stack EMS classifies this from its plant data; this module only provides the prices.')],
            ],
        ];
    }

    private function SourcePanel(): array
    {
        $entsoe = $this->source() === self::SOURCE_ENTSOE;
        $tibber = $this->source() === self::SOURCE_TIBBER;
        $zones = [];
        foreach (self::ZONES as $value => $caption) {
            $zones[] = ['caption' => $this->t($caption), 'value' => $value];
        }
        return [
            'type' => 'ExpansionPanel', 'expanded' => true,
            'caption' => $this->t('🔌  Data source'),
            'items' => [
                ['type' => 'Select', 'name' => 'Source', 'caption' => $this->t('Source'), 'width' => self::FIELD_WIDTH, 'onChange' => 'SPOT_UIChangeSource($id, $Source); SPOT_UIRefreshMarket($id, $UseTibberPrice, $TariffEnabled, $TibberInstance, $Source);', 'options' => [
                    ['caption' => $this->t('Energy-Charts (Fraunhofer ISE) — recommended'), 'value' => self::SOURCE_ENERGYCHARTS],
                    ['caption' => $this->t('EPEX Spot via ENTSO-E — with access key'), 'value' => self::SOURCE_ENTSOE],
                    ['caption' => $this->t('Tibber price overview — by postal code'), 'value' => self::SOURCE_TIBBER],
                    ['caption' => $this->t('aWATTar — hourly values only'), 'value' => self::SOURCE_AWATTAR],
                ]],
                ['type' => 'ValidationTextBox', 'name' => 'TibberPostalCode', 'visible' => $tibber, 'caption' => $this->t('Postal code'), 'validate' => '^[0-9]{5}$', 'width' => self::FIELD_WIDTH],
                ['type' => 'Label', 'name' => 'TibberPostalHint', 'visible' => $tibber, 'caption' => $this->t('ℹ️ The postal code determines the grid fee and levies in Tibber\'s retail price and is sent to Tibber.')],
                ['type' => 'Label', 'name' => 'EntsoeTokenStatus', 'visible' => $entsoe, 'caption' => $this->entsoeTokenStatus()],
                ['type' => 'PasswordTextBox', 'name' => 'EntsoeTokenInput', 'visible' => $entsoe, 'caption' => $this->t('ENTSO-E access key'), 'width' => self::FIELD_WIDTH],
                ['type' => 'Button', 'name' => 'EntsoeTokenButton', 'visible' => $entsoe, 'caption' => $this->t('🔑 Save key'), 'onClick' => 'echo SPOT_SetEntsoeToken($id, $EntsoeTokenInput);'],
                ['type' => 'Button', 'name' => 'EntsoeTokenGuide', 'visible' => $entsoe, 'caption' => $this->t('Guide: request an access key'), 'onClick' => "echo '" . self::ENTSOE_TOKEN_URL . "';", 'link' => true],
                ['type' => 'Select', 'name' => 'BiddingZone', 'caption' => $this->t('Bidding zone'), 'width' => self::FIELD_WIDTH, 'options' => $zones],
                ['type' => 'PopupButton', 'caption' => $this->t('Which source and bidding zone should I choose?'), 'width' => '500px', 'popup' => [
                    'caption' => $this->t('Which source and bidding zone should I choose?'),
                    'items' => [
                        ['type' => 'Label', 'caption' => $this->t('Bidding zone: the zone of your grid connection — Germany and Luxembourg together form "DE-LU", Austria is "AT". The price is the same throughout the zone.')],
                        ['type' => 'Label', 'caption' => $this->t('Energy-Charts (recommended): quarter-hour values straight from the day-ahead auction, free to use with attribution. That is exactly what you need for rules that apply per quarter hour, such as the feed-in rule for negative prices.')],
                        ['type' => 'Label', 'caption' => $this->t('EPEX Spot (via ENTSO-E): the same EPEX Spot results, straight from the European transparency platform of the grid operators, also in quarter hours. Needs a free, personal access key — useful as an independent second source or if you have an ENTSO-E account anyway.')],
                        ['type' => 'Label', 'caption' => $this->t('Tibber price overview: no account, just your postal code. Returns the same exchange price plus Tibber\'s retail price for that postal code — the variable for the Symcon Energy Manager uses it so the manager can plan with the real Tibber price, even without the Tibber Grid Rewards module.')],
                        ['type' => 'Label', 'caption' => $this->t('aWATTar: fallback source if Energy-Charts is unreachable. Returns hourly values only; a single negative quarter hour can get lost in them.')],
                        ['type' => 'Label', 'caption' => $this->t('After a change the module discards the old prices and fetches again right away when you apply.')],
                    ],
                ]],
                ['type' => 'Label', 'name' => 'SourceInfo', 'caption' => $this->sourceInfo()],
                ['type' => 'Button', 'caption' => $this->t('🔄 Fetch prices now'), 'onClick' => 'echo SPOT_Update($id);'],
                ['type' => 'Label', 'name' => 'FetchStatus', 'caption' => $this->fetchStatusLine()],
            ],
        ];
    }

    private function PricePanel(): array
    {
        return [
            'type' => 'ExpansionPanel', 'expanded' => true,
            'caption' => $this->t('📈  Exchange prices'),
            'items' => [
                ['type' => 'Label', 'name' => 'PriceSummary', 'caption' => $this->priceSummary()],
                ['type' => 'CheckBox', 'name' => 'ArchivePrice', 'caption' => $this->t('Archive price history')],
                ['type' => 'Label', 'name' => 'ArchiveStatus', 'caption' => $this->archiveStatus()],
                ['type' => 'PopupButton', 'caption' => $this->t('Why the exchange price and not my electricity tariff?'), 'width' => '500px', 'popup' => [
                    'caption' => $this->t('Why the exchange price and not my electricity tariff?'),
                    'items' => [
                        ['type' => 'Label', 'caption' => $this->t('Your tariff is what you pay for electricity from the grid — including taxes, levies and grid fee. The exchange price is the plain wholesale price of the day-ahead auction.')],
                        ['type' => 'Label', 'caption' => $this->t('Some rules refer explicitly to the exchange price, whatever tariff you have — for example the feed-in tariff during negative prices, or in future the marketing of new systems. For comparing costs with your purchase price, however, the exchange price alone is not suitable.')],
                    ],
                ]],
                ['type' => 'PopupButton', 'caption' => $this->t('What do negative prices mean for my feed-in tariff?'), 'width' => '500px', 'popup' => [
                    'caption' => $this->t('What do negative prices mean for my feed-in tariff?'),
                    'items' => [
                        ['type' => 'Label', 'caption' => $this->t('For PV systems commissioned on or after 25.02.2025 (German "Solarspitzengesetz") the feed-in tariff is dropped for every quarter hour in which the day-ahead exchange price is negative (§ 51 EEG). The lost time is partly added at the end of the support period.')],
                        ['type' => 'Label', 'caption' => $this->t('Older systems are affected differently or not at all, depending on size and year of commissioning. The NRG-Stack EMS classifies this for your system from its plant data. This is not legal advice.')],
                        ['type' => 'Label', 'caption' => $this->t('This module shows when negative quarter hours are coming up — an EMS can then route the energy into the battery, the heat pump or the car instead of the grid.')],
                    ],
                ]],
            ],
        ];
    }

    private function TariffPanel(): array
    {
        $vid = IPS_GetObjectIDByIdent('MarketData', $this->InstanceID);
        $ct = function (float $v) {
            return $this->num($v, 3);
        };
        $quarter = function (int $q, string $months) {
            return ['type' => 'CheckBox', 'name' => 'Modul3Q' . $q, 'caption' => 'Q' . $q . ' (' . $months . ')'];
        };
        $info = $this->tibberGridRewardsInfo();
        $auto = $this->tibberAutoLine($info);
        $show = $auto === ''; // Tarif-Felder nur, wenn der Endpreis NICHT automatisch von Tibber kommt
        $refresh = 'SPOT_UIRefreshMarket($id, $UseTibberPrice, $TariffEnabled, $TibberInstance, $Source);';
        $instOptions = [['caption' => $this->t('please choose'), 'value' => 0]];
        foreach ($info['list'] as $iid) {
            $instOptions[] = ['caption' => '#' . $iid . ' ' . IPS_GetName($iid), 'value' => $iid];
        }
        return [
            'type' => 'ExpansionPanel', 'expanded' => false,
            'caption' => $this->t('⚡  Symcon Energy Manager & tariff'),
            'items' => [
                ['type' => 'Label', 'caption' => $this->t('The variable "Market data (Energy Manager)"%s provides the prices from now for up to 24 hours in the format of Symcon\'s "Strompreis" module. In the Symcon Energy Manager select this variable under "Energiepreise" — it then plans cheap charging, for example, using your retail price.', $vid !== false ? ' (ID ' . $vid . ')' : '')],
                ['type' => 'Label', 'name' => 'MarketSourceStatus', 'caption' => $this->marketSourceStatus($info)],
                ['type' => 'CheckBox', 'name' => 'UseTibberPrice', 'caption' => $this->t('Use price from Tibber Grid Rewards'), 'onChange' => $refresh],
                ['type' => 'Label', 'caption' => $this->t('ℹ️ Only if the Tibber Grid Rewards module is installed: then your real Tibber retail price comes from there (recommended for Tibber customers).')],
                ['type' => 'Select', 'name' => 'TibberInstance', 'caption' => $this->t('Tibber instance'), 'width' => self::FIELD_WIDTH, 'options' => $instOptions,
                    'visible' => count($info['list']) > 1 && $info['state'] !== 'off', 'onChange' => $refresh],
                ['type' => 'Label', 'name' => 'TibberAutoLine', 'caption' => $auto, 'visible' => !$show, 'color' => $show ? -1 : self::COLOR_AUTO],
                ['type' => 'CheckBox', 'name' => 'TariffEnabled', 'caption' => $this->t('Include my own tariff'), 'visible' => $show, 'onChange' => $refresh],
                ['type' => 'Label', 'name' => 'TariffIntro', 'visible' => $show, 'caption' => $this->t('For example for a different dynamic tariff. All amounts net in ct/kWh — the module adds VAT itself.')],
                // Minimum 0: Bei negativem Minimum zeigte die Konsole für den Wert 0 das Minimum an (−20, Live-Fund 14.09.2026).
                ['type' => 'NumberSpinner', 'name' => 'TariffBeschaffung', 'visible' => $show, 'caption' => $this->t('Supplier surcharge'), 'suffix' => ' ct/kWh', 'digits' => 3, 'minimum' => 0, 'maximum' => 100, 'width' => self::FIELD_WIDTH],
                ['type' => 'NumberSpinner', 'name' => 'TariffKonzession', 'visible' => $show, 'caption' => $this->t('Concession levy'), 'suffix' => ' ct/kWh', 'digits' => 3, 'minimum' => 0, 'maximum' => 5, 'width' => self::FIELD_WIDTH],
                ['type' => 'NumberSpinner', 'name' => 'NetzArbeitspreis', 'visible' => $show, 'caption' => $this->t('Grid fee (energy rate)'), 'suffix' => ' ct/kWh', 'digits' => 3, 'minimum' => 0, 'maximum' => 50, 'width' => self::FIELD_WIDTH],
                ['type' => 'Label', 'name' => 'TariffTaxInfo', 'visible' => $show, 'caption' => $this->t('Identical nationwide and included automatically (as of %s, net): electricity tax %s · offshore grid levy %s · CHP levy %s · § 19 StromNEV levy %s ct/kWh; %s %% VAT on the total.', self::TAX_STAND, $ct(self::TAX_STROMSTEUER), $ct(self::TAX_OFFSHORE), $ct(self::TAX_KWK), $ct(self::TAX_STROMNEV19), (int)self::VAT_PERCENT)],
                ['type' => 'CheckBox', 'name' => 'Modul3Enabled', 'visible' => $show, 'caption' => $this->t('Grid fee under § 14a module 3')],
                ['type' => 'NumberSpinner', 'name' => 'NetzHT', 'visible' => $show, 'caption' => $this->t('Grid fee peak rate'), 'suffix' => ' ct/kWh', 'digits' => 3, 'minimum' => 0, 'maximum' => 50, 'width' => self::FIELD_WIDTH],
                ['type' => 'NumberSpinner', 'name' => 'NetzST', 'visible' => $show, 'caption' => $this->t('Grid fee standard rate'), 'suffix' => ' ct/kWh', 'digits' => 3, 'minimum' => 0, 'maximum' => 50, 'width' => self::FIELD_WIDTH],
                ['type' => 'NumberSpinner', 'name' => 'NetzNT', 'visible' => $show, 'caption' => $this->t('Grid fee off-peak rate'), 'suffix' => ' ct/kWh', 'digits' => 3, 'minimum' => 0, 'maximum' => 50, 'width' => self::FIELD_WIDTH],
                [
                    'type' => 'List', 'name' => 'NetzWindows', 'visible' => $show, 'caption' => $this->t('Time windows from your grid operator'), 'rowCount' => 6, 'add' => true, 'delete' => true,
                    'columns' => [
                        ['caption' => $this->t('From'), 'name' => 'From', 'width' => '110px', 'add' => '00:00', 'edit' => ['type' => 'ValidationTextBox', 'validate' => '^([01][0-9]|2[0-3]):[0-5][0-9]$']],
                        ['caption' => $this->t('To'), 'name' => 'To', 'width' => '110px', 'add' => '00:00', 'edit' => ['type' => 'ValidationTextBox', 'validate' => '^([01][0-9]|2[0-3]):[0-5][0-9]$']],
                        ['caption' => $this->t('Rate'), 'name' => 'Band', 'width' => '190px', 'add' => 'ST', 'edit' => ['type' => 'Select', 'options' => [
                            ['caption' => $this->t('Peak rate (HT)'), 'value' => 'HT'],
                            ['caption' => $this->t('Standard rate (ST)'), 'value' => 'ST'],
                            ['caption' => $this->t('Off-peak rate (NT)'), 'value' => 'NT'],
                        ]]],
                        ['caption' => $this->t('Days'), 'name' => 'Days', 'width' => 'auto', 'add' => 'all', 'edit' => ['type' => 'Select', 'options' => [
                            ['caption' => $this->t('All days'), 'value' => 'all'],
                            ['caption' => $this->t('Monday–Friday'), 'value' => 'weekday'],
                            ['caption' => $this->t('Saturday and Sunday'), 'value' => 'weekend'],
                        ]]],
                    ],
                ],
                ['type' => 'RowLayout', 'name' => 'Modul3Quarters', 'visible' => $show, 'items' => [$quarter(1, $this->t('Jan–Mar')), $quarter(2, $this->t('Apr–Jun')), $quarter(3, $this->t('Jul–Sep')), $quarter(4, $this->t('Oct–Dec'))]],
                ['type' => 'Label', 'name' => 'Modul3Hint', 'visible' => $show, 'caption' => $this->t('Outside the time windows and in quarters without a tick the energy rate applies. "To 00:00" means end of day, windows across midnight are allowed. The module does not know public holidays — they count as their weekday.')],
                ['type' => 'PopupButton', 'name' => 'TariffHelp', 'visible' => $show, 'caption' => $this->t('Where do I find the grid fee and the concession levy?'), 'width' => '500px', 'popup' => [
                    'caption' => $this->t('Where do I find the grid fee and the concession levy?'),
                    'items' => [
                        ['type' => 'Label', 'caption' => $this->t('The grid fee and the § 14a module 3 time windows are listed by your grid operator (on their website, usually under "Netzentgelte" or "Preisblatt Netznutzung") and on your electricity bill. The grid operator is named on the bill or can be found from your postal code.')],
                        ['type' => 'Label', 'caption' => $this->t('The concession levy depends on your municipality (in Germany usually between 1.32 and 2.39 ct/kWh net) and is also shown on the bill. Your supplier states the surcharge in its tariff conditions.')],
                        ['type' => 'Label', 'caption' => $this->t('Enter all values net — the module adds VAT itself. If Tibber Grid Rewards is installed you do not need to enter anything here: your real Tibber price comes from there.')],
                    ],
                ]],
                ['type' => 'PopupButton', 'caption' => $this->t('How do I connect the Energy Manager?'), 'width' => '500px', 'popup' => [
                    'caption' => $this->t('How do I connect the Energy Manager?'),
                    'items' => [
                        ['type' => 'Label', 'caption' => $this->t('1. In the Symcon Energy Manager open the field "Energiepreise" and select this instance\'s variable "Market data (Energy Manager)".')],
                        ['type' => 'Label', 'caption' => $this->t('2. Where the price comes from is shown by the status line above: real Tibber price (Tibber Grid Rewards), your own tariff, Tibber\'s price for your postal code, or the plain exchange price.')],
                        ['type' => 'Label', 'caption' => $this->t('3. The variable is rewritten every quarter hour and after every fetch; past quarter hours drop out.')],
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
            'caption' => $this->t('💬  Feedback in the Symcon forum'),
            'items' => [
                ['type' => 'Label', 'caption' => $this->t('Börsenpreis is new — feedback, especially on the sources and on the tariff for the Energy Manager, is very welcome in the community thread.')],
                ['type' => 'Button', 'caption' => $this->t('To the forum thread'), 'onClick' => "echo '" . self::FORUM_THREAD_URL . "';", 'link' => true],
                ['type' => 'Button', 'caption' => $this->t('Understood – do not show again'), 'onClick' => 'SPOT_AckForumHint($id);'],
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
            'caption' => $this->t('🧡  About this module'),
            'items' => [
                ['type' => 'Label', 'caption' => $this->t('Born out of real enthusiasm for my own energy system — and a few evenings spent typing. Still: hobby or not, this is intellectual property and real work went into it.')],
                ['type' => 'Label', 'caption' => $this->t('Licence: PolyForm Noncommercial 1.0.0 — free for private and non-commercial use; commercial use requires a separate licence from the rights holder.')],
                ['type' => 'Button', 'caption' => $this->t('View licence text'), 'onClick' => "echo '" . self::LICENSE_URL . "';", 'link' => true],
                ['type' => 'Label', 'caption' => $this->t('Commercial use or questions about the licence? Just get in touch: dietmar@gureth.eu')],
                ['type' => 'Label', 'caption' => $this->t('Do you like the module and still want to leave something? A small donation is always a pleasure — entirely voluntary, nothing expected in return.')],
                ['type' => 'Button', 'caption' => $this->t('☕  Donate via PayPal'), 'onClick' => "echo '" . self::PAYPAL_URL . "';", 'link' => true],
            ],
        ];
    }

    /** Die wählbaren Fachpanels mit stabilem Schlüssel (Caption darf sich ändern, der Schlüssel nicht). */
    private function fachPanels(): array
    {
        return [
            'quelle' => $this->SourcePanel(),
            'preise' => $this->PricePanel(),
            'tarif'  => $this->TariffPanel(),
        ];
    }

    /**
     * Reihenfolge und Aufklapp-Zustand der Fachpanels aus der Einstellung „FormLayout"
     * (SUITE.md „🪟 Formular: Aufbau"). Unbekannte Schlüssel fallen auf Standard zurück, ein neues
     * Fachpanel erscheint dadurch nach einem Update automatisch an seinem Standardplatz.
     */
    private function formLayout(array $keys): array
    {
        $saved = json_decode((string)$this->ReadPropertyString('FormLayout'), true);
        $by = [];
        foreach (is_array($saved) ? $saved : [] as $row) {
            if (is_array($row) && isset($row['Key'])) {
                $by[(string)$row['Key']] = $row;
            }
        }
        $out = [];
        foreach ($keys as $i => $k) {
            $r = $by[$k] ?? [];
            // Widerspruch: ausgeblendet vor zugeklappt vor aufgeklappt; kein Haken = Standard
            $open = !empty($r['Hidden']) ? 'hidden' : (!empty($r['Closed']) ? 'closed' : (!empty($r['Opened']) ? 'open' : 'std'));
            $out[$k] = [
                'open' => $open,
                'pos'  => isset($r['Pos']) ? max(1, min(99, (int)$r['Pos'])) : $i + 1,
                'idx'  => $i,
            ];
        }
        uasort($out, fn($a, $b) => [$a['pos'], $a['idx']] <=> [$b['pos'], $b['idx']]);
        return $out;
    }

    private function LayoutPanel(array $layout, array $frei): array
    {
        $rows = [];
        $hidden = [];
        foreach ($layout as $key => $l) {
            $name = trim((string)preg_replace('/\s*\(.*$/u', '', (string)($frei[$key]['caption'] ?? $key)));
            $rows[] = ['Key' => $key, 'Panel' => $name, 'Opened' => $l['open'] === 'open', 'Closed' => $l['open'] === 'closed',
                'Hidden' => $l['open'] === 'hidden', 'Pos' => $l['pos']];
            if ($l['open'] === 'hidden') {
                $hidden[] = trim((string)preg_replace('/^[^\p{L}\p{N}]+/u', '', $name));
            }
        }
        $hiddenLine = $hidden
            ? [['type' => 'Label', 'caption' => $this->t('🙈 Hidden: %s — to show them again remove the tick at "Hidden". Hidden sections keep working, only the display is missing.', implode(', ', $hidden))]]
            : [];
        return [
            'type' => 'ExpansionPanel', 'expanded' => false,
            'caption' => $this->t('🪟  Form: layout') . ($hidden ? ' ' . $this->t('(%s hidden)', count($hidden)) : ''),
            'items' => array_merge($hiddenLine, [
                ['type' => 'Label', 'caption' => $this->t('Here you decide which sections are expanded when the form opens and in which order they appear. Symcon does not remember expanding and collapsing itself, so the setting here applies every time the form opens. It takes effect after "Apply", the next time you open the form.')],
                ['type' => 'Label', 'caption' => $this->t('No tick means "default": the way the module intends it. The position is a number; with equal numbers the default order stays. "Hidden" takes a section out of the display without losing anything: its settings are kept and keep working — the tariff for the Energy Manager is still calculated even when its section is hidden. If several boxes are ticked, "Hidden" beats "Collapsed" beats "Expanded". The sections at the top ("What is this module for?", "New", "Documentation & help") and at the bottom ("Feedback in the Symcon forum", "About this module") stay where they are and cannot be hidden.')],
                [
                    'type' => 'List', 'name' => 'FormLayout', 'caption' => $this->t('Sections'), 'rowCount' => count($rows), 'add' => false, 'delete' => false,
                    'loadValuesFromConfiguration' => false,
                    'values' => $rows,
                    'columns' => [
                        ['caption' => $this->t('Section'), 'name' => 'Panel', 'width' => 'auto', 'add' => ''],
                        ['caption' => $this->t('Key'), 'name' => 'Key', 'width' => '0px', 'visible' => false, 'add' => '', 'save' => true],
                        ['caption' => $this->t('Expanded'), 'name' => 'Opened', 'width' => '110px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => $this->t('Collapsed'), 'name' => 'Closed', 'width' => '110px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => $this->t('🙈 Hidden'), 'name' => 'Hidden', 'width' => '130px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => $this->t('Position'), 'name' => 'Pos', 'width' => '100px', 'add' => 1, 'edit' => ['type' => 'NumberSpinner', 'minimum' => 1, 'maximum' => 99]],
                    ],
                ],
                ['type' => 'Button', 'caption' => $this->t('↩️ Reset to default'), 'onClick' => 'echo SPOT_ResetFormLayout($id);'],
            ]),
        ];
    }

    /**
     * SPOT_ResetFormLayout($id): string — Aufbau des Formulars auf Standard zurücksetzen.
     * Schreibt nur ins offene Formular (keine Selbstpersistenz, Store-Review-Checkliste 1/9l).
     */
    public function ResetFormLayout(): string
    {
        $rows = [];
        foreach (array_keys($this->fachPanels()) as $i => $key) {
            $name = trim((string)preg_replace('/\s*\(.*$/u', '', (string)($this->fachPanels()[$key]['caption'] ?? $key)));
            $rows[] = ['Key' => $key, 'Panel' => $name, 'Opened' => false, 'Closed' => false, 'Hidden' => false, 'Pos' => $i + 1];
        }
        $this->UpdateFormField('FormLayout', 'values', json_encode($rows, JSON_UNESCAPED_UNICODE));
        return $this->t('ℹ️ Reset to default. Click "Apply" below to save.');
    }

    public function GetConfigurationForm()
    {
        $this->istDeutsch = null;   // Sprache je Formularaufbau einmal neu ermitteln

        $base   = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        $status = $base['status'] ?? [];

        // Oben und unten stehen die Panels der Verbund-Konvention fest; dazwischen die Fachpanels,
        // deren Reihenfolge und Aufklapp-Zustand der Nutzer im Panel „Formular: Aufbau" wählt.
        $frei = $this->fachPanels();
        $layout = $this->formLayout(array_keys($frei));
        $mitte = [];
        foreach ($layout as $key => $l) {
            $panel = $frei[$key];
            if ($l['open'] === 'open') {
                $panel['expanded'] = true;
            } elseif ($l['open'] === 'closed') {
                $panel['expanded'] = false;
            } elseif ($l['open'] === 'hidden') {
                // Nicht weglassen: Felder bleiben im Formular, gespeicherte Werte überleben „Übernehmen".
                $panel['visible'] = false;
            }
            $mitte[] = $panel;
        }
        $elements = array_values(array_filter(array_merge(
            [$this->PurposeIntro(), $this->NewsBanner(), $this->DocPanel()],
            $mitte,
            [$this->LayoutPanel($layout, $frei), $this->ForumHint(), $this->LicenseHint()]
        )));

        return json_encode(['elements' => $elements, 'actions' => [], 'status' => $status]);
    }
}
