<?php

// ===========================================================================
// NRG-Stack Börsenpreis (NRGSpotPrice) — Day-Ahead-Börsenpreise ohne Tibber,
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
// VERTRAG (contractVersion 1.0, gleiches Format wie TIBBERGR_GetPriceCurve):
//   Liste aufsteigend nach 'start', je Slot:
//   start (Unix, inkl.), end (Unix, EXKLUSIV), price (ct/kWh NETTO, reiner
//   Börsenpreis ohne Steuern/Umlagen/Netzentgelt, negativ erlaubt),
//   basis='spot', netzentgelt='fehlt', level=null (Einstufung macht der
//   Konsument), quelle, aufloesung (Sekunden je Originalwert: 900 oder 3600),
//   contractVersion. Lücken sind zulässig, fehlende Werte werden NIE als 0
//   geliefert (SUITE.md Stolperstein 15).
//
// Eigenständig: setzt kein anderes Modul voraus und wird von keinem
// vorausgesetzt (Konsumenten wie EMS fragen hinter function_exists()).
// ===========================================================================

class NRGSpotPrice extends IPSModule
{
    private const LIBRARY_GUID = '{A5CA79FD-57C6-4F6E-A33B-61BCC2B0C9A7}';
    private const MODULE_GUID  = '{11BBF147-16A1-4332-82A3-29BB31154D03}';

    private const CONTRACT_PRICECURVE = '1.0';

    // Formular-Konvention (SUITE.md "Einheitliche Formular-Optik").
    private const NEWS_VERSION = '0.1.0';
    private const REPO_URL     = 'https://github.com/DG65/NRGSpotPrice';
    private const LICENSE_URL  = 'https://github.com/DG65/NRGSpotPrice/blob/main/LICENSE';
    private const PAYPAL_URL   = 'https://paypal.me/DietmarGureth';
    private const LOG_SENDER   = 'NRG-Stack Börsenpreis';

    private const SOURCE_ENERGYCHARTS = 0;
    private const SOURCE_AWATTAR      = 1;

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

        // Zwischenspeicher bewusst als Attribut: geht er bei einem Modul-Resync
        // verloren, holt der nächste Abruf einfach alles neu.
        $this->RegisterAttributeString('PriceCache', '{}');
        $this->RegisterAttributeInteger('LastTry', 0);
        $this->RegisterAttributeInteger('BlockedUntil', 0);
        $this->RegisterAttributeString('LastError', '');
        $this->RegisterAttributeString('LoggedError', '');

        $this->RegisterAttributeBoolean('PurposeIntroGone', false);
        $this->RegisterAttributeString('SeenNews', '');
        $this->RegisterAttributeBoolean('ForumHintGone', false);

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
        $this->MaintainVariable('CurrentPrice', 'Börsenpreis jetzt', VARIABLETYPE_FLOAT, 'SPOT.CentKWh', 1, true);
        $this->MaintainVariable('NegativeNow', 'Negativer Börsenpreis jetzt', VARIABLETYPE_BOOLEAN, 'SPOT.YesNo', 2, true);
        $this->MaintainVariable('NextNegativeStart', 'Nächste negative Viertelstunde', VARIABLETYPE_INTEGER, '~UnixTimestamp', 3, true);
        $this->MaintainVariable('TomorrowAvailable', 'Preise für morgen veröffentlicht', VARIABLETYPE_BOOLEAN, 'SPOT.YesNo', 4, true);

        // Quelle oder Gebotszone gewechselt: alte Preise gehören nicht mehr zur
        // Einstellung und dürfen nicht als aktuelle Kurve weitergereicht werden.
        $cache = $this->cache();
        if (isset($cache['source']) && ((int)$cache['source'] !== $this->source() || (string)$cache['zone'] !== $this->zone())) {
            $this->WriteAttributeString('PriceCache', '{}');
            $this->WriteAttributeInteger('BlockedUntil', 0);
            $this->WriteAttributeString('LastError', '');
        }

