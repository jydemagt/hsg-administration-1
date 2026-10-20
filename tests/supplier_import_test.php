<?php
declare(strict_types=1);
/*
 * Enhedstest af Kims uploadfil (core/supplier_import.php).
 * Kun rene funktioner med syntetiske data: ingen database, ingen ZipArchive og intet netværk.
 * Køres af tests/run.sh:  php tests/supplier_import_test.php   (exit-kode ≠ 0 ved fejl)
 */

error_reporting(E_ALL);
set_error_handler(static function (int $no, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $no) || in_array($no, [E_DEPRECATED, E_USER_DEPRECATED], true)) return true;
    throw new ErrorException($message, 0, $no, $file, $line);
});

require __DIR__.'/../core/supplier_import.php';

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
function t_has_note(array $item, string $needle, string $label): void {
    $found = false;
    foreach ((array)($item['notes'] ?? []) as $n) if (str_contains((string)$n, $needle)) { $found = true; break; }
    t_record($found, $label, 'bemærkninger: '.var_export($item['notes'] ?? [], true));
}
function t_case(string $name, callable $fn): void {
    $GLOBALS['t_case'] = $name;
    try { $fn(); } catch (Throwable $e) { t_record(false, 'uventet fejl: '.get_class($e).': '.$e->getMessage().' ('.basename($e->getFile()).':'.$e->getLine().')'); }
}

/* ---------- Syntetiske testdata ---------- */

/** Kims overskrifter A–AU (indeks 0–46). Ukendte kolonner er ''. */
function kim_headers(): array {
    $h = array_fill(0, 47, '');
    $known = [
        2 => 'Stk.', 4 => 'Varenr.', 5 => 'Variant:', 6 => 'Alder:', 7 => 'Destilleri:', 9 => 'Aftapper:', 10 => 'Alc.:',
        11 => 'Kostpris', 12 => 'Solgt/ stk', 14 => 'Lagerværdi', 16 => 'Lagerbeholdning', 17 => 'Christiansfeld',
        18 => 'hos Michael', 19 => 'hos Gert', 20 => 'optalt d. xxxx Christiansfeld', 24 => 'kostpris ex. Moms:',
        25 => 'Engros salgspris (ex moms)', 26 => 'vejl. salgspris (inkl moms)',
        34 => 'Henrik stockflet', 35 => 'Keld', 36 => 'Brian', 37 => 'Mike Knudsen', 38 => 'jack', 39 => 'jørn',
        40 => 'michael', 41 => 'kim', 42 => 'delle',
        44 => 'sponsor til whisky.dk', 45 => 'per (hr.klingenberg@gmail.com)', 46 => 'KWL (send til Klaus)',
    ];
    foreach ($known as $i => $v) $h[$i] = $v;
    return $h;
}

/** Feltkort med lokationskolonner: R→Christiansfeld (1), S→Michael (2), T→Gert (3), Q→Lagerantal i alt. */
function kim_map(bool $withTotal = true, bool $withLocations = true): array {
    $map = ['sku' => 4, 'name' => 5, 'distillery' => 7];
    if ($withTotal) $map['stock_quantity'] = 16;
    if ($withLocations) { $map['stock_loc_1'] = 17; $map['stock_loc_2'] = 18; $map['stock_loc_3'] = 19; }
    return $map;
}

/** Én filrække i Kims format. */
function kim_row(array $v): array {
    $row = array_fill(0, 47, '');
    $cols = ['sku' => 4, 'name' => 5, 'age' => 6, 'dist' => 7, 'Q' => 16, 'R' => 17, 'S' => 18, 'T' => 19];
    foreach ($v as $k => $val) $row[$cols[$k]] = (string)$val;
    return $row;
}

/** HSG-produkt med lager og reservationer pr. lokation (1, 2, 3). */
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

/** Finder varen med et bestemt varenummer i resultatet fra merge_raw_items. */
function item_by_sku(array $items, string $sku): ?array {
    foreach ($items as $it) if (strcasecmp((string)($it['source']['sku'] ?? ''), $sku) === 0) return $it;
    return null;
}

