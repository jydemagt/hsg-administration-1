<?php
declare(strict_types=1);
/*
 * Flowtest af Kims uploadfil via HTTP (supplier_upload.php) mod den installerede testdatabase:
 * upload → kolonnegæt/husket valg → gæt forfra → lokationskolonner → reservationsvalg → anvend (lager, lagerbevægelser,
 * alias, audit-log, flash) og afvisning af gamle previews (spec 2026-10-19, kriterie 5, 8, 13, 16, 24, 27, 28, 30, 32, 33, 34)
 * samt hjælpeteksten ved reservationsvalget og ugyldige preview-tokens (tillæg 2026-10-20, kriterie 57 og 59) og
 * "Fil:"-linjen med en ugyldig "Lagerantal i alt" (tillæg 2026-10-22, kriterie 74) og "Kun lagerantal" med arkivering af
 * reservationer og fanen "Arkiveret" i Reservationer (spec 2026-10-26-kims-upload-kun-lagerantal, kriterie 148-159 og 162).
 *
 * KØRES KUN af tests/run.sh, mens røgtestens server kører:
 *     php tests/supplier_upload_flow_test.php http://127.0.0.1:8787 testadmin 'Test-Pass-12345!'
 * Afviser at køre, hvis HSG_TEST_DB_NAME ikke er sat eller ikke passer til config.php. Alle data har præfikset TSTKIM-,
 * og de indstillinger, testen ændrer, sættes tilbage bagefter. Kræver ikke curl eller ZipArchive.
 */

error_reporting(E_ALL);
set_error_handler(static function (int $no, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $no) || in_array($no, [E_DEPRECATED, E_USER_DEPRECATED], true)) return true;
    throw new ErrorException($message, 0, $no, $file, $line);
});

$root = dirname(__DIR__);
if ($argc < 4) { fwrite(STDERR, "Brug: php tests/supplier_upload_flow_test.php <base-url> <admin-brugernavn> <adgangskode>\n"); exit(2); }
[, $base, $adminUser, $adminPass] = $argv;
$base = rtrim($base, '/');
if (!is_file($root.'/config.php')) { fwrite(STDERR, "config.php findes ikke – kør via tests/run.sh (røgtest med HSG_TEST_DB_*).\n"); exit(2); }
$config = require $root.'/config.php';
if ((string)getenv('HSG_TEST_DB_NAME') === '' || (string)getenv('HSG_TEST_DB_NAME') !== (string)($config['db_name'] ?? '')) {
    fwrite(STDERR, "Afvist: HSG_TEST_DB_NAME er ikke sat eller passer ikke til config.php. Testen køres aldrig mod andre databaser.\n");
    exit(2);
}
$pdo = new PDO('mysql:host='.$config['db_host'].';dbname='.$config['db_name'].';charset=utf8mb4', $config['db_user'], $config['db_pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false,
]);

$GLOBALS['t_pass'] = 0;
$GLOBALS['t_fail'] = [];
$GLOBALS['t_case'] = '';
function t_eq(mixed $expected, mixed $actual, string $label): void {
    if ($expected === $actual) { $GLOBALS['t_pass']++; return; }
    $GLOBALS['t_fail'][] = '['.$GLOBALS['t_case'].'] '.$label."\n      forventet ".var_export($expected, true).', fik '.var_export($actual, true);
}
function t_true(bool $cond, string $label, string $detail = ''): void {
    if ($cond) { $GLOBALS['t_pass']++; return; }
    $GLOBALS['t_fail'][] = '['.$GLOBALS['t_case'].'] '.$label.($detail !== '' ? "\n      ".$detail : '');
}
function t_case(string $name, callable $fn): void {
    $GLOBALS['t_case'] = $name;
    try { $fn(); } catch (Throwable $e) { $GLOBALS['t_fail'][] = '['.$name.'] uventet fejl: '.get_class($e).': '.$e->getMessage().' ('.basename($e->getFile()).':'.$e->getLine().')'; }
}

/** Minimal HTTP-klient med cookies (PHP-streams): ingen curl-udvidelse nødvendig. Følger ikke redirects automatisk. */
final class Http {
    /** @var array<string,string> */
    private array $cookies = [];
    public function __construct(private string $base) {}
    /** @return array{code:int,loc:?string,body:string} */
    public function request(string $method, string $path, ?string $body = null, ?string $contentType = null): array {
        $headers = ['Accept: text/html'];
        if ($this->cookies) { $pairs = []; foreach ($this->cookies as $k => $v) $pairs[] = $k.'='.$v; $headers[] = 'Cookie: '.implode('; ', $pairs); }
        if ($contentType !== null) $headers[] = 'Content-Type: '.$contentType;
        $ctx = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $headers), 'content' => $body ?? '', 'follow_location' => 0, 'ignore_errors' => true, 'timeout' => 120]]);
        $url = str_starts_with($path, 'http') ? $path : $this->base.'/'.ltrim($path, '/');
        $text = @file_get_contents($url, false, $ctx);
        $resp = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : ($http_response_header ?? []);
        if ($text === false && !$resp) throw new RuntimeException('Ingen svar fra '.$url);
        $code = 0; $loc = null;
        foreach ($resp as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) { $code = (int)$m[1]; $loc = null; }
            elseif (preg_match('/^Location:\s*(.+)$/i', $h, $m)) $loc = trim($m[1]);
            elseif (preg_match('/^Set-Cookie:\s*([^=;\s]+)=([^;]*)/i', $h, $m)) $this->cookies[$m[1]] = $m[2];
        }
        return ['code' => $code, 'loc' => $loc, 'body' => (string)$text];
    }
    public function get(string $path): array { return $this->request('GET', $path); }
    public function follow(array $r): array { $n = 0; while ($r['loc'] !== null && $n++ < 5) $r = $this->get($r['loc']); return $r; }
    public function csrf(string $path = 'supplier_upload.php'): string {
        $r = $this->get($path);
        if (!preg_match('/name="csrf" value="([^"]+)"/', $r['body'], $m)) throw new RuntimeException('Ingen csrf-token på '.$path.' (HTTP '.$r['code'].')');
        return $m[1];
    }
    public function post(string $path, array $data, ?string $csrf = null): array {
        $data['csrf'] = $csrf ?? $this->csrf($path);
        return $this->request('POST', $path, http_build_query($data), 'application/x-www-form-urlencoded');
    }
    public function upload(string $path, array $fields, string $field, string $filename, string $content): array {
        $b = 'hsgtest'.bin2hex(random_bytes(8)); $body = '';
        foreach ($fields as $k => $v) $body .= "--$b\r\nContent-Disposition: form-data; name=\"$k\"\r\n\r\n$v\r\n";
        $body .= "--$b\r\nContent-Disposition: form-data; name=\"$field\"; filename=\"$filename\"\r\nContent-Type: text/csv\r\n\r\n$content\r\n--$b--\r\n";
        return $this->request('POST', $path, $body, 'multipart/form-data; boundary='.$b);
    }
}
function flashes(string $html): array {
    preg_match_all('#<div class="flash ([a-z]+)"[^>]*>(.*?)</div>#s', $html, $m, PREG_SET_ORDER);
    return array_map(static fn($x) => [$x[1], html_entity_decode($x[2], ENT_QUOTES | ENT_HTML5, 'UTF-8')], $m);
}
function page_text(string $html): string { return html_entity_decode(strip_tags(preg_replace('#<(br|/li|/tr|/p|/div|/small)[^>]*>#i', "\n", $html) ?? $html), ENT_QUOTES | ENT_HTML5, 'UTF-8'); }
function has_flash(string $html, string $type, string $text): bool { return in_array([$type, $text], flashes($html), true); }