        $this->refresh(false);
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message === IPS_KERNELMESSAGE && isset($Data[0]) && $Data[0] === KR_READY) {
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
        $quelle = ((int)($cache['source'] ?? $this->source())) === self::SOURCE_AWATTAR ? 'awattar' : 'energy-charts';
        $out = [];
        foreach ($cache['slots'] ?? [] as $s) {
            if ((int)$s['end'] <= $todayStart) {
                continue; // gestern — gehört nicht mehr zu "heute + morgen"
            }
            $out[] = [
                'start'           => (int)$s['start'],
                'end'             => (int)$s['end'],
                'price'           => (float)$s['price'],
                'basis'           => 'spot',
                'netzentgelt'     => 'fehlt',
                'level'           => null,
                'quelle'          => $quelle,
                'aufloesung'      => (int)$s['res'],
                'contractVersion' => self::CONTRACT_PRICECURVE,
            ];
        }
        return $out;
    }

    // -----------------------------------------------------------------
    // Aktionen (Formular-Button, Timer, Skripte)
    // -----------------------------------------------------------------

    /** SPOT_Update($id): string — Preise jetzt abrufen, mit Ergebnistext (auch Timer-Einstieg). */
    public function Update(): string
    {
        return $this->refresh(true);
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
        if ($source === self::SOURCE_AWATTAR) {
            $sourceName = 'aWATTar';
            $url = self::AWATTAR_URL[$zone] . '?start=' . ($todayStart * 1000) . '&end=' . ($this->dayStart($now, 2) * 1000);
        } else {
            $sourceName = 'Energy-Charts';
            $url = self::EC_URL . '?bzn=' . rawurlencode($zone) . '&start=' . date('Y-m-d', $todayStart) . '&end=' . date('Y-m-d', $this->dayStart($now, 1));
        }

        $r = $this->httpGet($url);
        $status = (int)$r['status'];
        if ($status === 429) {
            $wait = $this->retryAfterSeconds($r['headers']['retry-after'] ?? '', $now);
            $this->WriteAttributeInteger('BlockedUntil', $now + $wait);
            return $this->fail($sourceName . ' meldet ein Ratenlimit (HTTP 429) — Pause bis ' . date('H:i:s', $now + $wait) . ' Uhr.');
        }
        if ($status !== 200) {
            $why = $status === 0 ? 'nicht erreichbar' . ($r['error'] !== '' ? ' (' . $r['error'] . ')' : '')
                : ($status === 404 ? 'hat für diesen Zeitraum keine Daten (HTTP 404)' : 'antwortet mit HTTP ' . $status);
            return $this->fail($sourceName . ' ' . $why . '.');
        }
        $data = json_decode((string)$r['body'], true);
        if (!is_array($data)) {
            return $this->fail($sourceName . ' lieferte keine lesbaren Daten (kein JSON).');
        }
        $parsed = $source === self::SOURCE_AWATTAR ? $this->parseAwattar($data) : $this->parseEnergyCharts($data);
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

        if ($current === null) {
            $this->SetStatus(201);
        } elseif ($this->ReadAttributeString('LastError') !== '') {
            $this->SetStatus(202);
        } else {
            $this->SetStatus(102);
        }
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
        return $this->ReadPropertyInteger('Source') === self::SOURCE_AWATTAR ? self::SOURCE_AWATTAR : self::SOURCE_ENERGYCHARTS;
    }

    private function zone(): string
    {
        $zone = $this->ReadPropertyString('BiddingZone');
        return isset(self::ZONES[$zone]) ? $zone : 'DE-LU';
    }

    private function cache(): array
    {
        $cache = json_decode($this->ReadAttributeString('PriceCache'), true);
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
        return $source === self::SOURCE_AWATTAR ? 'aWATTar' : 'Energy-Charts';
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
    }

    public function AckNews(): void
    {
        $this->WriteAttributeString('SeenNews', self::NEWS_VERSION);
        $this->UpdateFormField('NewsPanel', 'visible', false);
    }

    public function AckForumHint(): void
    {
        $this->WriteAttributeBoolean('ForumHintGone', true);
        $this->UpdateFormField('ForumHintPanel', 'visible', false);
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
        if ($this->source() === self::SOURCE_AWATTAR) {
            return 'aWATTar: kostenlos im Rahmen fairer Nutzung (laut Anbieter etwa 100 Abfragen am Tag — dieses Modul braucht meist 1–5). '
                . 'Liefert nur Stundenwerte: eine einzelne negative Viertelstunde kann im Stundenmittel verschwinden. Für die Viertelstunden-Pflichten (§ 51 EEG) ist Energy-Charts die bessere Wahl.';
        }
        $license = (string)($this->cache()['license'] ?? '');
        return 'Energy-Charts (Fraunhofer ISE): ohne Anmeldung, Viertelstunden. Die Quelle begrenzt die Abfragen pro Minute — das Modul fragt meist nur 1–5-mal am Tag und hält sich an jede Pause, um die die Quelle bittet. '
            . 'Quellennennung: Energy-Charts.info' . ($license !== '' ? ' — ' . $license : ' — Lizenz CC BY 4.0, Daten Bundesnetzagentur | SMARD.de') . '.';
    }

    private function PurposeIntro(): ?array
    {
        if ($this->ReadAttributeBoolean('PurposeIntroGone')) {
            return null;
        }
        return [
            'type' => 'ExpansionPanel', 'name' => 'PurposeIntroPanel', 'expanded' => true,
            'caption' => '👋  Wozu dieses Modul?',
            'items' => [
                ['type' => 'Label', 'caption' => 'Dieses Modul holt die Day-Ahead-Börsenpreise für heute und morgen (Viertelstunden, Gebotszone Deutschland/Luxemburg oder Österreich) — ohne Konto, ohne Stromvertrag bei einem bestimmten Anbieter — und stellt sie Variablen, Skripten und anderen NRG-Stack-Modulen bereit.'],
                ['type' => 'Label', 'caption' => 'Der Nutzen: Für manche Pflichten zählt der Börsenpreis, nicht dein Tarif. Beispiel: Neue PV-Anlagen bekommen in Viertelstunden mit negativem Börsenpreis keine Einspeisevergütung. Mit diesem Modul kann z. B. das NRG-Stack EMS genau diese Viertelstunden erkennen und die Einspeisung vermeiden — auch mit festem Stromtarif.'],
                ['type' => 'Label', 'caption' => 'Wer einen dynamischen Tarif über das Modul NRG-Stack Tibber Grid Rewards hat, bekommt dort den eigenen Endkundenpreis. Dieses Modul liefert den reinen Börsenpreis — beides kann nebeneinander laufen.'],
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
                ['type' => 'Label', 'caption' => '• Erstes Release: Day-Ahead-Börsenpreise heute + morgen von Energy-Charts (Viertelstunden, Standard) oder aWATTar (Stundenwerte), Gebotszone DE-LU oder AT.'],
                ['type' => 'Label', 'caption' => '• Verbund-Vertrag SPOT_GetPriceCurve() im selben Format wie die Tibber-Preiskurve (basis „spot"), Variablen für den aktuellen Preis und die nächste negative Viertelstunde.'],
                ['type' => 'Label', 'caption' => '• Sparsamer Abruf: nur wenn etwas fehlt, Pausen der Quelle (Ratenlimit) werden eingehalten.'],
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
                ['type' => 'Label', 'caption' => 'Was geliefert wird: der Day-Ahead-Börsenpreis der gewählten Gebotszone je Viertelstunde, umgerechnet in ct/kWh, NETTO — ohne Steuern, Umlagen und Netzentgelt. Negative Preise werden genau so weitergegeben. Das ist nicht dein Endkundenpreis.'],
                ['type' => 'Label', 'caption' => 'Wann abgerufen wird: nach dem Anlegen einmal sofort, danach nur, wenn etwas fehlt. Die Preise für morgen entstehen in der Day-Ahead-Auktion um 12 Uhr und stehen meist ab ca. 12:45 Uhr bereit; bis sie da sind, fragt das Modul alle 15 Minuten. Bittet die Quelle um eine Pause (Ratenlimit), wartet das Modul genau so lange.'],
                ['type' => 'Label', 'caption' => 'Variablen: „Börsenpreis jetzt" (ct/kWh), „Negativer Börsenpreis jetzt" (Ja/Nein), „Nächste negative Viertelstunde" (Beginn, 0 = keine bekannt), „Preise für morgen veröffentlicht" (Ja/Nein). Aktualisierung zu jeder Viertelstunde. Wer einen Verlauf möchte, schaltet für „Börsenpreis jetzt" die Archivierung ein.'],
                ['type' => 'Label', 'caption' => 'Skripte und andere Module: SPOT_GetPriceCurve(<InstanzID>) liefert eine Liste aller Viertelstunden von heute und (sobald veröffentlicht) morgen — je Eintrag start, end (exklusiv, Unixzeit), price (ct/kWh), basis „spot", netzentgelt „fehlt", level (immer leer), quelle, aufloesung (900 = Viertelstunde, 3600 = aus Stundenwert verteilt) und contractVersion. Lücken sind möglich, fehlende Werte stehen nie als 0 drin.'],
                ['type' => 'Label', 'caption' => 'SPOT_Update(<InstanzID>) ruft sofort ab und liefert das Ergebnis als Text. Zeitumstellung: Tage mit 23 bzw. 25 Stunden haben 92 bzw. 100 Viertelstunden — alle Zeitstempel sind echte Unixzeit, nichts wird aus festen Tageslängen errechnet.'],
                ['type' => 'Label', 'caption' => 'Keine Rechtsberatung: Welche Pflichten für deine Anlage bei negativen Preisen gelten, hängt u. a. vom Inbetriebnahmedatum ab. Das NRG-Stack EMS ordnet das über seine Anlagendaten ein; dieses Modul liefert nur die Preise.'],
            ],
        ];
    }

    private function SourcePanel(): array
    {
        $zones = [];
        foreach (self::ZONES as $value => $caption) {
            $zones[] = ['caption' => $caption, 'value' => $value];
        }
        return [
            'type' => 'ExpansionPanel', 'expanded' => true,
            'caption' => '🔌  Datenquelle',
            'items' => [
                ['type' => 'Select', 'name' => 'Source', 'caption' => 'Quelle', 'width' => '560px', 'options' => [
                    ['caption' => 'Energy-Charts (Fraunhofer ISE) — Viertelstunden, ohne Anmeldung (empfohlen)', 'value' => self::SOURCE_ENERGYCHARTS],
                    ['caption' => 'aWATTar — nur Stundenwerte', 'value' => self::SOURCE_AWATTAR],
                ]],
                ['type' => 'Select', 'name' => 'BiddingZone', 'caption' => 'Gebotszone', 'width' => '560px', 'options' => $zones],
                ['type' => 'PopupButton', 'caption' => 'Welche Quelle und Gebotszone soll ich wählen?', 'width' => '460px', 'popup' => [
                    'caption' => 'Welche Quelle und Gebotszone soll ich wählen?',
                    'items' => [
                        ['type' => 'Label', 'caption' => 'Gebotszone: die Zone deines Netzanschlusses — Deutschland und Luxemburg bilden gemeinsam „DE-LU", Österreich ist „AT". Der Preis ist in der ganzen Zone gleich.'],
                        ['type' => 'Label', 'caption' => 'Energy-Charts (empfohlen): Viertelstundenwerte direkt aus der Day-Ahead-Auktion, frei nutzbar mit Quellennennung. Genau das braucht man für Regeln, die je Viertelstunde gelten, z. B. die Vergütungsregel bei negativen Preisen.'],
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
                ['type' => 'PopupButton', 'caption' => 'Warum der Börsenpreis und nicht mein Stromtarif?', 'width' => '460px', 'popup' => [
                    'caption' => 'Warum der Börsenpreis und nicht mein Stromtarif?',
                    'items' => [
                        ['type' => 'Label', 'caption' => 'Dein Tarif ist das, was du für Strom aus dem Netz bezahlst — mit Steuern, Umlagen und Netzentgelt. Der Börsenpreis ist der reine Großhandelspreis der Day-Ahead-Auktion.'],
                        ['type' => 'Label', 'caption' => 'Einige Regeln knüpfen ausdrücklich an den Börsenpreis an, egal welchen Tarif du hast — z. B. die Einspeisevergütung bei negativen Preisen oder künftig die Vermarktung von Neuanlagen. Für Kostenvergleiche mit deinem Bezugspreis ist der Börsenpreis dagegen allein nicht geeignet.'],
                    ],
                ]],
                ['type' => 'PopupButton', 'caption' => 'Was haben negative Preise mit meiner Einspeisevergütung zu tun?', 'width' => '460px', 'popup' => [
                    'caption' => 'Was haben negative Preise mit meiner Einspeisevergütung zu tun?',
                    'items' => [
                        ['type' => 'Label', 'caption' => 'Für PV-Anlagen mit Inbetriebnahme ab 25.02.2025 (Solarspitzengesetz) entfällt die Einspeisevergütung in jeder Viertelstunde, in der der Day-Ahead-Börsenpreis negativ ist (§ 51 EEG). Die ausgefallene Zeit wird am Ende der Förderdauer teilweise angehängt.'],
                        ['type' => 'Label', 'caption' => 'Ältere Anlagen sind je nach Größe und Inbetriebnahmejahr anders oder gar nicht betroffen. Eine Einordnung für deine Anlage liefert das NRG-Stack EMS über seine Anlagendaten. Keine Rechtsberatung.'],
                        ['type' => 'Label', 'caption' => 'Dieses Modul zeigt, wann negative Viertelstunden anstehen — ein EMS kann die Energie dann z. B. in die Batterie, die Wärmepumpe oder das Auto lenken statt ins Netz.'],
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
        return [
            'type' => 'ExpansionPanel', 'name' => 'ForumHintPanel', 'expanded' => true,
            'caption' => '💬  Rückmeldungen',
            'items' => [
                ['type' => 'Label', 'caption' => '🧪 Das Modul ist neu — Fragen, Wünsche oder Fehler sind willkommen (Symcon-Forum bzw. GitHub).'],
                ['type' => 'Button', 'caption' => 'Zum Repository', 'onClick' => "echo '" . self::REPO_URL . "';", 'link' => true],
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
            [$this->SourcePanel(), $this->PricePanel()],
            [$this->ForumHint(), $this->LicenseHint()]
        )));

        return json_encode(['elements' => $elements, 'actions' => [], 'status' => $status]);
    }
}