/* ---------- A. Kolonnegæt ---------- */

t_case('1 Kims overskrifter uden husket valg', function () {
    $map = hsg_supplier_suggest_columns(kim_headers());
    $expected = [4 => 'sku', 5 => 'name', 6 => 'age_text', 7 => 'distillery', 9 => 'brand_name', 10 => 'abv',
        16 => 'stock_quantity', 25 => 'wholesale_price', 26 => 'retail_price'];
    foreach (kim_headers() as $ci => $_) t_eq($expected[$ci] ?? '', $map[$ci] ?? null, 'kolonne '.$ci);
});

t_case('2 Præcist match vinder over delvist match længere til venstre', function () {
    t_eq([0 => '', 1 => 'stock_quantity'], hsg_supplier_suggest_columns(['Lagerværdi', 'Lagerbeholdning']), 'Lagerværdi/Lagerbeholdning');
});

t_case('3 Beløbsoverskrifter', function () {
    foreach (['Lagerværdi', 'Lager kr.', 'Lagerbeløb', 'Kostpris', 'Værdi DKK'] as $h) t_true(hsg_supplier_header_is_money($h), $h.' er beløb');
    foreach (['Lagerbeholdning', 'Lagerantal', 'Antal', 'Christiansfeld', 'hos Michael', 'Stk.'] as $h) t_true(!hsg_supplier_header_is_money($h), $h.' er ikke beløb');
});

t_case('4 Produktnavn kræver præcist match for korte aliaser', function () {
    t_eq([0 => ''], hsg_supplier_suggest_columns(['sponsor til whisky.dk']), 'sponsor til whisky.dk');
    t_eq([0 => 'name'], hsg_supplier_suggest_columns(['Whisky']), 'Whisky');
});

t_case('5 Husket beløbskolonne som lager afvises', function () {
    $saved = ['lagervaerdi' => 'stock_quantity', 'variant' => 'name'];
    $map = hsg_supplier_suggest_columns(kim_headers(), $saved);
    t_eq('', $map[14], 'O (Lagerværdi) er afvist');
    t_eq('name', $map[5], 'F (Variant:) er husket');
    t_eq([14 => 'stock_quantity'], hsg_supplier_rejected_saved_columns(kim_headers(), $saved), 'col_rejected');
});

t_case('6 Husket "Ignorer" bevares trods præcist alias', function () {
    $map = hsg_supplier_suggest_columns(kim_headers(), ['varenr' => '', 'variant' => 'name']);
    t_eq('', $map[4], 'E (Varenr.) står stadig til Ignorer');
    t_eq('name', $map[5], 'F (Variant:)');
});

t_case('7 Beløbskolonne kan ikke vælges som lager', function () {
    t_true(!hsg_supplier_guess_allowed('stock_loc_1', 'Lagerværdi'), 'Lagerværdi som Christiansfeld afvises');
    t_true(!hsg_supplier_guess_allowed('stock_quantity', 'Lagerværdi'), 'Lagerværdi som lager i alt afvises');
    t_true(hsg_supplier_guess_allowed('stock_loc_1', 'Christiansfeld'), 'Christiansfeld er tilladt');
});

t_case('8 Gæt kolonner forfra beholder kun lokationskolonner', function () {
    $saved = ['christiansfeld' => 'stock_loc_1', 'engrossalgsprisexmoms' => 'retail_price', 'sponsortilwhiskydk' => 'name'];
    $before = hsg_supplier_suggest_columns(kim_headers(), $saved);
    t_eq('retail_price', $before[25], 'det huskede (forkerte) valg bruges uden "gæt forfra"');
    $map = hsg_supplier_reguess_columns(kim_headers(), $saved);
    t_eq('stock_loc_1', $map[17], 'R');
    t_eq('wholesale_price', $map[25], 'Z');
    t_eq('retail_price', $map[26], 'AA');
    t_eq('', $map[44], 'AS');
    t_eq('name', $map[5], 'F');
});

