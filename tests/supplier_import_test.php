<?php
declare(strict_types=1);
/*
 * Enhedstest af Kims uploadfil (core/supplier_import.php).
 * Kun rene funktioner med syntetiske data: ingen database, ingen ZipArchive og intet netværk. Alias-opslaget
 * testes med en fake-PDO (FakeAliasPdo) og en stub af db_table_exists. KAN-3 bruger en midlertidig fil i systemets temp-mappe.
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

/*
 * Fake-PDO til alias-opslaget i hsg_supplier_find_match (spec 2026-10-20, afsnit 8). Ingen database:
 * svarer kun på "SELECT product_id FROM hsg_supplier_sku_aliases …" ud fra et kort [leverandør-SKU => product_id]
 * uden forskel på store og små bogstaver (som MySQL-kollationen). Alle andre forespørgsler kaster en undtagelse.
 */
final class FakeAliasPdo extends PDO {
    /** @param array<string,int> $aliases */
    public function __construct(private array $aliases = []) {}
    public function prepare(string $query, array $options = []): PDOStatement|false {
        if (preg_match('/^\s*SELECT\s+product_id\s+FROM\s+hsg_supplier_sku_aliases\b/i', $query) !== 1) throw new LogicException('FakeAliasPdo: uventet forespørgsel: '.$query);
        return new FakeAliasStatement($this->aliases);
    }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false { throw new LogicException('FakeAliasPdo: uventet forespørgsel: '.$query); }
    public function exec(string $statement): int|false { throw new LogicException('FakeAliasPdo: uventet forespørgsel: '.$statement); }
}
final class FakeAliasStatement extends PDOStatement {
    private int|false $result = false;
    /** @param array<string,int> $aliases */
    public function __construct(private array $aliases) {}
    public function execute(?array $params = null): bool {
        $sku = (string)($params[0] ?? '');
        $this->result = false;
        foreach ($this->aliases as $alias => $pid) if (strcasecmp((string)$alias, $sku) === 0) { $this->result = (int)$pid; break; }
        return true;
    }
    public function fetchColumn(int $column = 0): mixed { return $this->result; }
}
if (!function_exists('db_table_exists')) {
    /** Stub: den rigtige ligger i migrations.php, som ikke indlæses her. Kun alias-tabellen "findes". */
    function db_table_exists(PDO $pdo, string $table): bool { return $table === 'hsg_supplier_sku_aliases'; }
}

/** Kildekoden til en fil i projektet (til de få tjek af sidens tekster, der ikke kræver en server). */
function project_source(string $relative): string { return (string)file_get_contents(__DIR__.'/../'.$relative); }

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

/* ---------- E. Rettelser efter review (tillæg 2026-10-20, kriterie 39–59) ----------
 * Kriterie 43 dækkes af de eksisterende tests 21, 22, 23, 23b, F2 og 26, og kriterie 52 af test 1, 2, 4, 5, 6 og 8.
 * Kriterie 57 (siden) og den del af 59, der kræver en POST, testes også i tests/supplier_upload_flow_test.php. */

// R1 – basens ejer og brand-filter

t_case('39 Gammel alias-kobling og brand-filter: 12-1123a matcher ikke 12-1123', function () {
    $product = hsg_product(['id' => 21, 'sku' => '12-1123', 'name' => 'St. Bridget Kirk, John Barleycorn Edition', 'distillery' => 'N/A', 'brand_id' => 9, 'stock_loc_1' => 15]);
    $pdo = new FakeAliasPdo(['12-1123a' => 21]);
    $items = hsg_supplier_merge_raw_items([clynelish_rows()[1]], kim_map(false), [$product], 5, 1, $pdo, merge_opts());
    t_eq(1, count($items), 'én vare');
    if (count($items) !== 1) return;
    t_eq('12-1123a', $items[0]['source']['sku'] ?? null, 'source.sku');
    t_true((int)$items[0]['match']['id'] !== 21, 'match.id ≠ 21');
    t_true(in_array('variant_split', $items[0]['flags'], true), 'flag variant_split');
    t_has_note($items[0], '12-1123a er ikke flettet med 12-1123', 'bemærkning');
    t_true(!$items[0]['selected'], 'ikke forvalgt');
});

t_case('40 Basen er kun koblet via alias: 12-1123 beholder HSG-55, 12-1123a står alene', function () {
    $product = hsg_product(['id' => 55, 'sku' => 'HSG-55', 'name' => 'St. Bridget Kirk, John Barleycorn Edition', 'distillery' => 'N/A', 'brand_id' => 9, 'stock_loc_1' => 24]);
    $pdo = new FakeAliasPdo(['12-1123' => 55, '12-1123a' => 55]);
    $rows = [clynelish_rows()[1], clynelish_rows()[0]];   // 12-1123a står først i filen
    $items = hsg_supplier_merge_raw_items($rows, kim_map(false), [$product], 9, 1, $pdo, merge_opts());
    $base = item_by_sku($items, '12-1123');
    $variant = item_by_sku($items, '12-1123a');
    t_true($base !== null && $variant !== null, 'begge varenumre findes');
    if ($base === null || $variant === null) return;
    t_eq(55, $base['match']['id'], '12-1123 matcher 55');
    t_true(!in_array('match_conflict', $base['flags'], true), '12-1123 har intet match_conflict');
    t_true((int)$variant['match']['id'] !== 55, '12-1123a matcher ikke 55');
    t_true(in_array('variant_split', $variant['flags'], true), '12-1123a har flag variant_split');
    t_true(!$variant['selected'], '12-1123a er ikke forvalgt');
});

function jane_2015_product(): array {
    return hsg_product(['id' => 30, 'sku' => '17-1030', 'name' => 'Jane Street 2015', 'distillery' => 'Jane', 'brand_id' => 9, 'stock_loc_1' => 1]);
}
function jane_2015_variant_row(): array {
    return kim_row(['sku' => '17-1030a', 'name' => 'Jane Street 2015', 'dist' => 'Jane', 'R' => 4]);
}

t_case('41 Brand-filteret respekteres: varianten flettes ikke ind i et produkt fra et andet brand', function () {
    $items = hsg_supplier_merge_raw_items([jane_2015_variant_row()], kim_map(false), [jane_2015_product()], 5, 1, new FakeAliasPdo([]), merge_opts());
    t_eq(1, count($items), 'én vare');
    if (count($items) !== 1) return;
    t_eq([], $items[0]['merged_skus'], 'merged_skus = []');
    t_eq('17-1030a', $items[0]['source']['sku'] ?? null, 'source.sku');
    t_true(!in_array('variant_split', $items[0]['flags'], true), 'intet variant_split');
    t_true((int)$items[0]['match']['id'] !== 30, 'match.id ≠ 30');
});

t_case('42 Gemt alias for basen: varianten flettes ind som i dag, også med brand-filter', function () {
    $items = hsg_supplier_merge_raw_items([jane_2015_variant_row()], kim_map(false), [jane_2015_product()], 5, 1, new FakeAliasPdo(['17-1030' => 30]), merge_opts());
    t_eq(1, count($items), 'én vare');
    if (count($items) !== 1) return;
    t_eq(30, $items[0]['match']['id'], 'match.id = 30');
    t_eq('17-1030', $items[0]['source']['sku'] ?? null, 'source.sku');
    t_eq(['17-1030a'], $items[0]['merged_skus'], 'merged_skus');
});

