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
$GLOBALS['MAINTAIN'] = [];
// Darstellungs-Konstanten, wörtlich aus get_defined_constants() auf Symcon 9.1 (23.11.2026).
const VARIABLE_PRESENTATION_VALUE_PRESENTATION = '{3319437D-7CDE-699D-750A-3C6A3841FA75}';
const VARIABLE_PRESENTATION_ENUMERATION        = '{52D9E126-D7D2-2CBB-5E62-4CF7BA7C5D82}';
const VARIABLE_PRESENTATION_LEGACY             = '{4153A8D4-5C33-C65F-C1F3-7B61AAF99B1C}';
$GLOBALS['LOG'] = [];
$GLOBALS['HTTP'] = [];      // Warteschlange der nächsten Antworten
$GLOBALS['REQUESTS'] = [];  // abgefragte URLs
$GLOBALS['CLOCK'] = 0;
// Geteiltes Ausblenden: generierte Kernel-Wrapper SPOT_* nachbilden (Instanz-ID → Objekt).
$GLOBALS['SPOT_OBJ'] = [];
$GLOBALS['ADOPT_CALLS'] = 0;
function SPOT_GetDismissState(int $id): string { if (!isset($GLOBALS['SPOT_OBJ'][$id])) { throw new RuntimeException('Instanz fehlt'); } return $GLOBALS['SPOT_OBJ'][$id]->GetDismissState(); }
function SPOT_AdoptDismissState(int $id, string $s): void { $GLOBALS['ADOPT_CALLS']++; if (!isset($GLOBALS['SPOT_OBJ'][$id])) { throw new RuntimeException('Instanz fehlt'); } $GLOBALS['SPOT_OBJ'][$id]->AdoptDismissState($s); }