/* ---------- B. Lager pr. lokation og total ---------- */

t_case('9 Tom lokationscelle tæller som 0, total er kun kontroltal', function () {
    $src = hsg_supplier_source_fields(kim_row(['sku' => '13-1021', 'name' => 'X', 'R' => 41, 'S' => '', 'T' => 12, 'Q' => 53]), kim_map());
    t_eq(41, $src['stock_loc_1'] ?? null, 'stock_loc_1');
    t_eq(0, $src['stock_loc_2'] ?? null, 'stock_loc_2');
    t_eq(12, $src['stock_loc_3'] ?? null, 'stock_loc_3');
    t_eq(53, $src['stock_file_total'] ?? null, 'stock_file_total');
    t_true(!array_key_exists('stock_quantity', $src), 'ingen stock_quantity');
});

t_case('10 Alle lokationsceller tomme ændrer intet', function () {
    $row = kim_row(['sku' => '10-1', 'name' => 'Tom vare', 'R' => '', 'S' => '', 'T' => '', 'Q' => 5]);
    $src = hsg_supplier_source_fields($row, kim_map());
    t_eq([], array_values(array_filter(array_keys($src), static fn($k) => str_starts_with((string)$k, 'stock_loc_'))), 'ingen stock_loc_*');
    t_eq(5, $src['stock_file_total'] ?? null, 'stock_file_total');
    $items = hsg_supplier_merge_raw_items([$row], kim_map(), [], null, 1, null, merge_opts());
    t_has_note($items[0], 'Ingen tal i lokationskolonnerne – lageret ændres ikke.', 'bemærkning');
});

t_case('11 Tekst i en lokationscelle', function () {
    $row = kim_row(['sku' => '10-2', 'name' => 'Spørgsmålstegn', 'R' => 10, 'S' => '?', 'T' => '']);
    $src = hsg_supplier_source_fields($row, kim_map(false));
    t_eq(10, $src['stock_loc_1'] ?? null, 'stock_loc_1');
    t_eq(0, $src['stock_loc_3'] ?? null, 'stock_loc_3');
    t_true(!array_key_exists('stock_loc_2', $src), 'stock_loc_2 findes ikke');
    $cells = hsg_supplier_location_cells($row, kim_map(false));
    t_eq(['stock_loc_2' => '?'], $cells['invalid'], 'invalid');
    $items = hsg_supplier_merge_raw_items([$row], kim_map(false), [], null, 1, null, merge_opts());
    t_has_note($items[0], 'Bemærk: Ugyldig værdi „?“ for Michael – lokationen ændres ikke.', 'bemærkning');
});

t_case('11b "?" i en datarække gør den ikke til overskriftsrække', function () {
    $rows = [kim_headers(), kim_row(['sku' => '10-2', 'name' => 'Spørgsmålstegn', 'R' => 10, 'S' => '?', 'T' => '-'])];
    t_eq(0, hsg_supplier_detect_header($rows)['row'], 'overskriftsrække');
    t_eq([0 => ''], hsg_supplier_suggest_columns(['?']), 'overskriften "?" gættes ikke');
});

t_case('12 Kun lagerantal i alt skrives på første lokation', function () {
    $src = hsg_supplier_source_fields(kim_row(['sku' => '13-1021', 'name' => 'X', 'Q' => 53]), kim_map(true, false), 1);
    t_eq(53, $src['stock_loc_1'] ?? null, 'stock_loc_1');
    t_eq(53, $src['stock_file_total'] ?? null, 'stock_file_total');
    t_true(!array_key_exists('stock_quantity', $src), 'ingen stock_quantity');
    $legacy = hsg_supplier_source_fields(kim_row(['sku' => '13-1021', 'name' => 'X', 'Q' => 53]), kim_map(true, false));
    t_eq(53, $legacy['stock_quantity'] ?? null, 'uden fallback-id: stock_quantity som før');
});

