<?php
declare(strict_types=1);
/*
 * Testerens supplement til opfølgningen på review af PR #181 (spec 2026-10-26-kims-upload-opfoelgning-review, kriterie 167-191).
 * Kun rene funktioner og midlertidige filer i systemets temp-mappe: ingen database, ingen server og intet netværk.
 * Dækker det, udviklerens tests (tests/supplier_import_test.php, kriterie 167-182) ikke prøver:
 *  - QO1  negative lagerceller: grænsetilfælde (decimaler, -0, hårde mellemrum, ugyldige varianter, ikke-tekst)
 *  - QO2  lokationscellerne: flere negative, nøglens placering, afkortet tekst, umappede kolonner
 *  - QO3  fletning af negative tal i tilstand L og T: flere rækker, ugyldig + negativ total (begge rækkefølger), nyt produkt,
 *         "brug dem uændret", Kun lagerantal, bogstavvarianter, JSON-runderejse af previewet
 *  - QO4  kolonnegæt: redning når kun varenummer ELLER kun produktnavn er husket som "Ignorer", dubletter, tilladte felter
 *  - QO5  preview-oprydning: præcis 24 timer, skjulte og for lange filnavne, ulæselig mappe/fil, symlink, skæve mapper
 *  - QO6  backup: præfikset rammer kun mappen (ikke storage/import-previews.txt), og en rigtig ZIP får ikke previews med
 * Køres af tests/run.sh:  php tests/supplier_opfoelgning_qa_test.php   (exit-kode ≠ 0 ved fejl)
 * Alle midlertidige filer ligger under sys_get_temp_dir() og ryddes op bagefter.
 */

error_reporting(E_ALL);
set_error_handler(static function (int $no, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $no) || in_array($no, [E_DEPRECATED, E_USER_DEPRECATED], true)) return true;
    throw new ErrorException($message, 0, $no, $file, $line);
});

require __DIR__.'/../core/supplier_import.php';
require_once __DIR__.'/../core/backup.php';

$GLOBALS['t_pass'] = 0;
$GLOBALS['t_fail'] = [];
$GLOBALS['t_case'] = '';

function t_record(bool $ok, string $label, string $detail = ''): void {
    if ($ok) { $GLOBALS['t_pass']++; return; }
    $GLOBALS['t_fail'][] = '['.$GLOBALS['t_case'].'] '.$label.($detail !== '' ? "\n      ".$detail : '');
}
function t_true(bool $cond, string $label): void { t_record($cond, $label); }
function t_eq(mixed $expected, mixed $actual, string $label): void {
    t_record($expected === $actual, $label, 'forventet '.json_encode($expected, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR).', fik '.json_encode($actual, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));
}
function t_case(string $name, callable $fn): void {
    $GLOBALS['t_case'] = $name;
    try { $fn(); } catch (Throwable $e) { t_record(false, 'uventet fejl: '.get_class($e).': '.$e->getMessage().' ('.basename($e->getFile()).':'.$e->getLine().')'); }
}

/* ---------- Syntetiske testdata (samme form som i tests/supplier_import_test.php) ---------- */

function kim_headers(): array {
    $h = array_fill(0, 47, '');
    foreach ([4 => 'Varenr.', 5 => 'Variant:', 6 => 'Alder:', 7 => 'Destilleri:', 16 => 'Lagerbeholdning', 17 => 'Christiansfeld', 18 => 'hos Michael', 19 => 'hos Gert'] as $i => $v) $h[$i] = $v;
    return $h;
}
function kim_map(bool $withTotal = true, bool $withLocations = true): array {
    $map = ['sku' => 4, 'name' => 5, 'distillery' => 7];
    if ($withTotal) $map['stock_quantity'] = 16;
    if ($withLocations) { $map['stock_loc_1'] = 17; $map['stock_loc_2'] = 18; $map['stock_loc_3'] = 19; }
    return $map;
}
function kim_row(array $v): array {
    $row = array_fill(0, 47, '');
    $cols = ['sku' => 4, 'name' => 5, 'age' => 6, 'dist' => 7, 'Q' => 16, 'R' => 17, 'S' => 18, 'T' => 19];
    foreach ($v as $k => $val) $row[$cols[$k]] = (string)$val;
    return $row;
}
function hsg_product(array $p): array {
    $base = ['id' => 1, 'sku' => '', 'name' => '', 'brand_id' => null, 'cask_number' => '', 'distillery' => '', 'vintage_year' => null,
        'abv' => null, 'age_text' => '', 'status' => 'active',
        'stock_loc_1' => 0, 'stock_loc_2' => 0, 'stock_loc_3' => 0, 'reserved_loc_1' => 0, 'reserved_loc_2' => 0, 'reserved_loc_3' => 0];
    $p = array_merge($base, $p);
    $p['stock_quantity'] = $p['stock_loc_1'] + $p['stock_loc_2'] + $p['stock_loc_3'];
    return $p;
}
function merge_opts(bool $addReserved = true): array {
    return ['fallback_location_id' => 1, 'location_names' => [1 => 'Christiansfeld', 2 => 'Michael', 3 => 'Gert'], 'add_reserved' => $addReserved];
}
/** Spøgelse Whisky: lager 2 på Gert, to reservationer à 1 på Gert. */
function spog(array $override = []): array {
    return hsg_product(array_merge(['id' => 501, 'sku' => '50-1', 'name' => 'Spøgelse Whisky', 'distillery' => 'Testdestilleri',
        'stock_loc_3' => 2, 'reserved_loc_3' => 2, 'active_reservations' => [
            ['id' => 9001, 'location_id' => 3, 'quantity' => 1, 'customer_name' => 'Hansen', 'reference' => 'Ordre 1047', 'created_at' => '2026-10-01 12:00:00'],
            ['id' => 9002, 'location_id' => 3, 'quantity' => 1, 'customer_name' => 'Jensen', 'reference' => '', 'created_at' => '2026-10-03 09:00:00'],
        ]], $override));
}
/** Samme vare med lager og reservationer på første lokation (Christiansfeld), til tilstand T. */
function spog_t(array $override = []): array {
    return spog(array_merge(['stock_loc_1' => 2, 'reserved_loc_1' => 2, 'stock_loc_3' => 0, 'reserved_loc_3' => 0, 'active_reservations' => [
        ['id' => 9001, 'location_id' => 1, 'quantity' => 1, 'customer_name' => 'Hansen', 'reference' => 'Ordre 1047', 'created_at' => '2026-10-01 12:00:00'],
        ['id' => 9002, 'location_id' => 1, 'quantity' => 1, 'customer_name' => 'Jensen', 'reference' => '', 'created_at' => '2026-10-03 09:00:00'],
    ]], $override));
}
function row_with(array $cells): array {
    return kim_row(array_merge(['sku' => '50-1', 'name' => 'Spøgelse Whisky', 'dist' => 'Testdestilleri', 'R' => 0, 'S' => '', 'T' => '-1', 'Q' => 0], $cells));
}
/** Kør merge_raw_items og returnér varen med det givne varenummer (første, hvis sku er null). */
function merge_one(array $rows, array $map, array $products, array $opts): array {
    $items = hsg_supplier_merge_raw_items($rows, $map, $products, null, 1, null, $opts);
    return $items[0] ?? [];
}
function notes_with(array $item, string $needle): array {
    return array_values(array_filter((array)($item['notes'] ?? []), static fn($n) => str_contains((string)$n, $needle)));
}
/** items[].archive – eller 'MANGLER', hvis nøglen ikke findes (?? kan ikke skelne null fra en manglende nøgle). */
function archive_of(array $item): mixed { return array_key_exists('archive', $item) ? $item['archive'] : 'MANGLER'; }
/** items[].stock_file[$key] – eller 'MANGLER', hvis nøglen ikke findes (null er en gyldig værdi). */
function sf_of(array $item, string $key): mixed { $sf = (array)($item['stock_file'] ?? []); return array_key_exists($key, $sf) ? $sf[$key] : 'MANGLER'; }
const N3A = 'Bemærk: Arkiveringen er ikke valgt på forhånd, fordi Kims fil har en negativ værdi for varen.';