// R2 – fadnummer uden ciffer

t_case('44 Fadnummer uden ciffer tæller som tomt (variant_compatible)', function () {
    $name = 'Benriach 2010 Sherry Cask Finish';
    t_eq(['ok' => true, 'reason' => 'samme navn'], hsg_supplier_variant_compatible(['name' => $name, 'cask_number' => 'FINISH'], ['name' => $name, 'cask_number' => '4567']), 'FINISH over for 4567');
    t_true(hsg_supplier_variant_compatible(['name' => $name, 'cask_number' => 'CRAFT'], ['name' => $name, 'cask_number' => 'FINISH'])['ok'], 'CRAFT over for FINISH');
});

t_case('45 Rigtige fadnumre afviser stadig', function () {
    $r = hsg_supplier_variant_compatible(['name' => 'Ben Nevis 1996', 'cask_number' => 'R10/123'], ['name' => 'Ben Nevis 1996', 'cask_number' => 'R10/124']);
    t_eq(false, $r['ok'], 'R10/123 over for R10/124: ok=false');
    t_true(str_contains($r['reason'], 'forskelligt fadnummer'), 'reason: '.$r['reason']);
});

t_case('46 match_score: "fadnummer er identisk" kræver et ciffer', function () {
    $m = hsg_supplier_match_score(['name' => 'Glenfarclas 15 Cask Strength', 'cask_number' => 'STRENGTH'], ['id' => 1, 'sku' => 'X-1', 'name' => 'Ardbeg Corryvreckan Cask Strength', 'cask_number' => 'STRENGTH']);
    t_true($m['score'] < 90, 'STRENGTH/STRENGTH: score under 90 (fik '.$m['score'].')');
    t_true(!str_contains($m['reason'], 'fadnummer er identisk'), 'STRENGTH/STRENGTH: reason uden "fadnummer er identisk" ('.$m['reason'].')');
    t_eq(['score' => 98, 'reason' => 'fadnummer er identisk'], hsg_supplier_match_score(['name' => 'Clynelish 2013', 'cask_number' => '231584'], ['id' => 2, 'sku' => 'X-2', 'name' => 'St. Bridget Kirk', 'cask_number' => '231584']), '231584/231584 uændret');
});

// R3 – lokationsceller

t_case('47 En lokationscelle skal være ét tal', function () {
    $map = ['stock_loc_1' => 17];
    foreach (['3 + 2', '2 (1 i bil)', '1/2', 'ca. 5', 'se note 2', '3 2', '1.234', '1,234', '1 234', '?'] as $x) {
        t_eq(['values' => [], 'blank' => [], 'invalid' => ['stock_loc_1' => $x]], hsg_supplier_location_cells(kim_row(['R' => $x]), $map), 'ugyldig: '.$x);
    }
    $numbers = ['41' => 41, '0' => 0, '41.0' => 41, '2,4' => 2, '2.6' => 3, '-2' => 0, "5\u{00A0}" => 5, "\u{202F}7" => 7, '40.999999999999993' => 41,
        // Ikke i spec'ens tabel: komma med mange decimaler læses også som decimaltegn (ikke som 40999999999999993).
        '40,999999999999993' => 41];
    foreach ($numbers as $x => $n) {
        t_eq(['values' => ['stock_loc_1' => $n], 'blank' => [], 'invalid' => []], hsg_supplier_location_cells(kim_row(['R' => (string)$x]), $map), 'tal: '.json_encode((string)$x, JSON_UNESCAPED_UNICODE));
    }
});

t_case('48 Fritekst i en lokationscelle ændrer ikke lokationen', function () {
    $product = hsg_product(['id' => 48, 'sku' => '48-1', 'name' => 'Fritekst Whisky', 'stock_loc_1' => 5]);
    $row = kim_row(['sku' => '48-1', 'name' => 'Fritekst Whisky', 'R' => '3 + 2', 'S' => '', 'T' => 12]);
    $items = hsg_supplier_merge_raw_items([$row], kim_map(false), [$product], null, 1, null, merge_opts());
    t_eq(1, count($items), 'én vare');
    if (count($items) !== 1) return;
    t_eq(['stock_loc_2' => 0, 'stock_loc_3' => 12], $items[0]['stock_file']['locations'], 'stock_file.locations');
    t_eq(['stock_loc_1' => '3 + 2'], $items[0]['stock_file']['invalid'], 'stock_file.invalid');
    t_true(!array_key_exists('stock_loc_1', $items[0]['changes']), 'ingen ændring af Christiansfeld');
    t_has_note($items[0], 'Bemærk: Ugyldig værdi „3 + 2“ for Christiansfeld – lokationen ændres ikke.', 'bemærkning');
});

// R4 – kolonnegæt

t_case('49 Bar "Price" tager ikke udsalgsprisen fra "Retail Price (inc VAT)"', function () {
    t_eq([0 => 'sku', 1 => 'name', 2 => 'stock_quantity', 3 => '', 4 => 'wholesale_price', 5 => 'retail_price'],
        hsg_supplier_suggest_columns(['SKU', 'Product', 'Stock', 'Price', 'Trade Price', 'Retail Price (inc VAT)']), 'kolonnevalg');
});

t_case('50 Brugerens eksempel uden SKU-kolonne', function () {
    $map = hsg_supplier_suggest_columns(['Product', 'Stock', 'Price', 'Trade Price', 'Retail Price (inc VAT)']);
    t_eq('', $map[2] ?? null, 'kolonne 2 (Price)');
    t_eq('wholesale_price', $map[3] ?? null, 'kolonne 3 (Trade Price)');
    t_eq('retail_price', $map[4] ?? null, 'kolonne 4 (Retail Price (inc VAT))');
});

t_case('51 En bar "Price" får første ledige prisfelt (uændret)', function () {
    t_eq([0 => 'sku', 1 => 'wholesale_price', 2 => 'retail_price'], hsg_supplier_suggest_columns(['SKU', 'Price', 'Retail Price (inc VAT)']), 'SKU, Price, Retail Price (inc VAT)');
    t_eq([0 => 'sku', 1 => 'wholesale_price', 2 => 'retail_price'], hsg_supplier_suggest_columns(['SKU', 'Trade Price', 'Price']), 'SKU, Trade Price, Price');
    t_eq([0 => 'sku', 1 => 'wholesale_price'], hsg_supplier_suggest_columns(['SKU', 'Price']), 'SKU, Price');
});

// KAN-punkter

