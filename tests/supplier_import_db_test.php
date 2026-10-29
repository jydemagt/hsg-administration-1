<?php
declare(strict_types=1);
/*
 * Databasetest af Kims uploadfil (core/supplier_import.php): previewet (merge_raw_items med alias-tabellen) samt
 * hsg_supplier_apply_product / create_product / remember_alias mod en rigtig MySQL-database
 * (spec 2026-10-19, kriterie 13, 16, 17, 21-24, 27, 28, 29, 31, 33) samt arkiveringen af reservationer, statusvagten,
 * reservations-migrationen 1.3.0 og labelen "Arkiveret" (spec 2026-10-26-kims-upload-kun-lagerantal, kriterie 141-147, 161 og 162).
 *
 * KØRES KUN mod testdatabasen, som tests/run.sh har installeret (config.php + HSG_TEST_DB_NAME):
 *     php tests/supplier_import_db_test.php
 * Testen afviser at køre, hvis HSG_TEST_DB_NAME ikke er sat eller ikke passer til config.php, så den aldrig
 * rører en produktionsdatabase. Alle rækker har varenummer-præfikset TSTKIM- og ryddes op bagefter.
 */

error_reporting(E_ALL);
set_error_handler(static function (int $no, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $no) || in_array($no, [E_DEPRECATED, E_USER_DEPRECATED], true)) return true;
    throw new ErrorException($message, 0, $no, $file, $line);
});

$root = dirname(__DIR__);
if (!is_file($root.'/config.php')) { fwrite(STDERR, "config.php findes ikke – kør via tests/run.sh (røgtest med HSG_TEST_DB_*).\n"); exit(2); }
$config = require $root.'/config.php';
if ((string)getenv('HSG_TEST_DB_NAME') === '' || (string)getenv('HSG_TEST_DB_NAME') !== (string)($config['db_name'] ?? '')) {
    fwrite(STDERR, "Afvist: HSG_TEST_DB_NAME er ikke sat eller passer ikke til config.php. Testen køres aldrig mod andre databaser.\n");
    exit(2);
}
$pdo = new PDO('mysql:host='.$config['db_host'].';dbname='.$config['db_name'].';charset=utf8mb4', $config['db_user'], $config['db_pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false,
]);
require_once $root.'/functions.php';
require_once $root.'/migrations.php';
require_once $root.'/core/settings.php';
require_once $root.'/core/supplier_import.php';

$GLOBALS['t_pass'] = 0;
$GLOBALS['t_fail'] = [];
$GLOBALS['t_case'] = '';
function t_eq(mixed $expected, mixed $actual, string $label): void {
    if ($expected === $actual) { $GLOBALS['t_pass']++; return; }
    $GLOBALS['t_fail'][] = '['.$GLOBALS['t_case'].'] '.$label."\n      forventet ".var_export($expected, true).', fik '.var_export($actual, true);
}
function t_true(bool $cond, string $label): void { t_eq(true, $cond, $label); }
function t_case(string $name, callable $fn): void {
    $GLOBALS['t_case'] = $name;
    try { $fn(); } catch (Throwable $e) { $GLOBALS['t_fail'][] = '['.$name.'] uventet fejl: '.get_class($e).': '.$e->getMessage().' ('.basename($e->getFile()).':'.$e->getLine().')'; }
}
function t_has_note(array $item, string $needle, string $label): void {
    $found = false;
    foreach ((array)($item['notes'] ?? []) as $n) if (str_contains((string)$n, $needle)) { $found = true; break; }
    t_true($found, $label.' (bemærkninger: '.json_encode($item['notes'] ?? [], JSON_UNESCAPED_UNICODE).')');
}

/** Kun lagerændringerne (navnet kan give afledte felter som destilleri; dem ser testen bort fra). */
function stock_only(array $changes): array { return array_filter($changes, static fn($k) => str_starts_with((string)$k, 'stock_'), ARRAY_FILTER_USE_KEY); }