t_case('13 Kun Michael ændres for 13-1021', function () {
    $product = hsg_product(['id' => 7, 'sku' => '13-1021', 'name' => 'Vare 13-1021', 'distillery' => 'Testdestilleri', 'stock_loc_1' => 41, 'stock_loc_2' => 2, 'stock_loc_3' => 12]);
    $row = kim_row(['sku' => '13-1021', 'name' => 'Vare 13-1021', 'dist' => 'Testdestilleri', 'R' => 41, 'S' => '', 'T' => 12, 'Q' => 53]);
    $items = hsg_supplier_merge_raw_items([$row], kim_map(), [$product], null, 1, null, merge_opts());
    t_eq(1, count($items), 'én vare');
    t_eq(7, $items[0]['match']['id'], 'match');
    t_eq(['stock_loc_2' => ['old' => 2, 'new' => 0, 'file' => 0]], $items[0]['changes'], 'eneste ændring er Michael 2 → 0');
    t_eq(['stock_loc_2'], $items[0]['stock_file']['blank'], 'Michael er (tom)');
    t_eq(53, $items[0]['stock_file']['total'], 'kontroltal');
    t_eq([], $items[0]['flags'], 'ingen flag');
    t_true($items[0]['selected'], 'forvalgt');
});

t_case('15 Kontrolafvigelse', function () {
    $row = kim_row(['sku' => '15-1', 'name' => 'Afvigelse', 'R' => 41, 'S' => '', 'T' => 12, 'Q' => 60]);
    $items = hsg_supplier_merge_raw_items([$row], kim_map(), [], null, 1, null, merge_opts());
    t_has_note($items[0], 'Summen af lokationskolonnerne (53) passer ikke med lagerantal i alt (60)', 'bemærkning');
    t_true(in_array('stock_mismatch', $items[0]['flags'], true), 'flag stock_mismatch');
    t_true(!$items[0]['selected'], 'ikke forvalgt');
});

t_case('16 Nyt produkt: badge-tal er summen af lokationer', function () {
    $row = kim_row(['sku' => '16-1', 'name' => 'Ny whisky', 'R' => 5, 'S' => '', 'T' => 3, 'Q' => 8]);
    $items = hsg_supplier_merge_raw_items([$row], kim_map(), [], null, 1, null, merge_opts());
    t_eq(8, $items[0]['total_stock'], 'total_stock');
    t_true($items[0]['is_create_new'], 'is_create_new');
    t_true(!array_key_exists('stock_quantity', $items[0]['source']), 'ingen stock_quantity i kilden');
    t_eq(5, $items[0]['source']['stock_loc_1'] ?? null, 'Christiansfeld 5');
    t_eq(3, $items[0]['source']['stock_loc_3'] ?? null, 'Gert 3');
});

t_case('17 Totaltilstand vises som ændring af første lokation', function () {
    $product = hsg_product(['id' => 7, 'sku' => '13-1021', 'name' => 'Vare 13-1021', 'distillery' => 'Testdestilleri', 'stock_loc_1' => 41, 'stock_loc_2' => 2, 'stock_loc_3' => 12]);
    $row = kim_row(['sku' => '13-1021', 'name' => 'Vare 13-1021', 'dist' => 'Testdestilleri', 'Q' => 53]);
    $items = hsg_supplier_merge_raw_items([$row], kim_map(true, false), [$product], null, 1, null, merge_opts());
    t_eq(['stock_loc_1' => ['old' => 41, 'new' => 53, 'file' => 53]], $items[0]['changes'], 'Christiansfeld 41 → 53 og intet "Lagerantal i alt"');
    t_has_note($items[0], 'Bemærk: Lagerantal i alt (53) skrives på Christiansfeld. Produktet har også 14 stk. på andre lokationer, så lageret i alt bliver 67.', 'bemærkning');
});