t_case('56 KAN-1: "Ingen tal i lokationskolonnerne" er en amber bemærkning', function () {
    $row = kim_row(['sku' => '10-1', 'name' => 'Tom vare', 'R' => '', 'S' => '', 'T' => '', 'Q' => 5]);
    $items = hsg_supplier_merge_raw_items([$row], kim_map(), [], null, 1, null, merge_opts());
    t_true(in_array('Bemærk: Ingen tal i lokationskolonnerne – lageret ændres ikke.', $items[0]['notes'] ?? [], true), 'notes indeholder præcis teksten');
    // Previewet viser bemærkninger, der starter med "Bemærk", i amber.
    t_true(str_contains(project_source('supplier_upload.php'), "str_starts_with((string)\$note,'Bemærk')?'style=\"color:var(--amber)"), 'supplier_upload.php viser "Bemærk …" i amber');
});

t_case('57 KAN-2: hjælpetekst under reservationsvalget', function () {
    $src = project_source('supplier_upload.php');
    $form = strpos($src, 'name="action" value="reservation_mode"');
    $text = strpos($src, "<small>Et nyt valg bruges først, når du har trykket 'Genberegn preview'. Tallene beregnes igen med de aktuelle reservationer, når du trykker 'Anvend'.</small>");
    t_true($text !== false, 'teksten findes i supplier_upload.php');
    t_true($form !== false && $text !== false && $text > $form, 'teksten står under reservationsvalget');
});

t_case('58 KAN-3: CSV læses med eksplicit enclosure og escape', function () {
    $path = tempnam(sys_get_temp_dir(), 'hsgcsv');
    t_true($path !== false, 'midlertidig fil');
    if ($path === false) return;
    $deprecations = [];
    try {
        file_put_contents($path, "SKU;Navn\n12-1;\"A;B\"\n");
        set_error_handler(static function (int $no, string $message, string $file, int $line) use (&$deprecations): bool {
            if (in_array($no, [E_DEPRECATED, E_USER_DEPRECATED], true)) { $deprecations[] = $message; return true; }
            throw new ErrorException($message, 0, $no, $file, $line);
        });
        try { $result = hsg_supplier_read_csv($path); } finally { restore_error_handler(); }
        t_eq(['CSV' => [['SKU', 'Navn'], ['12-1', 'A;B']]], $result, 'resultat');
        t_eq([], $deprecations, 'ingen deprecation fra fgetcsv');
    } finally {
        @unlink($path);
    }
    $fn = new ReflectionFunction('hsg_supplier_read_csv');
    $lines = array_slice((array)file((string)$fn->getFileName()), $fn->getStartLine() - 1, $fn->getEndLine() - $fn->getStartLine() + 1);
    $call = <<<'PHP'
fgetcsv($fh,0,$d,'"','\\')
PHP;
    t_true(str_contains(implode('', $lines), $call), 'koden kalder fgetcsv med eksplicit escape-parameter');
});

t_case('59 KAN-4: preview-token valideres ét sted', function () {
    t_true(hsg_supplier_preview_token_valid(str_repeat('abcdef0123', 4)), '40 små hex-tegn er gyldigt');
    t_true(!hsg_supplier_preview_token_valid(''), "'' er ugyldigt");
    t_true(!hsg_supplier_preview_token_valid('../x'), "'../x' er ugyldigt");
    t_true(!hsg_supplier_preview_token_valid(str_repeat('ABCDEF0123', 4)), '40 store hex-tegn er ugyldigt');
    $message = '';
    try { hsg_supplier_preview_load('../x'); } catch (RuntimeException $e) { $message = $e->getMessage(); }
    t_eq('Ugyldigt preview-token.', $message, 'preview_load afviser ../x, før der bygges en filsti');
});

/* ---------- F. "Lagerantal i alt" skal være ét tal og strammere lagerceller (tillæg 2026-10-22, kriterie 60–70) ----------
 * Kriterie 71 ("Fil:"-linjen) testes i tests/supplier_upload_flow_test.php, fordi funktionen ligger i supplier_upload.php. */

/** Bemærkningerne på en vare, der indeholder $needle. */
function notes_with(array $item, string $needle): array {
    return array_values(array_filter((array)($item['notes'] ?? []), static fn($n) => str_contains((string)$n, $needle)));
}

// Lagercellen (3.1, 3.4)

t_case('60 hsg_supplier_stock_cell: tom, tal og ugyldig', function () {
    foreach (['', '   ', "\u{00A0}", "\u{202F}", " \u{00A0}\t\u{202F} "] as $x) {
        $c = hsg_supplier_stock_cell($x);
        t_eq(['blank', null], [$c['state'], $c['value']], 'tom: '.json_encode($x));
    }
    $numbers = [['41', 41], ['0', 0], ['-0', 0], ['41.0', 41], ['2,4', 2], ['2.6', 3], ['-2', 0], ['-0.5', 0], ['0.5', 1], ['2,5', 3],
        ['0.4999', 0], ['0,4999', 0], ['2.4999', 2], ['99999', 99999], ["5\u{00A0}", 5], ['40.999999999999993', 41], ['40,999999999999993', 41]];
    foreach ($numbers as [$x, $n]) {
        $c = hsg_supplier_stock_cell($x);
        t_eq(['number', $n], [$c['state'], $c['value']], 'tal: '.json_encode($x));
    }
    $invalid = ['3 + 2', '53 stk.', '?', '-', '1e3', '−2',
        '1.234', '1,234', '1 234',
        '100000', '-100000', '000041', '5010494560018', '9223372036854775808', '99999999999999999999'];
    foreach ($invalid as $x) t_eq(['state' => 'invalid', 'value' => null, 'text' => $x], hsg_supplier_stock_cell($x), 'ugyldig: '.$x);
});

t_case('61 hsg_supplier_short_text afkorter til 40 tegn', function () {
    t_eq(str_repeat('a', 40), hsg_supplier_short_text(str_repeat('a', 40)), '40 × a er uændret');
    t_eq(str_repeat('ø', 40), hsg_supplier_short_text(str_repeat('ø', 40)), '40 × ø er uændret');
    t_eq(str_repeat('a', 40).'…', hsg_supplier_short_text(str_repeat('a', 41)), '41 × a');
    $r = hsg_supplier_short_text(str_repeat('a', 39).'æøå');
    t_eq(str_repeat('a', 39).'æ…', $r, '39 × a + æøå');
    t_eq(1, preg_match('//u', $r), 'resultatet er gyldig UTF-8');
    t_eq(str_repeat('ø', 40).'…', hsg_supplier_stock_cell(str_repeat('ø', 45))['text'], 'stock_cell(45 × ø)[text]');
});

// Lokationsceller (P2, P3, P4, P5)