function q(PDO $pdo, string $sql, array $p = []): array { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchAll(); }
function q1(PDO $pdo, string $sql, array $p = []): mixed { $r = q($pdo, $sql, $p); return $r ? array_values($r[0])[0] : null; }
function db_product(PDO $pdo, string $sku, string $name, array $stock = [], string $status = 'active'): int {
    $pdo->prepare('INSERT INTO lager_products(sku,name,status,bottle_size_cl) VALUES(?,?,?,70)')->execute([$sku, $name, $status]);
    $pid = (int)$pdo->lastInsertId();
    foreach ($stock as $lid => $qty) $pdo->prepare('INSERT INTO lager_stock(product_id,location_id,quantity) VALUES(?,?,?)')->execute([$pid, $lid, $qty]);
    return $pid;
}
function db_stock(PDO $pdo, int $pid): array { $o = []; foreach (q($pdo, 'SELECT location_id,quantity FROM lager_stock WHERE product_id=?', [$pid]) as $r) $o[(int)$r['location_id']] = (int)$r['quantity']; ksort($o); return $o; }
function db_movements(PDO $pdo, int $pid): array { return q($pdo, 'SELECT location_id,change_qty,balance_after,movement_type,reference FROM lager_stock_movements WHERE product_id=? ORDER BY id', [$pid]); }
function last_audit(PDO $pdo, string $action): array { return json_decode((string)q1($pdo, 'SELECT details_json FROM hsg_audit_log WHERE action=? ORDER BY id DESC LIMIT 1', [$action]), true) ?: []; }
function setting_raw(PDO $pdo, string $key): ?string { $v = q1($pdo, 'SELECT setting_value FROM hsg_settings WHERE setting_key=?', [$key]); return $v === null ? null : (string)$v; }
function setting_put(PDO $pdo, string $key, ?string $value): void {
    if ($value === null) { $pdo->prepare('DELETE FROM hsg_settings WHERE setting_key=?')->execute([$key]); return; }
    $pdo->prepare('INSERT INTO hsg_settings(setting_key,setting_value,updated_at) VALUES(?,?,NOW()) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_at=NOW()')->execute([$key, $value]);
}
function db_cleanup(PDO $pdo): void {
    foreach (q($pdo, "SELECT id FROM lager_products WHERE sku LIKE 'TSTKIM-%'") as $r) {
        foreach (['lager_reservations', 'lager_stock_movements', 'lager_stock', 'hsg_supplier_sku_aliases'] as $t) $pdo->prepare("DELETE FROM $t WHERE product_id=?")->execute([(int)$r['id']]);
        $pdo->prepare('DELETE FROM lager_products WHERE id=?')->execute([(int)$r['id']]);
    }
    $pdo->exec("DELETE FROM hsg_supplier_sku_aliases WHERE supplier_sku LIKE 'TSTKIM-%'");
    foreach (q($pdo, "SELECT id FROM lager_locations WHERE name LIKE 'TSTKIM %'") as $r) {
        foreach (['lager_reservations', 'lager_stock_movements', 'lager_stock'] as $t) $pdo->prepare("DELETE FROM $t WHERE location_id=?")->execute([(int)$r['id']]);
        $pdo->prepare('DELETE FROM lager_locations WHERE id=?')->execute([(int)$r['id']]);
    }
}
function preview_path(string $root, string $token): string { return $root.'/storage/import-previews/'.$token.'.json'; }
function preview_json(string $root, string $token): array { return json_decode((string)file_get_contents(preview_path($root, $token)), true, 512, JSON_THROW_ON_ERROR); }
function token_of(?string $loc): string { return preg_match('/preview=([a-f0-9]{40})/', (string)$loc, $m) ? $m[1] : ''; }
function item_index(array $preview, string $sku): int { foreach ($preview['items'] as $i => $it) if (($it['source']['sku'] ?? '') === $sku) return (int)$i; return -1; }

