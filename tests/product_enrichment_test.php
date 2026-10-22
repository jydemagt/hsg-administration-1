<?php
declare(strict_types=1);
/*
 * Enhedstest af fadnummer-udledningen (core/product_enrichment.php) og modulmigrationen products 1.5.1
 * (spec 2026-10-22-fadnummer-kraever-ciffer, kriterie 78-82 og 88; hed 60-64 og 70 før omnummereringen).
 * Kun rene funktioner: ingen database og intet netværk.
 * Køres af tests/run.sh:  php tests/product_enrichment_test.php   (exit-kode ≠ 0 ved fejl)
 */

error_reporting(E_ALL);
set_error_handler(static function (int $no, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $no) || in_array($no, [E_DEPRECATED, E_USER_DEPRECATED], true)) return true;
    throw new ErrorException($message, 0, $no, $file, $line);
});

$root = dirname(__DIR__);
require $root.'/core/supplier_import.php';

$GLOBALS['t_pass'] = 0;
$GLOBALS['t_fail'] = [];
$GLOBALS['t_case'] = '';

function t_record(bool $ok, string $label, string $detail = ''): void {
    if ($ok) { $GLOBALS['t_pass']++; return; }
    $GLOBALS['t_fail'][] = '['.$GLOBALS['t_case'].'] '.$label.($detail !== '' ? "\n      ".$detail : '');
}
function t_true(bool $cond, string $label): void { t_record($cond, $label); }
function t_eq(mixed $expected, mixed $actual, string $label): void {
    t_record($expected === $actual, $label, 'forventet '.var_export($expected, true).', fik '.var_export($actual, true));
}
function t_case(string $name, callable $fn): void {
    $GLOBALS['t_case'] = $name;
    try { $fn(); } catch (Throwable $e) { t_record(false, 'uventet fejl: '.get_class($e).': '.$e->getMessage().' ('.basename($e->getFile()).':'.$e->getLine().')'); }
}

/** Én filrække i Kims format (samme kolonner som tests/supplier_import_test.php: D=varenr., E=navn, H=destilleri). */
function pe_kim_row(array $v): array {
    $row = array_fill(0, 47, '');
    $cols = ['sku' => 4, 'name' => 5, 'dist' => 7];
    foreach ($v as $k => $val) $row[$cols[$k]] = (string)$val;
    return $row;
}
/** Feltkort som kim_map(false) i tests/supplier_import_test.php: lokationskolonner R/S/T, ingen total. */
function pe_kim_map(): array { return ['sku' => 4, 'name' => 5, 'distillery' => 7, 'stock_loc_1' => 17, 'stock_loc_2' => 18, 'stock_loc_3' => 19]; }

/* Tekst => [i dag (gammel udledning), efter (ny regel)] – tabellen i kriterie 78. */
$table = [
    'a' => ['Cask Strength', 'STRENGTH', null],
    'b' => ['Cask Finish', 'FINISH', null],
    'c' => ['Cask Craft', 'CRAFT', null],
    'd' => ['Cask No. 231584', '231584', '231584'],
    'e' => ['#200', '200', '200'],
    'f' => ['R10/123', null, null],
    'g' => ['Cask R10/123', 'R10/123', 'R10/123'],
    'h' => ['#R10/123', 'R10/123', 'R10/123'],
    'i' => ['Batch #3', null, null],
    'j' => ['Batch #12', null, null],
    'k' => ['1st fill', null, null],
    'l' => ['Cask Strength, Cask No. 231584', 'STRENGTH', '231584'],
    'm' => ['Knockdhu, 10yo, 2012 - #43 - Virgin Oak Barrel Finish, 58,2%', 'FINISH', '43'],
    'n' => ['Fadstyrke', 'STYRKE', null],
    'o' => ['Barrel 1st Fill', '1ST', '1ST'],
    'p' => ['', null, null],
    // Efter review: årstalsfilteret er nu eneste værn mod et årstal som fadnummer, og "0" er et ciffer.
    'q' => ['Cask 2012', null, null],
    'r' => ['#2015 Cask Strength', 'STRENGTH', null],
    's' => ['Cask 2012, #43', '43', '43'],
    't' => ['#00', '00', '00'],
];

t_case('78 hsg_extract_cask_number kræver et ciffer (filter i løkken)', function () use ($table) {
    foreach ($table as $k => [$text, , $after]) {
        t_eq($after, hsg_extract_cask_number($text), $k.') '.var_export($text, true));
    }
});