t_case('18 Fletning af lokationstal med tomme celler', function () {
    $rows = [
        kim_row(['sku' => '17-1028', 'name' => 'Jane Street 2014', 'dist' => 'Jane', 'R' => 5, 'S' => '', 'T' => '']),
        kim_row(['sku' => '17-1028a', 'name' => 'Jane Street 2014', 'dist' => 'Jane', 'R' => '', 'S' => 2, 'T' => '']),
    ];
    $items = hsg_supplier_merge_raw_items($rows, kim_map(false), [], null, 1, null, merge_opts());
    t_eq(1, count($items), 'én vare');
    t_eq(['stock_loc_1' => 5, 'stock_loc_2' => 2, 'stock_loc_3' => 0], $items[0]['stock_file']['locations'], 'lokationer');
    t_eq(['stock_loc_3'], $items[0]['stock_file']['blank'], 'kun Gert er (tom)');
});

t_case('merge_source summerer stock_file_total', function () {
    $m = hsg_supplier_merge_source(['stock_file_total' => 3, 'stock_loc_1' => 3, 'name' => 'A'], ['stock_file_total' => 2, 'stock_loc_1' => 2, 'name' => 'B', 'abv' => 46.0]);
    t_eq(5, $m['stock_file_total'], 'stock_file_total');
    t_eq(5, $m['stock_loc_1'], 'stock_loc_1');
    t_eq('A', $m['name'], 'navn fra primær række');
    t_eq(46.0, $m['abv'], 'tomt felt udfyldes');
});

/* ---------- C. Bogstav-varenumre ---------- */

t_case('19 Clynelish er ikke St. Bridget Kirk', function () {
    $r = hsg_supplier_variant_compatible(
        ['name' => 'Clynelish, 2013, #231584 - Hogshead', 'distillery' => 'Clynelish', 'cask_number' => '231584', 'vintage_year' => 2013],
        ['name' => 'St. Bridget Kirk, John Barleycorn Edition', 'distillery' => 'N/A']
    );
    t_eq(false, $r['ok'], 'ok=false');
    t_true(str_contains($r['reason'], 'navnene er forskellige'), 'reason: '.$r['reason']);
});

t_case('20 Samme navn og destilleri / forskelligt fadnummer', function () {
    $r = hsg_supplier_variant_compatible(['name' => 'Ben Nevis 1996', 'distillery' => 'Ben Nevis'], ['name' => 'Ben Nevis 1996', 'distillery' => 'Ben Nevis']);
    t_eq(['ok' => true, 'reason' => 'samme navn'], $r, 'samme navn');
    $r = hsg_supplier_variant_compatible(['name' => 'Ben Nevis 1996', 'cask_number' => '100'], ['name' => 'Ben Nevis 1996', 'cask_number' => '#200']);
    t_eq(false, $r['ok'], 'forskelligt fad afviser');
    t_true(str_contains($r['reason'], 'forskelligt fadnummer'), 'reason: '.$r['reason']);
    $r = hsg_supplier_variant_compatible(['name' => 'Ben Nevis 1996', 'distillery' => 'Ben Nevis'], ['name' => 'Ben Nevis 1996', 'distillery' => 'Ledaig']);
    t_true(!$r['ok'] && str_contains($r['reason'], 'forskelligt destilleri'), 'forskelligt destilleri');
    $r = hsg_supplier_variant_compatible(['name' => 'Ben Nevis', 'vintage_year' => 1996], ['name' => 'Ben Nevis', 'vintage_year' => 1997]);
    t_true(!$r['ok'] && str_contains($r['reason'], 'forskellig årgang'), 'forskellig årgang');
    $r = hsg_supplier_variant_compatible(['name' => 'Ben Nevis', 'age_text' => '12 år'], ['name' => 'Ben Nevis', 'age_text' => '15 Years']);
    t_true(!$r['ok'] && str_contains($r['reason'], 'forskellig alder'), 'forskellig alder');
    $r = hsg_supplier_variant_compatible(['name' => '', 'distillery' => 'Ben Nevis'], ['name' => 'Ben Nevis 1996', 'distillery' => 'Ben Nevis']);
    t_true($r['ok'], 'navn mangler på den ene side, samme kendte destilleri');
    $r = hsg_supplier_variant_compatible(['name' => '', 'distillery' => 'N/A'], ['name' => 'Ben Nevis 1996', 'distillery' => 'N/A']);
    t_eq(['ok' => false, 'reason' => 'for få oplysninger til at afgøre, om det er samme vare'], $r, 'for få oplysninger');
});

