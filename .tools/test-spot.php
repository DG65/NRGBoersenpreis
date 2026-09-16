<?php
/**
 * test-spot.php — Prüfstand für NRG-Stack Börsenpreis OHNE laufendes Symcon.
 *
 * Simuliert IPSModule (Properties/Attribute/Variablen/Timer/Status) und die
 * benötigten IPS_*-Kernfunktionen; HTTP-Antworten kommen aus einer Warteschlange
 * mit echten, eingefrorenen Quellantworten (.tools/fixtures). Geprüft werden:
 * Anlage und Erstabruf, Vertrag (Felder, Einheit, Reihenfolge, EXKLUSIVES end),
 * sparsamer Abrufplan (Folgetag ab ~12:45, alle 15 min, danach Ruhe),
 * negative Preise, Sommerzeit (23/25-Stunden-Tag), aWATTar-Stundenwerte auf
 * Viertelstunden verteilt, EMS-Verträglichkeit (parsePT15M-Nachbildung),
 * Fehlerfälle (404, 429 + Retry-After, Netz weg, kaputtes JSON, falsche Einheit,
 * Lücke = kein 0-Preis), gedrosselter Erstabruf im Getter, Tageswechsel,
 * Quellenwechsel, Formular- und Vertrags-Hygiene.
 *
 * Aufruf:   php .tools/test-spot.php
 * Rückgabe: 0 = alle Blöcke bestanden, 1 = mindestens ein Fehlschlag
 */

date_default_timezone_set('Europe/Berlin');

const KR_READY = 10103;
const IPS_KERNELMESSAGE = 10100;
const VARIABLETYPE_BOOLEAN = 0;
const VARIABLETYPE_INTEGER = 1;
const VARIABLETYPE_FLOAT = 2;
const VARIABLETYPE_STRING = 3;

$GLOBALS['VARS'] = [];      // id => [type, value, name, ident, parent, profile]
$GLOBALS['NEXT_ID'] = 50000;
$GLOBALS['PROFILES'] = [];
$GLOBALS['LOG'] = [];
$GLOBALS['HTTP'] = [];      // Warteschlange der nächsten Antworten
$GLOBALS['REQUESTS'] = [];  // abgefragte URLs
$GLOBALS['CLOCK'] = 0;
// Geteiltes Ausblenden: generierte Kernel-Wrapper SPOT_* nachbilden (Instanz-ID → Objekt).
$GLOBALS['SPOT_OBJ'] = [];
$GLOBALS['ADOPT_CALLS'] = 0;
function SPOT_GetDismissState(int $id): string { if (!isset($GLOBALS['SPOT_OBJ'][$id])) { throw new RuntimeException('Instanz fehlt'); } return $GLOBALS['SPOT_OBJ'][$id]->GetDismissState(); }
function SPOT_AdoptDismissState(int $id, string $s): void { $GLOBALS['ADOPT_CALLS']++; if (!isset($GLOBALS['SPOT_OBJ'][$id])) { throw new RuntimeException('Instanz fehlt'); } $GLOBALS['SPOT_OBJ'][$id]->AdoptDismissState($s); }

function IPS_GetKernelRunlevel(): int { return KR_READY; }
function IPS_GetObjectIDByIdent(string $ident, int $parent) {
    foreach ($GLOBALS['VARS'] as $id => $v) { if ($v['ident'] === $ident && $v['parent'] === $parent) { return $id; } }
    return false;
}
function IPS_VariableProfileExists(string $n): bool { return isset($GLOBALS['PROFILES'][$n]); }
function IPS_CreateVariableProfile(string $n, int $t): bool { $GLOBALS['PROFILES'][$n] = ['type' => $t, 'digits' => 0, 'suffix' => '', 'assoc' => []]; return true; }
function IPS_SetVariableProfileDigits(string $n, int $d): bool { $GLOBALS['PROFILES'][$n]['digits'] = $d; return true; }
function IPS_SetVariableProfileText(string $n, string $p, string $s): bool { $GLOBALS['PROFILES'][$n]['suffix'] = $s; return true; }
function IPS_SetVariableProfileAssociation(string $n, $v, string $c, string $i, int $col): bool { $GLOBALS['PROFILES'][$n]['assoc'][(string)$v] = $c; return true; }
function IPS_LogMessage(string $s, string $m): bool { $GLOBALS['LOG'][] = "$s: $m"; return true; }
function IPS_GetLibrary(string $guid): array { return ['Version' => '0.0.0-test', 'Build' => 0]; }
// Archive Control: wie Symcon speichert das Archiv nur WERTÄNDERUNGEN (siehe IPSModule::SetValue).
$GLOBALS['ARCHIVES'] = [];  // vorhandene Archiv-Instanzen
$GLOBALS['AC_LOG'] = [];    // vid => Archivierung an?
$GLOBALS['AC'] = [];        // vid => [['TimeStamp' => ts, 'Value' => v], ...]
$GLOBALS['AC_APPLY'] = 0;
$GLOBALS['INSTANCES'] = []; // Modul-GUID => [Instanz-IDs] (Partnermodule, z. B. Tibber Grid Rewards)
function IPS_GetInstanceListByModuleID(string $guid): array { return $guid === '{43192F0B-135B-4CE7-A0A7-1475603F3060}' ? $GLOBALS['ARCHIVES'] : ($GLOBALS['INSTANCES'][$guid] ?? []); }
function IPS_ApplyChanges(int $id): bool { $GLOBALS['AC_APPLY']++; return true; }
function IPS_InstanceExists(int $id): bool { return true; }
$GLOBALS['AC_CALLS'] = []; // [start, end, limit] je AC_GetLoggedValues-Aufruf (SUITE.md 9g)
function AC_GetLoggingStatus(int $a, int $v): bool { return $GLOBALS['AC_LOG'][$v] ?? false; }
function AC_SetLoggingStatus(int $a, int $v, bool $s): bool { $GLOBALS['AC_LOG'][$v] = $s; return true; }
function AC_GetLoggedValues(int $a, int $v, int $start, int $end, int $limit)
{
    $GLOBALS['AC_CALLS'][] = [$start, $end, $limit];
    $rows = array_values(array_filter($GLOBALS['AC'][$v] ?? [], fn($r) => $r['TimeStamp'] >= $start && ($end === 0 || $r['TimeStamp'] <= $end)));
    usort($rows, fn($x, $y) => $y['TimeStamp'] <=> $x['TimeStamp']); // neueste zuerst
    return array_slice($rows, 0, $limit > 0 ? $limit : 10000);
}

class IPSModule
{
    public int $InstanceID = 12345; // Abruf-Versatz 12345 % 10 = 5 min → Folgetag ab 12:50 Uhr
    public array $props = [];
    public array $attrs = [];
    public int $status = 0;
    public array $timers = [];
    public array $debug = [];
    public array $fieldUpdates = [];

    public function Create() {}
    public function ApplyChanges() {}
    public function RegisterPropertyString(string $n, string $d): void { if (!array_key_exists($n, $this->props)) { $this->props[$n] = $d; } }
    public function RegisterPropertyInteger(string $n, int $d): void { if (!array_key_exists($n, $this->props)) { $this->props[$n] = $d; } }
    public function ReadPropertyString(string $n): string { return $this->props[$n]; }
    public function ReadPropertyInteger(string $n): int { return $this->props[$n]; }
    public function RegisterPropertyFloat(string $n, float $d): void { if (!array_key_exists($n, $this->props)) { $this->props[$n] = $d; } }
    public function RegisterPropertyBoolean(string $n, bool $d): void { if (!array_key_exists($n, $this->props)) { $this->props[$n] = $d; } }
    public function ReadPropertyBoolean(string $n): bool { return $this->props[$n]; }
    public function ReadPropertyFloat(string $n): float { return $this->props[$n]; }
    public function RegisterAttributeString(string $n, string $d): void { if (!array_key_exists($n, $this->attrs)) { $this->attrs[$n] = $d; } }
    public function RegisterAttributeInteger(string $n, int $d): void { if (!array_key_exists($n, $this->attrs)) { $this->attrs[$n] = $d; } }
    public function RegisterAttributeBoolean(string $n, bool $d): void { if (!array_key_exists($n, $this->attrs)) { $this->attrs[$n] = $d; } }
    public function ReadAttributeString(string $n): string { return $this->attrs[$n]; }
    public function ReadAttributeInteger(string $n): int { return $this->attrs[$n]; }
    public function ReadAttributeBoolean(string $n): bool { return $this->attrs[$n]; }
    public function WriteAttributeString(string $n, string $v): void { $this->attrs[$n] = $v; }
    public function WriteAttributeInteger(string $n, int $v): void { $this->attrs[$n] = $v; }
    public function WriteAttributeBoolean(string $n, bool $v): void { $this->attrs[$n] = $v; }
    public function SetStatus(int $s): void { $this->status = $s; }
    public function RegisterTimer(string $n, int $ms, string $script): void { $this->timers[$n] = $ms; }
    public function SetTimerInterval(string $n, int $ms): void { $this->timers[$n] = $ms; }
    public function RegisterMessage(int $sender, int $msg): void {}
    public function SendDebug(string $a, string $b, int $c): void { $this->debug[] = "$a: $b"; }
    public function UpdateFormField(string $n, string $p, $v): void { $this->fieldUpdates[] = [$n, $p, $v]; }
    public function MaintainVariable(string $ident, string $name, int $type, string $profile, int $pos, bool $keep): void
    {
        if ($keep && IPS_GetObjectIDByIdent($ident, $this->InstanceID) === false) {
            $def = [VARIABLETYPE_BOOLEAN => false, VARIABLETYPE_INTEGER => 0, VARIABLETYPE_FLOAT => 0.0, VARIABLETYPE_STRING => ''][$type];
            $GLOBALS['VARS'][$GLOBALS['NEXT_ID']++] = ['type' => $type, 'value' => $def, 'name' => $name, 'ident' => $ident, 'parent' => $this->InstanceID, 'profile' => $profile];
        }
    }
    public function SetValue(string $ident, $v): void
    {
        $id = IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        $GLOBALS['VARS'][$id]['value'] = $v;
        $log = $GLOBALS['AC'][$id] ?? [];
        if (($GLOBALS['AC_LOG'][$id] ?? false) && (count($log) === 0 || end($log)['Value'] !== $v)) {
            $GLOBALS['AC'][$id][] = ['TimeStamp' => $GLOBALS['CLOCK'], 'Value' => $v];
        }
    }
    public function GetValue(string $ident) { return $GLOBALS['VARS'][IPS_GetObjectIDByIdent($ident, $this->InstanceID)]['value']; }
}