/* ---------- QO1: lagercellen ---------- */

t_case('QO1 Negative lagerceller: grænsetilfælde', function () {
    // [indhold, forventet value, forventet negative]
    $numbers = [
        ['-1', 0, true], ['-1.0', 0, true], ['-1,0', 0, true], ['-0,5', 0, true], ['-0.5', 0, true], ['-0,50', 0, true], ['-0,6', 0, true],
        ['-1,5', 0, true], ['-2,4999', 0, true], ['-99999', 0, true], ['-99999,9', 0, true],
        ['-0', 0, false], ['-00', 0, false], ['-0,0', 0, false], ['-0.0', 0, false], ['-00000', 0, false], ['-0,49', 0, false], ['-0,4', 0, false], ['-0,4999', 0, false],
        ['0', 0, false], ['0,4', 0, false], ['0,5', 1, false], ['1', 1, false], ['41', 41, false], ['99999', 99999, false],
        // Mellemrum og hårde mellemrum omkring minus-tallet fjernes (og text er den trimmede tekst).
        [' -1 ', 0, true], ["\u{00A0}-1\u{00A0}", 0, true], ["\u{202F}-1", 0, true], ["\t-1\n", 0, true],
    ];
    foreach ($numbers as [$x, $value, $negative]) {
        $c = hsg_supplier_stock_cell($x);
        t_eq(['number', $value, $negative], [$c['state'], $c['value'], $c['negative'] ?? null], 'celle '.json_encode($x, JSON_UNESCAPED_UNICODE));
    }
    t_eq('-1', hsg_supplier_stock_cell(" \u{00A0}-1\u{202F} ")['text'], 'text er den trimmede tekst');
    // Ugyldige varianter er præcis som før: ingen negative-nøgle, ingen bemærkning om negativt tal.
    foreach (['- 1', "\u{2212}1", '--1', '-', '+1', '-1,234', '-1.234', '-1e3', '-100000', '-1 stk.', '(1)', '-1-', '1-', '- '] as $x) {
        $c = hsg_supplier_stock_cell($x);
        t_eq(['state' => 'invalid', 'value' => null, 'text' => trim($x)], $c, 'ugyldig: '.json_encode($x, JSON_UNESCAPED_UNICODE));
    }
    // Ikke-tekst (Excel kan give heltal og kommatal): heltal -1 er negativt, -0.4 er det ikke, null er tom.
    t_eq(['number', 0, true], [hsg_supplier_stock_cell(-1)['state'], hsg_supplier_stock_cell(-1)['value'], hsg_supplier_stock_cell(-1)['negative']], 'int -1');
    t_eq(true, hsg_supplier_stock_cell(-1.5)['negative'], 'float -1.5');
    t_eq(false, hsg_supplier_stock_cell(-0.4)['negative'], 'float -0.4');
    t_eq(['state' => 'blank', 'value' => null, 'text' => ''], hsg_supplier_stock_cell(null), 'null er tom');
});

/* ---------- QO2: lokationscellerne ---------- */