function clynelish_rows(): array {
    return [
        kim_row(['sku' => '12-1123', 'name' => 'St. Bridget Kirk, John Barleycorn Edition', 'dist' => 'N/A', 'R' => 15]),
        kim_row(['sku' => '12-1123a', 'name' => 'Clynelish, 2013, #231584 - Hogshead', 'dist' => 'Clynelish', 'R' => 9]),
    ];
}

t_case('21 12-1123a flettes ikke med 12-1123', function () {
    $product = hsg_product(['id' => 21, 'sku' => '12-1123', 'name' => 'St. Bridget Kirk, John Barleycorn Edition', 'distillery' => 'N/A', 'stock_loc_1' => 24]);
    $items = hsg_supplier_merge_raw_items(clynelish_rows(), kim_map(false), [$product], null, 1, null, merge_opts());
    t_eq(2, count($items), 'to varer');
    $base = item_by_sku($items, '12-1123');
    $variant = item_by_sku($items, '12-1123a');
    t_true($base !== null && $variant !== null, 'begge varenumre findes');
    if ($base === null || $variant === null) return;
    t_eq(21, $base['match']['id'], '12-1123 matcher produktet');
    t_eq(15, $base['total_stock'], '12-1123 har 15 stk.');
    t_true((int)$variant['match']['id'] !== 21, '12-1123a matcher ikke 12-1123');
    t_true(in_array('variant_split', $variant['flags'], true), 'flag variant_split');
    t_has_note($variant, '12-1123a er ikke flettet med 12-1123', 'bemærkning');
    t_eq(9, $variant['total_stock'], 'total_stock 9');
    t_true(!$variant['selected'], 'ikke forvalgt');
});

t_case('22 Forurenet 12-1123 (fad 231584, 2013) matcher stadig ikke 12-1123a', function () {
    $product = hsg_product(['id' => 21, 'sku' => '12-1123', 'name' => 'St. Bridget Kirk, John Barleycorn Edition', 'distillery' => 'N/A', 'cask_number' => '231584', 'vintage_year' => 2013, 'stock_loc_1' => 24]);
    $items = hsg_supplier_merge_raw_items(clynelish_rows(), kim_map(false), [$product], null, 1, null, merge_opts());
    $variant = item_by_sku($items, '12-1123a');
    t_true($variant !== null, '12-1123a findes');
    if ($variant === null) return;
    t_true((int)$variant['match']['id'] !== 21, '12-1123a matcher ikke 12-1123');
    t_true(!$variant['selected'], 'ikke forvalgt');
});

t_case('23 Kun 12-1123a i filen, 12-1123 findes i HSG', function () {
    $product = hsg_product(['id' => 21, 'sku' => '12-1123', 'name' => 'St. Bridget Kirk, John Barleycorn Edition', 'distillery' => 'N/A', 'stock_loc_1' => 15]);
    $items = hsg_supplier_merge_raw_items([clynelish_rows()[1]], kim_map(false), [$product], null, 1, null, merge_opts());
    t_eq(1, count($items), 'én vare');
    t_eq('12-1123a', $items[0]['source']['sku'] ?? null, 'varen beholder sit eget varenummer');
    t_true((int)$items[0]['match']['id'] !== 21, 'matcher ikke 12-1123');
    t_eq([], $items[0]['merged_skus'], 'ikke flettet');
    t_true(in_array('variant_split', $items[0]['flags'], true), 'flag variant_split');
    t_true(!$items[0]['selected'], 'ikke forvalgt');
});