t_case('62 Lokationsceller: hårde mellemrum er tomme, højst 5 cifre og én afrunding', function () {
    t_eq(['values' => ['stock_loc_1' => 41, 'stock_loc_2' => 0, 'stock_loc_3' => 0], 'blank' => ['stock_loc_2', 'stock_loc_3'], 'invalid' => []],
        hsg_supplier_location_cells(kim_row(['R' => 41, 'S' => "\u{00A0}", 'T' => " \u{202F} "]), kim_map(false)), 'R=41, S=NBSP, T=smalt NBSP');
    $row = kim_row(['sku' => '62-1', 'name' => 'Hårde Mellemrum', 'R' => "\u{00A0}", 'S' => "\u{202F}", 'T' => " \u{00A0}\t "]);
    t_eq(['values' => [], 'blank' => ['stock_loc_1', 'stock_loc_2', 'stock_loc_3'], 'invalid' => []], hsg_supplier_location_cells($row, kim_map(false)), 'kun hårde mellemrum');
    $items = hsg_supplier_merge_raw_items([$row], kim_map(false), [], null, 1, null, merge_opts());
    t_eq(1, count($items), 'én vare');
    if (count($items) !== 1) return;
    t_has_note($items[0], 'Bemærk: Ingen tal i lokationskolonnerne – lageret ændres ikke.', 'bemærkning');
    t_eq([], notes_with($items[0], 'Ugyldig værdi'), 'ingen bemærkning med "Ugyldig værdi"');
    $cells = hsg_supplier_location_cells(kim_row(['R' => '0.4999', 'S' => '9223372036854775808', 'T' => '5010494560018']), kim_map(false));
    t_eq(['stock_loc_1' => 0], $cells['values'], 'values');
    t_eq(['stock_loc_2' => '9223372036854775808', 'stock_loc_3' => '5010494560018'], $cells['invalid'], 'invalid');
});

t_case('63 Lang ugyldig tekst i en lokationscelle afkortes', function () {
    $product = hsg_product(['id' => 63, 'sku' => '63-1', 'name' => 'Lang Tekst Whisky', 'stock_loc_1' => 5]);
    $row = kim_row(['sku' => '63-1', 'name' => 'Lang Tekst Whisky', 'R' => str_repeat('ø', 45), 'S' => 1, 'T' => '']);
    $items = hsg_supplier_merge_raw_items([$row], kim_map(false), [$product], null, 1, null, merge_opts());
    t_eq(1, count($items), 'én vare');
    if (count($items) !== 1) return;
    $short = str_repeat('ø', 40).'…';
    t_eq(['stock_loc_1' => $short], $items[0]['stock_file']['invalid'], 'stock_file.invalid');
    t_true(!array_key_exists('stock_loc_1', $items[0]['changes']), 'changes har ingen stock_loc_1');
    t_eq(['Bemærk: Ugyldig værdi „'.$short.'“ for Christiansfeld – lokationen ændres ikke.'], notes_with($items[0], 'Ugyldig værdi'), 'præcis bemærkningen med den afkortede tekst');
});

// "Lagerantal i alt" i tilstand L (3.2)

t_case('64 Tilstand L: ugyldig "Lagerantal i alt" i source_fields', function () {
    $src = hsg_supplier_source_fields(kim_row(['sku' => '64-1', 'name' => 'X', 'R' => 41, 'S' => '', 'T' => 12, 'Q' => '3 + 2']), kim_map());
    t_eq(41, $src['stock_loc_1'] ?? null, 'stock_loc_1');
    t_eq(0, $src['stock_loc_2'] ?? null, 'stock_loc_2');
    t_eq(12, $src['stock_loc_3'] ?? null, 'stock_loc_3');
    t_eq('3 + 2', $src['stock_file_total_invalid'] ?? null, 'stock_file_total_invalid');
    t_true(!array_key_exists('stock_file_total', $src), 'intet stock_file_total');
    t_true(!array_key_exists('stock_quantity', $src), 'intet stock_quantity');
    $nbsp = hsg_supplier_source_fields(kim_row(['sku' => '64-1', 'name' => 'X', 'R' => 41, 'S' => '', 'T' => 12, 'Q' => "\u{00A0}"]), kim_map());
    t_true(!array_key_exists('stock_file_total', $nbsp), 'Q=NBSP: intet stock_file_total');
    t_true(!array_key_exists('stock_file_total_invalid', $nbsp), 'Q=NBSP: intet stock_file_total_invalid');
    $round = hsg_supplier_source_fields(kim_row(['sku' => '64-1', 'name' => 'X', 'R' => 41, 'S' => '', 'T' => 12, 'Q' => '0.4999']), kim_map());
    t_eq(0, $round['stock_file_total'] ?? null, 'Q=0.4999: stock_file_total = 0');
});

t_case('65 Tilstand L: "3 + 2" giver ingen falsk kontrolafvigelse', function () {
    // Samme destilleri i HSG og filen (som i test 13). Ellers udleder navneparseren destilleriet "Kontrol Whisky" af navnet,
    // og changes får også en destilleri-ændring.
    $product = hsg_product(['id' => 65, 'sku' => '65-1', 'name' => 'Kontrol Whisky', 'distillery' => 'Testdestilleri', 'stock_loc_1' => 41, 'stock_loc_2' => 2, 'stock_loc_3' => 12]);
    $row = kim_row(['sku' => '65-1', 'name' => 'Kontrol Whisky', 'dist' => 'Testdestilleri', 'R' => 41, 'S' => '', 'T' => 12, 'Q' => '3 + 2']);
    $items = hsg_supplier_merge_raw_items([$row], kim_map(), [$product], null, 1, null, merge_opts());
    t_eq(1, count($items), 'én vare');
    if (count($items) !== 1) return;
    $it = $items[0];
    t_eq(['stock_loc_2' => ['old' => 2, 'new' => 0, 'file' => 0]], $it['changes'], 'changes');
    t_eq(['stock_loc_1' => 41, 'stock_loc_2' => 0, 'stock_loc_3' => 12], $it['stock_file']['locations'], 'stock_file.locations');
    t_eq(null, $it['stock_file']['total'], 'stock_file.total');
    t_eq('3 + 2', $it['stock_file']['total_invalid'] ?? 'MANGLER', 'stock_file.total_invalid');
    t_eq([], $it['flags'], 'ingen flag');
    t_eq([], notes_with($it, 'passer ikke med lagerantal i alt'), 'ingen afvigelsesbemærkning');
    t_has_note($it, 'Bemærk: Ugyldig værdi „3 + 2“ for lagerantal i alt – bruges ikke som kontroltal.', 'bemærkning L');
    t_true($it['selected'], 'forvalgt');
    t_true(!array_key_exists('stock_file_total', $it['source']), 'source har intet stock_file_total');
    t_true(!array_key_exists('stock_file_total_invalid', $it['source']), 'source har intet stock_file_total_invalid');
});

// "Lagerantal i alt" i tilstand T (3.2)

