<?php
declare(strict_types=1);
/*
 * Databasetest af engangsoprydningen af fadnumre uden ciffer (hsg_cask_number_cleanup, modulmigration products 1.5.1)
 * (spec 2026-10-22-fadnummer-kraever-ciffer, kriterie 65-69).
 *
 * KØRES KUN mod testdatabasen, som tests/run.sh har installeret (config.php + HSG_TEST_DB_NAME):
 *     php tests/cask_number_cleanup_db_test.php
 * Testen afviser at køre, hvis HSG_TEST_DB_NAME ikke er sat eller ikke passer til config.php, så den aldrig
 * rører en produktionsdatabase. Testprodukterne har varenummer-præfikset TSTCASK- og slettes bagefter. Testens egne
 * audit-poster slettes, og 12-1016 og eventuelle andre produkter, som oprydningen ændrer, sættes tilbage.
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
require_once $root.'/core/product_enrichment.php';

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

const CN_ACTION = 'product.cask_number_cleanup';

function cn_cleanup_products(PDO $pdo): void {
    $ids = $pdo->query("SELECT id FROM lager_products WHERE sku LIKE 'TSTCASK-%'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) {
        foreach (['lager_reservations', 'lager_stock_movements', 'lager_stock'] as $t) $pdo->prepare("DELETE FROM $t WHERE product_id=?")->execute([(int)$id]);
        $pdo->prepare('DELETE FROM lager_products WHERE id=?')->execute([(int)$id]);
    }
}
function cn_max_audit_id(PDO $pdo): int { return (int)$pdo->query('SELECT COALESCE(MAX(id),0) FROM hsg_audit_log')->fetchColumn(); }
/** Audit-posterne for oprydningen med id over $afterId. */
function cn_audit_rows(PDO $pdo, int $afterId): array {
    $st = $pdo->prepare('SELECT * FROM hsg_audit_log WHERE id>? AND action=? ORDER BY id'); $st->execute([$afterId, CN_ACTION]);
    return $st->fetchAll();
}
/** Fadnummer pr. varenummer (NULL bevares). */
function cn_cask(PDO $pdo, string $sku): ?string {
    $st = $pdo->prepare('SELECT cask_number FROM lager_products WHERE sku=?'); $st->execute([$sku]);
    $v = $st->fetchColumn();
    return $v === false || $v === null ? null : (string)$v;
}
/** Liste nøglet på varenummer i samme rækkefølge som cn_tst (til forventede værdier). */
function cn_sorted(array $a): array { ksort($a); return $a; }
/** Kun testens egne rækker fra en cleared/kept-liste, nøglet på varenummer. */
function cn_tst(array $list): array {
    $out = [];
    foreach ($list as $r) if (str_starts_with((string)($r['sku'] ?? ''), 'TSTCASK-')) $out[(string)$r['sku']] = $r;
    ksort($out);
    return $out;
}
/** Præcis én ny audit-post efter $afterId, med faste kolonner og samme lister som returværdien (kriterie 66). */
function cn_check_audit(PDO $pdo, int $afterId, array $result, string $run): ?array {
    $rows = cn_audit_rows($pdo, $afterId);
    t_eq(1, count($rows), $run.': præcis én ny audit-post');
    $all = $pdo->prepare('SELECT COUNT(*) FROM hsg_audit_log WHERE id>?'); $all->execute([$afterId]);
    t_eq(1, (int)$all->fetchColumn(), $run.': ingen andre nye audit-poster');
    if (count($rows) !== 1) return null;
    $a = $rows[0];
    t_eq('system', $a['actor_type'], $run.': actor_type');
    t_eq(null, $a['actor_id'], $run.': actor_id NULL');
    t_eq('System (opdatering)', $a['actor_name'], $run.': actor_name');
    t_eq('product', $a['entity_type'], $run.': entity_type');
    t_eq(null, $a['entity_id'], $run.': entity_id NULL');
    t_eq(null, $a['ip_hash'], $run.': ip_hash NULL');
    $d = json_decode((string)$a['details_json'], true);
    t_true(is_array($d), $run.': details_json er JSON');
    if (!is_array($d)) return null;
    t_eq('Fadnummer uden ciffer, udledt automatisk af navnet', $d['rule'] ?? null, $run.': rule');
    t_eq($result['cleared'], $d['cleared'] ?? null, $run.': cleared i audit = returværdien');
    t_eq($result['kept'], $d['kept'] ?? null, $run.': kept i audit = returværdien');
    t_eq(count($result['cleared']), $d['cleared_count'] ?? null, $run.': cleared_count');
    t_eq(count($result['kept']), $d['kept_count'] ?? null, $run.': kept_count');
    return $d;
}