t_case('23b Kun 12-1123a i filen, og 12-1123 i HSG er forurenet med fadnummer og årgang', function () {
    $product = hsg_product(['id' => 21, 'sku' => '12-1123', 'name' => 'St. Bridget Kirk, John Barleycorn Edition', 'distillery' => 'N/A', 'cask_number' => '231584', 'vintage_year' => 2013, 'stock_loc_1' => 24]);
    $items = hsg_supplier_merge_raw_items([clynelish_rows()[1]], kim_map(false), [$product], null, 1, null, merge_opts());
    t_eq(1, count($items), 'én vare');
    t_true((int)$items[0]['match']['id'] !== 21, 'matcher ikke 12-1123, selvom fadnummeret er identisk');
    t_true(in_array('variant_split', $items[0]['flags'], true), 'flag variant_split');
    t_true(!$items[0]['selected'], 'ikke forvalgt');
});

t_case('25 17-1028 og 17-1028a med samme navn flettes', function () {
    $rows = [
        kim_row(['sku' => '17-1028', 'name' => 'Jane Street 2014', 'dist' => 'Jane', 'R' => 3, 'Q' => 3]),
        kim_row(['sku' => '17-1028a', 'name' => 'Jane Street 2014', 'dist' => 'Jane', 'S' => 2, 'Q' => 2]),
    ];
    $items = hsg_supplier_merge_raw_items($rows, kim_map(), [], null, 1, null, merge_opts());
    t_eq(1, count($items), 'én vare');
    t_eq(5, $items[0]['total_stock'], 'summeret lager');
    t_eq(5, $items[0]['stock_file']['total'], 'summeret kontroltal');
    t_eq(['17-1028a'], $items[0]['merged_skus'], 'merged_skus');
    t_has_note($items[0], 'Lagerantal fra 17-1028a er lagt sammen med 17-1028 (samme navn).', 'bemærkning');
    t_eq([], $items[0]['flags'], 'ingen flag');
});

t_case('F2 Kun variant i filen, samme vare i HSG flettes ind', function () {
    $product = hsg_product(['id' => 30, 'sku' => '17-1030', 'name' => 'Jane Street 2015', 'distillery' => 'Jane', 'stock_loc_1' => 1]);
    $items = hsg_supplier_merge_raw_items([kim_row(['sku' => '17-1030a', 'name' => 'Jane Street 2015', 'dist' => 'Jane', 'R' => 4])], kim_map(false), [$product], null, 1, null, merge_opts());
    t_eq(30, $items[0]['match']['id'], 'matcher 17-1030');
    t_eq('17-1030', $items[0]['source']['sku'] ?? null, 'skrives på 17-1030');
    t_eq(['17-1030a'], $items[0]['merged_skus'], 'merged_skus');
});

t_case('26 Forskellige varer, der matcher samme HSG-produkt', function () {
    $product = hsg_product(['id' => 50, 'sku' => '20-100', 'name' => 'Glen Test Sherry Cask', 'distillery' => 'Glen Test', 'cask_number' => '100']);
    $rows = [
        kim_row(['sku' => '20-100', 'name' => 'Glen Test Sherry Cask #100', 'dist' => 'Glen Test', 'R' => 2]),
        kim_row(['sku' => '20-200', 'name' => 'Glen Test Sherry Cask #200', 'dist' => 'Glen Test', 'R' => 4]),
    ];
    $items = hsg_supplier_merge_raw_items($rows, kim_map(false), [$product], null, 1, null, merge_opts());
    t_eq(2, count($items), 'to varer');
    $x = item_by_sku($items, '20-100');
    $y = item_by_sku($items, '20-200');
    t_true($x !== null && $y !== null, 'begge findes');
    if ($x === null || $y === null) return;
    t_eq(50, $x['match']['id'], 'X beholder matchet');
    t_eq(0, $y['match']['id'], 'Y har match.id=0');
    t_true(in_array('match_conflict', $y['flags'], true), 'flag match_conflict');
    t_has_note($y, 'Bemærk: Matcher samme HSG-produkt som 20-100', 'bemærkning');
    t_true(!$y['selected'], 'Y ikke forvalgt');
});

/* ---------- D. Reservationer ---------- */