t_case('66 Tilstand T: ugyldig "Lagerantal i alt" i source_fields', function () {
    $isStockKey = static fn($k) => str_starts_with((string)$k, 'stock_loc_') || in_array($k, ['stock_file_total', 'stock_quantity'], true);
    $row = kim_row(['sku' => '66-1', 'name' => 'X', 'Q' => '3 + 2']);
    $src = hsg_supplier_source_fields($row, kim_map(true, false), 1);
    t_eq('3 + 2', $src['stock_file_total_invalid'] ?? null, 'fallback 1: stock_file_total_invalid');
    t_eq([], array_values(array_filter(array_keys($src), $isStockKey)), 'fallback 1: ingen stock_loc_*, stock_file_total eller stock_quantity');
    $legacy = hsg_supplier_source_fields($row, kim_map(true, false));
    t_eq('3 + 2', $legacy['stock_file_total_invalid'] ?? null, 'uden fallback-id: stock_file_total_invalid');
    t_eq([], array_values(array_filter(array_keys($legacy), $isStockKey)), 'uden fallback-id: ingen lagernøgler (heller ikke stock_quantity)');
    $nbsp = hsg_supplier_source_fields(kim_row(['sku' => '66-1', 'name' => 'X', 'Q' => "\u{00A0}"]), kim_map(true, false), 1);
    t_eq([], array_values(array_filter(array_keys($nbsp), $isStockKey)), 'Q=NBSP: ingen lagernøgler');
    t_true(!array_key_exists('stock_file_total_invalid', $nbsp), 'Q=NBSP: intet stock_file_total_invalid');
    $round = hsg_supplier_source_fields(kim_row(['sku' => '66-1', 'name' => 'X', 'Q' => '0.4999']), kim_map(true, false), 1);
    t_eq(0, $round['stock_loc_1'] ?? null, 'Q=0.4999: stock_loc_1 = 0');
    t_eq(0, $round['stock_file_total'] ?? null, 'Q=0.4999: stock_file_total = 0');
});

t_case('67 Tilstand T: "3 + 2" skrives ikke som 32 på Christiansfeld', function () {
    $product = hsg_product(['id' => 67, 'sku' => '67-1', 'name' => 'Total Whisky', 'stock_loc_1' => 41, 'stock_loc_2' => 2, 'stock_loc_3' => 12]);
    $row = kim_row(['sku' => '67-1', 'name' => 'Total Whisky', 'dist' => 'Testdestilleri', 'Q' => '3 + 2']);
    $items = hsg_supplier_merge_raw_items([$row], kim_map(true, false), [$product], null, 1, null, merge_opts());
    t_eq(1, count($items), 'én vare');
    if (count($items) !== 1) return;
    $it = $items[0];
    t_eq([], array_values(array_filter(array_keys($it['changes']), static fn($k) => str_starts_with((string)$k, 'stock_loc_'))), 'changes har ingen stock_loc_*');
    t_true(array_key_exists('distillery', $it['changes']), 'changes indeholder distillery');
    t_eq('total', $it['stock_file']['mode'], 'stock_file.mode');
    t_eq([], $it['stock_file']['locations'], 'stock_file.locations');
    t_eq(null, $it['stock_file']['total'], 'stock_file.total');
    t_eq('3 + 2', $it['stock_file']['total_invalid'] ?? 'MANGLER', 'stock_file.total_invalid');
    t_eq(0, $it['stock_file']['sum'], 'stock_file.sum');
    t_has_note($it, 'Bemærk: Ugyldig værdi „3 + 2“ for lagerantal i alt – lageret ændres ikke.', 'bemærkning T');
    t_eq([], notes_with($it, 'skrives på Christiansfeld'), 'ingen "skrives på Christiansfeld"');
    t_eq([], $it['flags'], 'ingen flag');
    t_true($it['selected'], 'forvalgt på grund af destilleriet');
    $new = hsg_supplier_merge_raw_items([$row], kim_map(true, false), [], null, 1, null, merge_opts());
    t_eq(1, count($new), 'uden match: én vare');
    if (count($new) !== 1) return;
    t_eq(0, $new[0]['total_stock'], 'uden match: total_stock');
    t_true(!$new[0]['is_create_new'], 'uden match: is_create_new er falsk');
    t_true(!$new[0]['selected'], 'uden match: ikke forvalgt');
});

// Fletning (3.3)

function jane_2015_rows(mixed $q1, mixed $q2, bool $withLocations = true): array {
    return [
        kim_row(['sku' => '17-1028', 'name' => 'Jane Street 2015'] + ($withLocations ? ['R' => 5, 'S' => '', 'T' => ''] : []) + ['Q' => $q1]),
        kim_row(['sku' => '17-1028a', 'name' => 'Jane Street 2015'] + ($withLocations ? ['R' => '', 'S' => 2, 'T' => ''] : []) + ['Q' => $q2]),
    ];
}

t_case('68 Fletning i tilstand L: én ugyldig total gør gruppens total ugyldig', function () {
    $items = hsg_supplier_merge_raw_items(jane_2015_rows('3 + 2', 2), kim_map(), [], null, 1, null, merge_opts());
    t_eq(1, count($items), 'én vare');
    if (count($items) !== 1) return;
    t_eq(['stock_loc_1' => 5, 'stock_loc_2' => 2, 'stock_loc_3' => 0], $items[0]['stock_file']['locations'], 'stock_file.locations');
    t_eq(null, $items[0]['stock_file']['total'], 'stock_file.total');
    t_eq('3 + 2', $items[0]['stock_file']['total_invalid'] ?? 'MANGLER', 'stock_file.total_invalid');
    t_true(!in_array('stock_mismatch', $items[0]['flags'], true), 'intet stock_mismatch');
    t_has_note($items[0], 'Bemærk: Ugyldig værdi „3 + 2“ for lagerantal i alt – bruges ikke som kontroltal.', 'bemærkning L');
    $items = hsg_supplier_merge_raw_items(jane_2015_rows(5, '?'), kim_map(), [], null, 1, null, merge_opts());
    t_eq(1, count($items), 'Q=5 og Q=?: én vare');
    if (count($items) !== 1) return;
    t_eq(null, $items[0]['stock_file']['total'], 'Q=5 og Q=?: delsummen 5 bruges ikke');
    t_eq('?', $items[0]['stock_file']['total_invalid'] ?? 'MANGLER', 'Q=5 og Q=?: stock_file.total_invalid');
    t_true(!in_array('stock_mismatch', $items[0]['flags'], true), 'Q=5 og Q=?: intet stock_mismatch');
    t_eq([], notes_with($items[0], 'passer ikke med lagerantal i alt'), 'Q=5 og Q=?: ingen afvigelse');
});

t_case('69 Fletning i tilstand T: ingen delsum skrives', function () {
    $items = hsg_supplier_merge_raw_items(jane_2015_rows(5, '?', false), kim_map(true, false), [], null, 1, null, merge_opts());
    t_eq(1, count($items), 'én vare');
    if (count($items) !== 1) return;
    t_eq([], array_values(array_filter(array_keys($items[0]['source']), static fn($k) => str_starts_with((string)$k, 'stock_loc_') || $k === 'stock_file_total')), 'source har hverken stock_loc_* eller stock_file_total');
    t_eq('?', $items[0]['stock_file']['total_invalid'] ?? 'MANGLER', 'stock_file.total_invalid');
    t_eq(0, $items[0]['total_stock'], 'total_stock');
    t_has_note($items[0], 'Bemærk: Ugyldig værdi „?“ for lagerantal i alt – lageret ændres ikke.', 'bemærkning T');
    $items = hsg_supplier_merge_raw_items(jane_2015_rows(5, 2, false), kim_map(true, false), [], null, 1, null, merge_opts());
    t_eq(1, count($items), 'Q=5 og Q=2: én vare');
    if (count($items) !== 1) return;
    t_eq(7, $items[0]['source']['stock_loc_1'] ?? null, 'Q=5 og Q=2: source.stock_loc_1');
    t_eq(7, $items[0]['stock_file']['total'], 'Q=5 og Q=2: stock_file.total');
    t_true(array_key_exists('total_invalid', $items[0]['stock_file']) && $items[0]['stock_file']['total_invalid'] === null, 'Q=5 og Q=2: stock_file.total_invalid findes og er null');
});