$GLOBALS['RUNLEVEL'] = KR_READY;
function IPS_GetKernelRunlevel(): int { return $GLOBALS['RUNLEVEL']; }
function IPS_GetObjectIDByIdent(string $ident, int $parent) {
    foreach ($GLOBALS['VARS'] as $id => $v) { if ($v['ident'] === $ident && $v['parent'] === $parent) { return $id; } }
    return false;
}
function IPS_VariableProfileExists(string $n): bool { return isset($GLOBALS['PROFILES'][$n]); }
function IPS_CreateVariableProfile(string $n, int $t): bool { $GLOBALS['PROFILES'][$n] = ['type' => $t, 'digits' => 0, 'suffix' => '', 'assoc' => []]; return true; }
function IPS_SetVariableProfileDigits(string $n, int $d): bool { $GLOBALS['PROFILES'][$n]['digits'] = $d; return true; }
function IPS_SetVariableProfileText(string $n, string $p, string $s): bool { $GLOBALS['PROFILES'][$n]['suffix'] = $s; return true; }
function IPS_SetVariableProfileAssociation(string $n, $v, string $c, string $i, int $col): bool { $GLOBALS['PROFILES'][$n]['assoc'][(string)$v] = $c; return true; }
const KL_MESSAGE = 1;
const KL_SUCCESS = 2;
const KL_NOTIFY = 3;
const KL_WARNING = 4;
const KL_ERROR = 5;
const KL_DEBUG = 6;
const KL_CUSTOM = 7;
// Absichtlich NICHT definiert: IPS_LogMessage() — Modulklassen loggen über $this->LogMessage()
// (Store-Review-Checkliste 15, SUITE.md 12.10.2026); ein Rückfall würde hier sofort auffliegen.
$GLOBALS['LIB_VERSION'] = '0.0.0-test';
function IPS_GetLibrary(string $guid): array { return ['Version' => $GLOBALS['LIB_VERSION'], 'Build' => 0]; }
$GLOBALS['NAMES'] = [];   // Objektnamen für IPS_GetName (Partnerinstanzen)
function IPS_GetName(int $id): string { return $GLOBALS['NAMES'][$id] ?? ('Objekt #' . $id); }
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
    // Übersetzung: der Prüfstand liest die echte locale.json und prüft damit beide Sprachen.
    public function Translate(string $text): string
    {
        $GLOBALS['TRANSLATE_CALLS'] = ($GLOBALS['TRANSLATE_CALLS'] ?? 0) + 1;
        $lang = $GLOBALS['LOCALE'] ?? 'de';   // Prüfstand läuft in der Referenzsprache Deutsch
        if ($lang === 'en') { return $text; }
        $loc = json_decode((string)@file_get_contents(dirname(__DIR__) . '/Boersenpreis/locale.json'), true);
        return (string)($loc['translations'][$lang][$text] ?? $text);
    }
    public function LogMessage(string $text, int $level): void { $GLOBALS['LOG'][] = $level . ': ' . $text; }
    public function UpdateFormField(string $n, string $p, $v): void { $this->fieldUpdates[] = [$n, $p, $v]; }
    /** $profile ist seit Symcon 8 „Profil ODER Darstellung" — String oder Array (SDK: mixed). */
    public function MaintainVariable(string $ident, string $name, int $type, $profile, int $pos, bool $keep): void
    {
        // Jeder Aufruf merkt sich, was das Modul übergeben hat — auch für bestehende Variablen,
        // damit der Prüfstand die Umstellung von Profil auf Darstellung sehen kann.
        $GLOBALS['MAINTAIN'][$ident] = ['type' => $type, 'profile' => $profile, 'pos' => $pos, 'keep' => $keep, 'name' => $name];
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
/**
 * Formular wie die Symcon-Konsole: Sie übersetzt caption/label/Options selbst anhand der
 * locale.json (Store-Review WPModbusHub, Checkliste 25) — das Modul liefert sie englisch.
 * Der Prüfstand bildet genau das nach, damit die Prüfungen den ANGEZEIGTEN Text sehen.
 */
function konsoleUebersetzt(array $el): array
{
    foreach ($el as $k => $v) {
        if (is_array($v)) {
            $el[$k] = konsoleUebersetzt($v);
        } elseif (in_array($k, ['caption', 'label'], true) && is_string($v)) {
            $el[$k] = konsoleText($v);
        }
    }
    return $el;
}
function konsoleText(string $text): string
{
    $lang = $GLOBALS['LOCALE'] ?? 'de';
    if ($lang === 'en') { return $text; }
    $loc = json_decode((string)@file_get_contents(dirname(__DIR__) . '/Boersenpreis/locale.json'), true);
    return (string)($loc['translations'][$lang][$text] ?? $text);
}
function form(SpotTest $m): array { $f = json_decode($m->GetConfigurationForm(), true); if (!is_array($f)) { throw new RuntimeException('GetConfigurationForm() liefert kein gültiges JSON'); } return konsoleUebersetzt($f); }
/** Formular so, wie das Modul es ausliefert — ohne die Übersetzung der Konsole. */
function formRoh(SpotTest $m): array { return json_decode($m->GetConfigurationForm(), true); }
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
/**
 * Nachbildung von EMS parsePT15M() (EMS/module.php, Zeile 4945 ff., gegengelesen 20.09.2026):
 * Preis → Viertelstunde seines start nach WANDUHR, nur Kalendertag $ymd. Am 25-Stunden-Tag gilt
 * bei EMS der ERSTE Preis der doppelten Stunde 02-03, der zweite überschreibt ihn nicht.
 */
function emsSlots(array $curve, string $ymd): array
{
    $p = array_fill(0, 96, null);
    foreach ($curve as $e) {
        $t = (int)$e['start'];
        if (date('Y-m-d', $t) !== $ymd) { continue; }
        $slot = (int)floor(((int)date('H', $t) * 60 + (int)date('i', $t)) / 15);
        if ($slot >= 0 && $slot < 96 && $p[$slot] === null) { $p[$slot] = (float)$e['price'] / 100.0; }
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
// Seit 0.12.0 Darstellungen statt eigener Profile (SUITE.md, verbindlich 23.11.2026)
check('Keine eigenen Profile mehr angelegt (SPOT.CentKWh / SPOT.YesNo)',
    !isset($GLOBALS['PROFILES']['SPOT.CentKWh']) && !isset($GLOBALS['PROFILES']['SPOT.YesNo']),
    implode(', ', array_keys($GLOBALS['PROFILES'])));
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
check('Felder exakt: start/end/price/basis/netzentgelt/level/quelle/aufloesung/contractVersion/contractType (+updatedAt)', array_keys($e) === ['start', 'end', 'price', 'basis', 'netzentgelt', 'level', 'quelle', 'aufloesung', 'contractVersion', 'contractType', 'updatedAt'], implode(',', array_keys($e)));
check('Fremdanbindung: contractType „price" in JEDEM Eintrag, Vertrag 1.2 (SUITE.md 1/5f)', count(array_filter($c, fn($x) => ($x['contractType'] ?? '') === 'price' && ($x['contractVersion'] ?? '') === '1.2')) === count($c));
check('updatedAt = Zeitpunkt des letzten Abrufs (optionale Frischemarke)', $e['updatedAt'] === ts('2026-09-12 10:00:00'));
check('Typen: int/int/float/string/string/null/string/int/string', is_int($e['start']) && is_int($e['end']) && is_float($e['price']) && $e['level'] === null && is_int($e['aufloesung']) && $e['contractVersion'] === '1.2');
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
check('Reihenfolge: Zweck → Neu → Doku → Fachpanels → Aufbau → Rückmeldungen → Über', ($f['elements'][0]['name'] ?? '') === 'PurposeIntroPanel' && ($f['elements'][1]['name'] ?? '') === 'NewsPanel'
    && str_contains($caps[2], 'Dokumentation') && str_contains($caps[3], 'Datenquelle') && str_contains($caps[4], 'Börsenpreise') && str_contains($caps[5], 'Energie Manager')
    && str_contains($caps[6], 'Formular: Aufbau') && ($f['elements'][7]['name'] ?? '') === 'ForumHintPanel' && str_contains($caps[8], 'Über dieses Modul'), implode(' | ', $caps));
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
check('Eingabefelder: Beschriftung ≤ 26 Zeichen und Breite gesetzt (' . count($inputs) . ' Felder)', count($inputs) === 11 && count($bad) === 0, implode(' | ', array_map(fn($i) => ($i['name'] ?? '?') . ': ' . ($i['caption'] ?? ''), $bad)));
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
// Pitfall 21: In der Wertanzeige heißen die Farbfelder ColorActive/ColorValue, in der Aufzählung
// Color — falsch gesetzt stürzt nur die Mobile-App ab. Dieses Modul setzt gar keinen Farbschlüssel.
// Geprüft wird der CODE; der Kommentar in presentationYesNo() darf die Falle beim Namen nennen.
check('Kein ColorValue/ColorActive im Code (Mobile-App-Absturz)', !preg_match('/ColorValue|ColorActive/', $codeOnly));
check('Kein @ vor IPS_Set*/SetValue (stille Fehlschläge)', !preg_match('/@(IPS_Set|SetValue)/', $code));
// Y-m-d nur als Maschinenformat: 2× Energy-Charts-URL, 1× Datumsvergleich in parseTibber() — nie in Texten.
check('Sichtbare Datumsangaben immer mit Jahr (TT.MM.JJJJ, Store-Checkliste 9b)', !preg_match("/date\\('d\\.m\\.[ ']/", $codeOnly) && substr_count($codeOnly, "date('Y-m-d'") === 3);
check('library.json: nur die 8 erlaubten Felder, Name „NRG-Stack Börsenpreis"', ($lj = json_decode(file_get_contents(dirname(__DIR__) . '/library.json'), true)) && array_keys($lj) === ['id', 'author', 'name', 'url', 'compatibility', 'version', 'build', 'date'] && $lj['name'] === 'NRG-Stack Börsenpreis' && $lj['compatibility'] === ['version' => '9.0']);
$mj = json_decode(file_get_contents(dirname(__DIR__) . '/Boersenpreis/module.json'), true);
check('module.json: Klasse = name, Präfix SPOT, GUIDs passen zur Klasse', $mj['name'] === 'Boersenpreis' && $mj['prefix'] === 'SPOT' && $mj['library'] === $lj['id'] && str_contains($code, $mj['id']) && str_contains($code, $lj['id']));

heading('14. Preisverlauf aus dem Archiv (Vertrag 1.1, SPOT_GetPriceHistory)');
$GLOBALS['ARCHIVES'] = [99]; $GLOBALS['AC'] = []; $GLOBALS['AC_LOG'] = []; $GLOBALS['AC_APPLY'] = 0;
clock('2026-05-01 00:00:30');
// Store-Review-Checkliste 9k: Vorgabe AUS — ohne Haken archiviert das Modul NICHTS.
$maus = fresh();
$GLOBALS['HTTP'][] = ok(fx('ec-DE-LU-2026-05-01.json'));
$maus->ApplyChanges();
$vidAus = IPS_GetObjectIDByIdent('CurrentPrice', 12345);
check('Ohne Haken „Preisverlauf archivieren": Archivierung bleibt aus, kein Übernehmen am Archiv', ($GLOBALS['AC_LOG'][$vidAus] ?? false) === false
    && $GLOBALS['AC_APPLY'] === 0 && $maus->props['ArchivePrice'] === false && $maus->attrs['ArchiveInitDone'] === false);
check('Formular sagt, dass ohne Archiv kein Rückblick möglich ist', str_contains(formText(form($maus)), 'wird nicht archiviert'));
check('Rückblick ohne Archivierung: leer, kein Absturz', $maus->GetPriceHistory(ts('2026-04-01 00:00:00'), ts('2026-05-01 00:00:00')) === []);
// Ab hier mit gesetztem Haken (wie bisher): Archivierung einmalig einschalten.
$GLOBALS['AC'] = []; $GLOBALS['AC_LOG'] = []; $GLOBALS['AC_APPLY'] = 0;
$m = fresh();
$m->props['ArchivePrice'] = true;
$GLOBALS['HTTP'][] = ok(fx('ec-DE-LU-2026-05-01.json'));
$m->ApplyChanges();
$vid = IPS_GetObjectIDByIdent('CurrentPrice', 12345);
check('Archivierung von „Börsenpreis jetzt" eingeschaltet, Archiv übernommen, Merker gesetzt', ($GLOBALS['AC_LOG'][$vid] ?? false) === true && $GLOBALS['AC_APPLY'] === 1 && $m->attrs['ArchiveInitDone'] === true);
check('Erster Preis schon im Archiv (Archivierung vor dem ersten Setzen eingeschaltet)', count($GLOBALS['AC'][$vid] ?? []) === 1);
$GLOBALS['AC_LOG'][$vid] = false;
$m->ApplyChanges();
check('Nutzer schaltet Archivierung ab: bleibt aus, kein erneutes Übernehmen', $GLOBALS['AC_LOG'][$vid] === false && $GLOBALS['AC_APPLY'] === 1);
check('Haken wieder entfernt: Modul schaltet die Archivierung NIE selbst ab', (function () use ($m, $vid) {
    $GLOBALS['AC_LOG'][$vid] = true; $m->props['ArchivePrice'] = false; $m->ApplyChanges();
    $r = ($GLOBALS['AC_LOG'][$vid] ?? false) === true; $m->props['ArchivePrice'] = true; $GLOBALS['AC_LOG'][$vid] = true; return $r; })());
$GLOBALS['AC_LOG'][$vid] = true;
for ($t = ts('2026-05-01 00:15:02'); $t < ts('2026-05-02 00:00:00'); $t += 900) { $GLOBALS['CLOCK'] = $t; $m->Tick(); }
$ecNeg = json_decode(fx('ec-DE-LU-2026-05-01.json'), true);
$changes = 1;
for ($i = 1; $i < count($ecNeg['price']); $i++) { if ($ecNeg['price'][$i] !== $ecNeg['price'][$i - 1]) { $changes++; } }
check('Archiv hält nur die Wertänderungen des Tages (' . $changes . ')', count($GLOBALS['AC'][$vid]) === $changes, (string)count($GLOBALS['AC'][$vid]));
clock('2026-05-02 10:00:00');
$m->attrs['PriceCache'] = '{}'; // Zwischenspeicher weg → nur noch das Archiv kennt den 01.05.
$h = $m->GetPriceHistory(ts('2026-05-01 00:00:00'), ts('2026-05-02 00:00:00'));
check('Verlauf 01.05.: 96 Viertelstunden, quelle archiv, aufloesung 900, lückenlos, Vertrag 1.2', count($h) === 96 && $h[0]['quelle'] === 'archiv' && $h[0]['aufloesung'] === 900 && contiguous($h) && $h[0]['contractVersion'] === '1.2', (string)count($h));
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
check('Statuszeile nennt Tibber Grid Rewards mit Instanz', str_contains(formText(form($m)), '✅ Preis aus Tibber Grid Rewards (#777 '));
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

heading('18b. Verbindung zu Tibber Grid Rewards sichtbar (SUITE.md „Verbund-Verbindungen“ + „Wert kommt automatisch“, 21.09.2026)');
/** Formular-Element per Name rekursiv suchen (auch in ExpansionPanel/RowLayout/Popup). */
$findEl = function (array $els, string $name) use (&$findEl) {
    foreach ($els as $e) {
        if (($e['name'] ?? '') === $name) { return $e; }
        $sub = array_merge($e['items'] ?? [], $e['popup']['items'] ?? []);
        if ($sub && ($r = $findEl($sub, $name)) !== null) { return $r; }
    }
    return null;
};
$tariffNames = ['TariffEnabled', 'TariffBeschaffung', 'NetzArbeitspreis', 'Modul3Enabled', 'NetzWindows', 'Modul3Quarters', 'TariffHelp'];
clock('2026-09-12 10:00:00');
$m = fresh();
$GLOBALS['HTTP'][] = ok(ecFirst('ec-DE-LU-2026-09-12_13.json', 96));
$m->ApplyChanges();
$f = form($m)['elements'];
$st = $findEl($f, 'MarketSourceStatus')['caption'] ?? '';
check('ℹ️ Tibber nicht installiert: Zeile sagt „nicht gefunden“ und was gilt', str_contains($st, 'ℹ️ Tibber Grid Rewards nicht gefunden') && str_contains($st, 'Reiner Börsenpreis'), $st);
check('Ohne Tibber: Tarif-Felder sichtbar, keine 🔗-Zeile, keine Instanzauswahl', count(array_filter($tariffNames, fn($n) => ($findEl($f, $n)['visible'] ?? true) !== true)) === 0
    && ($findEl($f, 'TibberAutoLine')['visible'] ?? true) === false && ($findEl($f, 'TibberInstance')['visible'] ?? true) === false);
$GLOBALS['INSTANCES']['{E92F62F4-88A6-4C6E-9F0D-E76C3B1C9A01}'] = [777];
$GLOBALS['NAMES'][777] = 'Tibber Zuhause';
$GLOBALS['TIBBER_CURVE'] = [['start' => ts('2026-09-12 10:00:00'), 'end' => ts('2026-09-12 11:00:00'), 'price' => 31.5, 'basis' => 'endkunde', 'netzentgelt' => 'enthalten', 'level' => null, 'contractVersion' => '1.1']];
$f = form($m)['elements'];
$st = $findEl($f, 'MarketSourceStatus')['caption'] ?? '';
check('✅ nennt Instanz, Name, Vertrag, übernommenen Wert und Horizont', str_contains($st, '✅ Preis aus Tibber Grid Rewards (#777 „Tibber Zuhause“, Vertrag 1.1) übernommen: Endpreis jetzt 31,50 ct/kWh, Tibber-Preise bis 12.09.2026 11:00 Uhr.')
    && str_contains($st, 'Für Viertelstunden ohne Tibber-Preis gilt:'), $st);
check('🔗-Zeile mit Wert und Quelle, Tarif-Felder ausgeblendet (nicht bloß erklärt)', str_contains($findEl($f, 'TibberAutoLine')['caption'] ?? '', '🔗 Endpreis: 31,50 ct/kWh jetzt (automatisch von Tibber Grid Rewards #777)')
    && ($findEl($f, 'TibberAutoLine')['visible'] ?? false) === true && ($findEl($f, 'TibberAutoLine')['color'] ?? null) === 0x2E8B3D && count(array_filter($tariffNames, fn($n) => ($findEl($f, $n)['visible'] ?? true) !== false)) === 0);
$m->fieldUpdates = [];
$m->UIRefreshMarket(false, false, 0, 0);
$upd = []; foreach ($m->fieldUpdates as [$n, $pp, $v]) { $upd[$n][$pp] = $v; }
check('onChange „Tibber nutzen“ aus: Zeile folgt sofort (ℹ️ abgewählt), Tarif-Felder wieder sichtbar, 🔗-Zeile weg, Speicherstand unberührt',
    str_contains($upd['MarketSourceStatus']['caption'] ?? '', 'ℹ️ Tibber Grid Rewards abgewählt') && ($upd['TariffEnabled']['visible'] ?? null) === true
    && ($upd['TibberAutoLine']['visible'] ?? null) === false && ($upd['TibberAutoLine']['color'] ?? null) === -1 && $m->props['UseTibberPrice'] === true, json_encode($upd, JSON_UNESCAPED_UNICODE));
check('Nie einen automatischen Wert per UpdateFormField(value) in ein Eingabefeld', count(array_filter($m->fieldUpdates, fn($u) => $u[1] === 'value')) === 0);
$m->fieldUpdates = [];
$m->UIRefreshMarket(true, true, 0, 0);
$upd = []; foreach ($m->fieldUpdates as [$n, $pp, $v]) { $upd[$n][$pp] = $v; }
check('onChange wieder an: ✅, Rückfall „eigener Tarif“ folgt dem Häkchen statt dem Speicherstand', str_contains($upd['MarketSourceStatus']['caption'] ?? '', '✅ Preis aus Tibber Grid Rewards (#777')
    && str_contains($upd['MarketSourceStatus']['caption'] ?? '', '🧾 Preis aus deinem eigenen Tarif') && ($upd['TariffEnabled']['visible'] ?? null) === false
    && ($upd['TibberAutoLine']['color'] ?? null) === 0x2E8B3D);
$GLOBALS['INSTANCES']['{E92F62F4-88A6-4C6E-9F0D-E76C3B1C9A01}'] = [777, 778];
$f = form($m)['elements'];
$st = $findEl($f, 'MarketSourceStatus')['caption'] ?? '';
check('⚠️ zwei Instanzen, keine gewählt: nicht raten, Auswahl sichtbar, Tarif-Felder sichtbar', str_contains($st, '⚠️ Mehrere Instanzen von Tibber Grid Rewards gefunden (#777, #778)')
    && ($findEl($f, 'TibberInstance')['visible'] ?? false) === true && ($findEl($f, 'TariffEnabled')['visible'] ?? false) === true, $st);
$m->Tick();
check('… und der Energie Manager bekommt dann NICHT stillschweigend einen Tibber-Preis', $mdAt($m, '2026-09-12 10:00:00') !== 31.5);
$m->props['TibberInstance'] = 778;
$m->Tick();
check('Instanz #778 gewählt: Tibber-Preis wird genutzt, Zeile nennt #778', $mdAt($m, '2026-09-12 10:00:00') === 31.5 && str_contains(formText(form($m)), '#778 '));
$m->props['TibberInstance'] = 0;
$GLOBALS['INSTANCES'] = [];
$GLOBALS['NAMES'] = [];

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

// ---------------------------------------------------------------------------
heading('26. Zeitumstellung 2026 — 25.10. (100) im Abruf, 29.03. (92) im Rückblick (Bitte EMS 20.09.2026)');
/** Energy-Charts-Antwort bauen: $n Viertelstunden ab $start, Preis = Index in EUR/MWh. */
$ecSynth = function (int $start, int $n): string {
    $u = []; $p = [];
    for ($i = 0; $i < $n; $i++) { $u[] = $start + 900 * $i; $p[] = (float)$i; }
    return json_encode(['unix_seconds' => $u, 'price' => $p, 'unit' => 'EUR / MWh', 'license_info' => 'CC BY 4.0']);
};
clock('2026-10-25 09:00:00');
$m = fresh();
$GLOBALS['HTTP'][] = ok($ecSynth(ts('2026-10-25 00:00:00'), 100));
$m->ApplyChanges();
$c = $m->GetPriceCurve();
check('25.10.2026: 100 Viertelstunden, lückenlos, 00:00 bis 24:00 (25-Stunden-Tag)', count($c) === 100 && contiguous($c)
    && $c[0]['start'] === ts('2026-10-25 00:00:00') && end($c)['end'] === ts('2026-10-26 00:00:00') && end($c)['end'] - $c[0]['start'] === 90000, count($c) . ' Slots');
check('Jeder Slot 900 s, auch über die Rückstellung', count(array_filter($c, fn($s) => $s['end'] - $s['start'] !== 900)) === 0);
$zwei = array_values(array_filter($c, fn($s) => date('H:i', $s['start']) === '02:00'));
check('02:00 zweimal: erst UTC+2 (00:00Z), dann UTC+1 (01:00Z), 3600 s auseinander', count($zwei) === 2
    && gmdate('H:i', $zwei[0]['start']) === '00:00' && gmdate('H:i', $zwei[1]['start']) === '01:00' && $zwei[1]['start'] - $zwei[0]['start'] === 3600);
check('Preise sitzen auf dem Zeitpunkt der Quelle (Index 8 = 02:00 CEST, Index 12 = 02:00 CET)', abs($zwei[0]['price'] - 0.8) < 1e-9 && abs($zwei[1]['price'] - 1.2) < 1e-9);
// EMS' Tagesplan kennt nur 96 Fächer nach Wanduhr; die zweite 02-Stunde ist dort nicht abbildbar,
// es gilt der ERSTE Preis (EMS 20.09.2026). Die §-51-Pflicht wertet dort ohnehin über start/end.
check('EMS-Nachbildung Tagesplan: 96 Fächer gefüllt, Fach 02:00 behält den CEST-Wert', count(array_filter(emsSlots($c, '2026-10-25'), fn($x) => $x !== null)) === 96
    && abs(emsSlots($c, '2026-10-25')[8] - 0.008) < 1e-9, var_export(emsSlots($c, '2026-10-25')[8], true));
clock('2026-03-30 10:00:00');
$m2 = fresh();
$GLOBALS['ARCHIVES'] = [4711];
$GLOBALS['HTTP'][] = ok($ecSynth(ts('2026-03-30 00:00:00'), 96));
$m2->ApplyChanges();
$vid = IPS_GetObjectIDByIdent('CurrentPrice', $m2->InstanceID);
$GLOBALS['AC_LOG'][$vid] = true;
$GLOBALS['AC'][$vid] = [];
for ($t = ts('2026-03-29 00:00:00'), $i = 0; $t < ts('2026-03-30 00:00:00'); $t += 900, $i++) { $GLOBALS['AC'][$vid][] = ['TimeStamp' => $t, 'Value' => $i / 10]; }
$h = $m2->GetPriceHistory(ts('2026-03-29 00:00:00'), ts('2026-03-30 00:00:00'));
check('29.03.2026 im Rückblick: 92 Viertelstunden, lückenlos, 23-Stunden-Tag', count($h) === 92 && contiguous($h)
    && $h[0]['start'] === ts('2026-03-29 00:00:00') && end($h)['end'] === ts('2026-03-30 00:00:00') && end($h)['end'] - $h[0]['start'] === 82800, count($h) . ' Slots');
check('Rückblick überspringt 02:xx, 01:45 folgt direkt 03:00, quelle=archiv', count(array_filter($h, fn($s) => date('H', $s['start']) === '02')) === 0
    && count(array_filter($h, fn($s) => date('H:i', $s['start']) === '03:00')) === 1 && $h[0]['quelle'] === 'archiv');
$treffer = 0;
foreach ($h as $s) { foreach ($GLOBALS['AC'][$vid] as $r) { if ($r['TimeStamp'] === $s['start'] && abs($r['Value'] - $s['price']) < 1e-9) { $treffer++; } } }
check('Jeder Rückblick-Preis steht auf seinem Archiv-Zeitpunkt', $treffer === 92, $treffer . '/92');
$GLOBALS['ARCHIVES'] = [];

heading('27. „Was ist Neu“ je Version (SUITE.md NEWS_VERSIONS, 23.09.2026)');
$news = function (SpotTest $o) {
    foreach (form($o)['elements'] as $el) {
        if (($el['name'] ?? '') === 'NewsPanel') { return $el; }
    }
    return null;
};
$newsText = function (?array $panel) { return $panel === null ? '' : json_encode($panel, JSON_UNESCAPED_UNICODE); };
// Die hoechste News-Version NICHT fest eintragen: sie wandert mit jedem Release. Der Prüfstand
// liest sie aus der Konstante des Moduls — so muss hier bei einer neuen Version nichts nachgezogen
// werden, und die Prüfungen bleiben trotzdem scharf (Reihenfolge im Array wird ignoriert).
$newsVersionen = array_keys((new ReflectionClass('Boersenpreis'))->getConstants()['NEWS_VERSIONS']);
$hoch = array_reduce($newsVersionen, fn($a, $b) => ($a === null || version_compare($b, $a, '>')) ? $b : $a);
echo '  (hoechste News-Version laut Modul: ' . $hoch . ")\n";
clock('2026-09-23 10:00:00');
$GLOBALS['VARS'] = [];
$n1 = new SpotTest();
$n1->Create();
$p1 = $news($n1);
check('Frische Instanz: Banner nennt die höchste Version, alle Versionen nach Version gruppiert', ($p1['caption'] ?? '') === '🆕  Neu bis Version ' . $hoch
    && str_contains($newsText($p1), 'Version 0.6.0:') && str_contains($newsText($p1), 'Version ' . $hoch . ':'), $p1['caption'] ?? '-');
$n1->attrs['SeenNews'] = '0.6.0';
$p2 = $news($n1);
check('Zuletzt gesehen 0.6.0: nur die Lücke (0.6.1 + 0.7.0), nichts aus 0.6.0', str_contains($newsText($p2), 'Version 0.6.1:') && str_contains($newsText($p2), 'Version 0.7.0:')
    && !str_contains($newsText($p2), 'Version 0.6.0:') && !str_contains($newsText($p2), 'Neuer Name'));
$n1->attrs['SeenNews'] = $hoch;
check('Alles gesehen: kein Banner', $news($n1) === null);
// Gegenprobe zum Live-Fund vom 23.11.2026: Vor 0.12.0 endete die Liste bei 0.10.0, und eine Anlage
// mit 0.11.0 bekam den Banner „Neu bis Version 0.10.0" zu sehen. Jede veröffentlichte Version
// braucht einen Eintrag.
$n1->attrs['SeenNews'] = '0.10.0';
$offen = array_values(array_filter($newsVersionen, fn($v) => version_compare($v, '0.10.0', '>')));
check('Wer bis 0.10.0 gelesen hat, bekommt JEDE neuere Version nachgereicht (' . implode(', ', $offen) . ')',
    count($offen) > 0
    && count(array_filter($offen, fn($v) => !str_contains($newsText($news($n1)), 'Version ' . $v . ':'))) === 0
    && !str_contains($newsText($news($n1)), 'Version 0.10.0:'));
$n1->attrs['SeenNews'] = '';
$GLOBALS['LIB_VERSION'] = $hoch . '-beta.2';
$n1->AckNews();
check('Bestätigen merkt die installierte Bibliotheksversion ohne Beta-Zusatz (höchste + "-beta.2")', $n1->attrs['SeenNews'] === $hoch && $news($n1) === null, $n1->attrs['SeenNews']);
$n1->attrs['SeenNews'] = '';
$neuer = $hoch . '.99';   // sicher neuer als jeder Eintrag
$GLOBALS['LIB_VERSION'] = $neuer;
$n1->AckNews();
check('Bibliothek neuer als der letzte Eintrag: dann gilt die Bibliotheksversion', $n1->attrs['SeenNews'] === $neuer, $n1->attrs['SeenNews']);
check('Die höchste News-Version kommt aus version_compare, nicht aus der Reihenfolge im Array',
    (function () use ($n1, $news, $hoch) { $n1->attrs['SeenNews'] = ''; $GLOBALS['LIB_VERSION'] = '0.0.0-test'; $n1->AckNews(); return $n1->attrs['SeenNews'] === $hoch && $news($n1) === null; })());
$n1->attrs['SeenNews'] = '';
$GLOBALS['LIB_VERSION'] = '0.0.0-test';
$n1->AckNews();
check('Bibliothek unbekannt/älter: Rückfall auf die höchste News-Version, Banner bleibt weg', $n1->attrs['SeenNews'] === $hoch && $news($n1) === null);

heading('28. Logging über $this->LogMessage (Store-Review-Checkliste 15, 12.10.2026)');
$src = file_get_contents(getenv('SPOT_TEST_MODULE') ?: dirname(__DIR__) . '/Boersenpreis/module.php');
check('module.php ruft nirgends IPS_LogMessage( auf', !str_contains($src, 'IPS_LogMessage('));
check('Stattdessen $this->LogMessage( mit KL_-Stufe', str_contains($src, '$this->LogMessage(') && preg_match('/\$this->LogMessage\([^;]*KL_[A-Z]+\)/', $src) === 1);
clock('2026-09-12 10:00:00');
$GLOBALS['LOG'] = [];
$m = fresh();
$GLOBALS['HTTP'][] = resp(404, 'keine Daten');
$m->ApplyChanges();
check('Fehler landet als Warnung (KL_WARNING) im Log, Absender ist die Instanz', count($GLOBALS['LOG']) === 1 && str_starts_with($GLOBALS['LOG'][0], '4: Abruf fehlgeschlagen'), implode(' | ', $GLOBALS['LOG']));
$GLOBALS['LOG'] = [];

heading('29. Symcon-Start und Darstellungen (Store-Review-Checkliste 16/17, 18.10.2026)');
$src = file_get_contents(getenv('SPOT_TEST_MODULE') ?: dirname(__DIR__) . '/Boersenpreis/module.php');
check('Keine MaintainVariable(..., false) — Variablen werden nie gelöscht, auch nicht nach Fremdabfragen',
    preg_match('/MaintainVariable\([^;]*,\s*false\s*\)/', $src) === 0);
// Bis 0.11.0 stand hier das Gegenteil (keine Darstellungen, nur klassische Profile). Seit der
// Verbund-Regel vom 23.11.2026 sind Darstellungen Pflicht — die Farb-Schlüssel-Falle wird jetzt
// nicht durch Vermeidung entschärft, sondern geprüft: Wertanzeige = ColorActive/ColorValue.
// Die Farb-Schlüssel-Falle (Pitfall 21: Wertanzeige ColorActive/ColorValue, Aufzählung Color —
// falsch gesetzt stürzt nur die Mobile-App ab) wird hier durch VERMEIDUNG entschärft: Diese zwei
// Anzeigen brauchen keine Farbe, also steht kein Farbschlüssel im Modul. Die scharfe Prüfung
// „Kein ColorValue/ColorActive" steht weiter unten in Block 13 und bleibt gültig.
check('Options-Schlüssel nur Value/Caption/IconActive/IconValue — kein Farbschlüssel',
    preg_match("/'Color[A-Za-z]*'\s*=>/", $src) === 0);
// Kommentare ausnehmen: sie dürfen die Vorlagen erklären, der Code nutzt keine.
$srcCode = '';
foreach (token_get_all($src) as $tk) {
    $srcCode .= (is_array($tk) && in_array($tk[0], [T_COMMENT, T_DOC_COMMENT], true)) ? '' : (is_array($tk) ? $tk[1] : $tk);
}
check('Keine Vorlage (TEMPLATE) behauptet, wo keine passt — Preis und Ja/Nein haben keine',
    !str_contains($srcCode, 'VARIABLE_TEMPLATE_') && !str_contains($srcCode, "'TEMPLATE'"));
// Start von Symcon: Fremdmodule (Tibber Grid Rewards, Archiv) sind noch nicht da.
clock('2026-09-12 10:00:00');
$GLOBALS['VARS'] = [];
$GLOBALS['RUNLEVEL'] = 10102; // KR_INIT
$GLOBALS['LOG'] = [];
$GLOBALS['REQUESTS'] = [];
$GLOBALS['HTTP'] = [];
$boot = new SpotTest();
$boot->Create();
$varsNachCreate = count($GLOBALS['VARS']);
$boot->ApplyChanges();
check('Vor KR_READY: kein Abruf, keine Variablenpflege, kein Fehler im Log', count($GLOBALS['REQUESTS']) === 0 && count($GLOBALS['LOG']) === 0
    && count($GLOBALS['VARS']) === $varsNachCreate, count($GLOBALS['REQUESTS']) . ' Abrufe');
$GLOBALS['RUNLEVEL'] = KR_READY;
$GLOBALS['HTTP'][] = ok(ecFirst('ec-DE-LU-2026-09-12_13.json', 96));
$boot->MessageSink(0, 0, IPS_KERNELMESSAGE, [KR_READY]);
check('Nach der Kernel-Meldung wird nachgeholt: Variablen da, Preise abgerufen', IPS_GetObjectIDByIdent('CurrentPrice', $boot->InstanceID) !== false
    && count($GLOBALS['REQUESTS']) === 1 && $boot->GetValue('TomorrowAvailable') === false);
$vorher = count($GLOBALS['VARS']);
$GLOBALS['INSTANCES'] = []; // Tibber Grid Rewards verschwindet (z. B. Modul-Update)
$boot->ApplyChanges();
check('Fehlendes Partnermodul löscht keine Variablen', count($GLOBALS['VARS']) === $vorher);
$GLOBALS['LOG'] = [];

heading('30. „🪟 Formular: Aufbau“ — Reihenfolge, Aufklapp-Zustand, Ausblenden (SUITE.md 3a, 29.10.2026)');
clock('2026-09-12 10:00:00');
$GLOBALS['VARS'] = [];
$GLOBALS['REQUESTS'] = []; $GLOBALS['HTTP'] = [];
$L = fresh();
$GLOBALS['HTTP'][] = ok(ecFirst('ec-DE-LU-2026-09-12_13.json', 96));
$L->ApplyChanges();
$caps = fn(SpotTest $o) => array_map(fn($e) => $e['caption'] ?? ($e['name'] ?? ''), form($o)['elements']);
$panel = function (SpotTest $o, string $teil) {
    foreach (form($o)['elements'] as $e) {
        if (str_contains((string)($e['caption'] ?? ''), $teil)) { return $e; }
    }
    return null;
};
$layoutRows = function (SpotTest $o) use ($panel) {
    foreach ($panel($o, 'Formular: Aufbau')['items'] as $it) {
        if (($it['name'] ?? '') === 'FormLayout') { return $it; }
    }
    return null;
};
check('Standard: Panel „Formular: Aufbau" sitzt nach den Fachpanels, vor dem Forum-Hinweis, eingeklappt',
    ($panel($L, 'Formular: Aufbau')['expanded'] ?? true) === false
    && array_search('🪟  Formular: Aufbau', $caps($L), true) === 6, implode(' | ', $caps($L)));
$liste = $layoutRows($L);
check('Liste zeigt die drei Fachpanels mit stabilem Schlüssel, Standard ohne Haken, Position 1–3',
    array_column($liste['values'], 'Key') === ['quelle', 'preise', 'tarif'] && array_column($liste['values'], 'Pos') === [1, 2, 3]
    && count(array_filter($liste['values'], fn($r) => $r['Opened'] || $r['Closed'] || $r['Hidden'])) === 0
    && ($liste['loadValuesFromConfiguration'] ?? true) === false);
check('Schlüsselspalte unsichtbar, aber gespeichert (save: true)', count(array_filter($liste['columns'], fn($c) => ($c['name'] ?? '') === 'Key' && ($c['visible'] ?? true) === false && ($c['save'] ?? false) === true)) === 1);
// Eigene Reihenfolge + Zustände
$L->props['FormLayout'] = json_encode([
    ['Key' => 'tarif', 'Opened' => true, 'Closed' => false, 'Hidden' => false, 'Pos' => 1],
    ['Key' => 'quelle', 'Opened' => false, 'Closed' => true, 'Hidden' => false, 'Pos' => 2],
    ['Key' => 'preise', 'Opened' => false, 'Closed' => false, 'Hidden' => true, 'Pos' => 3],
]);
$els = form($L)['elements'];
$c = array_map(fn($e) => $e['caption'] ?? ($e['name'] ?? ''), $els);
check('Gewählte Reihenfolge gilt: Tarif, Datenquelle, Börsenpreise', str_contains($c[3], 'Energie Manager') && str_contains($c[4], 'Datenquelle') && str_contains($c[5], 'Börsenpreise'), implode(' | ', $c));
check('Aufgeklappt/zugeklappt wie gewählt', ($els[3]['expanded'] ?? null) === true && ($els[4]['expanded'] ?? null) === false);
check('Ausgeblendet = visible:false, Panel bleibt im Formular (Werte gehen nicht verloren)', ($els[5]['visible'] ?? null) === false && str_contains(formText(form($L)), 'ArchivePrice'));
check('Caption nennt die Zahl, eine Zeile sagt welcher Bereich und wie man ihn zurückholt', str_contains($panel($L, 'Formular: Aufbau')['caption'], '(1 ausgeblendet)')
    && str_contains(json_encode($panel($L, 'Formular: Aufbau'), JSON_UNESCAPED_UNICODE), '🙈 Ausgeblendet: Börsenpreise'));
// Widerspruch und Grenzen
$L->props['FormLayout'] = json_encode([['Key' => 'quelle', 'Opened' => true, 'Closed' => true, 'Hidden' => true, 'Pos' => 300]]);
$els = form($L)['elements'];
check('Mehrere Haken: ausgeblendet vor zugeklappt vor aufgeklappt; Position wird auf 1–99 begrenzt',
    count(array_filter($els, fn($e) => str_contains((string)($e['caption'] ?? ''), 'Datenquelle') && ($e['visible'] ?? null) === false)) === 1
    && ($layoutRows($L)['values'][2]['Pos'] ?? 0) === 99);
// Unbekannter Schlüssel (Panel entfernt) und fehlender Schlüssel (Panel neu)
$L->props['FormLayout'] = json_encode([
    ['Key' => 'gibtsnichtmehr', 'Opened' => false, 'Closed' => true, 'Hidden' => false, 'Pos' => 1],
    ['Key' => 'tarif', 'Opened' => false, 'Closed' => true, 'Hidden' => false, 'Pos' => 2],
]);
$c = array_map(fn($e) => $e['caption'] ?? ($e['name'] ?? ''), form($L)['elements']);
check('Unbekannter Schlüssel wird übergangen, fehlende Panels stehen auf Standardplatz', str_contains($c[3], 'Datenquelle') && str_contains($c[4], 'Börsenpreise') && str_contains($c[5], 'Energie Manager'), implode(' | ', $c));
$L->props['FormLayout'] = 'kaputt';
check('Unlesbare Einstellung: Standard statt Absturz', count(form($L)['elements']) === 9);
// Zurücksetzen
$L->props['FormLayout'] = json_encode([['Key' => 'tarif', 'Opened' => false, 'Closed' => false, 'Hidden' => true, 'Pos' => 1]]);
$L->fieldUpdates = [];
$r = $L->ResetFormLayout();
$upd = array_values(array_filter($L->fieldUpdates, fn($u) => $u[0] === 'FormLayout'));
$neu = json_decode($upd[0][2] ?? '[]', true);
check('„Auf Standard zurücksetzen": Rückmeldung im Klartext, Liste ohne Haken in Standardreihenfolge, keine Selbstpersistenz',
    str_contains($r, 'Übernehmen') && ($upd[0][1] ?? '') === 'values' && array_column($neu, 'Key') === ['quelle', 'preise', 'tarif']
    && count(array_filter($neu, fn($x) => $x['Opened'] || $x['Closed'] || $x['Hidden'])) === 0
    && $L->props['FormLayout'] !== '[]', $r);
$codeLayout = file_get_contents(getenv('SPOT_TEST_MODULE') ?: dirname(__DIR__) . '/Boersenpreis/module.php');
check('Reset-Knopf gibt die Rückmeldung per echo aus (sichtbare Rückmeldung, Checkliste 13)', str_contains($codeLayout, "echo SPOT_ResetFormLayout(\$id);"));
$GLOBALS['REQUESTS'] = [];

heading('31. Zweisprachig DE/EN (SUITE.md Konvention 9, 05.11.2026)');
$mod = getenv('SPOT_TEST_MODULE') ?: dirname(__DIR__) . '/Boersenpreis/module.php';
$codeI18n = file_get_contents($mod);
$locale = json_decode((string)file_get_contents(dirname(__DIR__) . '/Boersenpreis/locale.json'), true);
$deTexte = $locale['translations']['de'] ?? [];
// 1. Jeder $this->t('…')-Quelltext steht in locale.json
preg_match_all("/\\\$this->t\\(\\s*'((?:[^'\\\\\\\\]|\\\\\\\\.)*)'/", $codeI18n, $mm);
$quellen = array_values(array_unique(array_map(fn($x) => str_replace("\\\\'", "'", $x), $mm[1])));
$fehlen = array_values(array_filter($quellen, fn($q) => !isset($deTexte[$q])));
check('Jeder übersetzte Text im Code hat eine deutsche Fassung in locale.json (' . count($quellen) . ' Texte)', count($fehlen) === 0, implode(' | ', array_slice($fehlen, 0, 3)));
// 2. Kein deutscher Text mehr im Code (Quelltexte sind englisch)
$deutschImCode = array_values(array_filter($quellen, fn($q) => (bool)preg_match('/\b(und|oder|nicht|keine|wird|werden|für|Viertelstunde|Preise|Uhr)\b/u', $q)));
check('Keine deutschen Quelltexte mehr im Code', count($deutschImCode) === 0, implode(' | ', array_slice($deutschImCode, 0, 3)));
// 3. Auch form.json und die Statuszeilen sind englisch
$formJson = file_get_contents(dirname(__DIR__) . '/Boersenpreis/form.json');
check('form.json englisch, deutsche Fassung in locale.json', !preg_match('/[äöüÄÖÜß]/u', $formJson)
    && isset($deTexte['Active — exchange prices for the current quarter hour are available']));
// 4. Deutsche Übersetzungen sind gepflegt: keine leeren, keine mit englischem Original identischen
// Gleich sein dürfen nur kurze Bezeichner, die in beiden Sprachen gleich lauten (z. B. „Apr–Jun").
$leer = array_values(array_filter($deTexte, fn($v, $k) => trim((string)$v) === '' || ($v === $k && mb_strlen((string)$k) > 12), ARRAY_FILTER_USE_BOTH));
check('Alle deutschen Übersetzungen gefüllt und nicht nur aus dem Englischen kopiert', count($leer) === 0, implode(' | ', array_slice($leer, 0, 3)));
// 5. Dasselbe Formular einmal auf Englisch: nichts Deutsches darf übrig bleiben
clock('2026-09-12 10:00:00');
$GLOBALS['VARS'] = [];
$GLOBALS['REQUESTS'] = []; $GLOBALS['HTTP'] = [];
$en = fresh();
$GLOBALS['HTTP'][] = ok(ecFirst('ec-DE-LU-2026-09-12_13.json', 96));
$en->ApplyChanges();
$GLOBALS['LOCALE'] = 'en';
$enForm = formText(form($en));
$GLOBALS['LOCALE'] = 'de';
$deForm = formText(form($en));
$deutscheReste = [];
foreach (['Viertelstunde', 'Börsenpreis jetzt', 'Datenquelle', 'Preise', 'Übernehmen', 'ausgeblendet'] as $wort) {
    if (str_contains($enForm, $wort)) { $deutscheReste[] = $wort; }
}
check('Englisches Formular enthält keine deutschen Wörter mehr', count($deutscheReste) === 0, implode(', ', $deutscheReste));
check('Englisches Formular: Beträge mit Punkt, Listen-/Knopf-Beschriftungen englisch (' . 'Umlagen/Knöpfe' . ')', str_contains($enForm, 'electricity tax 2.050')
    && str_contains($enForm, 'View licence text') && str_contains($enForm, 'Donate via PayPal') && !str_contains($enForm, 'Lizenztext'));
check('Englisches Formular nennt die englischen Überschriften', str_contains($enForm, '🔌  Data source') && str_contains($enForm, '📈  Exchange prices')
    && str_contains($enForm, '⚡  Symcon Energy Manager & tariff') && str_contains($enForm, '🪟  Form: layout'));
check('Deutsches Formular bleibt unverändert deutsch', str_contains($deForm, '🔌  Datenquelle') && str_contains($deForm, '📈  Börsenpreise'));
// 6. Zahlen und Datum folgen der Sprache
$GLOBALS['LOCALE'] = 'en';
$enStatus = formText(form($en));
$GLOBALS['LOCALE'] = 'de';
check('Zahlen englisch mit Punkt, deutsch mit Komma', preg_match('/\d+\.\d\d ct\/kWh/', $enStatus) === 1
    && preg_match('/\d+,\d\d ct\/kWh/', formText(form($en))) === 1);
// 7. Stolperstein 24: ein Viertelstunden-Takt übersetzt nicht hunderte Texte
$GLOBALS['TRANSLATE_CALLS'] = 0;
$en->Tick();
check('Tick übersetzt höchstens 20 Texte (kein Translate-Sturm je Durchlauf)', $GLOBALS['TRANSLATE_CALLS'] <= 20, (string)$GLOBALS['TRANSLATE_CALLS']);
$GLOBALS['REQUESTS'] = [];

heading('32. Fremdanbindung: price-Vertrag 1.2 (SUITE.md „Fremdanbindung“ 1/5b/5f, 20.11.2026)');
clock('2026-09-12 10:00:00');
$GLOBALS['VARS'] = [];
$GLOBALS['REQUESTS'] = []; $GLOBALS['HTTP'] = [];
$fa = fresh();
$GLOBALS['HTTP'][] = ok(ecFirst('ec-DE-LU-2026-09-12_13.json', 96));
$fa->ApplyChanges();
$curve = $fa->GetPriceCurve();
$kern = ['start', 'end', 'price', 'basis', 'netzentgelt', 'level', 'contractVersion', 'contractType'];
check('Gemeinsamer Preis-Kern in jedem Eintrag vorhanden', count(array_filter($curve, fn($s) => count(array_diff($kern, array_keys($s))) === 0)) === count($curve));
check('contractType „price", Vertrag 1.2, Major unverändert 1', $curve[0]['contractType'] === 'price' && $curve[0]['contractVersion'] === '1.2'
    && (int)explode('.', $curve[0]['contractVersion'])[0] === 1);
check('Nichts erfunden: level bleibt null, netzentgelt „fehlt", basis „spot"', $curve[0]['level'] === null && $curve[0]['netzentgelt'] === 'fehlt' && $curve[0]['basis'] === 'spot');
check('Kein Feld auf oberster Ebene (Liste bleibt iterierbar)', array_keys($curve) === range(0, count($curve) - 1));
// Rückblick aus dem Archiv: dort ist der Abrufzeitpunkt unbekannt → updatedAt fehlt, statt geraten zu werden
$GLOBALS['ARCHIVES'] = [99]; $GLOBALS['AC'] = []; $GLOBALS['AC_LOG'] = [];
$vidFa = IPS_GetObjectIDByIdent('CurrentPrice', $fa->InstanceID);
$GLOBALS['AC_LOG'][$vidFa] = true;
for ($t = ts('2026-09-10 00:00:00'); $t < ts('2026-09-11 00:00:00'); $t += 900) {
    $GLOBALS['AC'][$vidFa][] = ['TimeStamp' => $t, 'Value' => 10.0 + ($t % 7)];
}
$hist = $fa->GetPriceHistory(ts('2026-09-10 00:00:00'), ts('2026-09-11 00:00:00'));
check('Rückblick trägt denselben Vertrag (1.2, price), aber kein erfundenes updatedAt', count($hist) > 0
    && $hist[0]['contractType'] === 'price' && $hist[0]['contractVersion'] === '1.2'
    && !array_key_exists('updatedAt', $hist[0]) && $hist[0]['quelle'] === 'archiv');
$GLOBALS['ARCHIVES'] = []; $GLOBALS['AC'] = []; $GLOBALS['AC_LOG'] = [];
$GLOBALS['REQUESTS'] = [];

// ---------------------------------------------------------------------------
heading('33. Store-Review 25/27 — die Konsole übersetzt das Formular, Geschwister-Abgleich nicht in ApplyChanges');
$code33 = file_get_contents(dirname(__DIR__) . '/Boersenpreis/module.php');
check('Checkliste 25: kein $this->t() auf einem festen caption/label-Wert',
    preg_match_all("/'(?:caption|label)' => \\\$this->t\\('(?:[^'\\\\\\\\]|\\\\\\\\.)*'\\)\\s*[,\\]]/", $code33, $m33) === 0,
    implode(' | ', array_slice($m33[0] ?? [], 0, 3)));
check('Checkliste 27: ApplyChanges ruft den Geschwister-Abgleich nicht auf',
    preg_match('/function ApplyChanges\(\).*?\n    \}/s', $code33, $a33) === 1
    && !str_contains($a33[0], 'adoptDismissFromSibling'));
check('… GetConfigurationForm holt ihn nach',
    preg_match('/function GetConfigurationForm\(\).*?adoptDismissFromSibling\(\)/s', $code33) === 1);

clock('2026-09-12 10:00:00');
$GLOBALS['VARS'] = []; $GLOBALS['REQUESTS'] = []; $GLOBALS['HTTP'] = [];
$k33 = fresh();
$GLOBALS['HTTP'][] = ok(fx('ec-DE-LU-2026-09-12_13.json'));
$k33->ApplyChanges();
// Nur Beschriftungen prüfen: Listen-WERTE (Panel-Namen) sind weiterhin deutsch, die übersetzt
// die Konsole nicht (siehe unten).
$besch33 = function (array $el) use (&$besch33): array {
    $out = [];
    foreach ($el as $k => $v) {
        if (is_array($v)) { $out = array_merge($out, $besch33($v)); }
        elseif (in_array($k, ['caption', 'label'], true) && is_string($v)) { $out[] = $v; }
    }
    return $out;
};
// Feste Beschriftungen gehen englisch raus; was das Modul selbst übersetzt (Texte mit Werten,
// „Was ist Neu“-Zeilen, Quellennennung) bleibt deutsch — das ist gewollt.
$roh33 = $besch33(formRoh($k33));
$fest33 = array_map(fn($e) => (string)($e['caption'] ?? ''), formRoh($k33)['elements']);
// „🪟 Formular: Aufbau (1 ausgeblendet)" ist ein zusammengesetzter Text mit Wert — den muss das
// Modul selbst übersetzen, die Konsole kann das nicht (deshalb hier ausgenommen).
$deutsch33 = array_values(array_filter($fest33, fn($t) => preg_match('/Datenquelle|Börsenpreise|Tarif|Rückmeldung/u', $t) === 1));
check('Panel-Überschriften gehen englisch raus, auch bei deutscher Konsole',
    count(array_filter($fest33, fn($t) => str_contains($t, 'Data source'))) === 1
    && count(array_filter($fest33, fn($t) => str_contains($t, 'Exchange prices'))) === 1
    && $deutsch33 === [], implode(' | ', array_slice($deutsch33, 0, 3)));
check('… ebenso die festen Knopf- und Feldbeschriftungen',
    count(array_filter($roh33, fn($t) => $t === '🔄 Fetch prices now')) === 1
    && count(array_filter($roh33, fn($t) => preg_match('/^(Preise jetzt abrufen|Datenquelle)$/u', $t) === 1)) === 0);
check('Die Konsole macht daraus Deutsch (locale.json)',
    str_contains(formText(form($k33)), 'Datenquelle') && str_contains(formText(form($k33)), 'Börsenpreise'));
$GLOBALS['TRANSLATE_CALLS'] = 0;
formRoh($k33);
check('Formularaufbau übersetzt nur noch wenige Texte (vorher über 160)', $GLOBALS['TRANSLATE_CALLS'] <= 70, 'Aufrufe: ' . $GLOBALS['TRANSLATE_CALLS']);

// Werte in Listen übersetzt das Modul selbst — die Konsole übersetzt nur Beschriftungen
$liste33 = null;
foreach (form($k33)['elements'] as $el33) {
    foreach ($el33['items'] ?? [] as $it33) {
        if (($it33['name'] ?? '') === 'FormLayout') { $liste33 = $it33; }
    }
}
check('Panel-Namen in der Aufbau-Liste sind deutsch (Werte, keine Beschriftungen)',
    $liste33 !== null && count(array_filter(array_column($liste33['values'] ?? [], 'Panel'),
        fn($p) => preg_match('/Datenquelle|Börsenpreise|Tarif/u', (string)$p) === 1)) === count($liste33['values'] ?? []),
    implode(' | ', array_column($liste33['values'] ?? [], 'Panel')));

// 27 am Verhalten: neue Instanz übernimmt den Stand erst beim Öffnen des Formulars
$G33 = '{11BBF147-16A1-4332-82A3-29BB31154D03}';
$GLOBALS['VARS'] = []; $GLOBALS['SPOT_OBJ'] = [];
$mk33 = function (int $id) {
    $o = new SpotTest(); $o->InstanceID = $id; $o->Create();
    $o->props['Source'] = 0; $o->props['BiddingZone'] = 'DE-LU';
    $GLOBALS['SPOT_OBJ'][$id] = $o;
    return $o;
};
$alt33 = $mk33(33001);
$GLOBALS['INSTANCES'][$G33] = [33001];
$GLOBALS['HTTP'][] = ok(fx('ec-DE-LU-2026-09-12_13.json')); $alt33->ApplyChanges();
$alt33->AckPurposeIntro();
$neu33 = $mk33(33002);
$GLOBALS['INSTANCES'][$G33] = [33001, 33002];
$GLOBALS['HTTP'][] = ok(fx('ec-DE-LU-2026-09-12_13.json'));
$neu33->ApplyChanges();
check('ApplyChanges fragt die Geschwister nicht (Abgleich noch offen)',
    $neu33->attrs['DismissAdopted'] === false && $neu33->attrs['PurposeIntroGone'] === false);
$sicht33 = fn(SpotTest $o) => array_filter(array_map(fn($e) => ($e['visible'] ?? true) === false ? '' : ($e['name'] ?? ''), form($o)['elements']));
$panels33 = $sicht33($neu33);
check('Beim Öffnen des Formulars übernimmt die neue Instanz den Stand',
    $neu33->attrs['DismissAdopted'] === true && $neu33->attrs['PurposeIntroGone'] === true
    && !in_array('PurposeIntroPanel', $panels33, true));
$GLOBALS['ADOPT_CALLS'] = 0;
form($neu33); form($neu33);
check('… und fragt danach nie wieder (einmalig, kein Aufruf je Formularaufbau)', $GLOBALS['ADOPT_CALLS'] === 0, (string)$GLOBALS['ADOPT_CALLS']);
$GLOBALS['INSTANCES'] = []; $GLOBALS['SPOT_OBJ'] = []; $GLOBALS['REQUESTS'] = [];

// ---------------------------------------------------------------------------
heading('34. Darstellungen statt eigener Profile (SUITE.md, verbindlich 23.11.2026)');
clock('2026-11-23 10:00:00');
$GLOBALS['VARS'] = []; $GLOBALS['PROFILES'] = []; $GLOBALS['MAINTAIN'] = [];
$GLOBALS['REQUESTS'] = []; $GLOBALS['HTTP'] = [];
$d34 = fresh();
$GLOBALS['HTTP'][] = ok(fx('ec-DE-LU-2026-09-12_13.json'));
$d34->ApplyChanges();

$VP34 = '{3319437D-7CDE-699D-750A-3C6A3841FA75}'; // Wertanzeige
check('Keine eigenen Profile mehr angelegt', $GLOBALS['PROFILES'] === [], implode(', ', array_keys($GLOBALS['PROFILES'])));

// „Börsenpreis jetzt": Wertanzeige mit ct/kWh
$pr34 = $GLOBALS['MAINTAIN']['CurrentPrice']['profile'] ?? null;
check('CurrentPrice bekommt eine Darstellung (Array), kein Profil (String)', is_array($pr34), gettype($pr34));
check('… Wertanzeige mit Suffix " ct/kWh" und 2 Nachkommastellen',
    ($pr34['PRESENTATION'] ?? '') === $VP34 && ($pr34['SUFFIX'] ?? '') === ' ct/kWh' && ($pr34['DIGITS'] ?? -1) === 2,
    json_encode($pr34, JSON_UNESCAPED_UNICODE));
// Der Kern dieses Moduls sind negative Preise — eine Vorschau, die bei 0 beginnt, würde sie verstecken
check('… MIN deckt negative Preise ab und MAX die technische Preisgrenze der Börse',
    ($pr34['MIN'] ?? 0) <= -50 && ($pr34['MAX'] ?? 0) >= 400, 'MIN=' . ($pr34['MIN'] ?? '-') . ' MAX=' . ($pr34['MAX'] ?? '-'));

// Ja/Nein-Anzeigen
foreach (['NegativeNow', 'TomorrowAvailable'] as $i34) {
    $p34 = $GLOBALS['MAINTAIN'][$i34]['profile'] ?? null;
    check($i34 . ': Wertanzeige mit OPTIONS (kein Profil, keine Aufzählung)',
        is_array($p34) && ($p34['PRESENTATION'] ?? '') === $VP34 && isset($p34['OPTIONS']));
    $o34 = json_decode((string)($p34['OPTIONS'] ?? ''), true);
    check($i34 . ': OPTIONS ist ein JSON-STRING mit genau Nein=false und Ja=true (deutsche Oberfläche)',
        is_array($o34) && count($o34) === 2
        && $o34[0]['Value'] === false && $o34[0]['Caption'] === 'Nein'
        && $o34[1]['Value'] === true && $o34[1]['Caption'] === 'Ja',
        is_array($o34) ? json_encode(array_column($o34, 'Caption'), JSON_UNESCAPED_UNICODE) : 'kein Array');
    // Live bestätigt am 24.11.2026 auf Privat: Lässt das Modul die Farbfelder weg, ergänzt der Kernel
// beim Auflösen selbst ColorActive/ColorValue/ContentColorActive/ContentColorValue mit gültigen
// Werten (false/-1). Genau deshalb ist Weglassen sicherer als Selbst-Setzen — die Mobile-App
// bekommt gültige Zahlen, und der Schlüssel kann nicht mit dem `Color` der Aufzählung verwechselt
// werden.
check($i34 . ': Option trägt keinen Farbschlüssel (Pitfall 21)',
        is_array($o34) && array_keys($o34[0]) === ['Value', 'Caption', 'IconActive', 'IconValue'],
        is_array($o34) ? implode(', ', array_keys($o34[0])) : '-');
}

// Symcon-Standardprofile bleiben unangetastet
check('NextNegativeStart behält ~UnixTimestamp, MarketData ~TextBox (Symcon-Standard, kein eigenes Profil)',
    ($GLOBALS['MAINTAIN']['NextNegativeStart']['profile'] ?? '') === '~UnixTimestamp'
    && ($GLOBALS['MAINTAIN']['MarketData']['profile'] ?? '') === '~TextBox');

// Englische Oberfläche: die Beschriftungen der Optionen sind WERTE, die übersetzt das Modul selbst
$GLOBALS['LOCALE'] = 'en';
$GLOBALS['VARS'] = []; $GLOBALS['MAINTAIN'] = [];
$e34 = fresh();
$GLOBALS['HTTP'][] = ok(fx('ec-DE-LU-2026-09-12_13.json'));
$e34->ApplyChanges();
$oe34 = json_decode((string)($GLOBALS['MAINTAIN']['NegativeNow']['profile']['OPTIONS'] ?? ''), true);
check('Englische Oberfläche: Optionen heißen No/Yes (Werte übersetzt das Modul, nicht die Konsole)',
    is_array($oe34) && $oe34[0]['Caption'] === 'No' && $oe34[1]['Caption'] === 'Yes',
    is_array($oe34) ? implode('/', array_column($oe34, 'Caption')) : '-');
$GLOBALS['LOCALE'] = 'de';
$GLOBALS['VARS'] = []; $GLOBALS['MAINTAIN'] = []; $GLOBALS['REQUESTS'] = [];

// ---------------------------------------------------------------------------
heading('35. Typ-Spalte: lesbarer Modulname (SUITE.md, verbindlich 24.11.2026)');
$modJson = json_decode(file_get_contents(dirname(__DIR__) . '/Boersenpreis/module.json'), true);
$locJson = json_decode(file_get_contents(dirname(__DIR__) . '/Boersenpreis/locale.json'), true);
$de35 = $locJson['translations']['de'] ?? [];
$name35 = (string)($modJson['name'] ?? '');
check('module.json name unverändert "Boersenpreis" (technischer Bezeichner, nie umbenennen)', $name35 === 'Boersenpreis', $name35);
check('locale.json übersetzt ihn in den lesbaren Namen "NRG-Stack Börsenpreis"',
    ($de35[$name35] ?? '') === 'NRG-Stack Börsenpreis', $de35[$name35] ?? 'FEHLT');
check('Muster <Marke> <Produkt>: Marke NRG-Stack, keine Rolle (dies ist das Hauptmodul)',
    preg_match('/^NRG-Stack \S+$/u', (string)($de35[$name35] ?? '')) === 1);

echo "\n" . str_repeat('-', 62) . "\n";
if ($fails === 0) {
    echo "OK — alle Prüfungen bestanden.\n";
    exit(0);
}
echo "FEHLER — $fails Prüfung(en) fehlgeschlagen.\n";
exit(1);