// SPOT_TEST_MODULE: absichtlich verfälschte Modulkopie prüfen lassen (Gegenprobe: Prüfstand wird rot).
require getenv('SPOT_TEST_MODULE') ?: dirname(__DIR__) . '/Boersenpreis/module.php';

class SpotTest extends Boersenpreis
{
    protected function now(): int { return $GLOBALS['CLOCK']; }
    protected function httpGet(string $url): array
    {
        $GLOBALS['REQUESTS'][] = $url;
        return array_shift($GLOBALS['HTTP']) ?? ['status' => 0, 'headers' => [], 'body' => '', 'error' => 'kein Testfall hinterlegt'];
    }
    /** Private Helfer für Einzelprüfungen aufrufen. */
    public function call(string $method, ...$args) { return (new ReflectionMethod(Boersenpreis::class, $method))->invokeArgs($this, $args); }
}

$fails = 0;
function check(string $what, bool $ok, string $detail = ''): void
{
    global $fails;
    echo ($ok ? '  ✅ ' : '  ❌ ') . $what . ($ok || $detail === '' ? '' : ' — ' . $detail) . "\n";
    if (!$ok) { $fails++; }
}
function heading(string $t): void { echo "\n[$t]\n"; }
function ts(string $s): int { return strtotime($s); }
function clock(string $s): void { $GLOBALS['CLOCK'] = ts($s); }
function fx(string $name): string { return file_get_contents(__DIR__ . '/fixtures/' . $name); }
function ok(string $body, array $headers = []): array { return ['status' => 200, 'headers' => $headers, 'body' => $body, 'error' => '']; }
function resp(int $status, string $body = '', array $headers = [], string $error = ''): array { return ['status' => $status, 'headers' => $headers, 'body' => $body, 'error' => $error]; }
/** Energy-Charts-Antwort auf die ersten $n Werte kürzen (z. B. „nur heute" aus der Zwei-Tages-Datei). */
function ecFirst(string $file, int $n): string { $d = json_decode(fx($file), true); $d['unix_seconds'] = array_slice($d['unix_seconds'], 0, $n); $d['price'] = array_slice($d['price'], 0, $n); return json_encode($d); }
function requestsSince(int $from): array { return array_slice($GLOBALS['REQUESTS'], $from); }
function v(SpotTest $m, string $ident) { return $m->GetValue($ident); }
function form(SpotTest $m): array { $f = json_decode($m->GetConfigurationForm(), true); if (!is_array($f)) { throw new RuntimeException('GetConfigurationForm() liefert kein gültiges JSON'); } return $f; }
/** Formular als Text; Zeilenumbrüche aus dem Label-Umbruch werden wieder zu Leerzeichen (Inhaltsprüfungen). */
function formText(array $f): string { return str_replace('\n', ' ', json_encode($f, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); }
function fresh(int $source = 0, string $zone = 'DE-LU'): SpotTest
{
    $GLOBALS['VARS'] = [];
    $m = new SpotTest();
    $m->Create();
    $m->props['Source'] = $source;
    $m->props['BiddingZone'] = $zone;
    return $m;
}
/** Nachbildung von EMS parsePT15M() (EMS/module.php): Preis → Viertelstunde seines start, nur Kalendertag $ymd. */
function emsSlots(array $curve, string $ymd): array
{
    $p = array_fill(0, 96, null);
    foreach ($curve as $e) {
        $t = (int)$e['start'];
        if (date('Y-m-d', $t) !== $ymd) { continue; }
        $slot = (int)floor(((int)date('H', $t) * 60 + (int)date('i', $t)) / 15);
        if ($slot >= 0 && $slot < 96) { $p[$slot] = (float)$e['price'] / 100.0; }
    }
    return $p;
}
/**
 * ENTSO-E-A44-Antwort (Publication_MarketDocument) aus einer Preisreihe in EUR/MWh — Kurventyp
 * A03: gleiche Folgewerte werden weggelassen, auch am Periodenende. $decoy = zusätzliche
 * TimeSeries mit Position 2 (steht absichtlich zuerst im Dokument und darf nicht gewinnen).
 */
function entsoeXml(int $start, array $prices, int $res, array $decoy = []): string
{
    $series = function (int $pos, array $p) use ($start, $res) {
        $pts = ''; $prev = null;
        foreach (array_values($p) as $i => $v) {
            if ($v === $prev) { continue; }
            $pts .= '<Point><position>' . ($i + 1) . '</position><price.amount>' . $v . '</price.amount></Point>';
            $prev = $v;
        }
        return '<TimeSeries><mRID>' . $pos . '</mRID><auction.type>A01</auction.type><businessType>A62</businessType>'
            . '<currency_Unit.name>EUR</currency_Unit.name><price_Measure_Unit.name>MWH</price_Measure_Unit.name>'
            . '<classificationSequence_AttributeInstanceComponent.position>' . $pos . '</classificationSequence_AttributeInstanceComponent.position>'
            . '<curveType>A03</curveType><Period><timeInterval><start>' . gmdate('Y-m-d\TH:i\Z', $start) . '</start><end>'
            . gmdate('Y-m-d\TH:i\Z', $start + count($p) * $res) . '</end></timeInterval><resolution>' . ($res === 900 ? 'PT15M' : 'PT60M')
            . '</resolution>' . $pts . '</Period></TimeSeries>';
    };
    return '<?xml version="1.0" encoding="utf-8"?><Publication_MarketDocument xmlns="urn:iec62325.351:tc57wg16:451-3:publicationdocument:7:3">'
        . '<type>A44</type>' . ($decoy ? $series(2, $decoy) : '') . $series(1, $prices) . '</Publication_MarketDocument>';
}
function ackXml(): string
{
    return '<?xml version="1.0" encoding="utf-8"?><Acknowledgement_MarketDocument xmlns="urn:iec62325.351:tc57wg16:451-1:acknowledgementdocument:7:0">'
        . '<mRID>x</mRID><Reason><code>999</code><text>No matching data found for Data item Day-ahead Prices [12.1.D] (10Y1001A1001A82H, 10Y1001A1001A82H) and interval 2026-09-12T22:00:00.000Z/2026-09-13T22:00:00.000Z.</text></Reason></Acknowledgement_MarketDocument>';
}
function contiguous(array $curve): bool { for ($i = 1; $i < count($curve); $i++) { if ($curve[$i]['start'] !== $curve[$i - 1]['end']) { return false; } } return true; }

// ---------------------------------------------------------------------------
heading('1. Anlage am 12.09.2026 10:00 — Erstabruf (nur heute veröffentlicht)');
clock('2026-09-12 10:00:00');
$m = fresh();
$GLOBALS['HTTP'][] = ok(ecFirst('ec-DE-LU-2026-09-12_13.json', 96));
$m->ApplyChanges();
check('Genau ein Abruf', count($GLOBALS['REQUESTS']) === 1);
check('URL: Energy-Charts, DE-LU, heute bis morgen', ($GLOBALS['REQUESTS'][0] ?? '') === 'https://api.energy-charts.info/price?bzn=DE-LU&start=2026-09-12&end=2026-09-13', $GLOBALS['REQUESTS'][0] ?? '-');
check('Profile SPOT.CentKWh (2 Stellen, ct/kWh) und SPOT.YesNo angelegt', ($GLOBALS['PROFILES']['SPOT.CentKWh']['suffix'] ?? '') === ' ct/kWh' && ($GLOBALS['PROFILES']['SPOT.YesNo']['assoc']['1'] ?? '') === 'Ja');
check('Status 102', $m->status === 102, 'ist ' . $m->status);
$ec = json_decode(fx('ec-DE-LU-2026-09-12_13.json'), true);
$idx10 = array_search(ts('2026-09-12 10:00:00'), $ec['unix_seconds'], true);
check('„Börsenpreis jetzt" = Wert 10:00 Uhr in ct/kWh (EUR/MWh ÷ 10)', abs(v($m, 'CurrentPrice') - $ec['price'][$idx10] / 10) < 1e-9, v($m, 'CurrentPrice') . ' vs ' . $ec['price'][$idx10] / 10);
check('Morgen noch nicht veröffentlicht', v($m, 'TomorrowAvailable') === false);
check('Nächster Abruf 12:50 Uhr (12:45 + Instanz-Versatz 5 min)', $m->timers['Fetch'] === (ts('2026-09-12 12:50:00') - ts('2026-09-12 10:00:00')) * 1000, 'ms=' . $m->timers['Fetch']);
check('Viertelstunden-Takt auf 10:15:02', $m->timers['Tick'] === (ts('2026-09-12 10:15:02') - ts('2026-09-12 10:00:00')) * 1000);
check('Kein Fehler-Log', count($GLOBALS['LOG']) === 0, implode(' | ', $GLOBALS['LOG']));

heading('2. Vertrag SPOT_GetPriceCurve');
$c = $m->GetPriceCurve();
check('96 Viertelstunden, kein weiterer Abruf (liest nur den Speicher)', count($c) === 96 && count($GLOBALS['REQUESTS']) === 1, count($c) . ' / ' . count($GLOBALS['REQUESTS']));
$e = $c[0];
check('Felder exakt: start/end/price/basis/netzentgelt/level/quelle/aufloesung/contractVersion', array_keys($e) === ['start', 'end', 'price', 'basis', 'netzentgelt', 'level', 'quelle', 'aufloesung', 'contractVersion'], implode(',', array_keys($e)));
check('Typen: int/int/float/string/string/null/string/int/string', is_int($e['start']) && is_int($e['end']) && is_float($e['price']) && $e['level'] === null && is_int($e['aufloesung']) && $e['contractVersion'] === '1.1');
check('basis=spot, netzentgelt=fehlt, quelle=energy-charts, aufloesung=900', $e['basis'] === 'spot' && $e['netzentgelt'] === 'fehlt' && $e['quelle'] === 'energy-charts' && $e['aufloesung'] === 900);
check('Beginnt 00:00, lückenlos, end EXKLUSIV = start des nächsten', $c[0]['start'] === ts('2026-09-12 00:00:00') && contiguous($c) && end($c)['end'] === ts('2026-09-13 00:00:00'));
check('Preis exakt EUR/MWh ÷ 10', abs($c[40]['price'] - $ec['price'][40] / 10) < 1e-9);
check('EMS-Nachbildung parsePT15M: alle 96 Viertelstunden von heute gefüllt', count(array_filter(emsSlots($c, '2026-09-12'), fn($x) => $x !== null)) === 96);

heading('3. Sparsamer Abrufplan — Folgetag');
clock('2026-09-12 12:00:00');
$n = count($GLOBALS['REQUESTS']);
$m->ApplyChanges();
check('12:00 Übernehmen: kein Abruf nötig (heute da, vor Veröffentlichung)', count(requestsSince($n)) === 0);
check('SPOT_Update liefert trotzdem Rückmeldung', true);
clock('2026-09-12 12:50:00');
$GLOBALS['HTTP'][] = ok(ecFirst('ec-DE-LU-2026-09-12_13.json', 96));
$r = $m->Update();
check('12:50 Folgetag fehlt noch: Text sagt „noch nicht veröffentlicht" mit nächstem Versuch 13:05', str_contains($r, 'noch nicht veröffentlicht') && str_contains($r, '13:05'), $r);
check('… Timer in 15 min', $m->timers['Fetch'] === 900 * 1000);
clock('2026-09-12 13:05:00');
$GLOBALS['HTTP'][] = ok(fx('ec-DE-LU-2026-09-12_13.json'));
$r = $m->Update();
check('13:05 Folgetag da: 192 Viertelstunden, Text nennt heute + morgen und 11 negative', str_contains($r, '192 Viertelstunden') && str_contains($r, 'heute + morgen') && str_contains($r, '11 negativ'), $r);
check('Morgen veröffentlicht = Ja', v($m, 'TomorrowAvailable') === true);
check('Nächster Abruf erst morgen 12:50 Uhr', $m->timers['Fetch'] === (ts('2026-09-13 12:50:00') - ts('2026-09-12 13:05:00')) * 1000, 'ms=' . $m->timers['Fetch']);
$n = count($GLOBALS['REQUESTS']);
$m->ApplyChanges();
check('Übernehmen bei vollständigem Speicher: kein Abruf', count(requestsSince($n)) === 0);
check('Status 102 und Formular-Felder nachgezogen (FetchStatus + PriceSummary)', $m->status === 102 && count(array_filter($m->fieldUpdates, fn($u) => $u[0] === 'FetchStatus')) > 0 && count(array_filter($m->fieldUpdates, fn($u) => $u[0] === 'PriceSummary')) > 0);
$c = $m->GetPriceCurve();
check('Vertrag jetzt 192 Einträge, lückenlos über beide Tage', count($c) === 192 && contiguous($c));
$negAhead = array_values(array_filter($c, fn($s) => $s['end'] > $GLOBALS['CLOCK'] && $s['price'] < 0));
check('„Nächste negative Viertelstunde" = erste noch nicht vorbei (hier die laufende 13:00)', count($negAhead) > 0 && v($m, 'NextNegativeStart') === $negAhead[0]['start'] && $negAhead[0]['start'] === ts('2026-09-12 13:00:00'), date('d.m.Y H:i', v($m, 'NextNegativeStart')));

heading('4. Tageswechsel 13.09. 00:10');
clock('2026-09-13 00:10:00');
$m->Tick();
$c = $m->GetPriceCurve();
check('Gestern fällt aus dem Vertrag: 96 Einträge ab 13.09. 00:00', count($c) === 96 && $c[0]['start'] === ts('2026-09-13 00:00:00'));
check('„Börsenpreis jetzt" = Wert 13.09. 00:00', abs(v($m, 'CurrentPrice') - $ec['price'][96] / 10) < 1e-9);
check('Morgen (14.09.) noch nicht veröffentlicht', v($m, 'TomorrowAvailable') === false);
check('Takt auf 00:15:02', $m->timers['Tick'] === (ts('2026-09-13 00:15:02') - ts('2026-09-13 00:10:00')) * 1000);

heading('5. Negative Preise (01.05.2026, 32 Viertelstunden bis −499,99 EUR/MWh)');
clock('2026-05-01 08:00:00');
$m = fresh();
$GLOBALS['HTTP'][] = ok(fx('ec-DE-LU-2026-05-01.json'));
$m->ApplyChanges();
$c = $m->GetPriceCurve();
$neg = array_values(array_filter($c, fn($s) => $s['price'] < 0));
check('32 negative Viertelstunden im Vertrag, Minimum −49,999 ct/kWh (nicht abgeschnitten)', count($neg) === 32 && abs(min(array_column($c, 'price')) + 49.999) < 1e-9, count($neg) . ' / ' . min(array_column($c, 'price')));
$firstNeg = $neg[0]['start'];
check('Vor den Negativen: jetzt nicht negativ, nächste = erste negative', v($m, 'NegativeNow') === false && v($m, 'NextNegativeStart') === $firstNeg, date('H:i', v($m, 'NextNegativeStart')));
$GLOBALS['CLOCK'] = $firstNeg + 60;
$m->Tick();
check('In der ersten negativen Viertelstunde: „Negativer Börsenpreis jetzt" = Ja, nächste = laufende', v($m, 'NegativeNow') === true && v($m, 'NextNegativeStart') === $firstNeg);
$GLOBALS['CLOCK'] = end($neg)['end'] + 60;
$m->Tick();
check('Nach der letzten: nein, keine weitere bekannt (0)', v($m, 'NegativeNow') === false && v($m, 'NextNegativeStart') === 0);
$sum = $m->call('priceSummary');
check('Übersicht nennt „32 negative Viertelstunde(n)"', str_contains($sum, '32 negative Viertelstunde'), $sum);

heading('6. Sommerzeit — 23- und 25-Stunden-Tag');
clock('2026-03-29 09:00:00');
$m = fresh();
$GLOBALS['HTTP'][] = ok(fx('ec-DE-LU-2026-03-29.json'));
$m->ApplyChanges();
$c = $m->GetPriceCurve();
check('29.03.2026: 92 Viertelstunden, lückenlos, 00:00 bis 24:00', count($c) === 92 && contiguous($c) && $c[0]['start'] === ts('2026-03-29 00:00:00') && end($c)['end'] === ts('2026-03-30 00:00:00'));
check('Keine Viertelstunde fällt in die übersprungene Stunde 02:00–03:00', count(array_filter($c, fn($s) => date('H', $s['start']) === '02')) === 0);
check('Folgetag-Abruf 29.03. 12:50 (mktime, nicht +86400)', $m->timers['Fetch'] === (ts('2026-03-29 12:50:00') - ts('2026-03-29 09:00:00')) * 1000);
clock('2025-10-26 09:00:00');
$m = fresh();
$GLOBALS['HTTP'][] = ok(fx('ec-DE-LU-2025-10-26.json'));
$m->ApplyChanges();
$c = $m->GetPriceCurve();
check('26.10.2025: 100 Viertelstunden, lückenlos, 00:00 bis 24:00', count($c) === 100 && contiguous($c) && $c[0]['start'] === ts('2025-10-26 00:00:00') && end($c)['end'] === ts('2025-10-27 00:00:00'));
check('Doppelte Stunde 02:00–03:00 kommt 8× vor (zweimal vier Viertelstunden)', count(array_filter($c, fn($s) => date('H', $s['start']) === '02')) === 8);

heading('7. aWATTar — Stundenwerte auf vier gleiche Viertelstunden verteilt');
clock('2026-05-01 09:00:00');
$m = fresh(1);
$GLOBALS['HTTP'][] = ok(fx('aw-DE-2026-05-01.json'));
$m->ApplyChanges();
$u = end($GLOBALS['REQUESTS']);
check('URL: api.awattar.de, start/end in ms (heute 00:00 bis übermorgen 00:00)', $u === 'https://api.awattar.de/v1/marketdata?start=' . ts('2026-05-01 00:00:00') * 1000 . '&end=' . ts('2026-05-03 00:00:00') * 1000, $u);
$c = $m->GetPriceCurve();
check('96 Viertelstunden, quelle=awattar, aufloesung=3600, lückenlos', count($c) === 96 && $c[0]['quelle'] === 'awattar' && $c[0]['aufloesung'] === 3600 && contiguous($c));
check('Je Stunde vier gleiche Preise', $c[0]['price'] === $c[3]['price'] && $c[4]['price'] === $c[7]['price'] && $c[0]['end'] - $c[0]['start'] === 900);
check('EMS-Nachbildung: alle 96 Viertelstunden gefüllt (Stundenslot ließe sonst 72 leer)', count(array_filter(emsSlots($c, '2026-05-01'), fn($x) => $x !== null)) === 96);
check('Übersicht weist auf Stundenwerte hin', str_contains($m->call('priceSummary'), 'Stundenwerte'));
clock('2026-03-29 09:00:00');
$m = fresh(1);
$GLOBALS['HTTP'][] = ok(fx('aw-DE-2026-03-29.json'));
$m->ApplyChanges();
$c = $m->GetPriceCurve();
check('aWATTar 29.03.2026: 23 Stunden → 92 Viertelstunden, lückenlos', count($c) === 92 && contiguous($c));
clock('2026-09-12 10:00:00');
$m = fresh(1, 'AT');
$GLOBALS['HTTP'][] = ok('{"object":"list","data":[]}');
$m->ApplyChanges();
check('Gebotszone AT → api.awattar.at', str_starts_with(end($GLOBALS['REQUESTS']), 'https://api.awattar.at/v1/marketdata?'));
$m = fresh(0, 'AT');
$GLOBALS['HTTP'][] = ok(fx('ec-AT-2026-09-12.json'));
$m->ApplyChanges();
check('Energy-Charts AT → bzn=AT, 96 Viertelstunden', str_contains(end($GLOBALS['REQUESTS']), 'bzn=AT&') && count($m->GetPriceCurve()) === 96);
$m->props['BiddingZone'] = 'XX';
$GLOBALS['HTTP'][] = ok(fx('ec-DE-LU-2026-09-12_13.json'));
$m->ApplyChanges();
check('Unbekannte Zone fällt auf DE-LU zurück (und verwirft die AT-Preise)', str_contains(end($GLOBALS['REQUESTS']), 'bzn=DE-LU&'));

heading('8. Fehlerfälle — vorhandene Preise bleiben, nie 0 statt „fehlt"');
clock('2026-09-12 12:50:00');
$m = fresh();
$GLOBALS['HTTP'][] = ok(ecFirst('ec-DE-LU-2026-09-12_13.json', 96));
$m->ApplyChanges();
$GLOBALS['LOG'] = [];
clock('2026-09-12 13:05:00');
$GLOBALS['HTTP'][] = resp(404, 'no content available');
$r = $m->Update();
check('404: ⚠️-Text, Speicher bleibt (96), Status bleibt 102 (kein Fehlstatus für Watchdogs, SUITE.md 9d), Kopfzeile ⚠️', str_starts_with($r, '⚠️') && str_contains($r, 'bleiben gültig') && count($m->GetPriceCurve()) === 96 && $m->status === 102 && str_starts_with($m->call('fetchStatusLine'), '⚠️'), $r . ' / ' . $m->status);
check('404 einmal dauerhaft geloggt', count($GLOBALS['LOG']) === 1 && str_contains($GLOBALS['LOG'][0], 'HTTP 404'), implode(' | ', $GLOBALS['LOG']));
clock('2026-09-12 13:20:00');
$GLOBALS['HTTP'][] = resp(404, 'no content available');
$m->Update();
check('Gleicher Fehler erneut: kein zweiter Log-Eintrag', count($GLOBALS['LOG']) === 1);
clock('2026-09-12 13:35:00');
$GLOBALS['HTTP'][] = resp(429, 'Too Many Requests', ['retry-after' => '120']);
$r = $m->Update();
check('429 mit Retry-After 120: Pause bis 13:37:00, Timer auf Pausenende', $m->attrs['BlockedUntil'] === ts('2026-09-12 13:37:00') && $m->timers['Fetch'] === 125 * 1000 && str_contains($r, 'Ratenlimit'), $r);
$n = count($GLOBALS['REQUESTS']);
clock('2026-09-12 13:36:00');
$r = $m->Update();
check('Während der Pause: Knopf fragt NICHT, sagt ⏳ bis wann', count(requestsSince($n)) === 0 && str_starts_with($r, '⏳') && str_contains($r, '13:37:00'), $r);
check('Getter fragt während der Pause auch nicht (Speicher ist ohnehin gefüllt)', count($m->GetPriceCurve()) === 96 && count(requestsSince($n)) === 0);
check('Formular-Kopfzeile nennt die Pause', str_contains($m->call('fetchStatusLine'), 'Ratenlimit'));
clock('2026-09-12 13:37:10');
$GLOBALS['HTTP'][] = ok(fx('ec-DE-LU-2026-09-12_13.json'));
$r = $m->Update();
check('Nach der Pause: Abruf klappt, Fehler gelöscht, Status 102', str_starts_with($r, '✅') && $m->attrs['LastError'] === '' && $m->status === 102, $r);
$GLOBALS['HTTP'][] = resp(0, '', [], 'Verbindung abgelehnt');
$r = $m->Update();
check('Netz weg: „nicht erreichbar (Verbindung abgelehnt)", Speicher bleibt 192', str_contains($r, 'nicht erreichbar (Verbindung abgelehnt)') && count($m->GetPriceCurve()) === 192, $r);
$GLOBALS['HTTP'][] = ok('<html>Wartung</html>');
check('Kaputtes JSON: ⚠️ „keine lesbaren Daten"', str_contains($m->Update(), 'keine lesbaren Daten'));
$GLOBALS['HTTP'][] = ok('{"unix_seconds":[1],"price":[1],"unit":"EUR / kWh"}');
check('Falsche Einheit: ⚠️ „unerwartete Einheit"', str_contains($m->Update(), 'unerwartete Einheit'));
$GLOBALS['HTTP'][] = ok('{"unix_seconds":[1,2],"price":[1]}');
check('Ungleich lange Listen: ⚠️ „unerwartetes Antwortformat"', str_contains($m->Update(), 'unerwartetes Antwortformat'));
check('Nach all dem: Speicher unverändert 192', count($m->GetPriceCurve()) === 192);
$d = json_decode(fx('ec-DE-LU-2026-09-12_13.json'), true);
$d['price'][40] = null;
$GLOBALS['HTTP'][] = ok(json_encode($d));
$m->Update();
$c = $m->GetPriceCurve();
$hole = ts('2026-09-12 10:00:00');
check('null-Preis der Quelle = Lücke (191 Einträge), NICHT 0', count($c) === 191 && count(array_filter($c, fn($s) => $s['start'] === $hole)) === 0, (string)count($c));
check('EMS-Nachbildung sieht dort null (keine Daten), kein 0-ct-Sonderangebot', emsSlots($c, '2026-09-12')[40] === null);
$GLOBALS['CLOCK'] = $hole + 60;
$m->Tick();
check('Ohne Preis für jetzt: Status 201, „negativ jetzt" = Nein', $m->status === 201 && v($m, 'NegativeNow') === false);

heading('9. Frische Instanz — Getter lädt einmal gedrosselt nach');
clock('2026-09-12 10:00:00');
$m = fresh();
$GLOBALS['HTTP'][] = resp(0, '', [], 'DNS');
$m->ApplyChanges();
check('Erstabruf gescheitert: Status 201, Fetch-Timer in 15 min', $m->status === 201 && $m->timers['Fetch'] === 900 * 1000);
$n = count($GLOBALS['REQUESTS']);
clock('2026-09-12 10:01:00');
check('Getter 60 s danach: leer, KEIN weiterer Abruf (Drossel 120 s)', $m->GetPriceCurve() === [] && count(requestsSince($n)) === 0);
clock('2026-09-12 10:02:30');
$GLOBALS['HTTP'][] = ok(ecFirst('ec-DE-LU-2026-09-12_13.json', 96));
$c = $m->GetPriceCurve();
check('Getter nach Ablauf der Drossel: lädt einmal nach und liefert 96', count($c) === 96 && count(requestsSince($n)) === 1);

heading('10. Quellenwechsel verwirft alte Preise');
$GLOBALS['HTTP'][] = ok(fx('aw-DE-2026-05-01.json')); // falscher Tag → nichts für heute/morgen
$m->props['Source'] = 1;
$m->ApplyChanges();
check('Energy-Charts-Preise nach Wechsel auf aWATTar verworfen (keine Mischung)', $m->GetPriceCurve() === [] || ($m->GetPriceCurve()[0]['quelle'] ?? '') === 'awattar');
check('aWATTar-Antwort ohne heute/morgen: ⚠️ statt Altlast', str_contains($m->attrs['LastError'], 'keine Preise für heute oder morgen'), $m->attrs['LastError']);

heading('11. Hilfsfunktionen');
[$st, $h] = $m->call('parseResponseHeaders', ['HTTP/1.1 301 Moved Permanently', 'Location: https://x', 'HTTP/2 429 Too Many Requests', 'Retry-After: 30', 'Content-Type: text/plain']);
check('Kopfzeilen nach Weiterleitung: letzte Antwort zählt (429, retry-after 30)', $st === 429 && ($h['retry-after'] ?? '') === '30' && !isset($h['location']));
$now = ts('2026-09-12 10:00:00');
check('Retry-After als Sekunden', $m->call('retryAfterSeconds', '90', $now) === 90);
check('Retry-After als HTTP-Datum', $m->call('retryAfterSeconds', gmdate('D, d M Y H:i:s', $now + 300) . ' GMT', $now) === 300);
check('Retry-After fehlt/unsinnig: 60 s, höchstens 1 h', $m->call('retryAfterSeconds', '', $now) === 60 && $m->call('retryAfterSeconds', '99999', $now) === 3600 && $m->call('retryAfterSeconds', '1', $now) === 60);
check('Auflösung wird gemessen, nicht angenommen (Stundenwerte → 3600)', $m->call('resolutionOf', [0, 3600, 7200]) === 3600 && $m->call('resolutionOf', [5]) === 900);

heading('12. Formular — Konvention und Verdrahtung');
clock('2026-09-12 13:10:00');
$m = fresh();
$GLOBALS['HTTP'][] = ok(fx('ec-DE-LU-2026-09-12_13.json'));
$m->ApplyChanges();
$f = form($m);
$caps = array_map(fn($e) => $e['caption'] ?? '', $f['elements']);
check('Reihenfolge: Zweck → Neu → Doku → Datenquelle → Börsenpreise → Rückmeldungen → Über', ($f['elements'][0]['name'] ?? '') === 'PurposeIntroPanel' && ($f['elements'][1]['name'] ?? '') === 'NewsPanel'
    && str_contains($caps[2], 'Dokumentation') && str_contains($caps[3], 'Datenquelle') && str_contains($caps[4], 'Börsenpreise') && str_contains($caps[5], 'Energie Manager') && ($f['elements'][6]['name'] ?? '') === 'ForumHintPanel' && str_contains($caps[7], 'Über dieses Modul'), implode(' | ', $caps));
check('Status-Codes 102/201 beschriftet (kein Warnstatus > 200 für harmlose Abruffehler)', array_column($f['status'], 'code') === [102, 201]);
$txt = formText($f);
check('Kopfzeile ✅ mit Zeitpunkt TT.MM.JJJJ, Übersicht mit Heute/Morgen', str_contains($txt, '✅ Zuletzt abgerufen 12.09.2026 13:10:00 Uhr') && str_contains($txt, 'Heute: 96 Viertelstunden') && str_contains($txt, 'Morgen: 96 Viertelstunden'));
check('Quellennennung CC BY 4.0 aus der Antwort übernommen', str_contains($txt, 'CC BY 4.0') && str_contains($txt, 'SMARD'));
check('Quellen-Auswahl als Select mit beiden Quellen, Zonen DE-LU/AT', str_contains($txt, '"name":"Source"') && str_contains($txt, 'aWATTar') && str_contains($txt, '"value":"AT"'));
check('Kein Link-Button trägt die URL direkt in "link"', !preg_match('/"link":"http/', $txt));
check('Forum-Hinweis im MeterHub-Muster: „💬  Feedback im Symcon-Forum“, Knopf auf den echten Thread 144413, kein Platzhalter mehr', str_contains($txt, '💬  Feedback im Symcon-Forum') && str_contains($txt, 'Zum Forums-Thread') && str_contains($txt, 'community.symcon.de/t/beta-modul-nrg-stack-boersenpreis') && str_contains($txt, '/144413') && !str_contains($txt, 'PLATZHALTER') && !str_contains($txt, 'Platzhalter-Link') && !str_contains($txt, '?u='));
$popups = [];
array_walk_recursive($f, function () {});
foreach ($f['elements'] as $el) { foreach ($el['items'] ?? [] as $it) { if (($it['type'] ?? '') === 'PopupButton') { $popups[] = $it; } } }
check('Hilfe-Knöpfe: volle Frage mit genau einem „?", Fenstertitel = Frage, Breite gesetzt', count($popups) === 5 && count(array_filter($popups, fn($p) => str_ends_with($p['caption'], '?') && !str_contains($p['caption'], '??') && !str_contains($p['caption'], '? ?') && ($p['popup']['caption'] ?? '') === $p['caption'] && ($p['width'] ?? '') !== '')) === 5);
// Live-Fund 13.09.2026: 63 Zeichen liefen bei 460 px über den Knopfrand (Großbuchstaben-Skin).
check('Hilfe-Fragen passen auf den Knopf (≤ 50 Zeichen, einheitlich 500 px)', count(array_filter($popups, fn($p) => mb_strlen($p['caption']) <= 50 && $p['width'] === '500px')) === 5, implode(' | ', array_map(fn($p) => mb_strlen($p['caption']) . ' ' . $p['caption'], $popups)));
// Live-Fund 14.09.2026: Symcon zeigt die Beschriftung von Eingabefeldern IM Feld — zu lang oder zu schmal = abgeschnitten.
$inputs = [];
$walk = function (array $items) use (&$walk, &$inputs) {
    foreach ($items as $it) {
        if (in_array($it['type'] ?? '', ['Select', 'NumberSpinner', 'ValidationTextBox', 'PasswordTextBox'], true)) { $inputs[] = $it; }
        if (isset($it['items']) && ($it['type'] ?? '') !== 'PopupButton') { $walk($it['items']); }
    }
};
$m3f = fresh(3); // alle Felder sichtbar machen ist egal — die Prüfung gilt für jedes Feld im Formular
$walk(form($m3f)['elements']);
$bad = array_filter($inputs, fn($i) => mb_strlen($i['caption'] ?? '') > 26 || !isset($i['width']));
check('Eingabefelder: Beschriftung ≤ 26 Zeichen und Breite gesetzt (' . count($inputs) . ' Felder)', count($inputs) === 10 && count($bad) === 0, implode(' | ', array_map(fn($i) => ($i['name'] ?? '?') . ': ' . ($i['caption'] ?? ''), $bad)));
$opts = [];
foreach ($inputs as $i) { foreach ($i['options'] ?? [] as $o) { $opts[] = $o['caption']; } }
$labels = [];
$walkL = function (array $items) use (&$walkL, &$labels) {
    foreach ($items as $it) {
        if (($it['type'] ?? '') === 'Label') { $labels[] = (string)($it['caption'] ?? ''); }
        if (isset($it['items'])) { $walkL($it['items']); }
        if (isset($it['popup']['items'])) { $walkL($it['popup']['items']); }
    }
};
$walkL(form($m3f)['elements']);
$walkL(form($m)['elements']);
// Live-Funde 14.09.2026: Labels sind einfache Labels über die volle Breite (Symcon bricht selbst
// um, wie in allen Verbund-Modulen) — kein harter Umbruch. Überbreite auf schmalen Bildschirmen
// kam von nebeneinander stehenden Eingabefeldern → keine RowLayout mit Eingabefeldern.
$rows = [];
$walkR = function (array $items) use (&$walkR, &$rows) { foreach ($items as $it) { if (($it['type'] ?? '') === 'RowLayout') { $rows[] = $it; } if (isset($it['items'])) { $walkR($it['items']); } } };
$walkR(form($m3f)['elements']);
$rowInputs = array_filter($rows, fn($r) => count(array_filter($r['items'], fn($i) => in_array($i['type'] ?? '', ['Select', 'NumberSpinner', 'ValidationTextBox', 'PasswordTextBox'], true))) > 0);
check('Keine Zeile mit nebeneinander stehenden Eingabefeldern (sonst Überbreite auf schmalen Bildschirmen)', count($rowInputs) === 0);
check('Labels ohne harten Zeilenumbruch mitten im Satz (' . count($labels) . ' Labels)', count($labels) > 30 && count(array_filter($labels, fn($l) => preg_match('/[a-zäöüß,]\n[a-zäöüß]/u', $l))) === 0);
check('Feldbreite höchstens 400 px', count(array_filter($inputs, fn($i) => (int)($i['width'] ?? 0) > 400)) === 0);
$cbs = [];
$walkC = function (array $items) use (&$walkC, &$cbs) { foreach ($items as $it) { if (($it['type'] ?? '') === 'CheckBox') { $cbs[] = $it['caption']; } if (isset($it['items'])) { $walkC($it['items']); } } };
$walkC(form($m3f)['elements']);
check('Schalter-Beschriftungen ≤ 45 Zeichen', count(array_filter($cbs, fn($c) => mb_strlen($c) > 45)) === 0, implode(' | ', array_filter($cbs, fn($c) => mb_strlen($c) > 45)));
check('Kein Eingabefeld mit negativem Minimum (Konsole zeigt sonst das Minimum statt 0)', count(array_filter($inputs, fn($i) => ($i['minimum'] ?? 0) < 0)) === 0);
check('Auswahl-Einträge ≤ 50 Zeichen', count(array_filter($opts, fn($c) => mb_strlen($c) > 50)) === 0, implode(' | ', array_filter($opts, fn($c) => mb_strlen($c) > 50)));
check('Knopf „Preise jetzt abrufen" gibt Rückmeldung per echo', str_contains($txt, 'echo SPOT_Update($id);'));
$m->AckPurposeIntro(); $m->AckNews(); $m->AckForumHint();
$f2 = form($m);
$visible = fn(array $els) => array_values(array_filter($els, fn($e) => ($e['visible'] ?? true) !== false));
check('Nach Bestätigen: Zweck/Neu/Rückmeldungen nicht mehr sichtbar, Lizenz bleibt unten', count($visible($f2['elements'])) === count($visible($f['elements'])) - 3 && str_contains(end($f2['elements'])['caption'], 'Über dieses Modul'));
check('Zweck-Panel bleibt (unsichtbar) im Formular, Doku-Panel hat Knopf „wieder anzeigen“', ($f2['elements'][0]['name'] ?? '') === 'PurposeIntroPanel' && $f2['elements'][0]['visible'] === false && str_contains(formText($f2), 'echo SPOT_ShowPurposeIntro($id);'));
$r = $m->ShowPurposeIntro();
check('„Wieder anzeigen“: sofort sichtbar (ohne Neuladen), mit Rückmeldung', str_starts_with($r, '👋') && in_array(['PurposeIntroPanel', 'visible', true], $m->fieldUpdates, true) && form($m)['elements'][0]['visible'] === true);
$m2 = fresh(1);
check('aWATTar gewählt: Hinweis auf Stundenwerte und faire Nutzung', str_contains(formText(form($m2)), 'nur Stundenwerte') && str_contains(formText(form($m2)), 'fairer Nutzung'));

heading('13. Vertrags- und Code-Hygiene (SUITE.md Stolperstein 8/9/13/18/20)');
$rc = new ReflectionClass(Boersenpreis::class);
foreach (['GetPriceCurve', 'GetPriceHistory', 'Update', 'Tick', 'SetEntsoeToken', 'UIChangeSource', 'AckPurposeIntro', 'ShowPurposeIntro', 'AckNews', 'AckForumHint', 'GetDismissState', 'AdoptDismissState'] as $name) {
    $rm = $rc->getMethod($name);
    $okTypes = true; $okDefaults = true;
    foreach ($rm->getParameters() as $p) {
        $t = $p->getType();
        if ($t === null || !in_array($t->getName(), ['bool', 'int', 'float', 'string'], true)) { $okTypes = false; }
        if ($p->isOptional()) { $okDefaults = false; }
    }
    check("$name(): öffentlich, Parameter skalar typisiert, keine PHP-Standardwerte", $rm->isPublic() && $okTypes && $okDefaults);
}
preg_match_all('/SPOT_([A-Za-z]+)\(/', $txt . file_get_contents(dirname(__DIR__) . '/Boersenpreis/module.php'), $mm);
foreach (array_unique($mm[1]) as $fn) {
    check("SPOT_$fn → public function $fn() existiert", $rc->hasMethod($fn) && $rc->getMethod($fn)->isPublic());
}
$code = file_get_contents(dirname(__DIR__) . '/Boersenpreis/module.php');
$codeOnly = '';
foreach (token_get_all($code) as $t) { $codeOnly .= (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) ? '' : (is_array($t) ? $t[1] : $t); }
check('Keine feste Tageslänge (86400) im Code (Kommentare ausgenommen)', !str_contains($codeOnly, '86400'));
check('Kein ColorValue/ColorActive (Mobile-App-Absturz)', !preg_match('/ColorValue|ColorActive/', $code));
check('Kein @ vor IPS_Set*/SetValue (stille Fehlschläge)', !preg_match('/@(IPS_Set|SetValue)/', $code));
// Y-m-d nur als Maschinenformat: 2× Energy-Charts-URL, 1× Datumsvergleich in parseTibber() — nie in Texten.
check('Sichtbare Datumsangaben immer mit Jahr (TT.MM.JJJJ, Store-Checkliste 9b)', !preg_match("/date\\('d\\.m\\.[ ']/", $codeOnly) && substr_count($codeOnly, "date('Y-m-d'") === 3);
check('library.json: nur die 8 erlaubten Felder, Name „NRG-Stack Börsenpreis"', ($lj = json_decode(file_get_contents(dirname(__DIR__) . '/library.json'), true)) && array_keys($lj) === ['id', 'author', 'name', 'url', 'compatibility', 'version', 'build', 'date'] && $lj['name'] === 'NRG-Stack Börsenpreis' && $lj['compatibility'] === ['version' => '9.0']);
$mj = json_decode(file_get_contents(dirname(__DIR__) . '/Boersenpreis/module.json'), true);
check('module.json: Klasse = name, Präfix SPOT, GUIDs passen zur Klasse', $mj['name'] === 'Boersenpreis' && $mj['prefix'] === 'SPOT' && $mj['library'] === $lj['id'] && str_contains($code, $mj['id']) && str_contains($code, $lj['id']));

heading('14. Preisverlauf aus dem Archiv (Vertrag 1.1, SPOT_GetPriceHistory)');
$GLOBALS['ARCHIVES'] = [99]; $GLOBALS['AC'] = []; $GLOBALS['AC_LOG'] = []; $GLOBALS['AC_APPLY'] = 0;
clock('2026-05-01 00:00:30');
$m = fresh();
$GLOBALS['HTTP'][] = ok(fx('ec-DE-LU-2026-05-01.json'));
$m->ApplyChanges();
$vid = IPS_GetObjectIDByIdent('CurrentPrice', 12345);
check('Archivierung von „Börsenpreis jetzt" eingeschaltet, Archiv übernommen, Merker gesetzt', ($GLOBALS['AC_LOG'][$vid] ?? false) === true && $GLOBALS['AC_APPLY'] === 1 && $m->attrs['ArchiveInitDone'] === true);
check('Erster Preis schon im Archiv (Archivierung vor dem ersten Setzen eingeschaltet)', count($GLOBALS['AC'][$vid] ?? []) === 1);
$GLOBALS['AC_LOG'][$vid] = false;
$m->ApplyChanges();
check('Nutzer schaltet Archivierung ab: bleibt aus, kein erneutes Übernehmen', $GLOBALS['AC_LOG'][$vid] === false && $GLOBALS['AC_APPLY'] === 1);
$GLOBALS['AC_LOG'][$vid] = true;
for ($t = ts('2026-05-01 00:15:02'); $t < ts('2026-05-02 00:00:00'); $t += 900) { $GLOBALS['CLOCK'] = $t; $m->Tick(); }
$ecNeg = json_decode(fx('ec-DE-LU-2026-05-01.json'), true);
$changes = 1;
for ($i = 1; $i < count($ecNeg['price']); $i++) { if ($ecNeg['price'][$i] !== $ecNeg['price'][$i - 1]) { $changes++; } }
check('Archiv hält nur die Wertänderungen des Tages (' . $changes . ')', count($GLOBALS['AC'][$vid]) === $changes, (string)count($GLOBALS['AC'][$vid]));
clock('2026-05-02 10:00:00');
$m->attrs['PriceCache'] = '{}'; // Zwischenspeicher weg → nur noch das Archiv kennt den 01.05.
$h = $m->GetPriceHistory(ts('2026-05-01 00:00:00'), ts('2026-05-02 00:00:00'));
check('Verlauf 01.05.: 96 Viertelstunden, quelle archiv, aufloesung 900, lückenlos, Vertrag 1.1', count($h) === 96 && $h[0]['quelle'] === 'archiv' && $h[0]['aufloesung'] === 900 && contiguous($h) && $h[0]['contractVersion'] === '1.1', (string)count($h));
$exact = count($h) === 96;
foreach ($h as $i => $s) { if (abs($s['price'] - $ecNeg['price'][$i] / 10) > 1e-9) { $exact = false; } }
check('Jede Viertelstunde exakt der Börsenpreis (Stufenverlauf stellt gleiche Folgewerte wieder her)', $exact);
check('32 negative Viertelstunden auch im Rückblick', count(array_filter($h, fn($s) => $s['price'] < 0)) === 32);
$h2 = $m->GetPriceHistory(ts('2026-05-01 12:05:00'), ts('2026-05-01 13:00:00'));
check('Ausschnitt 12:05–13:00: auf Viertelstunden gerundet (12:00–13:00), Wert vor Beginn übernommen', count($h2) === 4 && $h2[0]['start'] === ts('2026-05-01 12:00:00') && abs($h2[0]['price'] - $ecNeg['price'][48] / 10) < 1e-9);
check('Vor dem ersten Archiveintrag: keine Einträge (nichts erfunden)', $m->GetPriceHistory(ts('2026-04-30 00:00:00'), ts('2026-05-01 00:00:00')) === []);
check('Zukunft ohne veröffentlichte Preise: keine Einträge', $m->GetPriceHistory(ts('2026-05-03 00:00:00'), ts('2026-05-04 00:00:00')) === []);
check('Leerer oder verkehrter Zeitraum: leer', $m->GetPriceHistory(ts('2026-05-01 12:00:00'), ts('2026-05-01 12:00:00')) === [] && $m->GetPriceHistory(ts('2026-05-02 00:00:00'), ts('2026-05-01 00:00:00')) === []);
$GLOBALS['AC_CALLS'] = [];
$m->GetPriceHistory(ts('2026-04-28 06:00:00'), ts('2026-05-02 06:00:00'));
check('Archiv tageweise abgefragt (5 Tagesfenster + 1 Vorwert), nie seit Epoche, keins länger als ein Tag (SUITE.md 9g)', count($GLOBALS['AC_CALLS']) === 6 && count(array_filter($GLOBALS['AC_CALLS'], fn($c) => $c[0] <= 0 || $c[1] - $c[0] > 90000)) === 0, json_encode($GLOBALS['AC_CALLS']));
$lastChange = intdiv(max(array_column($GLOBALS['AC'][$vid], 'TimeStamp')), 900) * 900;
clock('2026-05-02 20:00:00');
$h5 = $m->GetPriceHistory(ts('2026-05-02 00:00:00'), ts('2026-05-02 20:00:00'));
check('Stillstand (keine Ticks am 02.05.): letzter Wert gilt höchstens 12 h, danach leer statt erfunden', count($h5) > 0 && end($h5)['start'] === $lastChange + 43200 - 900, count($h5) . ' / ' . date('d.m.Y H:i', end($h5)['start'] ?? 0));
$GLOBALS['HTTP'][] = ok(ecFirst('ec-DE-LU-2026-09-12_13.json', 96));
clock('2026-09-12 10:00:00');
$m->Update();
$h6 = $m->GetPriceHistory(ts('2026-09-12 00:00:00'), ts('2026-09-13 00:00:00'));
check('Heute kommt exakt aus dem Zwischenspeicher (quelle energy-charts, auch künftige Viertelstunden)', count($h6) === 96 && $h6[0]['quelle'] === 'energy-charts' && $h6[95]['quelle'] === 'energy-charts');
$GLOBALS['AC'][$vid][] = ['TimeStamp' => ts('2026-09-12 09:00:05'), 'Value' => 999.0]; // widersprüchlicher Archivwert
usort($GLOBALS['AC'][$vid], fn($x, $y) => $x['TimeStamp'] <=> $y['TimeStamp']);
$h7 = $m->GetPriceHistory(ts('2026-09-12 09:00:00'), ts('2026-09-12 09:15:00'));
check('Archiv und Zwischenspeicher widersprechen sich: der exakte Speicherwert gewinnt', count($h7) === 1 && abs($h7[0]['price'] - $ec['price'][36] / 10) < 1e-9 && $h7[0]['quelle'] === 'energy-charts', json_encode($h7));
$GLOBALS['ARCHIVES'] = [];
check('Ohne Archiv-Instanz: Verlauf nur aus dem Zwischenspeicher, kein Fehler', count($m->GetPriceHistory(ts('2026-05-01 00:00:00'), ts('2026-09-13 00:00:00'))) === 96);
$m2 = fresh();
$GLOBALS['HTTP'][] = ok(ecFirst('ec-DE-LU-2026-09-12_13.json', 96));
$m2->ApplyChanges();
check('Ohne Archiv-Instanz beim Anlegen: Merker bleibt offen (später erneut versuchen)', $m2->attrs['ArchiveInitDone'] === false);

heading('15. EPEX Spot über ENTSO-E');
$GLOBALS['ARCHIVES'] = [];
clock('2026-09-12 10:00:00');
$m = fresh(2);
$n = count($GLOBALS['REQUESTS']);
$m->ApplyChanges();
check('Ohne Zugangsschlüssel: keine Anfrage, klarer Hinweis, Status 201', count(requestsSince($n)) === 0 && str_contains($m->attrs['LastError'], 'kein Zugangsschlüssel') && $m->status === 201, $m->attrs['LastError']);
$p = array_slice($ec['price'], 0, 96);
$p[93] = $p[94] = $p[95] = $p[92]; // Periodenende mit gleichen Werten — A03 lässt sie weg
$GLOBALS['HTTP'][] = ok(entsoeXml(ts('2026-09-12 00:00:00'), $p, 900, array_fill(0, 96, 999.0)));
$secret = 'abc-123-SECRET';
$r = $m->SetEntsoeToken($secret);
$u = end($GLOBALS['REQUESTS']);
check('Schlüssel ins Attribut, Eingabefeld geleert, sofort abgerufen', $m->attrs['EntsoeToken'] === $secret && in_array(['EntsoeTokenInput', 'value', ''], $m->fieldUpdates, true) && str_starts_with($r, '✅'), $r);
check('URL: ENTSO-E A44, UTC heute 00:00 bis übermorgen 00:00 Ortszeit, EIC DE-LU', $u === 'https://web-api.tp.entsoe.eu/api?securityToken=' . $secret . '&documentType=A44&periodStart=202609112200&periodEnd=202609132200&out_Domain=10Y1001A1001A82H&in_Domain=10Y1001A1001A82H', $u);
$c = $m->GetPriceCurve();
$exact = count($c) === 96;
foreach ($c as $i => $s) { if (abs($s['price'] - $p[$i] / 10) > 1e-9) { $exact = false; } }
check('96 Viertelstunden exakt (A03-Lücken inkl. Periodenende gefüllt, Nebenreihe Position 2 ignoriert)', $exact);
check('quelle=entsoe, aufloesung=900, lückenlos', $c[0]['quelle'] === 'entsoe' && $c[0]['aufloesung'] === 900 && contiguous($c));
check('Formular zeigt nur die letzten 4 Zeichen des Schlüssels, nie den Schlüssel', str_contains($m->call('entsoeTokenStatus'), '…CRET') && !str_contains(formText(form($m)), $secret));
$aw = json_decode(fx('aw-DE-2026-05-01.json'), true);
clock('2026-05-01 09:00:00');
$m2 = fresh(2);
$m2->attrs['EntsoeToken'] = $secret;
$GLOBALS['HTTP'][] = ok(entsoeXml(ts('2026-05-01 00:00:00'), array_map(fn($x) => (float)$x['marketprice'], $aw['data']), 3600));
$m2->ApplyChanges();
$c2 = $m2->GetPriceCurve();
check('PT60M: 24 Stunden → 96 Viertelstunden, aufloesung 3600', count($c2) === 96 && $c2[0]['aufloesung'] === 3600 && $c2[0]['price'] === $c2[3]['price'] && contiguous($c2), (string)count($c2));
$ds = json_decode(fx('ec-DE-LU-2026-03-29.json'), true);
clock('2026-03-29 09:00:00');
$m3 = fresh(2);
$m3->attrs['EntsoeToken'] = $secret;
$GLOBALS['HTTP'][] = ok(entsoeXml(ts('2026-03-29 00:00:00'), $ds['price'], 900));
$m3->ApplyChanges();
$c3 = $m3->GetPriceCurve();
check('29.03.2026 (23-Stunden-Tag): 92 Viertelstunden bis 24:00', count($c3) === 92 && contiguous($c3) && end($c3)['end'] === ts('2026-03-30 00:00:00'));
clock('2026-09-12 13:00:00');
$GLOBALS['HTTP'][] = resp(400, ackXml());
$r = $m->Update();
check('Keine Daten (HTTP 400 + Quittung „No matching data“): „noch keine Daten“, Speicher bleibt', str_contains($r, 'noch keine Daten') && count($m->GetPriceCurve()) === 96, $r);
$GLOBALS['HTTP'][] = resp(401, 'Unauthorized');
check('HTTP 401: „lehnt den Zugangsschlüssel ab“', str_contains($m->Update(), 'lehnt den Zugangsschlüssel ab'));
$GLOBALS['LOG'] = [];
$GLOBALS['HTTP'][] = resp(0, '', [], 'file_get_contents(https://web-api.tp.entsoe.eu/api?securityToken=' . $secret . '&documentType=A44): Failed to open stream');
$r = $m->Update();
check('Schlüssel steht nie in Rückmeldung, Fehler, Log oder Formular (***)', str_contains($r, '***') && !str_contains($r, $secret) && !str_contains($m->attrs['LastError'], $secret)
    && !str_contains(implode(' ', $GLOBALS['LOG']), $secret) && !str_contains(formText(form($m)), $secret), $r);
$GLOBALS['HTTP'][] = ok('<html>kein XML');
check('Kein XML: „keine lesbaren Daten“', str_contains($m->Update(), 'keine lesbaren Daten'));
$m->UIChangeSource(2);
$vis = array_values(array_filter($m->fieldUpdates, fn($x) => $x[0] === 'EntsoeTokenButton' && $x[1] === 'visible'));
check('Quellenwahl ENTSO-E blendet die Schlüsselfelder ein', end($vis)[2] === true);
$m->UIChangeSource(0);
$vis = array_values(array_filter($m->fieldUpdates, fn($x) => $x[0] === 'EntsoeTokenButton' && $x[1] === 'visible'));
check('… andere Quelle blendet sie aus', end($vis)[2] === false);
check('Leerer Schlüssel löscht', str_starts_with($m->SetEntsoeToken(''), '🗑') && $m->attrs['EntsoeToken'] === '');
$m->UIChangeSource(3);
$vis = array_values(array_filter($m->fieldUpdates, fn($x) => $x[0] === 'TibberPostalCode' && $x[1] === 'visible'));
check('Quellenwahl Tibber blendet das Postleitzahl-Feld ein', end($vis)[2] === true);

heading('16. Symcon Energie Manager — Variable „Marktdaten (Energie Manager)“');
clock('2026-09-12 10:00:00');
$m = fresh();
$GLOBALS['HTTP'][] = ok(fx('ec-DE-LU-2026-09-12_13.json'));
$m->ApplyChanges();
$md = json_decode(v($m, 'MarketData'), true);
check('Format wie Symcons „Strompreis“: Liste aus {start, end, price}', is_array($md) && array_keys($md[0] ?? []) === ['start', 'end', 'price'] && is_int($md[0]['start']) && is_float($md[0]['price']));
check('Ab der laufenden Viertelstunde, höchstens 24 Stunden (96 Einträge)', $md[0]['start'] === ts('2026-09-12 10:00:00') && count($md) === 96 && end($md)['end'] === ts('2026-09-13 10:00:00'));
check('Standard (alles 0): reiner Börsenpreis in ct/kWh', abs($md[0]['price'] - $ec['price'][40] / 10) < 1e-9);
$m->props['TariffEnabled'] = true; $m->props['TariffBeschaffung'] = 2.0; $m->props['TariffKonzession'] = 1.32; $m->props['NetzArbeitspreis'] = 8.0;
$m->ApplyChanges();
$md = json_decode(v($m, 'MarketData'), true);
$wantTariff = round(($ec['price'][40] / 10 + 2.0 + 8.0 + 1.32 + 2.05 + 0.941 + 0.446 + 1.56) * 1.19, 4);
check('Eigener Tarif: (Börsenpreis + Aufschlag + Netzentgelt + Konzession + Umlagen 4,997) × 1,19', abs($md[0]['price'] - $wantTariff) < 1e-9, $md[0]['price'] . ' vs ' . $wantTariff);
$m->props['MarketBase'] = 20.0; $m->props['MarketTax'] = 19.0; $m->props['MarketSurcharge'] = 3.0;
$m->ApplyChanges();
check('Alte Felder Grundpreis/Steuer/Aufschlag (bis 0.4) ohne Wirkung, aber noch registriert (kein Bruch)', abs(json_decode(v($m, 'MarketData'), true)[0]['price'] - $wantTariff) < 1e-9 && array_key_exists('MarketBase', $m->props));
check('Tarif wirkt NICHT auf Vertrag und „Börsenpreis jetzt“', abs($m->GetPriceCurve()[40]['price'] - $ec['price'][40] / 10) < 1e-9 && abs(v($m, 'CurrentPrice') - $ec['price'][40] / 10) < 1e-9);
clock('2026-09-12 10:15:02');
$m->Tick();
$md = json_decode(v($m, 'MarketData'), true);
check('Nach der Viertelstunde fällt der vergangene Eintrag heraus', $md[0]['start'] === ts('2026-09-12 10:15:00') && count($md) === 96);
$m2 = fresh();
$GLOBALS['HTTP'][] = resp(0, '', [], 'DNS');
$m2->ApplyChanges();
check('Ohne Preise: leere Liste „[]“ (nichts erfunden)', v($m2, 'MarketData') === '[]');
check('Formular: Panel nennt die Variable mit ID und das Feld „Energiepreise“', str_contains(formText(form($m)), 'Marktdaten (Energie Manager)“ (ID ') && str_contains(formText(form($m)), 'Energiepreise'));

heading('17. Tibber-Preisübersicht (ohne Konto, per Postleitzahl)');
/** Tibber-Preisübersicht aus einer Energy-Charts-Reihe nachbilden: Ortszeit ohne Zeitzone, Endpreis = Börsenpreis × 1,19 + 20 ct. */
function tibberJson(array $ecd): string
{
    $rows = [];
    foreach ($ecd['unix_seconds'] as $i => $t) {
        $p = $ecd['price'][$i] / 1000; // EUR/MWh → EUR/kWh
        $rows[] = ['priceExcludingVat' => $p + 0.17, 'priceIncludingVat' => round($p * 1.19 + 0.2, 4), 'hour' => (int)date('G', $t), 'minute' => (int)date('i', $t), 'date' => date('Y-m-d', $t),
            'priceComponents' => [['type' => 'power', 'priceExcludingVat' => $p], ['type' => 'taxes', 'priceExcludingVat' => 0.09], ['type' => 'grid', 'priceExcludingVat' => 0.08]]];
    }
    return json_encode(['priceArea' => 'DE', 'currency' => 'EUR', 'energy' => ['todayQuarterHours' => $rows, 'tomorrowQuarterHours' => [], 'todayHours' => [], 'tomorrowHours' => []]]);
}
clock('2026-09-14 18:30:00');
$m = fresh(3);
$n = count($GLOBALS['REQUESTS']);
$m->ApplyChanges();
check('Ohne Postleitzahl: keine Anfrage, klarer Hinweis', count(requestsSince($n)) === 0 && str_contains($m->attrs['LastError'], 'Postleitzahl'), $m->attrs['LastError']);
$m->props['TibberPostalCode'] = '10115';
$GLOBALS['HTTP'][] = ok(fx('tibber-10115-2026-09-14_15.json'));
$m->ApplyChanges();
check('URL: Tibber-Preisübersicht mit Postleitzahl', end($GLOBALS['REQUESTS']) === 'https://tibber.com/de/api/lookup/price-overview?postalCode=10115', end($GLOBALS['REQUESTS']));
$c = $m->GetPriceCurve();
$ecT = json_decode(fx('ec-DE-LU-2026-09-14_15.json'), true);
$mapT = array_combine($ecT['unix_seconds'], $ecT['price']);
$maxDiff = 0.0;
foreach ($c as $s) { $maxDiff = max($maxDiff, abs($s['price'] - ($mapT[$s['start']] ?? 1e9) / 10)); }
check('Echte Antwort: 192 Viertelstunden, Börsenpreisanteil = Energy-Charts (≤ 0,005 ct Rundung), quelle tibber', count($c) === 192 && $maxDiff <= 0.0051 && $c[0]['quelle'] === 'tibber' && $c[0]['aufloesung'] === 900 && contiguous($c), count($c) . ' / ' . $maxDiff);
$tq = json_decode(fx('tibber-10115-2026-09-14_15.json'), true)['energy']['todayQuarterHours'];
$md = json_decode(v($m, 'MarketData'), true);
check('Energie Manager bekommt Tibbers Endpreis inkl. MwSt (wie „Strompreis“), ab 18:30', $md[0]['start'] === ts('2026-09-14 18:30:00') && abs($md[0]['price'] - $tq[74]['priceIncludingVat'] * 100) < 1e-6, json_encode($md[0]));
check('Vertrag und „Börsenpreis jetzt“ bleiben der Börsenpreis (nicht der Endpreis)', abs(v($m, 'CurrentPrice') - ($mapT[ts('2026-09-14 18:30:00')] / 10)) <= 0.0051 && $c[74]['price'] < $md[0]['price']);
$m->props['MarketBase'] = 20.0;
$m->ApplyChanges();
check('Tarif-Felder werden bei Tibber nicht angewendet', abs(json_decode(v($m, 'MarketData'), true)[0]['price'] - $tq[74]['priceIncludingVat'] * 100) < 1e-6);
check('Formular: Statuszeile nennt den Tibber-Endpreis der PLZ, Übertragung der PLZ genannt', str_contains(formText(form($m)), 'Endpreis aus der Tibber-Preisübersicht für PLZ 10115') && str_contains(formText(form($m)), 'wird an Tibber übertragen'));
$m->props['TibberPostalCode'] = '80331';
$GLOBALS['HTTP'][] = ok(fx('tibber-10115-2026-09-14_15.json'));
$m->ApplyChanges();
check('Andere Postleitzahl: alte Preise verworfen, neu abgefragt', end($GLOBALS['REQUESTS']) === 'https://tibber.com/de/api/lookup/price-overview?postalCode=80331' && json_decode($m->attrs['PriceCache'], true)['plz'] === '80331');
$m->props['BiddingZone'] = 'AT';
$m->ApplyChanges();
check('Gebotszone AT: klarer Hinweis „nur für Deutschland“', str_contains($m->attrs['LastError'], 'nur für Deutschland'));
foreach ([['2025-10-26', 'ec-DE-LU-2025-10-26.json', 100], ['2026-03-29', 'ec-DE-LU-2026-03-29.json', 92]] as [$day, $file, $want]) {
    clock($day . ' 09:00:00');
    $mt = fresh(3);
    $mt->props['TibberPostalCode'] = '10115';
    $ecd = json_decode(fx($file), true);
    $GLOBALS['HTTP'][] = ok(tibberJson($ecd));
    $mt->ApplyChanges();
    $ct = $mt->GetPriceCurve();
    $same = count($ct) === $want;
    foreach ($ct as $i => $s) { if ($s['start'] !== $ecd['unix_seconds'][$i] || abs($s['price'] - $ecd['price'][$i] / 10) > 1e-9) { $same = false; } }
    check("Sommerzeit $day: $want Viertelstunden, jede zur richtigen Unixzeit (Ortszeit ohne Zeitzone richtig zugeordnet)", $same && contiguous($ct), count($ct) . ' Einträge');
}

heading('18. Tarif für den Energie Manager — § 14a Modul 3, Wochenende, Quartale, Tibber Grid Rewards');
$ecW = json_decode(fx('ec-DE-LU-2026-09-14_15.json'), true);
$spotAt = fn(string $when) => $ecW['price'][array_search(ts($when), $ecW['unix_seconds'], true)] / 10;
$gross = fn(float $spot, float $netz) => round(($spot + $netz + 4.997) * 1.19, 4); // Aufschlag 0, Konzession 0
$mdAt = function (SpotTest $m, string $when) { foreach (json_decode(v($m, 'MarketData'), true) as $e) { if ($e['start'] === ts($when)) { return $e['price']; } } return null; };
$windows = [
    ['From' => '00:00', 'To' => '06:00', 'Band' => 'NT', 'Days' => 'all'],
    ['From' => '06:00', 'To' => '16:30', 'Band' => 'ST', 'Days' => 'weekday'],
    ['From' => '16:30', 'To' => '20:30', 'Band' => 'HT', 'Days' => 'weekday'],
    ['From' => '20:30', 'To' => '00:00', 'Band' => 'ST', 'Days' => 'all'],
    ['From' => '06:00', 'To' => '20:30', 'Band' => 'NT', 'Days' => 'weekend'],
];
$tariffProps = ['TariffEnabled' => true, 'NetzArbeitspreis' => 8.0, 'Modul3Enabled' => true, 'NetzHT' => 12.0, 'NetzST' => 7.0, 'NetzNT' => 1.0, 'NetzWindows' => json_encode($windows)];
clock('2026-09-14 10:00:00'); // Montag
$m = fresh();
foreach ($tariffProps as $k => $val) { $m->props[$k] = $val; }
$GLOBALS['HTTP'][] = ok(fx('ec-DE-LU-2026-09-14_15.json'));
$m->ApplyChanges();
check('Mo 10:00 Standardtarif (7), Mo 18:30 Hochtarif (12), Mo 22:00 Standardtarif, Di 03:00 Niedertarif (1)',
    abs($mdAt($m, '2026-09-14 10:00:00') - $gross($spotAt('2026-09-14 10:00:00'), 7.0)) < 1e-9 && abs($mdAt($m, '2026-09-14 18:30:00') - $gross($spotAt('2026-09-14 18:30:00'), 12.0)) < 1e-9
    && abs($mdAt($m, '2026-09-14 22:00:00') - $gross($spotAt('2026-09-14 22:00:00'), 7.0)) < 1e-9 && abs($mdAt($m, '2026-09-15 03:00:00') - $gross($spotAt('2026-09-15 03:00:00'), 1.0)) < 1e-9);
$m->props['Modul3Q3'] = false;
$m->ApplyChanges();
check('Quartal ohne Modul 3 (September = Q3 aus): Arbeitspreis 8', abs($mdAt($m, '2026-09-14 18:30:00') - $gross($spotAt('2026-09-14 18:30:00'), 8.0)) < 1e-9);
$m->props['Modul3Q3'] = true;
$m->props['NetzWindows'] = json_encode([['From' => '00:00', 'To' => '06:00', 'Band' => 'NT']]); // alte Zeile ohne „Tage“
$m->ApplyChanges();
check('Außerhalb jedes Zeitfensters: Arbeitspreis; Zeile ohne „Tage“ gilt für alle Tage', abs($mdAt($m, '2026-09-14 10:00:00') - $gross($spotAt('2026-09-14 10:00:00'), 8.0)) < 1e-9 && abs($mdAt($m, '2026-09-15 03:00:00') - $gross($spotAt('2026-09-15 03:00:00'), 1.0)) < 1e-9);
check('Statuszeile: eigener Tarif', str_contains(formText(form($m)), '🧾 Preis aus deinem eigenen Tarif'));
clock('2026-09-12 10:00:00'); // Samstag
$m = fresh();
foreach ($tariffProps as $k => $val) { $m->props[$k] = $val; }
$GLOBALS['HTTP'][] = ok(fx('ec-DE-LU-2026-09-12_13.json'));
$m->ApplyChanges();
check('Sa 10:00 gilt das Wochenend-Fenster (Niedertarif 1), nicht der Werktags-Standardtarif', abs($mdAt($m, '2026-09-12 10:00:00') - round(($ec['price'][40] / 10 + 1.0 + 4.997) * 1.19, 4)) < 1e-9);
// Tibber Grid Rewards: echter Endpreis hat Vorrang (wo er vorliegt), sonst eigener Tarif
if (!function_exists('TIBBERGR_GetPriceCurve')) {
    function TIBBERGR_GetPriceCurve(int $id): array { if ($GLOBALS['TIBBER_THROW']) { throw new RuntimeException('Instanz lädt neu'); } return $GLOBALS['TIBBER_CURVE']; }
}
$GLOBALS['TIBBER_THROW'] = false;
$GLOBALS['INSTANCES']['{E92F62F4-88A6-4C6E-9F0D-E76C3B1C9A01}'] = [777];
$GLOBALS['TIBBER_CURVE'] = [['start' => ts('2026-09-12 10:00:00'), 'end' => ts('2026-09-12 11:00:00'), 'price' => 31.5, 'basis' => 'endkunde', 'netzentgelt' => 'enthalten', 'level' => null, 'contractVersion' => '1.1']];
$m->Tick();
check('Tibber Grid Rewards installiert: 10:00–11:00 sein echter Endpreis (31,5), 11:00 wieder eigener Tarif', $mdAt($m, '2026-09-12 10:00:00') === 31.5 && $mdAt($m, '2026-09-12 10:45:00') === 31.5 && abs($mdAt($m, '2026-09-12 11:00:00') - round(($ec['price'][44] / 10 + 1.0 + 4.997) * 1.19, 4)) < 1e-9);
check('Statuszeile nennt Tibber Grid Rewards mit Instanz', str_contains(formText(form($m)), '✅ Preis aus Tibber Grid Rewards (#777)'));
$m->props['UseTibberPrice'] = false;
$m->ApplyChanges();
check('Abgewählt: eigener Tarif statt Tibber-Preis', abs($mdAt($m, '2026-09-12 10:00:00') - round(($ec['price'][40] / 10 + 1.0 + 4.997) * 1.19, 4)) < 1e-9);
$m->props['UseTibberPrice'] = true;
$GLOBALS['TIBBER_CURVE'][0]['contractVersion'] = '2.0';
$m->ApplyChanges();
check('Tibber-Vertrag 2.x: nicht genutzt, sichtbare Meldung „benötigt eine Aktualisierung“', $mdAt($m, '2026-09-12 10:00:00') !== 31.5 && str_contains(formText(form($m)), 'benötigt eine Aktualisierung'));
$GLOBALS['TIBBER_CURVE'][0]['contractVersion'] = '1.1';
$GLOBALS['TIBBER_CURVE'][0]['basis'] = 'spot';
$m->ApplyChanges();
check('Nur basis „endkunde“ zählt als Endpreis', $mdAt($m, '2026-09-12 10:00:00') !== 31.5);
$GLOBALS['TIBBER_THROW'] = true;
$m->ApplyChanges();
check('Tibber wirft (z. B. lädt neu): kein Absturz, eigener Tarif, Meldung im Formular', $mdAt($m, '2026-09-12 10:00:00') !== null && str_contains(formText(form($m)), 'antwortet nicht'));
$GLOBALS['TIBBER_THROW'] = false;
$GLOBALS['INSTANCES'] = [];

heading('19. Ausblenden über mehrere Instanzen teilen (SUITE.md, 14.09.2026)');
$G = '{11BBF147-16A1-4332-82A3-29BB31154D03}';
clock('2026-09-12 10:00:00');
$GLOBALS['VARS'] = [];
$mk = function (int $id) {
    $o = new SpotTest();
    $o->InstanceID = $id;
    $o->Create();
    $o->props['Source'] = 0; $o->props['BiddingZone'] = 'DE-LU';
    $GLOBALS['SPOT_OBJ'][$id] = $o;
    return $o;
};
$a = $mk(20001); $b = $mk(20002);
$GLOBALS['INSTANCES'][$G] = [20001, 20002];
$GLOBALS['HTTP'][] = ok(fx('ec-DE-LU-2026-09-12_13.json')); $a->ApplyChanges();
$GLOBALS['HTTP'][] = ok(fx('ec-DE-LU-2026-09-12_13.json')); $b->ApplyChanges();
$panels = fn(SpotTest $o) => array_filter(array_map(fn($e) => ($e['visible'] ?? true) === false ? '' : ($e['name'] ?? ''), form($o)['elements']));
check('Beide Instanzen zeigen anfangs „Wozu dieses Modul?“, „Neu“ und „Rückmeldungen“', count(array_intersect(['PurposeIntroPanel', 'NewsPanel', 'ForumHintPanel'], $panels($a))) === 3 && count(array_intersect(['PurposeIntroPanel', 'NewsPanel', 'ForumHintPanel'], $panels($b))) === 3);
$GLOBALS['ADOPT_CALLS'] = 0;
$a->AckPurposeIntro();
check('„Wozu“ in A bestätigt → auch in B weg, genau ein Übernahme-Aufruf (kein Hin-und-her)', !in_array('PurposeIntroPanel', $panels($b), true) && in_array('NewsPanel', $panels($b), true) && $GLOBALS['ADOPT_CALLS'] === 1, (string)$GLOBALS['ADOPT_CALLS']);
$b->AckNews(); $b->AckForumHint();
check('„Neu“ und „Rückmeldungen“ in B bestätigt → auch in A weg', !in_array('NewsPanel', $panels($a), true) && !in_array('ForumHintPanel', $panels($a), true));
$c = $mk(20003);
$GLOBALS['INSTANCES'][$G] = [20001, 20002, 20003];
$GLOBALS['HTTP'][] = ok(fx('ec-DE-LU-2026-09-12_13.json'));
$c->ApplyChanges();
check('Neue Instanz C übernimmt den Stand einmalig (keine der drei Tafeln)', count(array_intersect(['PurposeIntroPanel', 'NewsPanel', 'ForumHintPanel'], $panels($c))) === 0);
$b->AdoptDismissState(json_encode(['purpose' => false, 'news' => '0.0.1', 'forum' => false]));
check('Übernahme blendet nie wieder ein und stuft „Neu“ nicht auf eine ältere Version zurück', !in_array('PurposeIntroPanel', $panels($b), true) && !in_array('NewsPanel', $panels($b), true));
$GLOBALS['INSTANCES'][$G] = [20001, 20002, 20009]; // 20009: entfernte/defekte Instanz
$a->attrs['ForumHintGone'] = false;
$a->AckForumHint();
check('Defekte Geschwister-Instanz: kein Absturz, die übrigen übernehmen trotzdem', !in_array('ForumHintPanel', $panels($b), true));
$GLOBALS['INSTANCES'] = [];
$GLOBALS['SPOT_OBJ'] = [];

echo "\n" . str_repeat('-', 62) . "\n";
if ($fails === 0) {
    echo "OK — alle Prüfungen bestanden.\n";
    exit(0);
}
echo "FEHLER — $fails Prüfung(en) fehlgeschlagen.\n";
exit(1);