t_case('70 merge_source og parse_decimal er uændrede', function () {
    t_eq(['stock_file_total' => 3, 'name' => 'A', 'stock_file_total_invalid' => '?'],
        hsg_supplier_merge_source(['stock_file_total' => 3, 'name' => 'A'], ['stock_file_total_invalid' => '?', 'name' => 'B']), 'stock_file_total_invalid lægges til');
    t_eq('a', hsg_supplier_merge_source(['stock_file_total_invalid' => 'a'], ['stock_file_total_invalid' => 'b'])['stock_file_total_invalid'], 'den primære rækkes tekst bevares');
    t_eq(1234.5, hsg_supplier_parse_decimal('1.234,50'), "parse_decimal('1.234,50')");
    t_eq(53.0, hsg_supplier_parse_decimal('53 stk.'), "parse_decimal('53 stk.')");
    t_eq(0.5, hsg_supplier_parse_decimal('0.4999'), "parse_decimal('0.4999')");
});

/* ---------- G. Testerens tillæg til tillæg 2026-10-22 (QA1–QA7 og 71u) ----------
 * Fanger fejl, som kriterie 60–70 ikke fanger (fundet ved mutationstest af kernen): ikke-ASCII-cifre, ugyldig UTF-8,
 * tilstand T uden fallback-id, fletning begge veje, fletning af enheder (trin 2), den interne nøgle, reservationsadvarsler,
 * bagudkompatible tal og "Fil:"-linjen (kriterie 71) uden database. */

t_case('QA1 Lagercellen: ikke-ASCII-cifre, ugyldig UTF-8, foranstillede nuller og hårde mellemrum', function () {
    // Kun cifrene 0–9 er cifre. (\d med /u ville ellers acceptere arabisk-indiske og fuldbredde-cifre og læse dem som 0.)
    foreach (['٣', '٤١', '４１', '4１', '²'] as $x) t_eq(['state' => 'invalid', 'value' => null, 'text' => $x], hsg_supplier_stock_cell($x), 'ikke-ASCII-ciffer er ugyldigt: '.$x);
    // Ugyldig UTF-8 er ugyldig, aldrig tom, og teksten afkortes ikke.
    foreach (["\xff", " \xff ", "5\xff", "\xc3", "41\x80"] as $x) {
        $c = hsg_supplier_stock_cell($x);
        t_eq(['invalid', null, trim($x)], [$c['state'], $c['value'], $c['text']], 'ugyldig UTF-8: '.bin2hex($x));
    }
    // Foranstillede nuller tæller med i de 5 cifre; negative med 5 cifre bliver 0.
    foreach ([['00041', 41], ['-99999', 0], ['99999,4', 99999], ["\u{00A0}5\t", 5], ["\t\u{202F}41 \u{00A0}", 41]] as [$x, $n]) {
        $c = hsg_supplier_stock_cell($x);
        t_eq(['number', $n], [$c['state'], $c['value']], 'tal: '.json_encode($x));
    }
    // Mellemrum midt i teksten gør cellen ugyldig, og hårde mellemrum i enderne fjernes fra den viste tekst.
    t_eq(['state' => 'invalid', 'value' => null, 'text' => "5\t\u{00A0}2"], hsg_supplier_stock_cell("5\t\u{00A0}2"), 'hårdt mellemrum og tabulator midt i tallet');
    t_eq(['state' => 'invalid', 'value' => null, 'text' => '?'], hsg_supplier_stock_cell("?\u{00A0}"), '"?" + NBSP vises som "?"');
    // Tekst på præcis 40 og 41 tegn med æøå.
    $t40 = str_repeat('æøå', 13).'æ';
    t_eq($t40, hsg_supplier_stock_cell($t40)['text'], '40 tegn æøå er uændret');
    t_eq($t40.'…', hsg_supplier_stock_cell($t40.'x')['text'], '41 tegn æøå afkortes til 40 + …');
    t_eq(1, preg_match('//u', hsg_supplier_stock_cell($t40.'x')['text']), 'afkortet tekst er gyldig UTF-8');
});

t_case('QA2 Rene tal giver samme værdi som den gamle formel (bagudkompatibelt)', function () {
    $old = static fn(string $s): int => (int)max(0, round((float)hsg_supplier_parse_decimal(str_replace(',', '.', $s))));
    $samples = ['0', '-1', '-5', '-0.5', '-0,5'];
    for ($n = 0; $n <= 99999; $n += 997) $samples[] = (string)$n;
    foreach ([1, 9, 10, 41, 99, 100, 999, 1000, 9999, 10000, 99999] as $n) $samples[] = (string)$n;
    foreach ([0, 1, 2, 5, 10, 41, 12345, 99999] as $n) foreach (['.0', ',0', '.00', ',00', '.5', ',5', '.25', ',75', '.99', ',10', '.4', '.6'] as $dec) $samples[] = $n.$dec;
    $mismatch = [];
    foreach ($samples as $s) {
        $c = hsg_supplier_stock_cell($s);
        if ($c['state'] !== 'number' || $c['value'] !== $old($s)) $mismatch[$s] = [$c['state'], $c['value'], $old($s)];
    }
    t_eq([], $mismatch, 'alle '.count($samples).' rene tal giver samme værdi som (int)max(0, round(parse_decimal(…)))');
    // Samme resultat i hele vejen ned gennem source_fields (tilstand T med og uden fallback, og tilstand L).
    $diff = [];
    foreach (['0', '5', '41', '99999', '2,6', '41.0'] as $s) {
        $row = kim_row(['sku' => 'QA2', 'name' => 'Rene tal', 'R' => $s, 'Q' => $s]);
        $n = (int)$old($s);
        $t = hsg_supplier_source_fields($row, kim_map(true, false), 1);
        $t0 = hsg_supplier_source_fields($row, kim_map(true, false));
        $l = hsg_supplier_source_fields($row, kim_map());
        if (($t['stock_loc_1'] ?? null) !== $n || ($t['stock_file_total'] ?? null) !== $n) $diff[] = "T $s";
        if (($t0['stock_quantity'] ?? null) !== $n) $diff[] = "T0 $s";
        if (($l['stock_loc_1'] ?? null) !== $n || ($l['stock_file_total'] ?? null) !== $n) $diff[] = "L $s";
    }
    t_eq([], $diff, 'source_fields giver samme tal som før for rene tal');
});