function db_cleanup(PDO $pdo): void {
    $ids = $pdo->query("SELECT id FROM lager_products WHERE sku LIKE 'TSTKIM-%'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) {
        foreach (['lager_reservations', 'lager_stock_movements', 'lager_stock', 'hsg_supplier_sku_aliases'] as $t) $pdo->prepare("DELETE FROM $t WHERE product_id=?")->execute([(int)$id]);
        $pdo->prepare('DELETE FROM lager_products WHERE id=?')->execute([(int)$id]);
    }
    $pdo->exec("DELETE FROM hsg_supplier_sku_aliases WHERE supplier_sku LIKE 'TSTKIM-%'");
    $locs = $pdo->query("SELECT id FROM lager_locations WHERE name LIKE 'TSTKIM %'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($locs as $l) {
        foreach (['lager_reservations', 'lager_stock_movements', 'lager_stock'] as $t) $pdo->prepare("DELETE FROM $t WHERE location_id=?")->execute([(int)$l]);
        $pdo->prepare('DELETE FROM lager_locations WHERE id=?')->execute([(int)$l]);
    }
}
function db_product(PDO $pdo, string $sku, string $name, array $stock = [], string $status = 'active'): int {
    $pdo->prepare('INSERT INTO lager_products(sku,name,status,bottle_size_cl) VALUES(?,?,?,70)')->execute([$sku, $name, $status]);
    $pid = (int)$pdo->lastInsertId();
    foreach ($stock as $lid => $qty) $pdo->prepare('INSERT INTO lager_stock(product_id,location_id,quantity) VALUES(?,?,?)')->execute([$pid, $lid, $qty]);
    return $pid;
}
function db_reserve(PDO $pdo, int $pid, int $lid, int $qty): void {
    $pdo->prepare("INSERT INTO lager_reservations(product_id,location_id,quantity,customer_name,reference,status) VALUES(?,?,?,'TSTKIM','TSTKIM','reserved')")->execute([$pid, $lid, $qty]);
}
function db_stock(PDO $pdo, int $pid): array {
    $st = $pdo->prepare('SELECT location_id,quantity FROM lager_stock WHERE product_id=? ORDER BY location_id'); $st->execute([$pid]);
    $o = []; foreach ($st->fetchAll() as $r) $o[(int)$r['location_id']] = (int)$r['quantity'];
    return $o;
}
function db_movements(PDO $pdo, int $pid): array {
    $st = $pdo->prepare('SELECT location_id,change_qty,balance_after,movement_type,reference FROM lager_stock_movements WHERE product_id=? ORDER BY id'); $st->execute([$pid]);
    return array_map(static fn($r) => ['location_id' => (int)$r['location_id'], 'change_qty' => (int)$r['change_qty'], 'balance_after' => (int)$r['balance_after'], 'movement_type' => $r['movement_type'], 'reference' => $r['reference']], $st->fetchAll());
}
function db_status(PDO $pdo, int $pid): string { $st = $pdo->prepare('SELECT status FROM lager_products WHERE id=?'); $st->execute([$pid]); return (string)$st->fetchColumn(); }
function db_aliases(PDO $pdo, string $sku): array {
    $st = $pdo->prepare('SELECT product_id FROM hsg_supplier_sku_aliases WHERE supplier_sku=? ORDER BY product_id'); $st->execute([$sku]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}
/** Produktet som preview ser det (fysisk lager og aktive reservationer pr. lokation). */
function db_preview_product(PDO $pdo, int $pid): array {
    $locs = hsg_supplier_locations($pdo);
    foreach (hsg_supplier_products_with_stock($pdo, array_keys($locs)) as $p) if ((int)$p['id'] === $pid) return $p;
    throw new RuntimeException('Produkt '.$pid.' blev ikke fundet');
}
/** Hele previewet, som siden laver det: produkter med lager og reservationer fra databasen, alias-tabellen og de samme options som siden. */
function db_preview_items(PDO $pdo, array $map, array $rows, string $mode = 'add'): array {
    $locs = hsg_supplier_locations($pdo);
    $products = hsg_supplier_products_with_stock($pdo, array_keys($locs));
    return hsg_supplier_merge_raw_items($rows, $map, $products, null, 1, $pdo, hsg_supplier_merge_options($locs, $mode));
}
function db_item_by_sku(array $items, string $sku): ?array {
    foreach ($items as $it) if (strcasecmp((string)($it['source']['sku'] ?? ''), $sku) === 0) return $it;
    return null;
}
/** Reservation med kunde, reference og status. Returnerer id'et. */
function db_reserve_named(PDO $pdo, int $pid, int $lid, int $qty, ?string $customer, ?string $reference, string $status = 'reserved'): int {
    $pdo->prepare('INSERT INTO lager_reservations(product_id,location_id,quantity,customer_name,reference,status) VALUES(?,?,?,?,?,?)')->execute([$pid, $lid, $qty, $customer, $reference, $status]);
    return (int)$pdo->lastInsertId();
}
function db_res_status(PDO $pdo, int $id): string { $st = $pdo->prepare('SELECT status FROM lager_reservations WHERE id=?'); $st->execute([$id]); return (string)$st->fetchColumn(); }
function db_status_column_type(PDO $pdo): string {
    $st = $pdo->prepare('SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
    $st->execute(['lager_reservations', 'status']);
    return (string)$st->fetchColumn();
}

db_cleanup($pdo);
try {
    // Testlokationer: Christiansfeld (første lokation, sort_order −300), Michael og Gert. De rigtige lokationer røres ikke.
    $ins = $pdo->prepare('INSERT INTO lager_locations(name,active,sort_order) VALUES(?,1,?)');
    $ids = [];
    foreach ([['TSTKIM Christiansfeld', -300], ['TSTKIM Michael', -200], ['TSTKIM Gert', -100]] as [$n, $so]) { $ins->execute([$n, $so]); $ids[$n] = (int)$pdo->lastInsertId(); }
    [$L1, $L2, $L3] = array_values($ids);
    $active = hsg_supplier_locations($pdo);
    t_eq($L1, (int)array_key_first($active), 'Testens første lokation er den første aktive (sort_order)');
    $map = ['sku' => 4, 'name' => 5, 'distillery' => 7, 'stock_quantity' => 16, 'stock_loc_'.$L1 => 17, 'stock_loc_'.$L2 => 18, 'stock_loc_'.$L3 => 19];
    $row = static function (array $v): array { $r = array_fill(0, 20, ''); foreach (['sku' => 4, 'name' => 5, 'dist' => 7, 'Q' => 16, 'R' => 17, 'S' => 18, 'T' => 19] as $k => $c) if (isset($v[$k])) $r[$c] = (string)$v[$k]; return $r; };

    t_case('13 Kun Michael ændres (41 / tom / 12, total 53)', function () use ($pdo, $map, $row, $L1, $L2, $L3) {
        $pid = db_product($pdo, 'TSTKIM-1021', 'Test 1021', [$L1 => 41, $L2 => 2, $L3 => 12]);
        $src = hsg_supplier_source_fields($row(['sku' => 'TSTKIM-1021', 'name' => 'Test 1021', 'R' => 41, 'S' => '', 'T' => 12, 'Q' => 53]), $map, $L1);
        $changes = hsg_supplier_apply_product($pdo, $pid, $src, ['add_reserved' => true]);
        t_eq(['stock_loc_'.$L2], array_values(array_filter(array_keys($changes), static fn($k) => str_starts_with((string)$k, 'stock_'))), 'eneste lagerændring er Michael');
        t_eq([$L1 => 41, $L2 => 0, $L3 => 12], db_stock($pdo, $pid), 'lager 41/0/12');
        t_eq(53, array_sum(db_stock($pdo, $pid)), 'sum = Kims lagerbeholdning');
        t_eq([['location_id' => $L2, 'change_qty' => -2, 'balance_after' => 0, 'movement_type' => 'import', 'reference' => 'Kims masterfil opdatering']], db_movements($pdo, $pid), 'præcis én lagerbevægelse (Michael −2)');
    });

    t_case('13p Previewet (mod databasen) viser kun Michael 2 → 0 og forvælger rækken', function () use ($pdo, $map, $row, $L1, $L2, $L3) {
        $pid = db_product($pdo, 'TSTKIM-1022', 'TSTKIM Blue Collection', [$L1 => 41, $L2 => 2, $L3 => 12]);
        $pdo->prepare("UPDATE lager_products SET distillery='TSTKIM Aultmore' WHERE id=?")->execute([$pid]);
        $items = db_preview_items($pdo, $map, [$row(['sku' => 'TSTKIM-1022', 'name' => 'TSTKIM Blue Collection', 'dist' => 'TSTKIM Aultmore', 'R' => 41, 'S' => '', 'T' => 12, 'Q' => 53])]);
        $it = db_item_by_sku($items, 'TSTKIM-1022');
        t_true($it !== null, 'varen findes i previewet');
        if ($it === null) return;
        t_eq($pid, (int)$it['match']['id'], 'match');
        t_eq(['stock_loc_'.$L2 => ['old' => 2, 'new' => 0, 'file' => 0]], stock_only($it['changes']), 'eneste lagerændring er Michael 2 → 0');
        t_eq(53, $it['stock_file']['total'], 'kontroltal 53');
        t_eq(['stock_loc_'.$L2], $it['stock_file']['blank'], 'Michael er (tom)');
        t_eq([], $it['flags'], 'ingen flag');
        t_true((bool)$it['selected'], 'forvalgt');
    });

    t_case('17p Previewet i totaltilstand (mod databasen): første lokation og bemærkning om andre lokationer', function () use ($pdo, $map, $row, $L1, $L2, $L3) {
        $pid = db_product($pdo, 'TSTKIM-1023', 'TSTKIM Total Mode', [$L1 => 41, $L2 => 2, $L3 => 12]);
        $totalMap = ['sku' => 4, 'name' => 5, 'stock_quantity' => 16];
        $items = db_preview_items($pdo, $totalMap, [$row(['sku' => 'TSTKIM-1023', 'name' => 'TSTKIM Total Mode', 'Q' => 53])]);
        $it = db_item_by_sku($items, 'TSTKIM-1023');
        t_true($it !== null, 'varen findes i previewet');
        if ($it === null) return;
        t_eq($pid, (int)$it['match']['id'], 'match');
        t_eq(['stock_loc_'.$L1 => ['old' => 41, 'new' => 53, 'file' => 53]], stock_only($it['changes']), 'TSTKIM Christiansfeld 41 → 53 og intet "Lagerantal i alt"');
        t_has_note($it, 'Produktet har også 14 stk. på andre lokationer, så lageret i alt bliver 67.', 'bemærkning om andre lokationer');
    });

    t_case('16 Nyt produkt: totalen skrives ikke på første lokation', function () use ($pdo, $map, $row, $L1, $L2, $L3) {
        $src = hsg_supplier_source_fields($row(['sku' => 'TSTKIM-2001', 'name' => 'Test 2001', 'R' => 5, 'S' => '', 'T' => 3, 'Q' => 8]), $map, $L1);
        $pid = hsg_supplier_create_product($pdo, $src);
        t_eq([$L1 => 5, $L3 => 3], db_stock($pdo, $pid), 'Christiansfeld 5 og Gert 3, ingen række på Michael');
        t_eq(8, array_sum(db_stock($pdo, $pid)), 'total 8 (ikke 16)');
    });

    t_case('16b Ældre kilde med kun stock_quantity bruger første lokation', function () use ($pdo, $L1) {
        $pid = hsg_supplier_create_product($pdo, ['sku' => 'TSTKIM-2002', 'name' => 'Test 2002', 'stock_quantity' => 7]);
        t_eq([$L1 => 7], db_stock($pdo, $pid), 'første lokation');
        $pid2 = db_product($pdo, 'TSTKIM-2003', 'Test 2003', [$L1 => 1]);
        hsg_supplier_apply_product($pdo, $pid2, ['stock_quantity' => 9]);
        t_eq([$L1 => 9], db_stock($pdo, $pid2), 'apply: første lokation når kilden ikke har stock_loc_*');
    });

    t_case('17 Totaltilstand skriver kun første lokation og rører ikke de andre', function () use ($pdo, $map, $row, $L1, $L2, $L3) {
        $pid = db_product($pdo, 'TSTKIM-1024', 'Test 1024', [$L1 => 41, $L2 => 2, $L3 => 12]);
        $totalOnly = ['sku' => 4, 'name' => 5, 'stock_quantity' => 16];
        $src = hsg_supplier_source_fields($row(['sku' => 'TSTKIM-1024', 'name' => 'Test 1024', 'Q' => 53]), $totalOnly, $L1);
        hsg_supplier_apply_product($pdo, $pid, $src, ['add_reserved' => true]);
        t_eq([$L1 => 53, $L2 => 2, $L3 => 12], db_stock($pdo, $pid), 'Christiansfeld 53, øvrige uændret');
    });

    t_case('27 add: reserverede flasker lægges oven i (Octomore)', function () use ($pdo, $map, $row, $L1) {
        $pid = db_product($pdo, 'TSTKIM-3001', 'Test Octomore', [$L1 => 0], 'inactive');
        db_reserve($pdo, $pid, $L1, 1); db_reserve($pdo, $pid, $L1, 1);
        $src = hsg_supplier_source_fields($row(['sku' => 'TSTKIM-3001', 'name' => 'Test Octomore', 'R' => 0, 'S' => '', 'T' => '', 'Q' => 0]), $map, $L1);
        $preview = hsg_supplier_changes_for_product($src, db_preview_product($pdo, $pid), ['add_reserved' => true]);
        t_eq(['old' => 0, 'new' => 2, 'file' => 0, 'reserved' => 2], $preview['stock_loc_'.$L1] ?? null, 'preview: 0 → 2 (fil 0 + 2 reserveret)');
        hsg_supplier_apply_product($pdo, $pid, $src, ['add_reserved' => true]);
        t_eq([$L1 => 2], db_stock($pdo, $pid), 'fysisk 2');
        t_eq(0, available_for($pdo, $pid, $L1), 'disponibelt 0 (fysisk 2 − reserveret 2)');
        $mv = db_movements($pdo, $pid);
        t_eq('Kims masterfil opdatering (+2 reserveret)', end($mv)['reference'] ?? null, 'lagerbevægelsens reference');
    });

    // Ændret bevidst af spec 2026-10-26-kims-upload-kun-lagerantal (kriterie 162): Octomore med Kims tal 0 foreslås nu
    // arkiveret. Tillægget og advarslen testes i stedet på TSTKIM-3008, hvor Kims tal er 1.
    t_case('27p Previewet (mod databasen): Octomore foreslås arkiveret, tillæg og advarsel på TSTKIM-3008', function () use ($pdo, $map, $row, $L1) {
        $pid = db_product($pdo, 'TSTKIM-3007', 'TSTKIM Octomore Preview', [$L1 => 0], 'inactive');
        db_reserve($pdo, $pid, $L1, 2);
        $r = $row(['sku' => 'TSTKIM-3007', 'name' => 'TSTKIM Octomore Preview', 'R' => 0, 'S' => '', 'T' => '', 'Q' => 0]);
        $it = db_item_by_sku(db_preview_items($pdo, $map, [$r], 'add'), 'TSTKIM-3007');
        t_true($it !== null, 'varen findes i previewet');
        if ($it === null) return;
        t_true(!array_key_exists('stock_loc_'.$L1, $it['changes']), 'add: ingen ændring (0 → 0 med forvalgt arkivering)');
        t_eq(2, $it['archive']['quantity'] ?? null, 'add: archive.quantity = 2');
        t_eq(true, $it['archive']['default'] ?? null, 'add: archive.default');
        t_eq(['stock_loc_'.$L1 => 2], $it['reserved'], 'Reserveret i HSG');
        $none = db_item_by_sku(db_preview_items($pdo, $map, [$r], 'none'), 'TSTKIM-3007');
        t_eq([], stock_only($none['changes'] ?? ['x' => 1]), 'none: ingen lagerændring');
        t_true(!str_contains(json_encode($none['notes'] ?? [], JSON_UNESCAPED_UNICODE), 'er reserveret'), 'none: ingen advarsel om negativt disponibelt');

        $pid8 = db_product($pdo, 'TSTKIM-3008', 'TSTKIM Octomore Tillæg', [$L1 => 0], 'inactive');
        db_reserve($pdo, $pid8, $L1, 2);
        $r8 = $row(['sku' => 'TSTKIM-3008', 'name' => 'TSTKIM Octomore Tillæg', 'R' => 1, 'S' => '', 'T' => '', 'Q' => 1]);
        $add8 = db_item_by_sku(db_preview_items($pdo, $map, [$r8], 'add'), 'TSTKIM-3008');
        t_eq(['old' => 0, 'new' => 3, 'file' => 1, 'reserved' => 2], $add8['changes']['stock_loc_'.$L1] ?? null, 'TSTKIM-3008 add: 0 → 3 (fil 1 + 2 reserveret)');
        $none8 = db_item_by_sku(db_preview_items($pdo, $map, [$r8], 'none'), 'TSTKIM-3008');
        t_eq(['old' => 0, 'new' => 1, 'file' => 1], $none8['changes']['stock_loc_'.$L1] ?? null, 'TSTKIM-3008 none: 0 → 1');
        t_has_note($none8 ?? [], 'får 1 fysisk efter import, men 2 er reserveret', 'TSTKIM-3008 none: advarsel om negativt disponibelt');
    });

    t_case('28 none: ingen ændring når Kims tal er 0', function () use ($pdo, $map, $row, $L1) {
        $pid = db_product($pdo, 'TSTKIM-3002', 'Test Octomore none', [$L1 => 0]);
        db_reserve($pdo, $pid, $L1, 2);
        $src = hsg_supplier_source_fields($row(['sku' => 'TSTKIM-3002', 'name' => 'Test Octomore none', 'R' => 0, 'S' => '', 'T' => '', 'Q' => 0]), $map, $L1);
        t_eq([], stock_only(hsg_supplier_apply_product($pdo, $pid, $src, ['add_reserved' => false])), 'ingen lagerændringer');
        t_eq([$L1 => 0], db_stock($pdo, $pid), 'fysisk uændret');
    });

    t_case('29 Uden reservationer giver add og none samme resultat', function () use ($pdo, $map, $row, $L1, $L2, $L3) {
        $a = db_product($pdo, 'TSTKIM-3003', 'Test A', [$L1 => 5, $L2 => 2, $L3 => 1]);
        $b = db_product($pdo, 'TSTKIM-3004', 'Test B', [$L1 => 5, $L2 => 2, $L3 => 1]);
        $srcA = hsg_supplier_source_fields($row(['sku' => 'TSTKIM-3003', 'name' => 'Test A', 'R' => 3, 'S' => '', 'T' => 4, 'Q' => 7]), $map, $L1);
        hsg_supplier_apply_product($pdo, $a, $srcA, ['add_reserved' => true]);
        hsg_supplier_apply_product($pdo, $b, $srcA, ['add_reserved' => false]);
        t_eq(db_stock($pdo, $a), db_stock($pdo, $b), 'samme lager');
        t_eq([$L1 => 3, $L2 => 0, $L3 => 4], db_stock($pdo, $a), '3/0/4');
    });

    t_case('31 Ny reservation efter preview bruges ved Anvend', function () use ($pdo, $map, $row, $L1) {
        $pid = db_product($pdo, 'TSTKIM-3005', 'Test Delta', [$L1 => 5]);
        db_reserve($pdo, $pid, $L1, 2);
        $src = hsg_supplier_source_fields($row(['sku' => 'TSTKIM-3005', 'name' => 'Test Delta', 'R' => 3, 'S' => '', 'T' => '', 'Q' => 3]), $map, $L1);
        t_eq([], stock_only(hsg_supplier_changes_for_product($src, db_preview_product($pdo, $pid), ['add_reserved' => true])), 'preview: 3 + 2 = 5, ingen lagerændring');
        db_reserve($pdo, $pid, $L1, 1);
        $changes = hsg_supplier_apply_product($pdo, $pid, $src, ['add_reserved' => true]);
        t_eq([$L1 => 6], db_stock($pdo, $pid), 'Anvend: 3 + 3 aktuelle reservationer = 6');
        t_eq(['old' => 5, 'new' => 6, 'file' => 3, 'reserved' => 3], $changes['stock_loc_'.$L1] ?? null, 'ændringen indeholder old/new/file/reserved');
    });

    t_case('33 Inaktivt produkt bliver aktivt, når Kims tal > 0', function () use ($pdo, $map, $row, $L1) {
        $pid = db_product($pdo, 'TSTKIM-3006', 'Test Epsilon', [$L1 => 0], 'inactive');
        db_reserve($pdo, $pid, $L1, 2);
        $src = hsg_supplier_source_fields($row(['sku' => 'TSTKIM-3006', 'name' => 'Test Epsilon', 'R' => 3, 'S' => '', 'T' => '', 'Q' => 3]), $map, $L1);
        hsg_supplier_apply_product($pdo, $pid, $src, ['add_reserved' => true]);
        t_eq([$L1 => 5], db_stock($pdo, $pid), 'fysisk 5 (3 + 2)');
        t_eq('active', db_status($pdo, $pid), 'produktet er aktivt igen');
    });

    t_case('24 Opret som nyt produkt rydder en gammel forkert alias-kobling', function () use ($pdo, $L1) {
        $old = db_product($pdo, 'TSTKIM-1123', 'Test St. Bridget', [$L1 => 15]);
        $pdo->prepare('INSERT INTO hsg_supplier_sku_aliases(supplier_sku,supplier_name_norm,product_id) VALUES(?,?,?)')->execute(['TSTKIM-1123a', 'x', $old]);
        $new = hsg_supplier_create_product($pdo, ['sku' => 'TSTKIM-1123a', 'name' => 'Test Clynelish', 'stock_loc_'.$L1 => 9]);
        hsg_supplier_remember_alias($pdo, 'TSTKIM-1123a', $new, 'Test Clynelish');
        t_eq([$new], db_aliases($pdo, 'TSTKIM-1123a'), 'aliaset peger kun på det nye produkt');
        t_eq([$L1 => 15], db_stock($pdo, $old), '12-1123 er uændret');
    });

    // Kriterie 21–24 med alias-tabellen: det rene enhedstest (uden $pdo) kan ikke se en gammel, forkert alias-kobling.
    t_case('21-24p Variant med gammel alias-kobling og forurenet basis matcher ikke basen – før og efter "Opret som nyt"', function () use ($pdo, $map, $row, $L1) {
        $base = db_product($pdo, 'TSTKIM-5123', 'TSTKIM Kirk John Barleycorn Edition', [$L1 => 24]);
        // Basen er forurenet af en tidligere forkert fletning (fad, årgang), og der ligger en gammel alias-kobling fra varianten.
        $pdo->prepare("UPDATE lager_products SET distillery='N/A', cask_number='98765', vintage_year=2013 WHERE id=?")->execute([$base]);
        $pdo->prepare('INSERT INTO hsg_supplier_sku_aliases(supplier_sku,supplier_name_norm,product_id) VALUES(?,?,?)')->execute(['TSTKIM-5123a', 'x', $base]);
        $rows = [
            $row(['sku' => 'TSTKIM-5123', 'name' => 'TSTKIM Kirk John Barleycorn Edition', 'dist' => 'N/A', 'R' => 15]),
            $row(['sku' => 'TSTKIM-5123a', 'name' => 'TSTKIM Zeta, 2013, #98765 - Hogshead', 'dist' => 'TSTKIM Zeta', 'R' => 9]),
        ];
        $items = db_preview_items($pdo, $map, $rows);
        t_eq(2, count($items), 'to varer');
        $b = db_item_by_sku($items, 'TSTKIM-5123');
        $v = db_item_by_sku($items, 'TSTKIM-5123a');
        t_true($b !== null && $v !== null, 'begge varenumre findes');
        if ($b === null || $v === null) return;
        t_eq($base, (int)$b['match']['id'], 'basen matcher sit eget produkt');
        t_true((int)$v['match']['id'] !== $base, 'varianten matcher ikke basen (trods alias og samme fadnummer)');
        t_true(in_array('variant_split', $v['flags'], true), 'flag variant_split');
        t_has_note($v, 'TSTKIM-5123a er ikke flettet med TSTKIM-5123', 'bemærkning om at varianten ikke er flettet');
        t_true(!$v['selected'], 'varianten er ikke forvalgt');
        t_eq(9, $v['total_stock'], 'total_stock 9');
        t_true((bool)$v['is_create_new'], 'tilbydes som nyt produkt');

        // Kun varianten i filen (kriterie 23): samme resultat.
        $only = db_item_by_sku(db_preview_items($pdo, $map, [$rows[1]]), 'TSTKIM-5123a');
        t_true($only !== null && (int)$only['match']['id'] !== $base && !$only['selected'] && in_array('variant_split', $only['flags'], true), 'kun varianten i filen: ikke flettet, ikke valgt, matcher ikke basen');

        // "Opret som nyt produkt" som siden gør det (kriterie 24): produkt + eget varenummer som alias.
        $new = hsg_supplier_create_product($pdo, $v['source']);
        hsg_supplier_remember_alias($pdo, (string)$v['source']['sku'], $new, $v['source']['name'] ?? null);
        t_eq([$new], db_aliases($pdo, 'TSTKIM-5123a'), 'aliaset peger kun på det nye produkt');
        t_eq([$L1 => 9], db_stock($pdo, $new), 'det nye produkt har lager 9');
        t_eq([$L1 => 24], db_stock($pdo, $base), 'basen er uændret');
        $v2 = db_item_by_sku(db_preview_items($pdo, $map, $rows), 'TSTKIM-5123a');
        t_eq($new, (int)($v2['match']['id'] ?? 0), 'næste upload: varianten matcher sit nye produkt via aliaset');
        t_true($v2 !== null && !$v2['selected'], 'varianten er stadig ikke forvalgt (variant_split)');
    });

    /* ---- Arkivering af reservationer, der ikke længere har lager (spec 2026-10-26-kims-upload-kun-lagerantal, kriterie 141–147 og 161) ----
     * Fælles data: lager [L1 0, L2 0, L3 2], r1 og r2 hos L3 på hver 1 stk. Kilden er rækken R=0, S='', T=0, Q=0. */
    $archiveFixture = static function (string $sku) use ($pdo, $map, $row, $L1, $L2, $L3): array {
        $pid = db_product($pdo, $sku, 'TSTKIM Spøgelse '.$sku, [$L1 => 0, $L2 => 0, $L3 => 2]);
        $r1 = db_reserve_named($pdo, $pid, $L3, 1, 'TSTKIM Hansen', 'Ordre 1047');
        $r2 = db_reserve_named($pdo, $pid, $L3, 1, 'TSTKIM Jensen', null);
        $src = hsg_supplier_source_fields($row(['sku' => $sku, 'name' => 'TSTKIM Spøgelse '.$sku, 'R' => 0, 'S' => '', 'T' => 0, 'Q' => 0]), $map, $L1);
        return [$pid, $r1, $r2, $src];
    };
    $movementRows = static fn(PDO $pdo, int $pid): array => db_movements($pdo, $pid);

    t_case('141 Begge arkiveres (add)', function () use ($pdo, $archiveFixture, $movementRows, $L1, $L2, $L3) {
        [$pid, $r1, $r2, $src] = $archiveFixture('TSTKIM-8001');
        $rows = hsg_supplier_archive_reservations($pdo, $pid, [$r1, $r2], [$L1, $L2, $L3]);
        t_eq(2, count($rows), 'to arkiverede rækker');
        t_eq(['id', 'product_id', 'location_id', 'quantity', 'customer_name', 'reference'], array_keys($rows[0] ?? []), 'nøglerne i en arkiveret række');
        t_eq(['id' => $r1, 'product_id' => $pid, 'location_id' => $L3, 'quantity' => 1, 'customer_name' => 'TSTKIM Hansen', 'reference' => 'Ordre 1047'], $rows[0] ?? null, 'første række er r1');
        hsg_supplier_apply_product($pdo, $pid, $src, ['add_reserved' => true, 'archived_by_location' => [$L3 => 2]]);
        t_eq('archived', db_res_status($pdo, $r1), 'r1 er arkiveret');
        t_eq('archived', db_res_status($pdo, $r2), 'r2 er arkiveret');
        t_eq([$L1 => 0, $L2 => 0, $L3 => 0], db_stock($pdo, $pid), 'lager 0/0/0');
        t_eq([['location_id' => $L3, 'change_qty' => -2, 'balance_after' => 0, 'movement_type' => 'import', 'reference' => 'Kims masterfil opdatering (2 arkiveret)']], $movementRows($pdo, $pid), 'præcis én lagerbevægelse (import, ingen sale)');
        t_eq(0, available_for($pdo, $pid, $L3), 'available_for(L3) = 0');
        t_eq(0, total_available_for($pdo, $pid), 'total_available_for = 0');
        t_eq('inactive', db_status($pdo, $pid), 'produktet er inaktivt');
    });

    t_case('142 Kun den ene arkiveres', function () use ($pdo, $archiveFixture, $movementRows, $L1, $L2, $L3) {
        [$pid, $r1, $r2, $src] = $archiveFixture('TSTKIM-8002');
        t_eq(1, count(hsg_supplier_archive_reservations($pdo, $pid, [$r1], [$L1, $L2, $L3])), 'én arkiveret række');
        hsg_supplier_apply_product($pdo, $pid, $src, ['add_reserved' => true, 'archived_by_location' => [$L3 => 1]]);
        t_eq(1, db_stock($pdo, $pid)[$L3] ?? null, 'L3 = 1');
        t_eq([['location_id' => $L3, 'change_qty' => -1, 'balance_after' => 1, 'movement_type' => 'import', 'reference' => 'Kims masterfil opdatering (+1 reserveret, 1 arkiveret)']], $movementRows($pdo, $pid), 'lagerbevægelsen');
        t_eq('reserved', db_res_status($pdo, $r2), 'r2 er stadig reserveret');
        t_eq(0, available_for($pdo, $pid, $L3), 'available_for(L3) = 0');
    });

    t_case('143 Ingen arkiveres (fravalgt, som i dag)', function () use ($pdo, $archiveFixture, $movementRows, $L1, $L2, $L3) {
        [$pid, $r1, $r2, $src] = $archiveFixture('TSTKIM-8003');
        t_eq([], stock_only(hsg_supplier_apply_product($pdo, $pid, $src, ['add_reserved' => true])), 'ingen lagerændring (0 + 2 = 2)');
        t_eq([$L1 => 0, $L2 => 0, $L3 => 2], db_stock($pdo, $pid), 'lageret er uændret');
        t_eq([], $movementRows($pdo, $pid), 'ingen lagerbevægelse');
        t_eq('reserved', db_res_status($pdo, $r1), 'r1 er reserveret');
        t_eq('reserved', db_res_status($pdo, $r2), 'r2 er reserveret');
    });

    t_case('144 none med arkivering', function () use ($pdo, $archiveFixture, $movementRows, $L1, $L2, $L3) {
        [$pid, $r1, $r2, $src] = $archiveFixture('TSTKIM-8004');
        t_eq(2, count(hsg_supplier_archive_reservations($pdo, $pid, [$r1, $r2], [$L1, $L2, $L3])), 'to arkiverede rækker');
        hsg_supplier_apply_product($pdo, $pid, $src, ['add_reserved' => false, 'archived_by_location' => [$L3 => 2]]);
        t_eq(0, db_stock($pdo, $pid)[$L3] ?? null, 'L3 = 0');
        $mv = $movementRows($pdo, $pid);
        t_eq('Kims masterfil opdatering (2 arkiveret)', $mv[0]['reference'] ?? null, 'referencen');
        t_eq(0, available_for($pdo, $pid, $L3), 'available_for(L3) = 0');
    });

    t_case('145 Fremmede og inaktive id\'er arkiveres ikke', function () use ($pdo, $archiveFixture, $L1, $L2, $L3) {
        [$pid, $r1, $r2] = $archiveFixture('TSTKIM-8005');
        $other = db_product($pdo, 'TSTKIM-8015', 'TSTKIM Andet produkt', [$L3 => 1]);
        $foreign = db_reserve_named($pdo, $other, $L3, 1, 'TSTKIM Fremmed', null);
        $cancelled = db_reserve_named($pdo, $pid, $L3, 1, 'TSTKIM Annulleret', null, 'cancelled');
        $atL2 = db_reserve_named($pdo, $pid, $L2, 1, 'TSTKIM Michael', null);
        $result = 'ingen';
        try { $result = hsg_supplier_archive_reservations($pdo, $pid, [$foreign, $cancelled, $atL2, 999999999], [$L1, $L3]); }
        catch (Throwable $e) { $result = get_class($e).': '.$e->getMessage(); }
        t_eq([], $result, 'returnerer [] uden undtagelse');
        t_eq('reserved', db_res_status($pdo, $foreign), 'reservationen på et andet produkt er uændret');
        t_eq('cancelled', db_res_status($pdo, $cancelled), 'den annullerede er uændret');
        t_eq('reserved', db_res_status($pdo, $atL2), 'reservationen hos L2 er uændret');
        t_eq('reserved', db_res_status($pdo, $r1), 'r1 er uændret (ikke i listen)');
    });

    t_case('161 Label', function () {
        t_eq('Arkiveret', reservation_status_label('archived'), "reservation_status_label('archived')");
        t_eq('Reserveret', reservation_status_label('reserved'), 'reserved er uændret');
        t_eq('Solgt/afsluttet', reservation_status_label('completed'), 'completed er uændret');
        t_eq('Annulleret', reservation_status_label('cancelled'), 'cancelled er uændret');
    });

    t_case('147 Migration: frisk installation og to indlæsninger', function () use ($pdo, $root) {
        $newType = "enum('reserved','completed','cancelled','archived')";
        t_eq($newType, db_status_column_type($pdo), 'COLUMN_TYPE på den friske installation');
        $ver = $pdo->prepare('SELECT version FROM hsg_module_versions WHERE module_id=?');
        $ver->execute(['reservations']); t_eq('1.3.0', $ver->fetchColumn(), 'hsg_module_versions.reservations = 1.3.0');
        // 1.2.1: opfølgning på review (spec 2026-10-26-kims-upload-opfoelgning-review, kriterie 189-190).
        $ver->execute(['supplier_upload']); t_eq('1.2.1', $ver->fetchColumn(), 'hsg_module_versions.supplier_upload = 1.2.1');
        $file = $root.'/modules/reservations/migrations.php';
        $before = get_defined_functions()['user'];
        ob_start();
        $first = require $file;
        $second = require $file;
        $out = ob_get_clean();
        t_eq('', $out, 'intet output');
        t_eq($before, get_defined_functions()['user'], 'ingen nye funktioner ved to indlæsninger');
        t_true(is_array($first) && is_array($second), 'returnerer et array');
        t_eq(['1.3.0'], array_map('strval', array_keys((array)$first)), "præcis nøglen '1.3.0'");
        t_true(is_callable($first['1.3.0'] ?? null), '1.3.0 er callable');
        $first['1.3.0']($pdo);
        t_eq($newType, db_status_column_type($pdo), 'COLUMN_TYPE er uændret, når callable\'en kaldes igen');
    });

    // 146 (vagten) og opgraderingen fra 147: kolonnetypen sættes midlertidigt tilbage til v11.2.0's ENUM. Testens arkiverede
    // rækker ryddes først, og typen genskabes altid i finally (med migrationen, ellers direkte).
    t_case('146/147 Vagt uden \'archived\' og opgradering med migrationen', function () use ($pdo, $root, $archiveFixture, $L1, $L2, $L3) {
        $pdo->exec("DELETE r FROM lager_reservations r JOIN lager_products p ON p.id=r.product_id WHERE p.sku LIKE 'TSTKIM-%' AND r.status='archived'");
        $foreignArchived = (int)$pdo->query("SELECT COUNT(*) FROM lager_reservations WHERE status='archived'")->fetchColumn();
        if ($foreignArchived > 0) { fwrite(STDOUT, "  INFO: 146/147 springes over – databasen har $foreignArchived arkiverede reservationer, der ikke er testens.\n"); return; }
        [$pid, $r1] = $archiveFixture('TSTKIM-8006');
        $completed = db_reserve_named($pdo, $pid, $L3, 1, 'TSTKIM Solgt', null, 'completed');
        $cancelled = db_reserve_named($pdo, $pid, $L3, 1, 'TSTKIM Annulleret', null, 'cancelled');
        $migration = (require $root.'/modules/reservations/migrations.php')['1.3.0'];
        $pdo->exec("ALTER TABLE lager_reservations MODIFY status ENUM('reserved','completed','cancelled') NOT NULL DEFAULT 'reserved'");
        try {
            $message = 'ingen undtagelse';
            try { hsg_supplier_archive_reservations($pdo, $pid, [$r1], [$L1, $L2, $L3]); }
            catch (RuntimeException $e) { $message = $e->getMessage(); }
            t_eq('Databasen kan ikke gemme status „Arkiveret“ endnu. Genindlæs siden, så opdateringen kan køre, og prøv igen.', $message, '146: RuntimeException med teksten fra 2.4');
            t_eq('reserved', db_res_status($pdo, $r1), '146: reservationen er uændret reserveret');
            $migration($pdo);
            t_eq("enum('reserved','completed','cancelled','archived')", db_status_column_type($pdo), '147: migrationen tilføjer archived');
            t_eq(['reserved', 'completed', 'cancelled'], [db_res_status($pdo, $r1), db_res_status($pdo, $completed), db_res_status($pdo, $cancelled)], '147: eksisterende reservationer har samme status som før');
        } finally {
            if (!str_contains(db_status_column_type($pdo), "'archived'")) $pdo->exec("ALTER TABLE lager_reservations MODIFY status ENUM('reserved','completed','cancelled','archived') NOT NULL DEFAULT 'reserved'");
        }
        t_eq(1, count(hsg_supplier_archive_reservations($pdo, $pid, [$r1], [$L1, $L2, $L3])), 'efter migrationen kan reservationen arkiveres');
    });
} finally {
    db_cleanup($pdo);
}

$fails = $GLOBALS['t_fail'];
foreach ($fails as $f) fwrite(STDOUT, "  FEJL: $f\n");
fwrite(STDOUT, sprintf("Kims uploadfil (database): %d tjek bestået, %d fejlede.\n", $GLOBALS['t_pass'], count($fails)));
exit($fails ? 1 : 0);