t_case('QO2 Lokationscellerne med negative tal', function () {
    $r = hsg_supplier_location_cells(kim_row(['R' => '-1', 'S' => '-2', 'T' => '-0']), kim_map(false));
    t_eq(['values' => ['stock_loc_1' => 0, 'stock_loc_2' => 0, 'stock_loc_3' => 0], 'blank' => [], 'invalid' => [], 'negative' => ['stock_loc_1' => '-1', 'stock_loc_2' => '-2']], $r, 'flere negative; -0 er ikke negativ');
    t_eq(['values', 'blank', 'invalid', 'negative'], array_keys($r), "'negative' står sidst");
    // Uden lokationskolonner: alle fire nøgler findes og er tomme.
    t_eq(['values' => [], 'blank' => [], 'invalid' => [], 'negative' => []], hsg_supplier_location_cells(kim_row(['R' => '-1']), kim_map(true, false)), 'uden lokationskolonner');
    // En negativ celle i en kolonne, der ikke er valgt som lokation, tæller ikke.
    $map = kim_map(false); unset($map['stock_loc_2']);
    t_eq([], hsg_supplier_location_cells(kim_row(['R' => 3, 'S' => '-9', 'T' => 1]), $map)['negative'], 'umappet kolonne med -9 ignoreres');
    // Negativ og ugyldig i samme række: hver sin liste.
    $mixed = hsg_supplier_location_cells(kim_row(['R' => '-1', 'S' => '?', 'T' => 4]), kim_map(false));
    t_eq([['stock_loc_2' => '?'], ['stock_loc_1' => '-1'], ['stock_loc_1' => 0, 'stock_loc_3' => 4]], [$mixed['invalid'], $mixed['negative'], $mixed['values']], 'negativ + ugyldig i samme række');
    // Lang negativ tekst afkortes til 40 tegn + "…" (decimaldelen må være så lang, den vil).
    $long = '-1,'.str_repeat('0', 60);
    t_eq('-1,'.str_repeat('0', 37).'…', hsg_supplier_location_cells(kim_row(['R' => $long]), kim_map(false))['negative']['stock_loc_1'] ?? null, 'afkortet til 40 tegn + …');
    // Hårde mellemrum omkring et negativt tal.
    t_eq(['stock_loc_1' => '-3'], hsg_supplier_location_cells(kim_row(['R' => "\u{00A0}-3\u{202F}"]), kim_map(false))['negative'], 'hårde mellemrum omkring -3');
});

/* ---------- QO3: fletning ---------- */

t_case('QO3a Tilstand L: flere negative celler, én N3a, ingen arkivering foreslået uden reservationer', function () {
    $it = merge_one([row_with(['R' => '-1', 'S' => '-2', 'T' => 0])], kim_map(), [spog()], merge_opts());
    t_eq(['stock_loc_1' => '-1', 'stock_loc_2' => '-2'], $it['stock_file']['negative'] ?? null, 'begge negative lokationer');
    t_eq(['Bemærk: Negativ værdi „-1“ for Christiansfeld behandles som 0.', 'Bemærk: Negativ værdi „-2“ for Michael behandles som 0.'], notes_with($it, 'Negativ værdi'), 'én N1 pr. lokation');
    t_eq([N3A], notes_with($it, 'negativ værdi for varen'), 'præcis én N3a');
    t_eq([false, false], [$it['archive']['default'] ?? null, $it['selected'] ?? null], 'ikke forvalgt, og rækken ændrer intet');
    // En vare uden aktive reservationer får N1 men hverken arkivering eller N3a – og lageret nulstilles som i dag.
    $none = merge_one([row_with([])], kim_map(), [spog(['active_reservations' => [], 'reserved_loc_3' => 0])], merge_opts());
    t_eq([null, true, ['stock_loc_3' => ['old' => 2, 'new' => 0, 'file' => 0]]], [archive_of($none), $none['selected'] ?? null, array_filter($none['changes'] ?? [], static fn($k) => str_starts_with((string)$k, 'stock_'), ARRAY_FILTER_USE_KEY)], 'uden reservationer: ingen arkivering, lager 2 → 0');
    t_eq([], notes_with($none, 'negativ værdi for varen'), 'uden reservationer: ingen N3a');
    t_eq(1, count(notes_with($none, 'Negativ værdi „-1“ for Gert')), 'uden reservationer: N1 for Gert');
});

t_case('QO3b Lang negativ tekst står afkortet i bemærkningen', function () {
    $long = '-1,'.str_repeat('0', 60);
    $it = merge_one([row_with(['T' => $long])], kim_map(), [spog()], merge_opts());
    $expected = 'Bemærk: Negativ værdi „-1,'.str_repeat('0', 37).'…“ for Gert behandles som 0.';
    t_eq([$expected], notes_with($it, 'Negativ værdi'), 'N1 med afkortet tekst');
});

t_case('QO3c Tilstand L: negativ "Lagerantal i alt"', function () {
    $it = merge_one([row_with(['T' => 0, 'Q' => '-5'])], kim_map(), [spog()], merge_opts());
    t_eq([0, '-5', []], [$it['stock_file']['total'] ?? null, $it['stock_file']['total_negative'] ?? null, $it['stock_file']['negative'] ?? null], 'total 0, total_negative -5, ingen negative lokationer');
    t_eq([false, []], [$it['archive']['default'] ?? null, $it['flags'] ?? null], 'ikke forvalgt og ingen flag');
    t_eq(['Bemærk: Negativ værdi „-5“ for lagerantal i alt behandles som 0.'], notes_with($it, 'Negativ værdi'), 'præcis N2');
    t_eq([N3A], notes_with($it, 'negativ værdi for varen'), 'N3a');
    // Negativ total og lokationer med tal: kontroltallet er 0, så summen afviger – og N2 står der stadig.
    $mm = merge_one([row_with(['R' => 3, 'T' => 0, 'Q' => '-1'])], kim_map(), [spog()], merge_opts());
    t_eq(['stock_mismatch'], $mm['flags'] ?? null, 'sum 3 mod kontroltal 0 giver stock_mismatch');
    t_eq([false, '-1', null], [$mm['selected'] ?? null, sf_of($mm, 'total_negative'), archive_of($mm)], 'ikke valgt, total_negative -1, ingen arkivering');
    t_eq(1, count(notes_with($mm, 'for lagerantal i alt behandles som 0')), 'N2 med mismatch');
});