t_case('QA3 Tilstand T uden fallback-id: stock_quantity-vejen, også ved fletning', function () {
    $noFb = ['fallback_location_id' => null, 'location_names' => [1 => 'Christiansfeld'], 'add_reserved' => true];
    $one = hsg_supplier_merge_raw_items([kim_row(['sku' => 'QA3-1', 'name' => 'Ingen Fallback', 'Q' => 7])], kim_map(true, false), [], null, 1, null, $noFb);
    t_eq(7, $one[0]['source']['stock_quantity'] ?? null, 'gyldig total: source.stock_quantity (uændret vej)');
    t_eq(7, $one[0]['total_stock'], 'gyldig total: total_stock');
    $bad = hsg_supplier_merge_raw_items([kim_row(['sku' => 'QA3-2', 'name' => 'Ingen Fallback Ugyldig', 'Q' => '3 + 2'])], kim_map(true, false), [], null, 1, null, $noFb);
    t_true(!array_key_exists('stock_quantity', $bad[0]['source']), 'ugyldig total: ingen stock_quantity');
    t_eq(0, $bad[0]['total_stock'], 'ugyldig total: total_stock 0');
    t_true(!$bad[0]['is_create_new'] && !$bad[0]['selected'], 'ugyldig total: ikke oprettet og ikke valgt');
    // Fletning begge veje: delsummen må ikke blive i stock_quantity.
    foreach ([[5, '?'], ['?', 5], ['3 + 2', 2]] as [$q1, $q2]) {
        $items = hsg_supplier_merge_raw_items(jane_2015_rows($q1, $q2, false), kim_map(true, false), [], null, 1, null, $noFb);
        t_eq(1, count($items), "uden fallback $q1/$q2: én vare");
        if (count($items) !== 1) continue;
        t_true(!array_key_exists('stock_quantity', $items[0]['source']), "uden fallback $q1/$q2: ingen stock_quantity i source");
        t_eq(0, $items[0]['total_stock'], "uden fallback $q1/$q2: total_stock 0");
        t_eq(null, $items[0]['stock_file']['total'], "uden fallback $q1/$q2: stock_file.total");
    }
    $ok = hsg_supplier_merge_raw_items(jane_2015_rows(5, 2, false), kim_map(true, false), [], null, 1, null, $noFb);
    t_eq(7, $ok[0]['source']['stock_quantity'] ?? null, 'uden fallback 5/2: source.stock_quantity = 7 (som før)');
});

t_case('QA4 Fletning i tilstand T: den primære række er ugyldig, den ekstra gyldig', function () {
    foreach ([['?', 5], ['3 + 2', 2], ['', '?'], ['?', '']] as [$q1, $q2]) {
        $items = hsg_supplier_merge_raw_items(jane_2015_rows($q1, $q2, false), kim_map(true, false), [], null, 1, null, merge_opts());
        t_eq(1, count($items), "T $q1/$q2: én vare");
        if (count($items) !== 1) continue;
        $stockKeys = array_values(array_filter(array_keys($items[0]['source']), static fn($k) => str_starts_with((string)$k, 'stock_')));
        t_eq([], $stockKeys, "T $q1/$q2: source har ingen lagernøgler");
        t_eq(null, $items[0]['stock_file']['total'], "T $q1/$q2: stock_file.total");
        t_eq([], $items[0]['stock_file']['locations'], "T $q1/$q2: stock_file.locations");
        t_eq(0, $items[0]['total_stock'], "T $q1/$q2: total_stock");
        t_eq($q1 === '' ? $q2 : $q1, $items[0]['stock_file']['total_invalid'], "T $q1/$q2: total_invalid er første ugyldige tekst");
    }
    // Tom + gyldig er som før.
    $items = hsg_supplier_merge_raw_items(jane_2015_rows('', 4, false), kim_map(true, false), [], null, 1, null, merge_opts());
    t_eq(4, $items[0]['stock_file']['total'] ?? null, 'tom + 4: total 4');
    t_true(array_key_exists('total_invalid', $items[0]['stock_file']) && $items[0]['stock_file']['total_invalid'] === null, 'tom + 4: total_invalid findes og er null');
    // Tre rækker: gyldig, ugyldig, gyldig.
    $rows = [
        kim_row(['sku' => '17-1028', 'name' => 'Jane Street 2015', 'Q' => 5]),
        kim_row(['sku' => '17-1028a', 'name' => 'Jane Street 2015', 'Q' => 'abc']),
        kim_row(['sku' => '17-1028b', 'name' => 'Jane Street 2015', 'Q' => 3]),
    ];
    $items = hsg_supplier_merge_raw_items($rows, kim_map(true, false), [], null, 1, null, merge_opts());
    t_eq([1, null, 'abc', 0], [count($items), $items[0]['stock_file']['total'], $items[0]['stock_file']['total_invalid'], $items[0]['total_stock']], 'tre rækker: gruppens total er ugyldig');
});

t_case('QA5 Fletning trin 2: to enheder matcher samme HSG-produkt, og den ene total er ugyldig', function () {
    $product = hsg_product(['id' => 75, 'sku' => '75-1', 'name' => 'Trin To Whisky', 'distillery' => 'Testdestilleri', 'stock_loc_1' => 3]);
    $rows = static fn($q1, $q2) => [
        kim_row(['sku' => '75-1', 'name' => 'Trin To Whisky', 'dist' => 'Testdestilleri', 'Q' => $q1]),
        kim_row(['sku' => '75-2', 'name' => 'Trin To Whisky', 'dist' => 'Testdestilleri', 'Q' => $q2]),
    ];
    foreach ([[5, '?'], ['?', 5]] as [$q1, $q2]) {
        $items = hsg_supplier_merge_raw_items($rows($q1, $q2), kim_map(true, false), [$product], null, 1, null, merge_opts());
        t_eq(1, count($items), "$q1/$q2: de to enheder er flettet til én vare");
        if (count($items) !== 1) continue;
        t_eq(75, $items[0]['match']['id'], "$q1/$q2: match på produkt 75");
        t_eq([], array_values(array_filter(array_keys($items[0]['changes']), static fn($k) => str_starts_with((string)$k, 'stock_loc_'))), "$q1/$q2: ingen stock_loc_* i changes");
        t_eq('?', $items[0]['stock_file']['total_invalid'], "$q1/$q2: total_invalid");
        t_eq(0, $items[0]['total_stock'], "$q1/$q2: total_stock");
    }
    $items = hsg_supplier_merge_raw_items($rows(5, 2), kim_map(true, false), [$product], null, 1, null, merge_opts());
    t_eq(1, count($items), '5/2: én vare');
    if (count($items) === 1) t_eq(7, $items[0]['changes']['stock_loc_1']['new'] ?? null, '5/2: Christiansfeld 3 → 7 (som før)');
});