$keepMap = setting_raw($pdo, 'supplier_import_header_map');
$keepMode = setting_raw($pdo, 'supplier_import_reservation_mode');
$keepScope = setting_raw($pdo, 'supplier_import_scope');
$tokens = [];
db_cleanup($pdo);
try {
    $ins = $pdo->prepare('INSERT INTO lager_locations(name,active,sort_order) VALUES(?,1,?)');
    $loc = [];
    foreach ([['TSTKIM Christiansfeld', -300], ['TSTKIM Michael', -200], ['TSTKIM Gert', -100]] as [$n, $so]) { $ins->execute([$n, $so]); $loc[] = (int)$pdo->lastInsertId(); }
    [$L1, $L2, $L3] = $loc;

    // HSG-produkter: 13-1021-lignende (41/2/12), Octomore (0, to reserveret, inaktiv) og en ny vare, der ikke findes.
    $alpha = db_product($pdo, 'TSTKIM-7001', 'TSTKIM Alpha Whisky', [$L1 => 41, $L2 => 2, $L3 => 12]);
    $pdo->prepare("UPDATE lager_products SET distillery='TSTKIM Dist' WHERE id=?")->execute([$alpha]);
    $octo = db_product($pdo, 'TSTKIM-7002', 'TSTKIM Octomore Test', [$L1 => 0], 'inactive');
    foreach ([1, 1] as $qty) $pdo->prepare("INSERT INTO lager_reservations(product_id,location_id,quantity,customer_name,reference,status) VALUES(?,?,?,'TSTKIM','TSTKIM','reserved')")->execute([$octo, $L1, $qty]);

    $epsilon = db_product($pdo, 'TSTKIM-7004', 'TSTKIM Epsilon Test', [$L1 => 0], 'inactive');
    $pdo->prepare("INSERT INTO lager_reservations(product_id,location_id,quantity,customer_name,reference,status) VALUES(?,?,2,'TSTKIM','TSTKIM','reserved')")->execute([$epsilon, $L1]);

    // Kims overskrifter. "Lagerværdi" står før "Lagerbeholdning", og "sponsor til whisky.dk" ligner et produktnavn.
    $headers = ['Varenr.', 'Variant:', 'Destilleri:', 'Kostpris', 'Lagerværdi', 'Lagerbeholdning', 'Christiansfeld', 'hos Michael', 'hos Gert', 'Engros salgspris (ex moms)', 'vejl. salgspris (inkl moms)', 'sponsor til whisky.dk'];
    $rows = [
        ['TSTKIM-7001', 'TSTKIM Alpha Whisky', 'TSTKIM Dist', '100', '9856', '53', '41', '', '12', '253', '499', 'Sponsor tekst'],
        ['TSTKIM-7002', 'TSTKIM Octomore Test', 'TSTKIM Dist2', '100', '0', '0', '0', '', '', '', '', ''],
        ['TSTKIM-7003', 'TSTKIM Helt Ny Vare æøå', 'TSTKIM Dist3', '100', '800', '8', '5', '', '3', '100', '200', ''],
        ['TSTKIM-7004', 'TSTKIM Epsilon Test', 'TSTKIM Dist4', '100', '300', '3', '3', '', '', '', '', ''],
    ];
    $fh = fopen('php://memory', 'w+b');
    foreach (array_merge([$headers], $rows) as $line) fputcsv($fh, $line, ';', '"', '');
    rewind($fh); $csv = (string)stream_get_contents($fh); fclose($fh);

    $http = new Http($base);
    $login = $http->get('admin-login.php');
    preg_match('/name="csrf" value="([^"]+)"/', $login['body'], $m);
    $http->request('POST', 'admin-login.php', http_build_query(['csrf' => $m[1] ?? '', 'username' => $adminUser, 'password' => $adminPass]), 'application/x-www-form-urlencoded');
    if (!str_contains($http->get('supplier_upload.php')['body'], 'Kims uploadfil')) throw new RuntimeException('Admin-login lykkedes ikke');

    // Det forkerte kolonnevalg, som den gamle version huskede (beløbskolonne som lager, engrospris som udsalgspris, sponsor som navn).
    setting_put($pdo, 'supplier_import_header_map', json_encode(['lagervaerdi' => 'stock_quantity', 'variant' => 'name', 'engrossalgsprisexmoms' => 'retail_price', 'sponsortilwhiskydk' => 'name'], JSON_FORCE_OBJECT));
    setting_put($pdo, 'supplier_import_reservation_mode', null);
    setting_put($pdo, 'supplier_import_scope', null);

    $token = ''; $pv = [];
    $col = array_flip($headers);   // overskrift → kolonneindeks

    t_case('5/30 Upload: afvist husket beløbskolonne og standard "add"', function () use (&$token, &$pv, $http, $csv, $root, $col, &$tokens, $pdo) {
        $r = $http->upload('supplier_upload.php', ['csrf' => $http->csrf(), 'action' => 'upload'], 'file', 'tstkim-kim.csv', $csv);
        t_eq(302, $r['code'], 'upload giver redirect');
        $token = token_of($r['loc']);
        t_true($token !== '', 'redirect peger på et preview', (string)$r['loc']);
        if ($token === '') return;
        $tokens[] = $token;
        $pv = preview_json($root, $token);
        t_eq(2, $pv['format'], 'preview har format 2');
        t_eq('add', $pv['reservation_mode'], 'standard for reservationsvalget er add');
        t_eq('', $pv['col_mapping'][$col['Lagerværdi']], 'Lagerværdi er ikke lagerantal');
        t_eq([(string)$col['Lagerværdi'] => 'stock_quantity'], array_combine(array_map('strval', array_keys($pv['col_rejected'])), array_values($pv['col_rejected'])), 'col_rejected viser det afviste valg');
        t_eq('stock_quantity', $pv['col_mapping'][$col['Lagerbeholdning']], 'Lagerbeholdning er lagerantal i alt');
        $page = $http->get('supplier_upload.php?preview='.$token);
        t_true(str_contains(page_text($page['body']), 'Husket valg "Lagerantal i alt" er ikke brugt: overskriften ligner et beløb.'), 'panelet viser bemærkningen');
        t_true(str_contains($page['body'], '<details class="card" open>'), 'panelet åbner automatisk');
        t_true((bool)preg_match('#<option value="add"\s+selected>#', $page['body']), '"add" er forvalgt i reservationsvalget');
        t_true(str_contains(page_text($page['body']), "Et nyt valg bruges først, når du har trykket 'Genberegn preview'."), '57: hjælpeteksten står under reservationsvalget');
        t_eq('supplier_import.preview', (string)q1($pdo, "SELECT action FROM hsg_audit_log WHERE action='supplier_import.preview' ORDER BY id DESC LIMIT 1"), 'upload logges');
    });
    if ($token === '') throw new RuntimeException('Upload gav intet preview – de øvrige trin springes over');

    t_case('8 Gæt kolonner forfra gemmer ikke noget', function () use (&$pv, $http, $root, $col, $token, $pdo) {
        $before = setting_raw($pdo, 'supplier_import_header_map');
        $page = $http->follow($http->post('supplier_upload.php', ['action' => 'reguess', 'preview_token' => $token]));
        t_true(has_flash($page['body'], 'success', "Kolonnerne er gættet forfra. Tjek valget, og tryk 'Genberegn' eller 'Anvend' for at gemme det."), 'flash efter gæt forfra', json_encode(flashes($page['body']), JSON_UNESCAPED_UNICODE));
        $pv = preview_json($root, $token);
        t_eq('name', $pv['col_mapping'][$col['Variant:']], 'Variant: er produktnavn');
        t_eq('', $pv['col_mapping'][$col['sponsor til whisky.dk']], 'sponsor til whisky.dk er ignoreret');
        t_eq('wholesale_price', $pv['col_mapping'][$col['Engros salgspris (ex moms)']], 'Engros er engrospris');
        t_eq('retail_price', $pv['col_mapping'][$col['vejl. salgspris (inkl moms)']], 'vejl. salgspris er udsalgspris');
        t_eq($before, setting_raw($pdo, 'supplier_import_header_map'), 'det huskede kolonnevalg er uændret i databasen');
    });

    t_case('13 Lokationskolonner: kun Michael ændres, og Fil-linjen vises', function () use (&$pv, $http, $root, $col, $token, $L1, $L2, $L3, $alpha, $pdo) {
        $map = (array)$pv['col_mapping'];
        $map[$col['Christiansfeld']] = 'stock_loc_'.$L1; $map[$col['hos Michael']] = 'stock_loc_'.$L2; $map[$col['hos Gert']] = 'stock_loc_'.$L3;
        $page = $http->follow($http->post('supplier_upload.php', ['action' => 'remap', 'preview_token' => $token, 'col_map' => $map]));
        $pv = preview_json($root, $token);
        $i = item_index($pv, 'TSTKIM-7001');
        t_true($i >= 0, 'TSTKIM-7001 findes i previewet');
        if ($i < 0) return;
        t_eq($alpha, (int)$pv['items'][$i]['match']['id'], 'match');
        $stock = array_filter($pv['items'][$i]['changes'], static fn($k) => str_starts_with((string)$k, 'stock_'), ARRAY_FILTER_USE_KEY);
        t_eq(['stock_loc_'.$L2 => ['old' => 2, 'new' => 0, 'file' => 0]], $stock, 'eneste lagerændring er Michael 2 → 0 (ikke 9856 fra Lagerværdi)');
        $text = page_text($page['body']);
        t_true(str_contains($text, 'Lagerantal: TSTKIM Michael: 2 → 0'), 'ændringslinjen vises');
        t_true(str_contains($text, 'Fil: TSTKIM Christiansfeld 41 · TSTKIM Michael 0 (tom) · TSTKIM Gert 12 · i alt 53'), 'Fil-linjen vises');
        t_true(str_contains($text, 'Nyt produkt oprettes (Lager: 8)'), 'badgen viser summen af lokationerne (8), ikke 16');
        t_true(!str_contains(json_encode($pv['items'], JSON_UNESCAPED_UNICODE), '9856'), 'Lagerværdien (9856) bruges ikke i previewet');
    });

    // Ændret bevidst af spec 2026-10-26-kims-upload-kun-lagerantal (kriterie 162): Octomore (Kims tal 0, 2 reserveret)
    // foreslås nu arkiveret, så der er hverken advarsel eller tillæg. Tillægget tjekkes i stedet på Epsilon.
    t_case('28/30 Reservationsvalg "none": gemmes, logges, og Octomore foreslås arkiveret', function () use (&$pv, $http, $root, $token, $L1, $pdo) {
        $page = $http->follow($http->post('supplier_upload.php', ['action' => 'reservation_mode', 'preview_token' => $token, 'reservation_mode' => 'none']));
        t_true(has_flash($page['body'], 'success', 'Reservationsvalget er gemt, og preview er genberegnet.'), 'flash', json_encode(flashes($page['body']), JSON_UNESCAPED_UNICODE));
        t_eq('none', setting_raw($pdo, 'supplier_import_reservation_mode'), 'indstillingen er gemt');
        t_eq(['reservation_mode' => 'none'], last_audit($pdo, 'supplier_import.settings'), 'audit: supplier_import.settings');
        $pv = preview_json($root, $token);
        t_eq('none', $pv['reservation_mode'], 'previewet er genberegnet med none');
        $i = item_index($pv, 'TSTKIM-7002');
        $stock = array_filter($pv['items'][$i]['changes'] ?? [], static fn($k) => str_starts_with((string)$k, 'stock_'), ARRAY_FILTER_USE_KEY);
        t_eq([], $stock, 'ingen lagerændring for Octomore med none (0 → 0)');
        t_eq(2, $pv['items'][$i]['archive']['quantity'] ?? null, 'Octomore: archive.quantity = 2');
        t_eq(true, $pv['items'][$i]['archive']['default'] ?? null, 'Octomore: archive.default');
        t_true(!str_contains(page_text($page['body']), 'får 0 fysisk efter import, men 2 er reserveret'), 'ingen advarsel om negativt disponibelt for Octomore');
        // Ugyldig værdi afvises og ændrer ikke indstillingen.
        $bad = $http->follow($http->post('supplier_upload.php', ['action' => 'reservation_mode', 'preview_token' => $token, 'reservation_mode' => "x'; DROP TABLE hsg_settings;--"]));
        t_eq('error', flashes($bad['body'])[0][0] ?? '', 'ugyldigt reservationsvalg afvises');
        t_eq('none', setting_raw($pdo, 'supplier_import_reservation_mode'), 'indstillingen er uændret efter ugyldigt valg');
        // Tilbage til add.
        $http->follow($http->post('supplier_upload.php', ['action' => 'reservation_mode', 'preview_token' => $token, 'reservation_mode' => 'add']));
        $pv = preview_json($root, $token);
        t_eq('add', $pv['reservation_mode'], 'tilbage til add');
        $i = item_index($pv, 'TSTKIM-7002');
        t_eq(null, $pv['items'][$i]['changes']['stock_loc_'.$L1] ?? null, 'add: ingen ændring for Octomore (0 → 0 med forvalgt arkivering)');
        $e = item_index($pv, 'TSTKIM-7004');
        t_eq(['old' => 0, 'new' => 5, 'file' => 3, 'reserved' => 2], $pv['items'][$e]['changes']['stock_loc_'.$L1] ?? null, 'add: Epsilon 0 → 5 (fil 3 + 2 reserveret)');
    });

    t_case('34 Gammelt preview uden format 2 kan ikke anvendes', function () use (&$pv, $http, $root, $octo, $L1, $pdo, &$tokens) {
        $old = $pv; unset($old['format']);
        $oldToken = bin2hex(random_bytes(20)); $tokens[] = $oldToken;
        file_put_contents(preview_path($root, $oldToken), json_encode($old));
        $i = item_index($pv, 'TSTKIM-7002');
        $before = db_stock($pdo, $octo); $runs = (int)q1($pdo, 'SELECT COUNT(*) FROM hsg_supplier_import_runs');
        $page = $http->follow($http->post('supplier_upload.php', ['action' => 'apply', 'preview_token' => $oldToken, 'apply_rows' => [$i], 'product_'.$i => (string)$octo]));
        t_true(has_flash($page['body'], 'error', 'Previewet er lavet med en ældre version af systemet. Upload filen igen.'), 'fejl-flash', json_encode(flashes($page['body']), JSON_UNESCAPED_UNICODE));
        t_eq($before, db_stock($pdo, $octo), 'lageret er uændret');
        t_eq($runs, (int)q1($pdo, 'SELECT COUNT(*) FROM hsg_supplier_import_runs'), 'ingen ny import-kørsel');
    });

    t_case('59 Ugyldigt preview-token: fejlbesked, redirect uden token og ingen ændringer', function () use (&$pv, $http, $octo, $pdo) {
        $i = item_index($pv, 'TSTKIM-7002');
        $before = db_stock($pdo, $octo); $runs = (int)q1($pdo, 'SELECT COUNT(*) FROM hsg_supplier_import_runs');
        $r = $http->post('supplier_upload.php', ['action' => 'apply', 'preview_token' => '../x', 'apply_rows' => [$i], 'product_'.$i => (string)$octo]);
        t_eq(302, $r['code'], 'POST giver redirect');
        t_eq('supplier_upload.php', basename((string)parse_url((string)$r['loc'], PHP_URL_PATH)), 'redirect til supplier_upload.php');
        t_eq(null, parse_url((string)$r['loc'], PHP_URL_QUERY), 'intet token i URL\'en');
        $page = $http->follow($r);
        t_true(has_flash($page['body'], 'error', 'Kunne ikke anvende ændringer: Ugyldigt preview-token.'), 'fejl-flash', json_encode(flashes($page['body']), JSON_UNESCAPED_UNICODE));
        t_eq($before, db_stock($pdo, $octo), 'lageret er uændret');
        t_eq($runs, (int)q1($pdo, 'SELECT COUNT(*) FROM hsg_supplier_import_runs'), 'ingen ny import-kørsel');
        // GET med et ugyldigt token viser upload-siden uden preview.
        $get = $http->get('supplier_upload.php?preview='.rawurlencode('../x'));
        t_eq(200, $get['code'], 'GET svarer 200');
        t_true(str_contains($get['body'], 'Upload Kims masterfil') && !str_contains($get['body'], '<h2>Preview:'), 'GET viser upload-siden uden preview');
    });

    t_case('13/16/24/27/32/33 Anvend: lager, lagerbevægelser, alias, audit og flash', function () use (&$pv, $http, $root, $token, $L1, $L2, $L3, $alpha, $octo, $epsilon, $pdo, $col) {
        $post = ['action' => 'apply', 'preview_token' => $token, 'apply_rows' => []];
        foreach ($pv['items'] as $i => $it) {
            if (empty($it['selected'])) continue;
            $post['apply_rows'][] = $i;
            $post['product_'.$i] = !empty($it['is_create_new']) ? 'create_new' : (string)(int)$it['match']['id'];
        }
        t_eq(4, count($post['apply_rows']), 'alle fire varer er forvalgt');
        $page = $http->follow($http->post('supplier_upload.php', $post));
        $fl = flashes($page['body']);
        t_true(($fl[0][0] ?? '') === 'success' && str_contains($fl[0][1] ?? '', '4 reserverede flasker er lagt oven i Kims tal.'), 'flash nævner de reserverede flasker', json_encode($fl, JSON_UNESCAPED_UNICODE));
        // 13: Michael 2 → 0, Christiansfeld og Gert uændrede, præcis én lagerbevægelse.
        t_eq([$L1 => 41, $L2 => 0, $L3 => 12], db_stock($pdo, $alpha), '13: lager 41/0/12 (sum 53)');
        t_eq([['location_id' => $L2, 'change_qty' => -2, 'balance_after' => 0, 'movement_type' => 'import', 'reference' => 'Kims masterfil opdatering']], array_map(static fn($r) => ['location_id' => (int)$r['location_id'], 'change_qty' => (int)$r['change_qty'], 'balance_after' => (int)$r['balance_after'], 'movement_type' => $r['movement_type'], 'reference' => $r['reference']], db_movements($pdo, $alpha)), '13: præcis én lagerbevægelse (Michael −2)');
        // 27/33: reservationerne er lagt oven i, og produktet er aktivt igen.
        t_eq([$L1 => 2], db_stock($pdo, $octo), '27: fysisk 2');
        t_eq('Kims masterfil opdatering (+2 reserveret)', (string)(db_movements($pdo, $octo)[0]['reference'] ?? ''), '27: lagerbevægelsens reference');
        t_eq(0, (int)q1($pdo, "SELECT COALESCE(SUM(s.quantity),0)-COALESCE((SELECT SUM(quantity) FROM lager_reservations WHERE product_id=? AND status='reserved'),0) FROM lager_stock s WHERE s.product_id=?", [$octo, $octo]), '27: disponibelt 0');
        // 33: Kims tal 3 + 2 reserveret = 5 fysisk, disponibelt 3 – det inaktive produkt er aktivt igen.
        t_eq([$L1 => 5], db_stock($pdo, $epsilon), '33: fysisk 5 (3 + 2)');
        t_eq('active', (string)q1($pdo, 'SELECT status FROM lager_products WHERE id=?', [$epsilon]), '33: produktet er aktivt igen');
        // 16/24: nyt produkt med lager på de lokationer, der har tal, og alias for eget varenummer.
        $newId = (int)q1($pdo, "SELECT id FROM lager_products WHERE sku='TSTKIM-7003'");
        t_true($newId > 0, '16: det nye produkt er oprettet');
        t_eq([$L1 => 5, $L3 => 3], $newId > 0 ? db_stock($pdo, $newId) : [], '16: Christiansfeld 5 og Gert 3, ingen række på Michael');
        t_eq([$newId], array_map(static fn($r) => (int)$r['product_id'], q($pdo, "SELECT product_id FROM hsg_supplier_sku_aliases WHERE supplier_sku='TSTKIM-7003'")), '24: eget varenummer er gemt som alias');
        // 32: audit.
        $audit = last_audit($pdo, 'supplier_import.apply');
        t_eq('add', $audit['reservation_mode'] ?? null, '32: audit reservation_mode');
        t_eq(4, $audit['reserved_added_total'] ?? null, '32: audit reserved_added_total (2 Octomore + 2 Epsilon)');
        $byProduct = [];
        foreach ((array)($audit['details'] ?? []) as $d) if (isset($d['product_id'])) $byProduct[(int)$d['product_id']] = $d;
        t_eq([['location_id' => $L2, 'old' => 2, 'new' => 0, 'file' => 0]], $byProduct[$alpha]['stock'] ?? null, '32: audit stock[] for 13-1021-varen');
        t_eq([['location_id' => $L1, 'old' => 0, 'new' => 2, 'file' => 0, 'reserved_added' => 2]], $byProduct[$octo]['stock'] ?? null, '32: audit stock[] med reserved_added for Octomore');
        // Det huskede kolonnevalg er rettet: beløbskolonnen er ignoreret, lagerbeholdning og lokationer er husket.
        $saved = json_decode((string)setting_raw($pdo, 'supplier_import_header_map'), true) ?: [];
        t_eq('', $saved['lagervaerdi'] ?? 'MANGLER', 'huskede kolonner: Lagerværdi er ignoreret');
        t_eq('stock_quantity', $saved['lagerbeholdning'] ?? null, 'huskede kolonner: Lagerbeholdning');
        t_eq('stock_loc_'.$L2, $saved['hosmichael'] ?? null, 'huskede kolonner: hos Michael');
        t_eq('add', setting_raw($pdo, 'supplier_import_reservation_mode'), 'reservationsvalget er husket efter Anvend');
    });

    t_case('74 Fil-linjen viser en ugyldig "Lagerantal i alt" i tilstand L og T', function () use ($http, $root, $headers, $col, $L1, $L2, $L3, &$tokens) {
        // Egen upload, så de øvrige trin er uændrede. Intet anvendes, og det huskede kolonnevalg sættes tilbage i finally.
        $fh = fopen('php://memory', 'w+b');
        foreach ([$headers, ['TSTKIM-7101', 'TSTKIM Ugyldig Total', 'TSTKIM Dist5', '', '', '3 + 2', '41', '', '12', '', '', '']] as $line) fputcsv($fh, $line, ';', '"', '');
        rewind($fh); $csv = (string)stream_get_contents($fh); fclose($fh);
        $r = $http->upload('supplier_upload.php', ['csrf' => $http->csrf(), 'action' => 'upload'], 'file', 'tstkim-71.csv', $csv);
        $t = token_of($r['loc']);
        t_true($t !== '', 'upload giver et preview', (string)$r['loc']);
        if ($t === '') return;
        $tokens[] = $t;
        $map = array_fill_keys(array_keys($headers), '');
        $map[$col['Varenr.']] = 'sku'; $map[$col['Variant:']] = 'name'; $map[$col['Lagerbeholdning']] = 'stock_quantity';
        $map[$col['Christiansfeld']] = 'stock_loc_'.$L1; $map[$col['hos Michael']] = 'stock_loc_'.$L2; $map[$col['hos Gert']] = 'stock_loc_'.$L3;
        $modes = [
            'L' => ['Fil: TSTKIM Christiansfeld 41 · TSTKIM Michael 0 (tom) · TSTKIM Gert 12 · i alt „3 + 2“ (ugyldig)', 'Bemærk: Ugyldig værdi „3 + 2“ for lagerantal i alt – bruges ikke som kontroltal.'],
            'T' => ['Fil: lagerantal i alt „3 + 2“ (ugyldig)', 'Bemærk: Ugyldig værdi „3 + 2“ for lagerantal i alt – lageret ændres ikke.'],
        ];
        foreach ($modes as $mode => [$fileLine, $note]) {
            // Tilstand T: kun "Lagerantal i alt" er valgt.
            if ($mode === 'T') { $map[$col['Christiansfeld']] = ''; $map[$col['hos Michael']] = ''; $map[$col['hos Gert']] = ''; }
            $page = $http->follow($http->post('supplier_upload.php', ['action' => 'remap', 'preview_token' => $t, 'col_map' => $map]));
            $pv = preview_json($root, $t);
            $i = item_index($pv, 'TSTKIM-7101');
            t_true($i >= 0, $mode.': varen findes i previewet');
            if ($i < 0) return;
            t_eq('3 + 2', $pv['items'][$i]['stock_file']['total_invalid'] ?? null, $mode.': stock_file.total_invalid');
            t_eq(null, $pv['items'][$i]['stock_file']['total'] ?? null, $mode.': stock_file.total');
            t_true(!array_key_exists('stock_file_total_invalid', (array)$pv['items'][$i]['source']), $mode.': source har intet stock_file_total_invalid');
            $text = page_text($page['body']);
            t_true(str_contains($text, $fileLine), $mode.': '.$fileLine);
            t_true(str_contains($text, $note), $mode.': bemærkningen vises');
        }
    });

    /* ---- "Kun lagerantal" og arkivering af reservationer (spec 2026-10-26-kims-upload-kun-lagerantal, kriterie 148–159) ----
     * Egne varer og egne uploads, så de eksisterende trin er uændrede. Kolonnevalget sættes eksplicit med remap
     * (R/S/T → L1/L2/L3, Q → Lagerantal i alt), og reservationsvalget er add. */
    $reserve = static function (int $pid, int $lid, int $qty, string $customer, ?string $reference = null) use ($pdo): int {
        $pdo->prepare("INSERT INTO lager_reservations(product_id,location_id,quantity,customer_name,reference,status) VALUES(?,?,?,?,?,'reserved')")->execute([$pid, $lid, $qty, $customer, $reference]);
        return (int)$pdo->lastInsertId();
    };
    $p8101 = db_product($pdo, 'TSTKIM-8101', 'TSTKIM Spøgelse Flow', [$L1 => 0, $L2 => 0, $L3 => 2]);
    $pdo->prepare("UPDATE lager_products SET distillery='TSTKIM Dist' WHERE id=?")->execute([$p8101]);
    $rA = $reserve($p8101, $L3, 1, '<b>TSTKIM Hansen</b>', 'Ordre 1047');
    $rB = $reserve($p8101, $L3, 1, 'TSTKIM Jensen');
    $p8102 = db_product($pdo, 'TSTKIM-8102', 'TSTKIM Halv Flow', [$L1 => 5, $L3 => 1]);
    $pdo->prepare("UPDATE lager_products SET distillery='TSTKIM Dist' WHERE id=?")->execute([$p8102]);
    $rC = $reserve($p8102, $L3, 1, 'TSTKIM Gert-kunde');
    $p8103 = db_product($pdo, 'TSTKIM-8103', 'TSTKIM Ikke i fil', [$L3 => 2]);
    $rD = $reserve($p8103, $L3, 1, 'TSTKIM D-kunde');
    $rE = $reserve($p8103, $L3, 1, 'TSTKIM E-kunde');
    $p8104 = db_product($pdo, 'TSTKIM-8104', 'TSTKIM Pris Flow', [$L1 => 1]);
    $pdo->prepare('UPDATE lager_products SET retail_price=100.00, wholesale_price=NULL, distillery=NULL, cask_number=NULL WHERE id=?')->execute([$p8104]);
    $p8105 = db_product($pdo, 'TSTKIM-8105', 'TSTKIM Manuel Flow', [$L3 => 1]);
    $rF = $reserve($p8105, $L3, 1, 'TSTKIM F-kunde');

    $csvOf = static function (array $rows) use ($headers): string {
        $fh = fopen('php://memory', 'w+b');
        foreach (array_merge([$headers], $rows) as $line) fputcsv($fh, $line, ';', '"', '');
        rewind($fh); $csv = (string)stream_get_contents($fh); fclose($fh);
        return $csv;
    };
    //               Varenr.        Variant:                    Destilleri:       Kost Værdi Q    R    S   T    Engros vejl. sponsor
    $f1 = [['TSTKIM-8101', 'TSTKIM Spøgelse Flow', 'TSTKIM Dist', '', '', '0', '0', '', '0', '', '', ''],
           ['TSTKIM-8102', 'TSTKIM Halv Flow', 'TSTKIM Dist', '', '', '5', '5', '', '0', '', '', '']];
    $f2 = [['TSTKIM-8104', 'TSTKIM Pris Flow', 'TSTKIM Ny Dist', '', '', '3', '3', '', '', '253', '499', ''],
           ['TSTKIM-8199', 'TSTKIM Helt Ny Kun Lager', '', '', '', '4', '4', '', '', '', '', '']];
    $f3 = [['TSTKIM-8105', 'TSTKIM Manuel Flow', '', '', '', '0', '0', '', '0', '', '', '']];
    $fullMap = array_fill_keys(array_keys($headers), '');
    $fullMap[$col['Varenr.']] = 'sku'; $fullMap[$col['Variant:']] = 'name'; $fullMap[$col['Destilleri:']] = 'distillery';
    $fullMap[$col['Lagerbeholdning']] = 'stock_quantity';
    $fullMap[$col['Christiansfeld']] = 'stock_loc_'.$L1; $fullMap[$col['hos Michael']] = 'stock_loc_'.$L2; $fullMap[$col['hos Gert']] = 'stock_loc_'.$L3;
    $fullMap[$col['Engros salgspris (ex moms)']] = 'wholesale_price'; $fullMap[$col['vejl. salgspris (inkl moms)']] = 'retail_price';
    /** Upload + eksplicit kolonnevalg. Returnerer tokenet ('' ved fejl). */
    $uploadFile = static function (string $filename, array $rows, array $extra = []) use ($http, $csvOf, $fullMap, &$tokens): string {
        $r = $http->upload('supplier_upload.php', ['csrf' => $http->csrf(), 'action' => 'upload'] + $extra, 'file', $filename, $csvOf($rows));
        $t = token_of($r['loc']);
        if ($t === '') return '';
        $tokens[] = $t;
        $http->follow($http->post('supplier_upload.php', ['action' => 'remap', 'preview_token' => $t, 'col_map' => $fullMap]));
        return $t;
    };
    $stockOf = static fn(array $changes): array => array_filter($changes, static fn($k) => str_starts_with((string)$k, 'stock_'), ARRAY_FILTER_USE_KEY);
    $resStatus = static fn(int $id): string => (string)q1($pdo, 'SELECT status FROM lager_reservations WHERE id=?', [$id]);
    $archiveEvents = static fn(): int => (int)q1($pdo, "SELECT COUNT(*) FROM hsg_audit_log WHERE action='reservation.archive'");
    $movementList = static fn(int $pid): array => array_map(static fn($r) => ['location_id' => (int)$r['location_id'], 'change_qty' => (int)$r['change_qty'], 'balance_after' => (int)$r['balance_after'], 'movement_type' => $r['movement_type'], 'reference' => $r['reference']], db_movements($pdo, $pid));
    $f1Token = ''; $f3Preview = [];

    t_case('148 Preview med arkiveringsliste (F1, Produktdata og lager)', function () use (&$f1Token, $uploadFile, $f1, $root, $http, $stockOf, $L3, $rA, $rB) {
        $f1Token = $uploadFile('tstkim-f1.csv', $f1);
        t_true($f1Token !== '', 'upload af F1 giver et preview');
        if ($f1Token === '') return;
        $pv = preview_json($root, $f1Token);
        t_eq('full', $pv['import_scope'] ?? null, 'preview.import_scope = full');
        t_eq('add', $pv['reservation_mode'] ?? null, 'reservationsvalget er add');
        $it = $pv['items'][item_index($pv, 'TSTKIM-8101')] ?? [];
        t_eq(true, $it['archive']['default'] ?? null, '8101: archive.default');
        t_eq(2, $it['archive']['quantity'] ?? null, '8101: archive.quantity = 2');
        t_eq([$rA, $rB], array_column((array)($it['archive']['reservations'] ?? []), 'id'), '8101: kandidaterne er rA og rB');
        t_eq(['stock_loc_'.$L3 => ['old' => 2, 'new' => 0, 'file' => 0, 'archived' => 2]], $stockOf((array)($it['changes'] ?? [])), '8101: lagerændringerne');
        $halv = $pv['items'][item_index($pv, 'TSTKIM-8102')] ?? [];
        t_true(array_key_exists('archive', $halv) && $halv['archive'] === null, '8102: archive = null');
        t_eq([], $stockOf((array)($halv['changes'] ?? ['x' => 1])), '8102: ingen lagerændringer');
        t_eq(-1, item_index($pv, 'TSTKIM-8103'), '8103 står ikke i previewet');
        $body = $http->get('supplier_upload.php?preview='.$f1Token)['body'];
        $text = page_text($body);
        preg_match_all('#<input type="checkbox" name="archive_res\[\]" value="(\d+)"( checked)?#', $body, $m);
        t_eq([$rA, $rB], array_map('intval', $m[1]), 'siden har præcis to felter archive_res[] (rA og rB)');
        t_eq([' checked', ' checked'], $m[2], 'begge felter er afkrydset');
        t_true(str_contains($text, 'Arkivér reservationer – Kims lager i alt er 0:'), 'overskriften på arkiveringslisten');
        t_true(str_contains($text, 'Lagerantal: TSTKIM Gert: 2 → 0 (fil 0, 2 reserveret arkiveres)'), 'ændringslinjen med arkivering');
        t_true(str_contains($text, 'Arkiveres: 2 reservationer (2 stk.) på 1 vare, fordi Kims lager i alt er 0.'), 'opsummeringen "Arkiveres: …"');
        t_true(str_contains($body, '&lt;b&gt;TSTKIM Hansen&lt;/b&gt;'), 'kundenavnet er escapet');
        t_true(!str_contains($body, '<b>TSTKIM Hansen</b>'), 'kundenavnet står ikke uescapet');
    });

    t_case('149 Andet produkt valgt manuelt: intet arkiveres', function () use (&$f3Preview, $uploadFile, $f3, $root, $http, $pdo, $resStatus, $archiveEvents, $p8103, $rD, $rE, $rF, $L3) {
        $t = $uploadFile('tstkim-f3.csv', $f3);
        t_true($t !== '', 'upload af F3 giver et preview');
        if ($t === '') return;
        $f3Preview = preview_json($root, $t);
        $i = item_index($f3Preview, 'TSTKIM-8105');
        t_eq([$rF], array_column((array)($f3Preview['items'][$i]['archive']['reservations'] ?? []), 'id'), 'forudsætning: rF er kandidat');
        $events = $archiveEvents();
        $page = $http->follow($http->post('supplier_upload.php', ['action' => 'apply', 'preview_token' => $t, 'apply_rows' => [$i], 'product_'.$i => (string)$p8103, 'archive_res' => [$rF]]));
        t_eq(['reserved', 'reserved', 'reserved'], [$resStatus($rF), $resStatus($rD), $resStatus($rE)], 'rF, rD og rE er stadig reserveret');
        t_eq([$L3 => 2], db_stock($pdo, $p8103), 'lageret for 8103 er uændret');
        t_true(has_flash($page['body'], 'warning', '1 valgt reservation blev ikke arkiveret, fordi de ikke længere er aktive, eller fordi du valgte et andet produkt end previewets match.'), 'warning-flash', json_encode(flashes($page['body']), JSON_UNESCAPED_UNICODE));
        t_eq($events, $archiveEvents(), 'ingen nye reservation.archive');
    });

    t_case('150/159 Anvend som browseren: arkivering, lager, flash, audit', function () use (&$f1Token, $root, $http, $pdo, $resStatus, $movementList, $p8101, $p8102, $p8103, $rA, $rB, $rC, $rD, $rE, $L1, $L2, $L3) {
        if ($f1Token === '') { t_true(false, 'previewet fra 148 mangler'); return; }
        $pv = preview_json($root, $f1Token);
        $post = ['action' => 'apply', 'preview_token' => $f1Token, 'apply_rows' => [], 'archive_res' => [$rA, $rB, $rC, $rD]];
        foreach ($pv['items'] as $i => $it) {
            if (empty($it['selected'])) continue;
            $post['apply_rows'][] = $i;
            $post['product_'.$i] = !empty($it['is_create_new']) ? 'create_new' : (string)(int)$it['match']['id'];
        }
        t_true(in_array(item_index($pv, 'TSTKIM-8101'), $post['apply_rows'], true), '8101 er forvalgt');
        $stock8102 = db_stock($pdo, $p8102); $stock8103 = db_stock($pdo, $p8103);
        $lastAudit = (int)q1($pdo, 'SELECT COALESCE(MAX(id),0) FROM hsg_audit_log');
        $page = $http->follow($http->post('supplier_upload.php', $post));
        t_eq(['archived', 'archived'], [$resStatus($rA), $resStatus($rB)], 'rA og rB er arkiveret');
        t_eq(['reserved', 'reserved', 'reserved'], [$resStatus($rC), $resStatus($rD), $resStatus($rE)], 'rC, rD og rE er reserveret');
        t_eq([$L1 => 0, $L2 => 0, $L3 => 0], db_stock($pdo, $p8101), '8101: lager 0/0/0');
        t_eq([['location_id' => $L3, 'change_qty' => -2, 'balance_after' => 0, 'movement_type' => 'import', 'reference' => 'Kims masterfil opdatering (2 arkiveret)']], $movementList($p8101), '8101: præcis én lagerbevægelse');
        t_eq($stock8102, db_stock($pdo, $p8102), '8102: uændret lager');
        t_eq($stock8103, db_stock($pdo, $p8103), '8103: uændret lager');
        t_eq('inactive', (string)q1($pdo, 'SELECT status FROM lager_products WHERE id=?', [$p8101]), '8101: status inactive');
        $fl = flashes($page['body']);
        t_true(($fl[0][0] ?? '') === 'success' && str_contains($fl[0][1] ?? '', '2 reservationer (2 stk.) er arkiveret, fordi Kims lager var 0.'), 'success-flash nævner arkiveringen', json_encode($fl, JSON_UNESCAPED_UNICODE));
        t_eq([], array_values(array_filter($fl, static fn($f) => $f[0] === 'warning')), 'ingen warning-flash (rC og rD ignoreres uden besked)');
        $applyRow = q($pdo, "SELECT id,entity_id,details_json FROM hsg_audit_log WHERE action='supplier_import.apply' AND id>? ORDER BY id DESC LIMIT 1", [$lastAudit])[0] ?? [];
        $audit = json_decode((string)($applyRow['details_json'] ?? ''), true) ?: [];
        t_eq('full', $audit['import_scope'] ?? null, 'audit: import_scope');
        t_eq(2, $audit['reservations_archived'] ?? null, 'audit: reservations_archived');
        t_eq(2, $audit['reservations_archived_qty'] ?? null, 'audit: reservations_archived_qty');
        $d8101 = [];
        foreach ((array)($audit['details'] ?? []) as $d) if ((int)($d['product_id'] ?? 0) === $p8101) $d8101 = $d;
        t_eq(2, count((array)($d8101['archived_reservations'] ?? [])), 'audit: to archived_reservations for 8101');
        t_eq(['reservation_id' => $rA, 'location_id' => $L3, 'quantity' => 1, 'customer_name' => '<b>TSTKIM Hansen</b>', 'reference' => 'Ordre 1047', 'status_before' => 'reserved', 'status_after' => 'archived'], $d8101['archived_reservations'][0] ?? null, 'audit: første arkiverede reservation');
        t_eq([['location_id' => $L3, 'old' => 2, 'new' => 0, 'file' => 0, 'archived' => 2]], $d8101['stock'] ?? null, 'audit: stock[] for 8101');
        $events = q($pdo, "SELECT entity_type,entity_id,details_json FROM hsg_audit_log WHERE action='reservation.archive' AND id>? ORDER BY id", [$lastAudit]);
        t_eq([['reservation', (string)$rA], ['reservation', (string)$rB]], array_map(static fn($e) => [$e['entity_type'], (string)$e['entity_id']], $events), 'præcis to reservation.archive (rA og rB)');
        foreach ($events as $e) {
            $det = json_decode((string)$e['details_json'], true) ?: [];
            t_eq('supplier_import', $det['source'] ?? null, 'reservation.archive '.$e['entity_id'].': source');
            t_eq((int)($applyRow['entity_id'] ?? -1), (int)($det['import_run_id'] ?? -2), 'reservation.archive '.$e['entity_id'].': import_run_id = kørslens id');
        }
        // 159: arkiverede tæller ikke som reserveret, og disponibelt = fysisk.
        t_eq(0, (int)q1($pdo, "SELECT COUNT(*) FROM lager_reservations WHERE product_id=? AND status='reserved'", [$p8101]), '159: ingen aktive reservationer på 8101');
        t_eq(0, (int)q1($pdo, "SELECT COALESCE(SUM(s.quantity),0)-COALESCE((SELECT SUM(quantity) FROM lager_reservations WHERE product_id=? AND status='reserved'),0) FROM lager_stock s WHERE s.product_id=?", [$p8101, $p8101]), '159: disponibelt = fysisk (0)');
    });

    t_case('151 Idempotent: samme fil igen', function () use ($uploadFile, $f1, $root, $http, $pdo, $stockOf, $movementList, $archiveEvents, $p8101, $L1, $L2, $L3) {
        $t = $uploadFile('tstkim-f1-igen.csv', $f1);
        t_true($t !== '', 'upload af F1 igen giver et preview');
        if ($t === '') return;
        $pv = preview_json($root, $t);
        $i = item_index($pv, 'TSTKIM-8101');
        t_true(array_key_exists('archive', $pv['items'][$i] ?? []) && $pv['items'][$i]['archive'] === null, '8101: archive = null');
        t_eq([], $stockOf((array)($pv['items'][$i]['changes'] ?? ['x' => 1])), '8101: ingen lagerændringer');
        $moves = count($movementList($p8101)); $events = $archiveEvents();
        $http->follow($http->post('supplier_upload.php', ['action' => 'apply', 'preview_token' => $t, 'apply_rows' => [$i], 'product_'.$i => (string)$p8101]));
        t_eq($moves, count($movementList($p8101)), 'ingen nye lagerbevægelser for 8101');
        t_eq($events, $archiveEvents(), 'ingen nye reservation.archive');
        t_eq([$L1 => 0, $L2 => 0, $L3 => 0], db_stock($pdo, $p8101), 'lageret er uændret 0/0/0');
    });

    t_case('152 Kun lagerantal: preview og husket valg (F2)', function () use ($uploadFile, $f2, $root, $http, $pdo, $L1) {
        $t = $uploadFile('tstkim-f2.csv', $f2, ['import_scope' => 'stock_only']);
        t_true($t !== '', 'upload af F2 giver et preview');
        if ($t === '') return;
        $pv = preview_json($root, $t);
        t_eq('stock_only', $pv['import_scope'] ?? null, 'preview.import_scope = stock_only');
        t_eq('stock_only', setting_raw($pdo, 'supplier_import_scope'), 'supplier_import_scope er gemt');
        t_eq('stock_only', last_audit($pdo, 'supplier_import.preview')['import_scope'] ?? null, 'audit: supplier_import.preview har import_scope');
        t_eq(['stock_loc_'.$L1 => ['old' => 1, 'new' => 3, 'file' => 3]], $pv['items'][item_index($pv, 'TSTKIM-8104')]['changes'] ?? null, '8104: kun lagerændringen');
        $ny = $pv['items'][item_index($pv, 'TSTKIM-8199')] ?? [];
        t_eq([true, false, false], [$ny['skipped'] ?? null, $ny['is_create_new'] ?? null, $ny['selected'] ?? null], '8199: skipped, ikke nyt produkt, ikke valgt');
        $body = $http->get('supplier_upload.php?preview='.$t)['body'];
        $text = page_text($body);
        t_true(str_contains($text, 'Springes over'), 'siden viser "Springes over"');
        t_true(str_contains($text, 'Kun lagerantal: 1 vare matcher et HSG-produkt · 1 vare springes over (findes ikke i HSG).'), 'opsummeringen "Kun lagerantal: …"');
        t_true(!str_contains($body, 'value="create_new"'), 'ingen mulighed for at oprette nye produkter');
        t_true(str_contains($http->get('supplier_upload.php')['body'], '<option value="stock_only" selected>'), 'upload-kortet forvælger "Kun lagerantal"');
    });

    t_case('153 Kun lagerantal: Anvend', function () use ($uploadFile, $f2, $root, $http, $pdo, $p8104, $L1) {
        $t = $uploadFile('tstkim-f2-anvend.csv', $f2, ['import_scope' => 'stock_only']);
        t_true($t !== '', 'upload af F2 giver et preview');
        if ($t === '') return;
        $pv = preview_json($root, $t);
        $i = item_index($pv, 'TSTKIM-8104'); $n = item_index($pv, 'TSTKIM-8199');
        $page = $http->follow($http->post('supplier_upload.php', ['action' => 'apply', 'preview_token' => $t, 'apply_rows' => [$i, $n], 'product_'.$i => (string)$p8104, 'product_'.$n => 'create_new']));
        $p = q($pdo, 'SELECT retail_price,wholesale_price,distillery,cask_number FROM lager_products WHERE id=?', [$p8104])[0] ?? [];
        t_eq(100.0, (float)($p['retail_price'] ?? 0), '8104: udsalgspris er stadig 100.00');
        t_eq(['wholesale_price' => null, 'distillery' => null, 'cask_number' => null], array_diff_key($p, ['retail_price' => true]), '8104: engrospris, destilleri og fadnummer er stadig NULL');
        t_eq(3, db_stock($pdo, $p8104)[$L1] ?? null, '8104: L1 = 3');
        t_eq(0, (int)q1($pdo, "SELECT COUNT(*) FROM lager_products WHERE sku='TSTKIM-8199'"), 'der er ikke oprettet et produkt TSTKIM-8199');
        $fl = flashes($page['body']);
        t_true(($fl[0][0] ?? '') === 'success' && str_contains($fl[0][1] ?? '', '1 række uden match er sprunget over.'), 'success-flash nævner den oversprungne række', json_encode($fl, JSON_UNESCAPED_UNICODE));
        $audit = last_audit($pdo, 'supplier_import.apply');
        t_eq(['stock_only', 1, 0], [$audit['import_scope'] ?? null, $audit['rows_skipped'] ?? null, $audit['products_created'] ?? null], 'audit: import_scope, rows_skipped og products_created');
    });

    t_case('154 Skift i previewet', function () use ($uploadFile, $f2, $root, $http, $pdo) {
        $t = $uploadFile('tstkim-f2-skift.csv', $f2, ['import_scope' => 'stock_only']);
        t_true($t !== '', 'upload af F2 giver et preview');
        if ($t === '') return;
        $page = $http->follow($http->post('supplier_upload.php', ['action' => 'import_scope', 'preview_token' => $t, 'import_scope' => 'full']));
        t_true(has_flash($page['body'], 'success', 'Valget er gemt, og preview er genberegnet.'), 'success-flash', json_encode(flashes($page['body']), JSON_UNESCAPED_UNICODE));
        $pv = preview_json($root, $t);
        t_eq('full', $pv['import_scope'] ?? null, 'preview.import_scope = full');
        t_eq(true, $pv['items'][item_index($pv, 'TSTKIM-8199')]['is_create_new'] ?? null, '8199 tilbydes som nyt produkt');
        t_true(isset($pv['items'][item_index($pv, 'TSTKIM-8104')]['changes']['distillery']), '8104: changes indeholder distillery');
        t_eq('full', setting_raw($pdo, 'supplier_import_scope'), 'supplier_import_scope = full');
        t_eq(['import_scope' => 'full'], last_audit($pdo, 'supplier_import.settings'), 'audit: supplier_import.settings er præcis {"import_scope":"full"}');
        $bad = $http->follow($http->post('supplier_upload.php', ['action' => 'import_scope', 'preview_token' => $t, 'import_scope' => "x' OR 1=1"]));
        t_true(has_flash($bad['body'], 'error', 'Kunne ikke gemme valget: Vælg, om filen skal opdatere produktdata og lager eller kun lagerantal.'), 'ugyldigt valg giver fejl-flash', json_encode(flashes($bad['body']), JSON_UNESCAPED_UNICODE));
        t_eq('full', setting_raw($pdo, 'supplier_import_scope'), 'indstillingen er uændret efter ugyldigt valg');
        // Den gemte værdi sættes til noget andet, så testen viser, at previewets valg bevares (ikke det gemte).
        setting_put($pdo, 'supplier_import_scope', 'stock_only');
        $http->follow($http->post('supplier_upload.php', ['action' => 'remap', 'preview_token' => $t, 'col_map' => (array)$pv['col_mapping']]));
        t_eq('full', preview_json($root, $t)['import_scope'] ?? null, 'remap bevarer previewets valg');
        $http->follow($http->post('supplier_upload.php', ['action' => 'reguess', 'preview_token' => $t]));
        t_eq('full', preview_json($root, $t)['import_scope'] ?? null, 'reguess bevarer previewets valg');
        $http->follow($http->post('supplier_upload.php', ['action' => 'reservation_mode', 'preview_token' => $t, 'reservation_mode' => 'add']));
        t_eq('full', preview_json($root, $t)['import_scope'] ?? null, 'reservation_mode bevarer previewets valg');
        setting_put($pdo, 'supplier_import_scope', 'full');
    });

    t_case('156 Ældre preview uden de nye nøgler arkiverer intet', function () use (&$f3Preview, &$tokens, $root, $http, $pdo, $resStatus, $archiveEvents, $p8105, $rF) {
        if (!$f3Preview) { t_true(false, 'previewet fra 149 mangler'); return; }
        $old = $f3Preview;
        unset($old['import_scope']);
        foreach ($old['items'] as &$it) unset($it['skipped'], $it['archive']);
        unset($it);
        $oldToken = bin2hex(random_bytes(20)); $tokens[] = $oldToken;
        file_put_contents(preview_path($root, $oldToken), json_encode($old, JSON_UNESCAPED_UNICODE));
        $i = item_index($old, 'TSTKIM-8105');
        $events = $archiveEvents();
        $http->follow($http->post('supplier_upload.php', ['action' => 'apply', 'preview_token' => $oldToken, 'apply_rows' => [$i], 'product_'.$i => (string)$p8105, 'archive_res' => [$rF]]));
        t_eq('reserved', $resStatus($rF), 'rF er stadig reserveret');
        t_eq('full', last_audit($pdo, 'supplier_import.apply')['import_scope'] ?? null, 'audit: import_scope = full');
        t_eq($events, $archiveEvents(), 'ingen reservation.archive');
    });

    t_case('157/158 Reservationer-siden: fanen Arkiveret og escaping', function () use ($http) {
        $arch = $http->get('reservations.php?status=archived');
        t_eq(200, $arch['code'], '?status=archived svarer 200');
        $body = $arch['body']; $text = page_text($body);
        t_true(str_contains($body, '&lt;b&gt;TSTKIM Hansen&lt;/b&gt;') && str_contains($text, 'TSTKIM Jensen'), 'rækkerne for rA og rB vises');
        t_true(preg_match_all('#<span class="badge\s*">Arkiveret</span>#', $body) >= 2, 'badget "Arkiveret" uden farveklasse');
        t_true(str_contains($text, 'Arkiverede reservationer er afsluttet ved upload af Kims fil, fordi varen ikke længere havde lager i filen. Lageret blev ikke nedskrevet ved arkiveringen. Detaljer står i audit-loggen.'), 'forklaringen vises');
        t_true(str_contains($body, '<a class="button " href="reservations.php?status=archived">Arkiveret</a>'), 'fanen "Arkiveret" er aktiv');
        t_true(!str_contains($body, 'value="complete"') && !str_contains($body, 'value="cancel"'), 'ingen knapper til solgt eller annullér');
        t_true(!str_contains($body, '<b>TSTKIM Hansen</b>'), '158: kundenavnet står ikke uescapet');
        $res = page_text($http->get('reservations.php?status=reserved')['body']);
        t_true(str_contains($res, 'TSTKIM Gert-kunde') && !str_contains($res, 'TSTKIM Jensen'), '?status=reserved viser rC, men ikke rB');
        $all = page_text($http->get('reservations.php')['body']);
        $posC = strpos($all, 'TSTKIM Gert-kunde'); $posB = strpos($all, 'TSTKIM Jensen');
        t_true($posC !== false && $posB !== false && $posC < $posB, 'Alle: både rC og rB, og rC står før rB');
    });
} finally {
    db_cleanup($pdo);
    setting_put($pdo, 'supplier_import_header_map', $keepMap);
    setting_put($pdo, 'supplier_import_reservation_mode', $keepMode);
    setting_put($pdo, 'supplier_import_scope', $keepScope);
    foreach ($tokens as $t) @unlink(preview_path($root, $t));
}

$fails = $GLOBALS['t_fail'];
foreach ($fails as $f) fwrite(STDOUT, "  FEJL: $f\n");
fwrite(STDOUT, sprintf("Kims uploadfil (flow via HTTP): %d tjek bestået, %d fejlede.\n", $GLOBALS['t_pass'], count($fails)));
exit($fails ? 1 : 0);