function octomore(): array {
    return hsg_product(['id' => 292, 'sku' => '14-292', 'name' => 'Octomore 15.1', 'distillery' => 'Bruichladdich', 'stock_loc_1' => 0, 'reserved_loc_1' => 2]);
}
function octomore_row(): array {
    return kim_row(['sku' => '14-292', 'name' => 'Octomore 15.1', 'dist' => 'Bruichladdich', 'Q' => 0, 'R' => 0, 'S' => '', 'T' => '']);
}

t_case('27 add: reserverede flasker lægges oven i', function () {
    $src = hsg_supplier_source_fields(octomore_row(), kim_map());
    t_eq(['stock_loc_1' => ['old' => 0, 'new' => 2, 'file' => 0, 'reserved' => 2]], hsg_supplier_changes_for_product($src, octomore(), ['add_reserved' => true]), 'Christiansfeld 0 → 2 (fil 0 + 2 reserveret)');
    $items = hsg_supplier_merge_raw_items([octomore_row()], kim_map(), [octomore()], null, 1, null, merge_opts(true));
    t_eq(['stock_loc_1' => 2], $items[0]['reserved'], 'Reserveret i HSG');
    t_eq(2, $items[0]['changes']['stock_loc_1']['new'] ?? null, 'previewet viser 2');
});

t_case('28 none: ingen ændring, men advarsel', function () {
    $src = hsg_supplier_source_fields(octomore_row(), kim_map());
    t_eq([], hsg_supplier_changes_for_product($src, octomore(), ['add_reserved' => false]), 'ingen lagerændring');
    $items = hsg_supplier_merge_raw_items([octomore_row()], kim_map(), [octomore()], null, 1, null, merge_opts(false));
    t_has_note($items[0], 'Christiansfeld får 0 fysisk efter import, men 2 er reserveret', 'bemærkning');
});

t_case('29 Uden reservationer giver add og none det samme', function () {
    $product = hsg_product(['id' => 7, 'sku' => '13-1021', 'name' => 'X', 'stock_loc_1' => 41, 'stock_loc_2' => 2, 'stock_loc_3' => 12]);
    $src = hsg_supplier_source_fields(kim_row(['sku' => '13-1021', 'name' => 'X', 'R' => 40, 'S' => '', 'T' => 12, 'Q' => 52]), kim_map());
    t_eq(hsg_supplier_changes_for_product($src, $product, ['add_reserved' => false]), hsg_supplier_changes_for_product($src, $product, ['add_reserved' => true]), 'samme ændringer');
});

t_case('31 Ny reservation efter previewet bruges ved Anvend (ren del)', function () {
    $src = hsg_supplier_source_fields(kim_row(['sku' => '31-1', 'name' => 'X', 'R' => 3, 'S' => '', 'T' => '']), kim_map(false));
    $product = hsg_product(['id' => 31, 'sku' => '31-1', 'name' => 'X', 'stock_loc_1' => 5, 'reserved_loc_1' => 2]);
    t_eq([], hsg_supplier_changes_for_product($src, $product, ['add_reserved' => true]), 'preview: 3 + 2 = 5, ingen ændring');
    $product['reserved_loc_1'] = 3;
    t_eq(['stock_loc_1' => ['old' => 5, 'new' => 6, 'file' => 3, 'reserved' => 3]], hsg_supplier_changes_for_product($src, $product, ['add_reserved' => true]), 'anvend: 3 + 3 = 6');
});

t_case('stock_file_total er aldrig en ændring', function () {
    $changes = hsg_supplier_changes_for_product(['stock_file_total' => 99, 'stock_loc_1' => 1], hsg_product(['stock_loc_1' => 1]));
    t_eq([], $changes, 'ingen ændringer');
});

/* ---------- Resultat ---------- */

$fails = $GLOBALS['t_fail'];
foreach ($fails as $f) fwrite(STDOUT, "  FEJL: $f\n");
fwrite(STDOUT, sprintf("Kims uploadfil: %d tjek bestået, %d fejlede.\n", $GLOBALS['t_pass'], count($fails)));
exit($fails ? 1 : 0);