t_case('QA6 Den interne nøgle stock_file_total_invalid når aldrig source, changes eller apply/create', function () {
    $leaks = [];
    $maps = ['L' => [kim_map(), merge_opts()], 'T' => [kim_map(true, false), merge_opts()], 'T0' => [kim_map(true, false), ['fallback_location_id' => null, 'location_names' => [], 'add_reserved' => true]]];
    $product = hsg_product(['id' => 76, 'sku' => '17-1028', 'name' => 'Jane Street 2015', 'stock_loc_1' => 1, 'stock_loc_2' => 2, 'stock_loc_3' => 3]);
    foreach ($maps as $label => [$map, $opts]) {
        foreach ([['3 + 2', 2], [5, '?'], ['?', '?'], [5, 2], ['', '?']] as [$q1, $q2]) {
            foreach ([[], [$product]] as $products) {
                foreach (hsg_supplier_merge_raw_items(jane_2015_rows($q1, $q2, $label === 'L'), $map, $products, null, 1, null, $opts) as $it) {
                    foreach (['source', 'changes'] as $part) if (array_key_exists('stock_file_total_invalid', $it[$part])) $leaks[] = "$label $q1/$q2 $part";
                    if (str_contains(json_encode($it['changes']), 'invalid')) $leaks[] = "$label $q1/$q2 changes-JSON";
                    if (!array_key_exists('total_invalid', $it['stock_file'])) $leaks[] = "$label $q1/$q2 mangler stock_file.total_invalid";
                }
            }
        }
    }
    t_eq([], $leaks, 'ingen lækager');
    // Selv hvis nøglen skulle ligge i source, er den aldrig en ændring.
    $changes = hsg_supplier_changes_for_product(['stock_file_total_invalid' => 'x', 'stock_file_total' => 9, 'stock_loc_1' => 5], hsg_product(['stock_loc_1' => 1]), ['add_reserved' => true]);
    t_eq(['stock_loc_1'], array_keys($changes), 'changes_for_product ignorerer stock_file_total_invalid og stock_file_total');
    // apply og create læser kun kendte felter (kildetekst): nøglen nævnes ikke.
    $core = project_source('core/supplier_import.php');
    $apply = (string)substr($core, (int)strpos($core, 'function hsg_supplier_apply_product'), (int)strpos($core, 'function hsg_supplier_remember_alias') - (int)strpos($core, 'function hsg_supplier_apply_product'));
    t_true($apply !== '' && !str_contains($apply, 'total_invalid'), 'apply_product og create_product nævner ikke stock_file_total_invalid');
});

t_case('QA7 Ugyldig total og reservationer: ingen advarsel om reservationer, når intet skrives (tilstand T)', function () {
    $product = hsg_product(['id' => 77, 'sku' => '77-1', 'name' => 'Reservation Whisky', 'distillery' => 'Testdestilleri', 'stock_loc_1' => 10, 'stock_loc_2' => 5, 'reserved_loc_1' => 4]);
    $row = static fn($q) => kim_row(['sku' => '77-1', 'name' => 'Reservation Whisky', 'dist' => 'Testdestilleri', 'Q' => $q]);
    $bad = hsg_supplier_merge_raw_items([$row('3 + 2')], kim_map(true, false), [$product], null, 1, null, merge_opts(false));
    t_eq([], notes_with($bad[0], 'reserveret'), 'ugyldig total + "none": ingen reservationsadvarsel');
    t_eq([], notes_with($bad[0], 'skrives på'), 'ugyldig total + "none": ingen "skrives på"');
    $good = hsg_supplier_merge_raw_items([$row(2)], kim_map(true, false), [$product], null, 1, null, merge_opts(false));
    t_true(count(notes_with($good[0], 'reserveret')) === 1, 'gyldig total 2 < reserveret 4 + "none": advarslen vises som før');
});

t_case('71u "Fil:"-linjen med en ugyldig "Lagerantal i alt" (kriterie 71, enhed uden database)', function () {
    if (!function_exists('supplier_upload_file_line')) {
        // Funktionen ligger i supplier_upload.php, som ikke kan indlæses uden server og database. Den hentes ud af kildeteksten.
        t_eq(1, preg_match('/^function supplier_upload_file_line\(.*?^}\n/ms', project_source('supplier_upload.php'), $m), 'funktionen findes i supplier_upload.php');
        if (!$m) return;
        eval($m[0]);
    }
    $names = ['stock_loc_1' => 'Christiansfeld', 'stock_loc_2' => 'Michael', 'stock_loc_3' => 'Gert'];
    $keys = array_keys($names);
    $locs = ['mode' => 'locations', 'locations' => ['stock_loc_1' => 41, 'stock_loc_2' => 0, 'stock_loc_3' => 12], 'blank' => ['stock_loc_2'], 'invalid' => [], 'sum' => 53];
    t_eq('Fil: Christiansfeld 41 · Michael 0 (tom) · Gert 12 · i alt „3 + 2“ (ugyldig)', supplier_upload_file_line(['stock_file' => $locs + ['total' => null, 'total_invalid' => '3 + 2']], $keys, $names), 'tilstand L');
    t_eq('Fil: lagerantal i alt „3 + 2“ (ugyldig)', supplier_upload_file_line(['stock_file' => ['mode' => 'total', 'locations' => [], 'total' => null, 'total_invalid' => '3 + 2', 'sum' => 0]], [], $names), 'tilstand T');
    // Ældre previews uden nøglen vises som før.
    t_eq('Fil: Christiansfeld 41 · Michael 0 (tom) · Gert 12 · i alt 53', supplier_upload_file_line(['stock_file' => $locs + ['total' => 53]], $keys, $names), 'ældre preview, tilstand L');
    t_eq('Fil: lagerantal i alt 53 → skrives på Christiansfeld', supplier_upload_file_line(['stock_file' => ['mode' => 'total', 'locations' => ['stock_loc_1' => 53], 'total' => 53, 'sum' => 53]], [], $names), 'ældre preview, tilstand T');
    t_eq('Fil: Christiansfeld 41 · Michael 0 (tom) · Gert 12 · i alt 0', supplier_upload_file_line(['stock_file' => $locs + ['total' => 0, 'total_invalid' => null]], $keys, $names), 'total 0 er et tal, ikke en ugyldig total');
    t_eq('', supplier_upload_file_line(['stock_file' => ['mode' => 'total', 'locations' => [], 'total' => null, 'total_invalid' => null]], [], $names), 'tilstand T uden total: tom linje');
    // Hele vejen fra en celle: kim_row → merge_raw_items → linjen.
    $items = hsg_supplier_merge_raw_items([kim_row(['sku' => '71-1', 'name' => 'Fil Linje', 'R' => 41, 'S' => '', 'T' => 12, 'Q' => '3 + 2'])], kim_map(), [], null, 1, null, merge_opts());
    t_eq('Fil: Christiansfeld 41 · Michael 0 (tom) · Gert 12 · i alt „3 + 2“ (ugyldig)', supplier_upload_file_line($items[0], $keys, $names), 'fra celle til linje, tilstand L');
    $items = hsg_supplier_merge_raw_items([kim_row(['sku' => '71-2', 'name' => 'Fil Linje To', 'Q' => '3 + 2'])], kim_map(true, false), [], null, 1, null, merge_opts());
    t_eq('Fil: lagerantal i alt „3 + 2“ (ugyldig)', supplier_upload_file_line($items[0], [], $names), 'fra celle til linje, tilstand T');
});

/* ---------- Resultat ---------- */

$fails = $GLOBALS['t_fail'];
foreach ($fails as $f) fwrite(STDOUT, "  FEJL: $f\n");
fwrite(STDOUT, sprintf("Kims uploadfil: %d tjek bestået, %d fejlede.\n", $GLOBALS['t_pass'], count($fails)));
exit($fails ? 1 : 0);