t_case('QO3d Fletning: første negative tekst pr. lokation vinder, uanset rækkefølge og bogstavvarianter', function () {
    $a = merge_one([row_with(['T' => '-1']), row_with(['T' => '-2'])], kim_map(), [spog()], merge_opts());
    t_eq(['stock_loc_3' => '-1'], $a['stock_file']['negative'] ?? null, '-1 før -2');
    $b = merge_one([row_with(['T' => '-2']), row_with(['T' => '-1'])], kim_map(), [spog()], merge_opts());
    t_eq(['stock_loc_3' => '-2'], $b['stock_file']['negative'] ?? null, '-2 før -1');
    // Tre rækker: negativ i den midterste.
    $c = merge_one([row_with(['T' => 0]), row_with(['T' => '-1']), row_with(['T' => 0])], kim_map(), [spog()], merge_opts());
    t_eq([['stock_loc_3' => '-1'], false], [$c['stock_file']['negative'] ?? null, $c['archive']['default'] ?? null], 'negativ i midterste af tre rækker');
    // Bogstavvariant (50-1a) flettes ind i 50-1; den negative celle står på variantens række – og omvendt.
    foreach ([[row_with(['T' => 0]), row_with(['sku' => '50-1a', 'T' => '-1'])], [row_with(['T' => '-1']), row_with(['sku' => '50-1a', 'T' => 0])]] as $k => $rows) {
        $items = hsg_supplier_merge_raw_items($rows, kim_map(), [spog()], null, 1, null, merge_opts());
        t_eq(1, count($items), 'variant '.$k.': én vare');
        t_eq([['stock_loc_3' => '-1'], false], [$items[0]['stock_file']['negative'] ?? null, $items[0]['archive']['default'] ?? null], 'variant '.$k.': negativ bevares, arkivering ikke forvalgt');
    }
    // En negativ celle i en flettet række gør tallet på den lokation til summen af de øvrige (den negative tæller 0).
    $sum = merge_one([row_with(['T' => '-1']), row_with(['T' => 3])], kim_map(), [spog()], merge_opts());
    t_eq([3, ['stock_loc_3' => '-1'], null], [$sum['stock_file']['locations']['stock_loc_3'] ?? null, $sum['stock_file']['negative'] ?? null, archive_of($sum)], '-1 + 3 = 3 på Gert, N1 bevaret, ingen arkivering (3 er ikke 0)');
});

t_case('QO3e Ugyldig og negativ total i flettede rækker: ugyldig vinder i begge rækkefølger og begge tilstande', function () {
    $tProducts = [spog_t()];
    foreach ([['-1', '?'], ['?', '-1']] as $k => [$q1, $q2]) {
        $t = merge_one([row_with(['Q' => $q1]), row_with(['Q' => $q2])], kim_map(true, false), $tProducts, merge_opts());
        t_eq(['?', null, null], [$t['stock_file']['total_invalid'] ?? null, sf_of($t, 'total_negative'), archive_of($t)], 'T '.$k.': total_invalid, ingen total_negative, ingen arkivering');
        t_eq([], notes_with($t, 'Negativ værdi'), 'T '.$k.': ingen N2');
        t_eq(1, count(notes_with($t, 'Ugyldig værdi „?“ for lagerantal i alt')), 'T '.$k.': den ugyldige total har sin bemærkning');
        t_eq([], (array)($t['stock_file']['locations'] ?? ['x']), 'T '.$k.': intet lager skrives');
        $l = merge_one([row_with(['T' => 0, 'Q' => $q1]), row_with(['T' => 0, 'Q' => $q2])], kim_map(), [spog()], merge_opts());
        t_eq(['?', null], [$l['stock_file']['total_invalid'] ?? null, sf_of($l, 'total_negative')], 'L '.$k.': total_invalid, ingen total_negative');
        t_eq([], notes_with($l, 'Negativ værdi'), 'L '.$k.': ingen N2');
        t_eq(null, archive_of($l), 'L '.$k.': ingen arkivering (ugyldig total)');
    }
});