t_case('79 hsg_cask_number_candidates gengiver den gamle udledning', function () use ($table) {
    foreach ($table as $k => [$text, $today]) {
        $c = hsg_cask_number_candidates($text);
        t_eq($today, $c[0] ?? null, $k.') første kandidat = i dag');
        foreach ($c as $v) t_eq(strtoupper($v), $v, $k.') store bogstaver: '.$v);
        t_true(array_is_list($c), $k.') liste');
    }
    t_eq(['FINISH', '43'], hsg_cask_number_candidates($table['m'][0]), 'm) [FINISH, 43]');
    t_eq(['STRENGTH', '231584'], hsg_cask_number_candidates($table['l'][0]), 'l) [STRENGTH, 231584]');
    t_eq([], hsg_cask_number_candidates($table['j'][0]), 'j) []');
    t_eq([], hsg_cask_number_candidates($table['p'][0]), 'p) []');
});

t_case('80 hsg_product_parse_text', function () {
    $r = hsg_product_parse_text('Glenfarclas 15 Cask Strength, 60%');
    t_true(!array_key_exists('cask_number', $r['fields']), 'Cask Strength: ingen cask_number i fields');
    t_true(!array_key_exists('cask_number', $r['confidence']), 'Cask Strength: ingen cask_number i confidence');
    t_true(!str_contains($r['reason'], 'fadnummer'), 'Cask Strength: reason nævner ikke fadnummer ('.$r['reason'].')');

    $r = hsg_product_parse_text('MMD - Auchroisk, Cask Craft, Koval/Bourbon, 44,5%');
    t_true(!array_key_exists('cask_number', $r['fields']), 'Cask Craft: ingen cask_number');

    $r = hsg_product_parse_text('Clynelish 2013, Cask No. 231584, 56,1%');
    t_eq('231584', $r['fields']['cask_number'] ?? null, 'Cask No. 231584: fields');
    t_eq(99, $r['confidence']['cask_number'] ?? null, 'Cask No. 231584: confidence 99');
    t_true(str_contains($r['reason'], 'fadnummer aflæst direkte efter #/Cask No.'), 'Cask No. 231584: reason ('.$r['reason'].')');

    $r = hsg_product_parse_text('Knockdhu, 10yo, 2012 - #43 - Virgin Oak Barrel Finish, 58,2%');
    t_eq('43', $r['fields']['cask_number'] ?? null, 'Knockdhu: 43');
});

t_case('80n Normaliseringen er den samme som før (– og — bliver -, mellemrum samles)', function () {
    t_eq('Knockdhu (2012) - 10 år - 58,2 %', hsg_product_text_normalize("  Knockdhu (2012)  –  10 år — 58,2 %\t"), 'normaliseret tekst');
    t_eq('', hsg_product_text_normalize(" \n "), 'tom tekst');
});

t_case('81 hsg_supplier_source_fields', function () {
    $src = hsg_supplier_source_fields(pe_kim_row(['sku' => '20-300', 'name' => 'Glenfarclas 15 Cask Strength']), pe_kim_map());
    t_true(!array_key_exists('cask_number', $src), 'Cask Strength: ingen cask_number ('.json_encode($src, JSON_UNESCAPED_UNICODE).')');
    $src = hsg_supplier_source_fields(pe_kim_row(['sku' => '20-301', 'name' => 'Glen Test Sherry Cask #100']), pe_kim_map());
    t_eq('100', $src['cask_number'] ?? null, 'Sherry Cask #100: 100');
});

t_case('88 modules/products/migrations.php', function () use ($root) {
    $file = $root.'/modules/products/migrations.php';
    $before = get_defined_functions()['user'];
    ob_start();
    $first = require $file;
    $second = require $file;
    $out = ob_get_clean();
    t_eq('', $out, 'intet output');
    t_eq($before, get_defined_functions()['user'], 'ingen nye funktioner ved to indlæsninger');
    t_true(is_array($first) && is_array($second), 'returnerer et array');
    t_eq(['1.5.1'], array_map('strval', array_keys((array)$first)), 'præcis nøglen 1.5.1');
    t_true(is_callable($first['1.5.1'] ?? null), '1.5.1 er callable');
    $manifest = require $root.'/modules/products/module.php';
    t_eq('1.5.1', $manifest['version'] ?? null, 'products-version 1.5.1');
    $supplier = require $root.'/modules/supplier_upload/module.php';
    t_eq('1.1.1', $supplier['version'] ?? null, 'supplier_upload er uændret 1.1.1');
});

$fails = $GLOBALS['t_fail'];
foreach ($fails as $f) fwrite(STDOUT, "  FEJL: $f\n");
fwrite(STDOUT, sprintf("Fadnummer-udledning: %d tjek bestået, %d fejlede.\n", $GLOBALS['t_pass'], count($fails)));
exit($fails ? 1 : 0);