$startAuditId = cn_max_audit_id($pdo);
$seedSkus = array_keys(hsg_cask_number_seed_names());
/* Andre produkter end testens med et fadnummer uden ciffer (på en frisk testdatabase ingen). Oprydningen kan ændre
 * dem, når den kaldes uden for migrationen, så de sættes tilbage bagefter. */
$snapshot = [];
foreach ($pdo->query("SELECT id,cask_number FROM lager_products WHERE sku NOT LIKE 'TSTCASK-%' AND cask_number IS NOT NULL AND cask_number<>''")->fetchAll() as $r) {
    if (trim((string)$r['cask_number']) !== '' && !preg_match('/\d/', (string)$r['cask_number'])) $snapshot[(int)$r['id']] = (string)$r['cask_number'];
}
$p1016 = null;

cn_cleanup_products($pdo);
try {
    t_case('69 Frisk installation: migrationen products 1.5.1 er kørt præcis én gang', function () use ($pdo, $startAuditId, $seedSkus) {
        $st = $pdo->prepare('SELECT version FROM hsg_module_versions WHERE module_id=?'); $st->execute(['products']);
        t_eq('1.5.1', $st->fetchColumn(), 'hsg_module_versions.products = 1.5.1');
        $st = $pdo->prepare('SELECT * FROM hsg_audit_log WHERE action=? AND id<=? ORDER BY id'); $st->execute([CN_ACTION, $startAuditId]);
        $rows = $st->fetchAll();
        t_eq(1, count($rows), 'præcis én audit-post fra installationen (røgtesten har ikke kørt den igen)');
        if ($rows) {
            $d = json_decode((string)$rows[0]['details_json'], true);
            t_eq(0, $d['cleared_count'] ?? null, 'cleared_count 0');
            t_eq(0, $d['kept_count'] ?? null, 'kept_count 0');
            t_eq('System (opdatering)', $rows[0]['actor_name'], 'aktør System (opdatering)');
        }
        t_true(count($seedSkus) > 100, 'startlageret (initial-stock.json) kan læses');
        $bad = [];
        foreach ($seedSkus as $sku) {
            $v = cn_cask($pdo, (string)$sku);
            if ($v !== null && trim($v) !== '' && !preg_match('/\d/', $v)) $bad[] = $sku.'='.$v;
        }
        t_eq([], $bad, 'intet startlagerprodukt har et fadnummer uden ciffer');
        foreach (['12-1016' => '43', '12-1087' => '143', '12-1098' => '71324', '12-1104' => '300223'] as $sku => $want) t_eq($want, cn_cask($pdo, $sku), $sku);
        $exists = $pdo->prepare('SELECT COUNT(*) FROM lager_products WHERE sku=?');
        foreach (['11-2010', '18-1015'] as $sku) {
            $exists->execute([$sku]);
            t_eq(1, (int)$exists->fetchColumn(), $sku.' findes (startlageret)');
            t_eq('', trim((string)cn_cask($pdo, $sku)), $sku.' har tomt fadnummer');
        }
    });

    // Testdata (kriterie 65). A er udgået for at vise, at alle statusser gennemgås.
    $ins = $pdo->prepare('INSERT INTO lager_products(sku,name,cask_number,status) VALUES(?,?,?,?)');
    $data = [
        'TSTCASK-1' => ['TSTCASK Glenfarclas 15 Cask Strength, 60%', 'STRENGTH', 'discontinued'],
        'TSTCASK-2' => ['TSTCASK Knockdhu, 10yo, 2012 - #43 - Virgin Oak Barrel Finish, 58,2%', 'FINISH', 'active'],
        'TSTCASK-3' => ['TSTCASK Royal Brackla (2013) – 11 år – 54,6 %', 'FINISH', 'active'],
        'TSTCASK-4' => ['TSTCASK Glen Test – 12 år – 55 %', 'STRENGTH', 'active'],
        'TSTCASK-5' => ['TSTCASK Ardbeg Cask Strength', 'N/A', 'active'],
        'TSTCASK-6' => ['TSTCASK Clynelish 2013, Cask No. 231584', '231584', 'active'],
        'TSTCASK-7' => ['TSTCASK Benriach Cask Strength', 'strength', 'active'],
        'TSTCASK-8' => ['TSTCASK Cask Strength Tom', null, 'active'],
        'TSTCASK-9' => ['TSTCASK Cask Strength Tom', '', 'active'],
        // I (efter review): mellemrum omkring værdien. Beviset bruger trim, audit den gemte værdi.
        'TSTCASK-10' => ['TSTCASK Highland Park Cask Strength', ' STRENGTH ', 'active'],
    ];
    $id = [];
    foreach ($data as $sku => [$name, $cask, $status]) { $ins->execute([$sku, $name, $cask, $status]); $id[$sku] = (int)$pdo->lastInsertId(); }
    $seed = ['TSTCASK-3' => ['Royal Brackla, 11yo, 2013 - #143 - Peated Whisky Cask Finish, 54,6%']];
    $keptDEG = [
        'TSTCASK-4' => ['id' => $id['TSTCASK-4'], 'sku' => 'TSTCASK-4', 'value' => 'STRENGTH'],
        'TSTCASK-5' => ['id' => $id['TSTCASK-5'], 'sku' => 'TSTCASK-5', 'value' => 'N/A'],
        'TSTCASK-7' => ['id' => $id['TSTCASK-7'], 'sku' => 'TSTCASK-7', 'value' => 'strength'],
    ];
    $notListed = static function (array $r, string $run): void {
        foreach (['TSTCASK-6', 'TSTCASK-8', 'TSTCASK-9'] as $sku) {
            t_true(!isset(cn_tst($r['cleared'])[$sku]) && !isset(cn_tst($r['kept'])[$sku]), $run.': '.$sku.' står ikke i listerne');
        }
    };

    t_case('65/66 Kørsel 1 uden startlager: kun det beviste ryddes', function () use ($pdo, $id, $keptDEG, $notListed) {
        $before = cn_max_audit_id($pdo);
        $r = hsg_cask_number_cleanup($pdo, []);
        t_eq(null, cn_cask($pdo, 'TSTCASK-1'), 'A er NULL');
        t_eq('43', cn_cask($pdo, 'TSTCASK-2'), 'B er 43');
        t_eq(null, cn_cask($pdo, 'TSTCASK-10'), 'I (" STRENGTH ") er NULL');
        foreach (['TSTCASK-3' => 'FINISH', 'TSTCASK-4' => 'STRENGTH', 'TSTCASK-5' => 'N/A', 'TSTCASK-6' => '231584', 'TSTCASK-7' => 'strength', 'TSTCASK-8' => null, 'TSTCASK-9' => ''] as $sku => $want) {
            t_eq($want, cn_cask($pdo, $sku), $sku.' uændret');
        }
        t_eq(cn_sorted([
            'TSTCASK-1' => ['id' => $id['TSTCASK-1'], 'sku' => 'TSTCASK-1', 'old' => 'STRENGTH', 'new' => null, 'source' => 'name'],
            'TSTCASK-2' => ['id' => $id['TSTCASK-2'], 'sku' => 'TSTCASK-2', 'old' => 'FINISH', 'new' => '43', 'source' => 'name'],
            'TSTCASK-10' => ['id' => $id['TSTCASK-10'], 'sku' => 'TSTCASK-10', 'old' => ' STRENGTH ', 'new' => null, 'source' => 'name'],
        ]), cn_tst($r['cleared']), 'cleared: A, B og I (I med old " STRENGTH " utrimmet)');
        t_eq(['TSTCASK-3' => ['id' => $id['TSTCASK-3'], 'sku' => 'TSTCASK-3', 'value' => 'FINISH']] + $keptDEG, cn_tst($r['kept']), 'kept: C, D, E og G');
        $notListed($r, 'kørsel 1');
        cn_check_audit($pdo, $before, $r, 'kørsel 1');
    });

    t_case('65/66 Kørsel 2 med startlagerets navn: C får 143', function () use ($pdo, $id, $seed, $keptDEG, $notListed) {
        $before = cn_max_audit_id($pdo);
        $r = hsg_cask_number_cleanup($pdo, $seed);
        t_eq('143', cn_cask($pdo, 'TSTCASK-3'), 'C er 143');
        t_eq(['TSTCASK-3' => ['id' => $id['TSTCASK-3'], 'sku' => 'TSTCASK-3', 'old' => 'FINISH', 'new' => '143', 'source' => 'initial_stock']], cn_tst($r['cleared']), 'cleared: præcis C');
        t_eq($keptDEG, cn_tst($r['kept']), 'kept: D, E og G');
        t_eq(null, cn_cask($pdo, 'TSTCASK-1'), 'A uændret siden kørsel 1');
        t_eq('43', cn_cask($pdo, 'TSTCASK-2'), 'B uændret siden kørsel 1');
        t_eq(null, cn_cask($pdo, 'TSTCASK-10'), 'I uændret siden kørsel 1');
        $notListed($r, 'kørsel 2');
        cn_check_audit($pdo, $before, $r, 'kørsel 2');
    });

    t_case('67 Kørsel 3: idempotent', function () use ($pdo, $seed, $keptDEG, $notListed) {
        $before = cn_max_audit_id($pdo);
        $r = hsg_cask_number_cleanup($pdo, $seed);
        foreach (['TSTCASK-1' => null, 'TSTCASK-2' => '43', 'TSTCASK-3' => '143', 'TSTCASK-4' => 'STRENGTH', 'TSTCASK-5' => 'N/A', 'TSTCASK-6' => '231584', 'TSTCASK-7' => 'strength', 'TSTCASK-8' => null, 'TSTCASK-9' => '', 'TSTCASK-10' => null] as $sku => $want) {
            t_eq($want, cn_cask($pdo, $sku), $sku.' uændret');
        }
        t_eq([], cn_tst($r['cleared']), 'cleared har ingen TSTCASK-række');
        t_eq($keptDEG, cn_tst($r['kept']), 'kept: stadig D, E og G');
        $notListed($r, 'kørsel 3');
        cn_check_audit($pdo, $before, $r, 'kørsel 3');
    });

    t_case('68 Startlagerets 12-1016 med den rigtige initial-stock.json', function () use ($pdo, &$p1016) {
        $st = $pdo->prepare('SELECT id,name,cask_number FROM lager_products WHERE sku=?'); $st->execute(['12-1016']);
        $p = $st->fetch();
        if (!$p) { t_true(false, '12-1016 findes ikke i testdatabasen – testen kræver startlageret (run.sh installerer med seed_stock=1)'); return; }
        $p1016 = $p;
        $pdo->prepare('UPDATE lager_products SET cask_number=?,name=? WHERE id=?')->execute(['FINISH', 'Knockdhu (2012) – 10 år – 58,2 %', (int)$p['id']]);
        $before = cn_max_audit_id($pdo);
        $r = hsg_cask_number_cleanup($pdo);
        t_eq('43', cn_cask($pdo, '12-1016'), '12-1016 er 43');
        $want = ['id' => (int)$p['id'], 'sku' => '12-1016', 'old' => 'FINISH', 'new' => '43', 'source' => 'initial_stock'];
        t_true(in_array($want, $r['cleared'], true), 'returværdien indeholder 12-1016 FINISH → 43 (initial_stock)');
        $d = cn_check_audit($pdo, $before, $r, 'kørsel uden seed');
        t_true(is_array($d) && in_array($want, (array)($d['cleared'] ?? []), true), 'audit-posten indeholder 12-1016 FINISH → 43 (initial_stock)');
    });
} finally {
    if (is_array($p1016)) $pdo->prepare('UPDATE lager_products SET name=?,cask_number=? WHERE id=?')->execute([$p1016['name'], $p1016['cask_number'], (int)$p1016['id']]);
    $restore = $pdo->prepare('UPDATE lager_products SET cask_number=? WHERE id=?');
    foreach ($snapshot as $pid => $value) $restore->execute([$value, $pid]);
    cn_cleanup_products($pdo);
    $pdo->prepare('DELETE FROM hsg_audit_log WHERE id>? AND action=?')->execute([$startAuditId, CN_ACTION]);
}

$fails = $GLOBALS['t_fail'];
foreach ($fails as $f) fwrite(STDOUT, "  FEJL: $f\n");
fwrite(STDOUT, sprintf("Fadnummer-oprydning (database): %d tjek bestået, %d fejlede.\n", $GLOBALS['t_pass'], count($fails)));
exit($fails ? 1 : 0);