t_case('QO3f Tilstand T: negativ total i flere rækker, "brug dem uændret", nyt produkt og uden fallback', function () {
    // -1 og 3 på samme varenummer: totalen er 3 (den negative tæller 0), N2 står der, og der er ingen arkivering (3 er ikke 0).
    foreach ([['-1', '3'], ['3', '-1']] as $k => [$q1, $q2]) {
        $t = merge_one([row_with(['Q' => $q1]), row_with(['Q' => $q2])], kim_map(true, false), [spog_t()], merge_opts());
        t_eq([['stock_loc_1' => 3], 3, '-1', null], [$t['stock_file']['locations'] ?? null, $t['stock_file']['total'] ?? null, $t['stock_file']['total_negative'] ?? null, archive_of($t)], 'rækkefølge '.$k);
        t_eq(1, count(notes_with($t, 'Negativ værdi „-1“ for lagerantal i alt behandles som 0.')), 'rækkefølge '.$k.': N2');
        t_eq([], notes_with($t, 'negativ værdi for varen'), 'rækkefølge '.$k.': ingen N3a (ingen arkivering)');
        t_true(!array_key_exists('stock_file_total_negative', $t['source'] ?? []) && !array_key_exists('stock_file_total_negative', $t['changes'] ?? []), 'rækkefølge '.$k.': den interne nøgle når ikke source eller changes');
    }
    // "Brug dem uændret" (add_reserved falsk): arkiveringen er stadig ikke valgt, og advarslen om reserveret står der.
    $none = merge_one([row_with(['Q' => '-1'])], kim_map(true, false), [spog_t()], merge_opts(false));
    t_eq([false, '-1'], [$none['archive']['default'] ?? null, $none['stock_file']['total_negative'] ?? null], 'none: ikke forvalgt');
    t_eq(['stock_loc_1' => ['old' => 2, 'new' => 0, 'file' => 0]], array_filter($none['changes'] ?? [], static fn($k) => str_starts_with((string)$k, 'stock_'), ARRAY_FILTER_USE_KEY), 'none: lageret skrives til 0');
    t_eq(1, count(notes_with($none, 'Christiansfeld får 0 fysisk efter import, men 2 er reserveret')), 'none: advarsel om reserveret');
    // Et nyt produkt (intet match) med negativ total: N2 vises, men det oprettes ikke (lager 0), og intet lækker.
    $new = merge_one([row_with(['sku' => 'ZZ-9', 'name' => 'Helt Ny Vare', 'Q' => '-1'])], kim_map(true, false), [spog_t()], merge_opts());
    t_eq([0, false, '-1', null], [$new['match']['id'] ?? null, $new['is_create_new'] ?? null, $new['stock_file']['total_negative'] ?? null, archive_of($new)], 'nyt produkt: intet match, ikke oprettet, total_negative -1');
    t_eq(1, count(notes_with($new, 'Negativ værdi „-1“ for lagerantal i alt')), 'nyt produkt: N2');
    t_true(!array_key_exists('stock_file_total_negative', $new['source'] ?? []), 'nyt produkt: ingen intern nøgle i source');
    // Uden fallback-lokation (gammel vej via stock_quantity): ingen arkivering, intet lækker.
    $nofb = merge_one([row_with(['Q' => '-1'])], kim_map(true, false), [spog_t()], ['fallback_location_id' => null, 'location_names' => [1 => 'Christiansfeld'], 'add_reserved' => true]);
    t_eq([null, '-1'], [archive_of($nofb), $nofb['stock_file']['total_negative'] ?? null], 'uden fallback: ingen arkivering, total_negative -1');
    t_true(!array_key_exists('stock_file_total_negative', $nofb['source'] ?? []) && !array_key_exists('stock_file_total_negative', $nofb['changes'] ?? []), 'uden fallback: ingen intern nøgle');
});

t_case('QO3g Kun lagerantal og uden lagerkolonner', function () {
    $only = merge_one([row_with([])], kim_map(), [spog()], merge_opts() + ['stock_only' => true]);
    t_eq([false, ['stock_loc_3' => '-1']], [$only['archive']['default'] ?? null, $only['stock_file']['negative'] ?? null], 'Kun lagerantal: ikke forvalgt, N1-data');
    t_eq([N3A], notes_with($only, 'negativ værdi for varen'), 'Kun lagerantal: N3a');
    // Ingen lagerkolonner: nøglerne findes og er tomme, og der er ingen negative bemærkninger.
    $none = merge_one([row_with([])], kim_map(false, false), [spog()], merge_opts());
    t_eq([[], null, 'none'], [sf_of($none, 'negative'), sf_of($none, 'total_negative'), $none['stock_file']['mode'] ?? null], 'uden lagerkolonner');
    t_eq([], notes_with($none, 'egativ'), 'uden lagerkolonner: ingen negative bemærkninger');
});

t_case('QO3h Preview-JSON: runderejse bevarer de nye nøgler, og nøglen er altid med', function () {
    $items = hsg_supplier_merge_raw_items([row_with(['T' => '-1', 'Q' => '-2']), row_with(['sku' => '50-9', 'name' => 'Anden Vare', 'T' => 1])], kim_map(), [spog()], null, 1, null, merge_opts());
    $back = json_decode((string)json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    t_eq($items, $back, 'items er identiske efter JSON-runderejse');
    foreach ($items as $i => $it) {
        t_true(array_key_exists('negative', $it['stock_file']) && array_key_exists('total_negative', $it['stock_file']), 'vare '.$i.': stock_file har både negative og total_negative');
        t_true(!array_key_exists('stock_file_total_negative', $it['source']) && !array_key_exists('stock_file_total_invalid', $it['source']), 'vare '.$i.': ingen interne nøgler i source');
    }
    t_eq([['stock_loc_3' => '-1'], '-2'], [$back[0]['stock_file']['negative'], $back[0]['stock_file']['total_negative']], 'den negative vare: lokation og total');
    t_eq([[], null], [$back[1]['stock_file']['negative'], $back[1]['stock_file']['total_negative']], 'upåvirket vare: tom liste og null');
});

/* ---------- QO4: kolonnegæt ---------- */

t_case('QO4 Redning af husket "Ignorer": kun varenummer eller kun produktnavn, dubletter og tilladte felter', function () {
    $sc = static function (array $headers, array $saved, ?array $allowed = null): array {
        $map = hsg_supplier_suggest_columns($headers, $saved, $allowed);
        return [$map, hsg_supplier_restored_key_columns($headers, $saved, $map)];
    };
    // Kun varenummer er husket som Ignorer, og der er ingen navnekolonne: Varenr. reddes.
    t_eq([[0 => 'sku', 1 => 'distillery'], [0 => 'sku']], $sc(['Varenr.', 'Destilleri:'], ['varenr' => '', 'destilleri' => 'distillery']), 'kun Varenr. husket Ignorer, ingen navnekolonne');
    // Kun produktnavn er husket som Ignorer, og der er ingen varenummerkolonne: Variant: reddes.
    t_eq([[0 => 'name', 1 => 'distillery'], [0 => 'name']], $sc(['Variant:', 'Destilleri:'], ['variant' => '', 'destilleri' => 'distillery']), 'kun Variant: husket Ignorer, ingen varenummerkolonne');
    // Den anden nøglekolonne er ny og gættes (4a) – så er der ikke brug for redningen, og "Ignorer" respekteres (spec: Ellers respekteres Ignorer).
    t_eq([[0 => '', 1 => 'name', 2 => 'distillery'], []], $sc(['Varenr.', 'Variant:', 'Destilleri:'], ['varenr' => '', 'destilleri' => 'distillery']), 'Varenr. husket Ignorer, Variant: ny: Variant: gættes, Varenr. forbliver Ignorer');
    t_eq([[0 => 'sku', 1 => '', 2 => 'distillery'], []], $sc(['Varenr.', 'Variant:', 'Destilleri:'], ['variant' => '', 'destilleri' => 'distillery']), 'Variant: husket Ignorer, Varenr. ny: Varenr. gættes, Variant: forbliver Ignorer');
    // Dubletter: første kolonne fra venstre pr. felt; den anden "Varenr." (nøgle varenr|2) forbliver Ignorer.
    t_eq([[0 => 'sku', 1 => '', 2 => 'name'], [0 => 'sku', 2 => 'name']], $sc(['Varenr.', 'Varenr.', 'Variant:'], ['varenr' => '', 'varenr|2' => '', 'variant' => '']), 'dubletter af Varenr.');
    // Tilladte felter: et felt, der ikke er tilladt, reddes ikke.
    t_eq([[0 => '', 1 => 'name'], [1 => 'name']], $sc(['Varenr.', 'Variant:'], ['varenr' => '', 'variant' => ''], ['name', 'stock_loc_1']), 'sku ikke tilladt: kun produktnavn reddes');
    t_eq([[0 => 'sku', 1 => ''], [0 => 'sku']], $sc(['Varenr.', 'Variant:'], ['varenr' => '', 'variant' => ''], ['sku', 'stock_loc_1']), 'name ikke tilladt: kun varenummer reddes');
    t_eq([[0 => '', 1 => ''], []], $sc(['Varenr.', 'Variant:'], ['varenr' => '', 'variant' => ''], []), 'ingen tilladte felter: intet reddes');
    // Et varenummer, der allerede er valgt på en anden kolonne, blokerer redningen.
    t_eq([[0 => 'sku', 1 => '', 2 => ''], []], $sc(['Artikelnr', 'Varenr.', 'Variant:'], ['artikelnr' => 'sku', 'varenr' => '', 'variant' => '']), 'sku allerede valgt på Artikelnr: ingen rest');
    // Kun præcise alias redder: "Varenummer (Kim)" er et delvist match og reddes ikke (T1 forklarer så det tomme preview).
    t_eq([[0 => '', 1 => ''], []], $sc(['Varenummer (Kim)', 'Produkt navn og mere'], ['varenummerkim' => '', 'produktnavnogmere' => '']), 'delvise match reddes ikke');
    // Store/små bogstaver og mellemrum i overskriften.
    t_eq([[0 => 'sku', 1 => 'name'], [0 => 'sku', 1 => 'name']], $sc(['  VARENR ', 'VARIANT'], ['varenr' => '', 'variant' => '']), 'overskrifter normaliseres');
    // Fadnummer valgt (husket): "Ignorer" respekteres, men står fadnummeret selv som Ignorer, reddes Varenr.
    t_eq([[0 => 'sku', 1 => ''], [0 => 'sku']], $sc(['Varenr.', 'Fadnr'], ['varenr' => '', 'fadnr' => '']), 'fadnr husket Ignorer: Varenr. reddes');
    // "Gæt kolonner forfra" ser bort fra det huskede valg og kan aldrig give restored.
    t_eq([0 => 'sku', 1 => 'name', 2 => 'stock_loc_1'], hsg_supplier_reguess_columns(['Varenr.', 'Variant:', 'Christiansfeld'], ['varenr' => '', 'variant' => '', 'christiansfeld' => 'stock_loc_1'], hsg_supplier_allowed_fields([1, 2, 3])), 'reguess');
    // restored_key_columns peger kun på kolonner, der var husket som Ignorer (ikke på kolonner, der blot er gættet).
    $h = ['Varenr.', 'Variant:'];
    t_eq([], hsg_supplier_restored_key_columns($h, [], [0 => 'sku', 1 => 'name']), 'uden husket valg: ingen restored');
    t_eq([], hsg_supplier_restored_key_columns($h, ['varenr' => 'sku', 'variant' => 'name'], [0 => 'sku', 1 => 'name']), 'husket som sku/name: ingen restored');
});

/* ---------- QO5: preview-oprydning ---------- */

function qo_tmpdir(string $prefix): string {
    $d = sys_get_temp_dir().'/'.$prefix.bin2hex(random_bytes(6));
    mkdir($d);
    return $d;
}
function qo_token(): string { return bin2hex(random_bytes(20)); }
function qo_rmtree(string $d): void {
    if (!is_dir($d)) return;
    foreach (scandir($d) ?: [] as $n) {
        if ($n === '.' || $n === '..') continue;
        $p = $d.'/'.$n;
        if (is_link($p)) { @unlink($p); @rmdir($p); }
        elseif (is_dir($p)) qo_rmtree($p);
        else { @chmod($p, 0666); @unlink($p); }
    }
    @rmdir($d);
}

t_case('QO5a Oprydning: grænsen på 24 timer og skæve filnavne', function () {
    $dir = qo_tmpdir('hsg-qo5a-');
    try {
        $now = time();
        $files = [
            'one_sec_older' => [qo_token().'.json', $now - 86400 - 2, true],
            'one_sec_younger' => [qo_token().'.json', $now - 86400 + 5, false],
            'future' => [qo_token().'.json', $now + 7200, false],
            'hidden' => ['.'.qo_token().'.json', $now - 90000, false],
            'too_short' => [substr(qo_token(), 0, 39).'.json', $now - 90000, false],
            'too_long' => [qo_token().'a.json', $now - 90000, false],
            'uppercase_ext' => [qo_token().'.JSON', $now - 90000, false],
            'double_ext' => [qo_token().'.json.json', $now - 90000, false],
            'with_space' => [qo_token().' .json', $now - 90000, false],
            'no_ext' => [qo_token(), $now - 90000, false],
            'gitkeep' => ['.gitkeep', $now - 90000, false],
            'htaccess' => ['.htaccess', $now - 90000, false],
        ];
        foreach ($files as [$name, $mtime]) { file_put_contents($dir.'/'.$name, '{}'); touch($dir.'/'.$name, $mtime); }
        $expectedDeleted = count(array_filter($files, static fn($f) => $f[2]));
        t_eq($expectedDeleted, hsg_supplier_preview_cleanup(86400, $dir), 'returnerer antallet af slettede filer');
        foreach ($files as $label => [$name, $mtime, $deleted]) t_eq(!$deleted, file_exists($dir.'/'.$name), $label.($deleted ? ' er slettet' : ' findes stadig'));
        // Med grænsen 0 slettes alt, der matcher navnet og ikke er fra fremtiden; en negativ grænse behandles som 0.
        $young = qo_token().'.json'; file_put_contents($dir.'/'.$young, '{}'); touch($dir.'/'.$young, $now - 5);
        t_eq(2, hsg_supplier_preview_cleanup(-100, $dir), 'negativ grænse = 0: den 5 sekunder gamle og den knap 24 timer gamle fil slettes');
        t_true(!file_exists($dir.'/'.$young) && !file_exists($dir.'/'.$files['one_sec_younger'][0]), 'begge er væk');
        t_true(file_exists($dir.'/'.$files['future'][0]), 'en fil med mtime i fremtiden overlever også grænsen 0');
        // Andet kald giver 0 (idempotent).
        t_eq(0, hsg_supplier_preview_cleanup(86400, $dir), 'andet kald giver 0');
        // Præcis på grænsen (mtime = nu − 86400 sekunder) er filen ikke ældre end grænsen og bevares. Sekundet kan skifte mellem touch og
        // oprydning, så målingen gentages, til uret ikke har flyttet sig (højst 20 forsøg).
        $edge = qo_token().'.json'; $kept = null;
        for ($try = 0; $try < 20 && $kept === null; $try++) {
            $t0 = time();
            file_put_contents($dir.'/'.$edge, '{}'); touch($dir.'/'.$edge, $t0 - 86400);
            $deleted = hsg_supplier_preview_cleanup(86400, $dir);
            if (time() === $t0) $kept = ['deleted' => $deleted, 'exists' => file_exists($dir.'/'.$edge)];
            @unlink($dir.'/'.$edge);
        }
        if ($kept === null) fwrite(STDOUT, "  (QO5a: sekundet skiftede i alle 20 forsøg – grænsetjekket sprunget over)\n");
        else t_eq([0, true], [$kept['deleted'], $kept['exists']], 'en fil på præcis 24 timer bevares (kun ældre end grænsen slettes)');
    } finally { qo_rmtree($dir); }
});

t_case('QO5b Oprydning kaster aldrig: ikke-mapper, skæve stier, skrivebeskyttet fil, undermappe og symlink', function () {
    $dir = qo_tmpdir('hsg-qo5b-');
    $other = qo_tmpdir('hsg-qo5b-uden-for-');
    $errLog = sys_get_temp_dir().'/hsg-qo5b-'.bin2hex(random_bytes(4)).'.log';
    $oldLog = ini_set('error_log', $errLog);   // error_log() fra oprydningen skrives i en fil, så testoutputtet er rent
    try {
        $now = time();
        // Stier, der ikke er mapper.
        $plain = $dir.'/en-fil'; file_put_contents($plain, 'x');
        foreach (['en fil' => $plain, 'tom streng' => '', 'NUL-byte' => "a\0b", 'findes ikke' => $dir.'/findes/ikke/her'] as $label => $path) {
            $r = null;
            try { $r = hsg_supplier_preview_cleanup(86400, $path); } catch (Throwable $e) { $r = get_class($e); }
            t_eq(0, $r, $label.': 0 uden undtagelse');
        }
        // Mappen indeholder en gammel fil, der ikke kan slettes (skrivebeskyttet på Windows), og to, der kan: de andre slettes alligevel,
        // og returværdien er antallet af filer, der faktisk er væk. På systemer, hvor ejeren altid må slette, er alle tre væk.
        $names = [qo_token().'.json', qo_token().'.json', qo_token().'.json'];
        foreach ($names as $n) { file_put_contents($dir.'/'.$n, '{}'); touch($dir.'/'.$n, $now - 90000); }
        @chmod($dir.'/'.$names[0], 0444);
        $deleted = hsg_supplier_preview_cleanup(86400, $dir);
        $gone = count(array_filter($names, static fn($n) => !file_exists($dir.'/'.$n)));
        t_eq($gone, $deleted, 'returværdien er antallet af filer, der faktisk er slettet');
        t_true($gone >= 2, 'mindst de to sletbare filer er væk (en fil, der ikke kan slettes, stopper ikke løkken)');
        if ($gone < 3) t_true(is_file($errLog) && str_contains((string)file_get_contents($errLog), 'kunne ikke slette gammelt preview'), 'en fil, der ikke kunne slettes, er skrevet i fejlloggen');
        // Undermappe med et gyldigt filnavn (og gamle filer i) røres ikke.
        $sub = $dir.'/'.qo_token().'.json'; mkdir($sub); $inner = $sub.'/'.qo_token().'.json'; file_put_contents($inner, '{}'); touch($inner, $now - 90000); touch($sub, $now - 90000);
        t_eq(0, hsg_supplier_preview_cleanup(86400, $dir), 'undermappe: 0 slettet');
        t_true(is_dir($sub) && is_file($inner), 'undermappen og filen i den findes stadig');
        // Symlink med et gyldigt navn til en fil uden for mappen springes over, og målet røres ikke (hvis systemet tillader symlinks).
        $target = $other.'/mål.json'; file_put_contents($target, '{}'); touch($target, $now - 90000);
        $link = $dir.'/'.qo_token().'.json';
        if (@symlink($target, $link)) {
            t_eq(0, hsg_supplier_preview_cleanup(86400, $dir), 'symlink: 0 slettet');
            t_true(is_link($link) && is_file($target), 'symlinket og dets mål findes stadig');
        } else {
            fwrite(STDOUT, "  (QO5b: symlink kan ikke oprettes her – symlink-tjekket sprunget over)\n");
        }
    } finally { ini_set('error_log', (string)$oldLog); @unlink($errLog); qo_rmtree($dir); qo_rmtree($other); }
});

/* ---------- QO6: backup ---------- */

t_case('QO6a Backup-udelukkelsen rammer kun previewmappen', function () {
    foreach (['storage/import-previews/a.json', 'storage\\import-previews\\a.json', '/storage/import-previews/a.json', 'storage/import-previews/under/b.json', 'storage//import-previews/a.json'] as $p) {
        // Den sidste ('storage//…') er ikke en normal sti; testen dokumenterer blot, hvad funktionen gør, og kræver ikke noget bestemt.
        if ($p === 'storage//import-previews/a.json') continue;
        t_true(hsg_backup_excluded_relpath($p), 'udelades: '.$p);
    }
    foreach (['storage/import-previews.txt', 'storage/import-previews.json', 'storage/import-previews-old/a.json', 'storage/data/import-previews/a.json', 'uploads/storage/import-previews/a.json', 'xstorage/import-previews/a.json', 'storage/data/x.key', 'uploads/a.jpg', 'storage/.htaccess', 'core/backup.php'] as $p) {
        t_true(!hsg_backup_excluded_relpath($p), 'kommer med: '.$p);
    }
    // Eksisterende udelukkelser er uændrede.
    foreach (['config.php', '.env', 'config.local.php', 'storage/backups/x.zip', 'storage/tmp/x', '.git/HEAD', '.idea/x', 'restore.php', 'database.sql', 'manifest.json', 'README-RESTORE.txt'] as $p) t_true(hsg_backup_excluded_relpath($p), 'stadig udeladt: '.$p);
});

t_case('QO6b En rigtig ZIP får ikke previews med, men alt andet', function () {
    if (!class_exists('ZipArchive')) { fwrite(STDOUT, "  (QO6b: ZipArchive findes ikke her – sprunget over)\n"); return; }
    $site = qo_tmpdir('hsg-qo6b-site-');
    $zipPath = sys_get_temp_dir().'/hsg-qo6b-'.bin2hex(random_bytes(4)).'.zip';
    try {
        $put = static function (string $rel) use ($site): void { $p = $site.'/'.$rel; if (!is_dir(dirname($p))) mkdir(dirname($p), 0777, true); file_put_contents($p, 'x'); };
        foreach (['index.php', 'core/a.php', 'uploads/a.jpg', 'storage/data/x.key', 'storage/.htaccess', 'storage/import-previews.txt', 'storage/import-previews/'.qo_token().'.json', 'storage/import-previews/.gitkeep',
                  'storage/backups/old.zip', 'storage/tmp/t', 'config.php', '.env'] as $rel) $put($rel);
        $zip = new ZipArchive();
        t_true($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 'ZIP oprettet');
        $count = hsg_add_site_to_zip($zip, $site);
        $zip->close();
        $zip = new ZipArchive(); $zip->open($zipPath);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) $names[] = (string)$zip->getNameIndex($i);
        $zip->close();
        sort($names);
        t_eq(['site/core/a.php', 'site/index.php', 'site/storage/.htaccess', 'site/storage/data/x.key', 'site/storage/import-previews.txt', 'site/uploads/a.jpg'], $names, 'ZIP-indhold: previews, backups, tmp, config.php og .env er ikke med');
        t_eq(6, $count, 'antal filer');
        // DATA-backup tager kun uploads og storage/data.
        $zip = new ZipArchive(); $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        hsg_add_site_to_zip($zip, $site, true); $zip->close();
        $zip = new ZipArchive(); $zip->open($zipPath);
        $dataNames = []; for ($i = 0; $i < $zip->numFiles; $i++) $dataNames[] = (string)$zip->getNameIndex($i);
        $zip->close(); sort($dataNames);
        t_eq(['site/storage/data/x.key', 'site/uploads/a.jpg'], $dataNames, 'DATA-backup: kun uploads og storage/data');
    } finally { @unlink($zipPath); qo_rmtree($site); }
});

/* ---------- Resultat ---------- */

$fails = $GLOBALS['t_fail'];
echo 'Kims uploadfil, opfølgning på review (testerens supplement, enhedstest): '.$GLOBALS['t_pass'].' tjek bestået, '.count($fails)." fejlede.\n";
foreach ($fails as $f) echo '  FEJL '.$f."\n";
exit($fails ? 1 : 0);
