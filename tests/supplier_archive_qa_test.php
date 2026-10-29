<?php
declare(strict_types=1);
/*
 * Testerens supplement til "Kun lagerantal" og arkivering af reservationer (spec 2026-10-26-kims-upload-kun-lagerantal).
 * Dækker det, de øvrige tests ikke prøver af som angreb eller grænsetilfælde:
 *  - Q1  reservation annulleret og ny reservation oprettet efter previewet (kriterie 150/151, 2.7 trin 3)
 *  - Q2  ukendte eller afvigende tal giver aldrig arkivering – også med alle id'er lagt i POST'en (2.2, kriterie 137)
 *  - Q3  række uden markering, fravalgt reservation og forfalskede id'er (2.7 trin 2, kriterie 150)
 *  - Q4  vagten: ENUM uden 'archived' ruller hele "Anvend" tilbage, også en række før (2.4, kriterie 146)
 *  - Q5  linkbrugere: ingen knapper på arkiverede reservationer, ingen adgang til supplier_upload.php (kriterie 160)
 *  - Q6  samme "Anvend" to gange med samme token gør intet den anden gang
 *  - Q7  tilstand T: kun reservationer på første lokation arkiveres (2.3, kriterie 138)
 *  - Q8/Q9 review-fund B1: "– Intet match –" (product_<i> sendt tomt) springer rækken over i begge tilstande –
 *        intet lager, ingen produktdata og ingen arkivering. Tom værdi + "Opret nyt" virker stadig i full (2.7 trin 1)
 * Opfølgning på review af PR #181 (spec 2026-10-26-kims-upload-opfoelgning-review):
 *  - Q8/Q9 nye trin: product_<i>='0' springer også rækken over, og et manglende felt bruger stadig matchet (178, 179)
 *  - Q10 negativ lagercelle: arkiveringen tilbydes, men er ikke forvalgt (173), og oversigtslinjen uden forvalg (192; forvalgt i Q8)
 *  - Q11 upload rydder previews op, der er ældre end 24 timer (175)
 *  - Q12 husket "Ignorer" på Varenr./Variant: reddes, og et tomt preview siger hvorfor og kan ikke anvendes (183-185)
 *  - Q13 KAN-2: samme række to gange i apply_rows behandles én gang (187)
 * Testerens supplement til opfølgningen (tilføjet af uafhængig tester, Q14-Q20):
 *  - Q14 "Gæt kolonner forfra" nulstiller col_key_restored (183)   - Q15 ingen lækage af col_key_restored mellem uploads (183)
 *  - Q16 bevidst "Ignorer" på begge nøglekolonner respekteres, og reguess er vejen ud (184-185)
 *  - Q17 handlingerne afvises uden login/CSRF, og oprydningen kan ikke udløses af andre (175)   - Q18 product_<i> som liste og skæve tal (178)
 *  - Q19 negative tal i hele flowet: decimaler, hårde mellemrum, tilstand T (173)   - Q20 KAN-3: en hook, der kaster, vælter ikke importen (188; egen server + tests/hook_throw_prepend.php)
 *  - Q21 tomt preview med kun fadnummer valgt viser T2 og ikke T1 (184)
 *  - Q22 oversigtslinjen "Arkiveres: …" i alle tilstande: forvalgt, blandet, kun vurdering og ingen arkivering (192, tilføjet efter reviewet)
 *  - Q23 hvornår kolonnevalget gemmes (de præciserede knapnavne i docs/SUPPLIER-UPLOAD.md): kun "Genberegn preview med tilpasset kolonnemapping" og "Anvend valgte ændringer", og panelteksten i Kolonnemapping (193)
 *
 * KØRES af tests/run.sh, mens røgtestens server kører:
 *     php tests/supplier_archive_qa_test.php http://127.0.0.1:8787 testadmin 'Test-Pass-12345!'
 * Afviser at køre, hvis HSG_TEST_DB_NAME ikke er sat eller ikke passer til config.php. Alle data har præfikset TSTKIM,
 * og de indstillinger, testen ændrer, sættes tilbage bagefter. Kræver ikke curl eller ZipArchive.
 */

error_reporting(E_ALL);
set_error_handler(static function (int $no, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $no) || in_array($no, [E_DEPRECATED, E_USER_DEPRECATED], true)) return true;
    throw new ErrorException($message, 0, $no, $file, $line);
});

$root = dirname(__DIR__);
if ($argc < 4) { fwrite(STDERR, "Brug: php tests/supplier_archive_qa_test.php <base-url> <admin-brugernavn> <adgangskode>\n"); exit(2); }
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

/** Minimal HTTP-klient med cookies (PHP-streams). Følger ikke redirects automatisk. */
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
    /** POST med en rå body (til skæve felter som archive_res[]=abc&archive_res[][]=1). */
    public function postRaw(string $path, string $raw, ?string $csrf = null): array {
        return $this->request('POST', $path, $raw.'&csrf='.rawurlencode($csrf ?? $this->csrf($path)), 'application/x-www-form-urlencoded');
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
function has_flash(string $html, string $type, string $needle): bool { foreach (flashes($html) as [$t, $x]) if ($t === $type && str_contains($x, $needle)) return true; return false; }
/** Sidens synlige tekst (tags fjernet, entiteter afkodet). */
function page_text(string $html): string { return html_entity_decode(strip_tags(preg_replace('#<(br|/li|/tr|/p|/div|/small)[^>]*>#i', "\n", $html) ?? $html), ENT_QUOTES | ENT_HTML5, 'UTF-8'); }
/** Har siden selve knappen "Anvend valgte ændringer"? Panelteksten i Kolonnemapping nævner knapnavnet, så sidens tekst kan ikke bruges. */
function has_apply_button(string $html): bool { return str_contains($html, '<button>Anvend valgte ændringer</button>'); }
function q(PDO $pdo, string $sql, array $p = []): array { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchAll(); }
function q1(PDO $pdo, string $sql, array $p = []): mixed { $r = q($pdo, $sql, $p); return $r ? array_values($r[0])[0] : null; }
function db_product(PDO $pdo, string $sku, string $name, array $stock = []): int {
    $pdo->prepare("INSERT INTO lager_products(sku,name,distillery,status,bottle_size_cl) VALUES(?,?,'TSTKIM Dist','active',70)")->execute([$sku, $name]);
    $pid = (int)$pdo->lastInsertId();
    foreach ($stock as $lid => $qty) $pdo->prepare('INSERT INTO lager_stock(product_id,location_id,quantity) VALUES(?,?,?)')->execute([$pid, $lid, $qty]);
    return $pid;
}
function db_reserve(PDO $pdo, int $pid, int $lid, int $qty, string $customer): int {
    $pdo->prepare("INSERT INTO lager_reservations(product_id,location_id,quantity,customer_name,reference,status) VALUES(?,?,?,?,'TSTKIM','reserved')")->execute([$pid, $lid, $qty, $customer]);
    return (int)$pdo->lastInsertId();
}
function db_stock(PDO $pdo, int $pid): array { $o = []; foreach (q($pdo, 'SELECT location_id,quantity FROM lager_stock WHERE product_id=?', [$pid]) as $r) $o[(int)$r['location_id']] = (int)$r['quantity']; ksort($o); return $o; }
function db_movements(PDO $pdo, int $pid): array { return q($pdo, 'SELECT location_id,change_qty,balance_after,movement_type,reference FROM lager_stock_movements WHERE product_id=? ORDER BY id', [$pid]); }
function res_status(PDO $pdo, int $id): string { return (string)q1($pdo, 'SELECT status FROM lager_reservations WHERE id=?', [$id]); }
function count_of(PDO $pdo, string $sql): int { return (int)q1($pdo, $sql); }
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
    foreach (q($pdo, "SELECT id FROM lager_users WHERE name LIKE 'TSTKIM Link%'") as $r) {
        $pdo->prepare('UPDATE lager_reservations SET created_by=NULL WHERE created_by=?')->execute([(int)$r['id']]);
        foreach (['hsg_user_module_access'] as $t) $pdo->prepare("DELETE FROM $t WHERE user_id=?")->execute([(int)$r['id']]);
        $pdo->prepare('DELETE FROM lager_users WHERE id=?')->execute([(int)$r['id']]);
    }
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
    foreach ([['TSTKIM Christiansfeld', -3000], ['TSTKIM Michael', -2000], ['TSTKIM Gert', -1000]] as [$n, $so]) { $ins->execute([$n, $so]); $loc[] = (int)$pdo->lastInsertId(); }
    [$L1, $L2, $L3] = $loc;

    $http = new Http($base);
    preg_match('/name="csrf" value="([^"]+)"/', $http->get('admin-login.php')['body'], $m);
    $http->request('POST', 'admin-login.php', http_build_query(['csrf' => $m[1] ?? '', 'username' => $adminUser, 'password' => $adminPass]), 'application/x-www-form-urlencoded');
    if (!str_contains($http->get('supplier_upload.php')['body'], 'Kims uploadfil')) throw new RuntimeException('Admin-login lykkedes ikke');
    setting_put($pdo, 'supplier_import_reservation_mode', 'add');
    setting_put($pdo, 'supplier_import_scope', null);

    $headers = ['Varenr.', 'Variant:', 'Destilleri:', 'Lagerbeholdning', 'Christiansfeld', 'hos Michael', 'hos Gert'];
    $col = array_flip($headers);
    $csvOf = static function (array $rows) use ($headers): string {
        $fh = fopen('php://memory', 'w+b');
        foreach (array_merge([$headers], $rows) as $line) fputcsv($fh, $line, ';', '"', '');
        rewind($fh); $csv = (string)stream_get_contents($fh); fclose($fh);
        return $csv;
    };
    $lMap = array_fill_keys(array_keys($headers), '');
    $lMap[$col['Varenr.']] = 'sku'; $lMap[$col['Variant:']] = 'name'; $lMap[$col['Destilleri:']] = 'distillery'; $lMap[$col['Lagerbeholdning']] = 'stock_quantity';
    $lMap[$col['Christiansfeld']] = 'stock_loc_'.$L1; $lMap[$col['hos Michael']] = 'stock_loc_'.$L2; $lMap[$col['hos Gert']] = 'stock_loc_'.$L3;
    $tMap = $lMap; foreach (['Christiansfeld', 'hos Michael', 'hos Gert'] as $h) $tMap[$col[$h]] = '';   // tilstand T: kun "Lagerantal i alt"
    /** Upload + eksplicit kolonnevalg. Returnerer [token, preview]. */
    $upload = static function (array $rows, string $scope, array $map) use ($http, $csvOf, $root, &$tokens): array {
        $r = $http->upload('supplier_upload.php', ['csrf' => $http->csrf(), 'action' => 'upload', 'import_scope' => $scope], 'file', 'tstkim-qa.csv', $csvOf($rows));
        $t = token_of($r['loc']);
        if ($t === '') throw new RuntimeException('Upload gav intet preview');
        $tokens[] = $t;
        $http->follow($http->post('supplier_upload.php', ['action' => 'remap', 'preview_token' => $t, 'col_map' => $map]));
        return [$t, preview_json($root, $t)];
    };
    $apply = static function (string $token, array $rows, array $products, array $archive) use ($http): array {
        $post = ['action' => 'apply', 'preview_token' => $token, 'apply_rows' => $rows, 'archive_res' => $archive];
        foreach ($products as $i => $pid) $post['product_'.$i] = (string)$pid;
        return $http->follow($http->post('supplier_upload.php', $post));
    };

    // ------------------------------------------------------------------------------------------
    t_case('Q1 Annulleret og nyoprettet efter previewet', function () use ($pdo, $L1, $L2, $L3, $upload, $apply) {
        $p = db_product($pdo, 'TSTKIM-9001', 'TSTKIM Race Whisky', [$L3 => 3]);
        $s1 = db_reserve($pdo, $p, $L3, 1, 'TSTKIM S1'); $s2 = db_reserve($pdo, $p, $L3, 1, 'TSTKIM S2');
        [$t, $pv] = $upload([['TSTKIM-9001', 'TSTKIM Race Whisky', 'TSTKIM Dist', '0', '0', '', '0']], 'full', $GLOBALS['lMap']);
        $i = item_index($pv, 'TSTKIM-9001');
        t_eq([$s1, $s2], array_column($pv['items'][$i]['archive']['reservations'] ?? [], 'id'), 'previewet foreslår s1 og s2');
        $pdo->prepare("UPDATE lager_reservations SET status='cancelled' WHERE id=?")->execute([$s1]);   // annulleret efter previewet
        $s3 = db_reserve($pdo, $p, $L3, 1, 'TSTKIM S3');                                                  // oprettet efter previewet
        $page = $apply($t, [$i], [$i => $p], [$s1, $s2]);
        t_eq('cancelled', res_status($pdo, $s1), 's1 forbliver annulleret (genoplives ikke som arkiveret)');
        t_eq('archived', res_status($pdo, $s2), 's2 er arkiveret');
        t_eq('reserved', res_status($pdo, $s3), 's3 (ny) er stadig aktiv');
        t_eq([$L3 => 1], db_stock($pdo, $p), 'lager = Kims 0 + den nye aktive reservation (add)');
        t_eq('Kims masterfil opdatering (+1 reserveret, 1 arkiveret)', (string)(db_movements($pdo, $p)[0]['reference'] ?? ''), 'referencen');
        t_true(has_flash($page['body'], 'warning', '1 valgt reservation blev ikke arkiveret'), 'advarsel om s1', json_encode(flashes($page['body']), JSON_UNESCAPED_UNICODE));
    });

    // ------------------------------------------------------------------------------------------
    t_case('Q2 Ukendte eller afvigende tal giver aldrig arkivering', function () use ($pdo, $L1, $L2, $L3, $upload, $apply) {
        $rows = [];
        $ids = [];
        $prods = [];
        // [varenr, Q, R, S, T, forventet tekst]
        foreach ([['9101', '', '', '', ''], ['9102', '0', '0', 'abc', '0'], ['9103', '2', '0', '0', '0'], ['9104', '3 + 2', '0', '0', '0'], ['9105', '0', '0', '0', '']] as [$n, $q, $r, $s, $t3]) {
            $p = db_product($pdo, 'TSTKIM-'.$n, 'TSTKIM Ukendt '.$n, [$L3 => 2]);
            $prods[$n] = $p;
            $ids[] = db_reserve($pdo, $p, $L3, 1, 'TSTKIM U'.$n); $ids[] = db_reserve($pdo, $p, $L3, 1, 'TSTKIM V'.$n);
            $rows[] = ['TSTKIM-'.$n, 'TSTKIM Ukendt '.$n, 'TSTKIM Dist', $q, $r, $s, $t3];
        }
        [$t, $pv] = $upload($rows, 'stock_only', $GLOBALS['lMap']);
        // 9105: Gert-cellen er tom, men de andre er 0 – den tomme tæller som 0, og varen er entydigt 0 (godkendt regel). Den forventes arkiveret.
        foreach (['9101', '9102', '9103', '9104'] as $n) t_eq(null, $pv['items'][item_index($pv, 'TSTKIM-'.$n)]['archive'], $n.': ingen arkivering foreslås');
        t_true(is_array($pv['items'][item_index($pv, 'TSTKIM-9105')]['archive']), '9105 (tom celle = 0 når rækken har tal): arkivering foreslås');
        // Alle rækker markeres, og ALLE reservations-id'er lægges i POST'en
        $sel = array_keys($pv['items']);
        $products = []; foreach ($sel as $i) $products[$i] = (int)$pv['items'][$i]['match']['id'];
        $apply($t, $sel, $products, $ids);
        foreach (['9101', '9102', '9103', '9104'] as $n) {
            $rs = array_column(q($pdo, 'SELECT status FROM lager_reservations WHERE product_id=?', [$prods[$n]]), 'status');
            t_eq(['reserved', 'reserved'], $rs, $n.': begge reservationer er stadig aktive');
        }
        t_eq([$L3 => 2], db_stock($pdo, $prods['9101']), '9101: tomme celler ændrer ikke lageret');
        t_eq([$L3 => 2], db_stock($pdo, $prods['9102']), '9102: 2 + ugyldig celle ændrer ikke lageret på Michael, og Gert (0) + 2 aktive = 2');
    });

    // ------------------------------------------------------------------------------------------
    t_case('Q3 Række uden markering, fravalgt reservation og forfalskede id\'er', function () use ($pdo, $L1, $L2, $L3, $http, $upload, $apply) {
        $a = db_product($pdo, 'TSTKIM-9201', 'TSTKIM Ikke Markeret', [$L3 => 2]); $a1 = db_reserve($pdo, $a, $L3, 1, 'TSTKIM A1'); $a2 = db_reserve($pdo, $a, $L3, 1, 'TSTKIM A2');
        $b = db_product($pdo, 'TSTKIM-9202', 'TSTKIM Markeret', [$L3 => 2]); $b1 = db_reserve($pdo, $b, $L3, 1, 'TSTKIM B1'); $b2 = db_reserve($pdo, $b, $L3, 1, 'TSTKIM B2');
        $c = db_product($pdo, 'TSTKIM-9203', 'TSTKIM Anden Vare', [$L3 => 2]); $c1 = db_reserve($pdo, $c, $L3, 1, 'TSTKIM C1');
        [$t, $pv] = $upload([['TSTKIM-9201', 'TSTKIM Ikke Markeret', 'TSTKIM Dist', '0', '0', '', '0'], ['TSTKIM-9202', 'TSTKIM Markeret', 'TSTKIM Dist', '0', '0', '', '0']], 'stock_only', $GLOBALS['lMap']);
        $ia = item_index($pv, 'TSTKIM-9201'); $ib = item_index($pv, 'TSTKIM-9202');
        $before = (int)q1($GLOBALS['pdo'], "SELECT COUNT(*) FROM hsg_audit_log WHERE action='reservation.archive'");
        // Kun B er markeret. B2 er fravalgt. I POST'en ligger også A's og C's id'er, tekst, negative tal, arrays og en kæmpestor værdi.
        $raw = 'action=apply&preview_token='.$t.'&apply_rows[]='.$ib.'&product_'.$ib.'='.$b.'&archive_res[]='.$b1.'&archive_res[]='.$a1.'&archive_res[]='.$a2.'&archive_res[]='.$c1
            .'&archive_res[]=abc&archive_res[]=-5&archive_res[]=99999999999999999999&archive_res[][]=1&archive_res[x]=7&archive_res[]=%27%20OR%201%3D1';
        $page = $http->follow($http->postRaw('supplier_upload.php', $raw));
        t_eq('archived', res_status($pdo, $b1), 'B1 er arkiveret');
        t_eq('reserved', res_status($pdo, $b2), 'B2 (fravalgt) er stadig aktiv');
        foreach ([$a1, $a2, $c1] as $id) t_eq('reserved', res_status($pdo, $id), 'reservation '.$id.' (ikke markeret række eller andet produkt) er uændret');
        t_eq([$L3 => 1], db_stock($pdo, $b), 'B: Kims 0 + 1 fravalgt aktiv reservation = 1');
        t_eq([$L3 => 2], db_stock($pdo, $a), 'A: lageret er uændret');
        t_eq($before + 1, (int)q1($GLOBALS['pdo'], "SELECT COUNT(*) FROM hsg_audit_log WHERE action='reservation.archive'"), 'præcis én reservation.archive');
        t_true(!array_filter(flashes($page['body']), static fn($f) => $f[0] === 'error'), 'ingen fejl-flash for skæve id\'er', json_encode(flashes($page['body']), JSON_UNESCAPED_UNICODE));
        // Samme POST igen: previewet er brugt
        $again = $http->follow($http->postRaw('supplier_upload.php', $raw));
        t_true(has_flash($again['body'], 'error', 'Kunne ikke anvende ændringer'), 'Q6: samme token kan ikke anvendes to gange', json_encode(flashes($again['body']), JSON_UNESCAPED_UNICODE));
        t_eq(1, count(db_movements($pdo, $b)), 'Q6: stadig kun én lagerbevægelse for B');
    });

    // ------------------------------------------------------------------------------------------
    t_case('Q4 Vagten ruller hele Anvend tilbage (også en række før)', function () use ($pdo, $L1, $L2, $L3, $upload, $apply) {
        $first = db_product($pdo, 'TSTKIM-9301', 'TSTKIM Før Vagt', [$L1 => 5]);
        $a = db_product($pdo, 'TSTKIM-9302', 'TSTKIM Vagt', [$L3 => 2]); $a1 = db_reserve($pdo, $a, $L3, 1, 'TSTKIM W1'); $a2 = db_reserve($pdo, $a, $L3, 1, 'TSTKIM W2');
        [$t, $pv] = $upload([['TSTKIM-9301', 'TSTKIM Før Vagt', 'TSTKIM Dist', '2', '2', '', ''], ['TSTKIM-9302', 'TSTKIM Vagt', 'TSTKIM Dist', '0', '0', '', '0']], 'stock_only', $GLOBALS['lMap']);
        $i1 = item_index($pv, 'TSTKIM-9301'); $i2 = item_index($pv, 'TSTKIM-9302');
        $newType = "enum('reserved','completed','cancelled','archived')";
        $oldType = "ENUM('reserved','completed','cancelled') NOT NULL DEFAULT 'reserved'";
        $typeNow = static fn(): string => strtolower((string)q1($GLOBALS['pdo'], "SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='lager_reservations' AND COLUMN_NAME='status'"));
        if ($typeNow() !== $newType) { t_true(false, 'forudsætning: ENUM har archived', $typeNow()); return; }
        // Midlertidigt: de arkiverede rækker (fra Q1-Q3) sættes til cancelled, så ALTER ikke afkorter dem, og sættes tilbage i finally.
        $archived = array_map('intval', array_column(q($pdo, "SELECT id FROM lager_reservations WHERE status='archived'"), 'id'));
        $pdo->exec("UPDATE lager_reservations SET status='cancelled' WHERE status='archived'");
        $pdo->exec('ALTER TABLE lager_reservations MODIFY status '.$oldType);
        try {
            $runs = count_of($pdo, 'SELECT COUNT(*) FROM hsg_supplier_import_runs'); $moves = count_of($pdo, 'SELECT COUNT(*) FROM lager_stock_movements');
            $page = $apply($t, [$i1, $i2], [$i1 => $first, $i2 => $a], [$a1, $a2]);
            t_true(has_flash($page['body'], 'error', 'Kunne ikke anvende ændringer: Databasen kan ikke gemme status „Arkiveret“ endnu. Genindlæs siden, så opdateringen kan køre, og prøv igen.'), 'fejl-flash', json_encode(flashes($page['body']), JSON_UNESCAPED_UNICODE));
            t_eq([$L1 => 5], db_stock($pdo, $first), 'rækken før vagten er rullet tilbage (5)');
            t_eq([$L3 => 2], db_stock($pdo, $a), 'lageret er uændret');
            t_eq(['reserved', 'reserved'], array_column(q($pdo, 'SELECT status FROM lager_reservations WHERE product_id=? ORDER BY id', [$a]), 'status'), 'ingen status er gemt som tom streng eller ændret');
            t_eq($runs, count_of($pdo, 'SELECT COUNT(*) FROM hsg_supplier_import_runs'), 'ingen import-kørsel');
            t_eq($moves, count_of($pdo, 'SELECT COUNT(*) FROM lager_stock_movements'), 'ingen lagerbevægelser');
            t_true(is_file(preview_path($GLOBALS['root'], $t)), 'previewet er bevaret, så "Anvend" kan prøves igen');
            t_eq("enum('reserved','completed','cancelled')", $typeNow(), 'kolonnen er stadig den gamle (ingen stille ændring)');
        } finally {
            $pdo->exec("ALTER TABLE lager_reservations MODIFY status ENUM('reserved','completed','cancelled','archived') NOT NULL DEFAULT 'reserved'");
            if ($archived) $pdo->exec("UPDATE lager_reservations SET status='archived' WHERE id IN (".implode(',', $archived).')');
        }
        t_eq($newType, $typeNow(), 'kolonnen er genskabt');
        $apply($t, [$i1, $i2], [$i1 => $first, $i2 => $a], [$a1, $a2]);
        t_eq(['archived', 'archived'], array_column(q($pdo, 'SELECT status FROM lager_reservations WHERE product_id=? ORDER BY id', [$a]), 'status'), 'efter genskabt ENUM virker samme preview');
        t_eq([$L1 => 2], db_stock($pdo, $first), 'første række skrevet (2)');
    });

    // ------------------------------------------------------------------------------------------
    t_case('Q5 Linkbrugere: ingen knapper på arkiverede, ingen adgang til supplier_upload.php', function () use ($pdo, $L1, $L2, $L3, $base) {
        $mk = static function (string $name, bool $operate) use ($pdo): array {
            $tok = bin2hex(random_bytes(20));
            $pdo->prepare("INSERT INTO lager_users(name,role,token_hash,token_last4,active) VALUES(?,'user',?,?,1)")->execute([$name, hash('sha256', $tok), substr($tok, -4)]);
            $uid = (int)$pdo->lastInsertId();
            foreach (['reservations' => [1, $operate ? 1 : 0], 'inventory' => [1, 0], 'dashboard' => [1, 0]] as $mod => [$v, $o]) $pdo->prepare('INSERT INTO hsg_user_module_access(user_id,module_id,can_view,can_operate) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE can_view=VALUES(can_view),can_operate=VALUES(can_operate)')->execute([$uid, $mod, $v, $o]);
            return [$uid, $tok];
        };
        [$uView, $tView] = $mk('TSTKIM Link Visning', false); [$uOp, $tOp] = $mk('TSTKIM Link Operatør', true);
        $p = db_product($pdo, 'TSTKIM-9401', 'TSTKIM Link Whisky', [$L3 => 9]);
        $own = db_reserve($pdo, $p, $L3, 1, 'TSTKIM Egen arkiveret'); $other = db_reserve($pdo, $p, $L3, 1, 'TSTKIM Andens arkiveret'); $act = db_reserve($pdo, $p, $L3, 1, 'TSTKIM Egen aktiv');
        $pdo->prepare("UPDATE lager_reservations SET status='archived', created_by=? WHERE id=?")->execute([$uView, $own]);
        $pdo->prepare("UPDATE lager_reservations SET status='archived', created_by=? WHERE id=?")->execute([$uOp, $other]);
        $pdo->prepare('UPDATE lager_reservations SET created_by=? WHERE id=?')->execute([$uView, $act]);

        $view = new Http($base); $view->follow($view->get('reservations.php?k='.$tView));
        $arch = $view->get('reservations.php?status=archived');
        t_eq(200, $arch['code'], 'visningsbruger: ?status=archived svarer 200');
        t_true(str_contains($arch['body'], 'TSTKIM Egen arkiveret') && str_contains($arch['body'], 'Arkiveret'), 'ser egen arkiverede reservation med badget');
        t_true(!str_contains($arch['body'], 'TSTKIM Andens arkiveret'), 'ser ikke andres reservationer');
        t_true(!str_contains($arch['body'], 'value="complete"') && !str_contains($arch['body'], 'value="cancel"') && !str_contains($arch['body'], 'edit_res=') && !str_contains($arch['body'], 'partial_res='), 'ingen knapper på arkiverede');
        $su = $view->get('supplier_upload.php');
        t_true(in_array($su['code'], [401, 403, 404], true) && !str_contains($su['body'], 'Upload Kims masterfil'), 'supplier_upload.php afvises for linkbrugeren (HTTP '.$su['code'].')');

        $op = new Http($base); $op->follow($op->get('reservations.php?k='.$tOp));
        $csrf = $op->csrf('reservations.php');
        $codeScope = $op->request('POST', 'supplier_upload.php', http_build_query(['action' => 'import_scope', 'preview_token' => 'x', 'import_scope' => 'stock_only', 'csrf' => $csrf]), 'application/x-www-form-urlencoded')['code'];
        t_true(in_array($codeScope, [401, 403, 404], true), 'POST import_scope med gyldig CSRF afvises for linkbrugeren (HTTP '.$codeScope.')');
        $codeApply = $op->request('POST', 'supplier_upload.php', http_build_query(['action' => 'apply', 'preview_token' => 'x', 'apply_rows' => [0], 'archive_res' => [$act], 'csrf' => $csrf]), 'application/x-www-form-urlencoded')['code'];
        t_true(in_array($codeApply, [401, 403, 404], true), 'POST apply afvises for linkbrugeren (HTTP '.$codeApply.')');
        t_eq('reserved', res_status($pdo, $act), 'den aktive reservation er uændret');
        // Operatøren (reservations.manage_all) kan hverken afslutte, annullere eller genåbne en arkiveret reservation.
        foreach (['complete', 'cancel'] as $act2) { $op->follow($op->post('reservations.php', ['action' => $act2, 'id' => (string)$other], $csrf)); t_eq('archived', res_status($pdo, $other), $act2.' på en arkiveret reservation ændrer intet'); }
    });

    // ------------------------------------------------------------------------------------------
    t_case('Q7 Tilstand T: kun første lokation arkiveres', function () use ($pdo, $L1, $L2, $L3, $upload, $apply) {
        $p = db_product($pdo, 'TSTKIM-9501', 'TSTKIM Total Whisky', [$L1 => 1, $L3 => 1]);
        $r1 = db_reserve($pdo, $p, $L1, 1, 'TSTKIM T1'); $r3 = db_reserve($pdo, $p, $L3, 1, 'TSTKIM T3');
        [$t, $pv] = $upload([['TSTKIM-9501', 'TSTKIM Total Whisky', 'TSTKIM Dist', '0', '', '', '']], 'stock_only', $GLOBALS['tMap']);
        $i = item_index($pv, 'TSTKIM-9501');
        t_eq([$r1], array_column($pv['items'][$i]['archive']['reservations'] ?? [], 'id'), 'kun reservationen på første lokation er kandidat');
        $apply($t, [$i], [$i => $p], [$r1, $r3]);
        t_eq('archived', res_status($pdo, $r1), 'T1 arkiveret'); t_eq('reserved', res_status($pdo, $r3), 'T3 (Gert) uændret');
        t_eq([$L1 => 0, $L3 => 1], db_stock($pdo, $p), 'kun første lokation skrives');
    });

    // ------------------------------------------------------------------------------------------
    // Review-fund B1: "– Intet match (springes over) –" har værdien '' og må aldrig falde tilbage til previewets match.
    $selectOf = static function (string $body, int $i): string {
        return preg_match('#<select name="product_'.$i.'"[^>]*>((?:(?!</select>).)*)</select>#s', $body, $m) ? $m[1] : '';
    };
    $applyAudit = static function (int $afterId) use ($pdo): array {
        return json_decode((string)q1($pdo, "SELECT details_json FROM hsg_audit_log WHERE action='supplier_import.apply' AND id>? ORDER BY id DESC LIMIT 1", [$afterId]), true) ?: [];
    };

    t_case('Q8 B1: "– Intet match –" springer rækken over i Kun lagerantal', function () use ($pdo, $L3, $http, $upload, $apply, $selectOf, $applyAudit) {
        $p = db_product($pdo, 'TSTKIM-9601', 'TSTKIM Intet Match Lager', [$L3 => 2]);
        $r1 = db_reserve($pdo, $p, $L3, 1, 'TSTKIM M1'); $r2 = db_reserve($pdo, $p, $L3, 1, 'TSTKIM M2');
        [$t, $pv] = $upload([['TSTKIM-9601', 'TSTKIM Intet Match Lager', 'TSTKIM Dist', '0', '0', '', '0']], 'stock_only', $GLOBALS['lMap']);
        $i = item_index($pv, 'TSTKIM-9601');
        t_eq($p, (int)($pv['items'][$i]['match']['id'] ?? 0), 'forudsætning: previewet har et match');
        t_eq(true, $pv['items'][$i]['archive']['default'] ?? null, 'forudsætning: arkiveringen er forvalgt');
        // En uændret dropdown sender matchets id (det er valgt), ikke ''.
        $previewBody = $http->get('supplier_upload.php?preview='.$t)['body'];
        $select = $selectOf($previewBody, $i);
        // Kriterie 192 (O4): et forvalgt tilfælde har stadig "Arkiveres: N reservationer …".
        t_true(str_contains(page_text($previewBody), 'Arkiveres: 2 reservationer (2 stk.) på 1 vare, fordi Kims lager i alt er 0. Du kan fravælge dem på de enkelte varer.'), '192: forvalgt arkivering vises som "Arkiveres: 2 reservationer …"');
        t_true(!str_contains(page_text($previewBody), 'Ingen reservationer er valgt til arkivering'), '192: ingen "Ingen reservationer er valgt", når der er forvalgt');
        t_true(str_contains($select, '<option value="'.$p.'" selected>'), 'uændret dropdown har matchet valgt');
        t_true(str_contains($select, '<option value="">– Intet match (springes over) –</option>'), 'dropdown har "– Intet match (springes over) –" med værdien \'\'');
        $events = count_of($pdo, "SELECT COUNT(*) FROM hsg_audit_log WHERE action='reservation.archive'");
        $lastAudit = (int)q1($pdo, 'SELECT COALESCE(MAX(id),0) FROM hsg_audit_log');
        $page = $apply($t, [$i], [$i => ''], [$r1, $r2]);
        t_eq([$L3 => 2], db_stock($pdo, $p), 'lageret er uændret');
        t_eq([], db_movements($pdo, $p), 'ingen lagerbevægelse');
        t_eq(['reserved', 'reserved'], [res_status($pdo, $r1), res_status($pdo, $r2)], 'begge reservationer er stadig reserveret');
        t_eq($events, count_of($pdo, "SELECT COUNT(*) FROM hsg_audit_log WHERE action='reservation.archive'"), 'ingen reservation.archive');
        $audit = $applyAudit($lastAudit);
        t_eq([1, 0, 0], [$audit['rows_skipped'] ?? null, $audit['reservations_archived'] ?? null, $audit['products_updated'] ?? null], 'audit: rows_skipped 1, intet arkiveret, intet opdateret');
        t_true(has_flash($page['body'], 'success', '1 række uden match er sprunget over.'), 'success-flash nævner den oversprungne række', json_encode(flashes($page['body']), JSON_UNESCAPED_UNICODE));

        // Kriterie 178: product_<i>='0' (også ' 0 ') bruges, som den er, og falder ikke tilbage til matchet.
        foreach (['0', ' 0 '] as $zero) {
            [$t0, $pv0] = $upload([['TSTKIM-9601', 'TSTKIM Intet Match Lager', 'TSTKIM Dist', '0', '0', '', '0']], 'stock_only', $GLOBALS['lMap']);
            $i0 = item_index($pv0, 'TSTKIM-9601');
            $events = count_of($pdo, "SELECT COUNT(*) FROM hsg_audit_log WHERE action='reservation.archive'");
            $lastAudit = (int)q1($pdo, 'SELECT COALESCE(MAX(id),0) FROM hsg_audit_log');
            $page = $http->follow($http->post('supplier_upload.php', ['action' => 'apply', 'preview_token' => $t0, 'apply_rows' => [$i0], 'product_'.$i0 => $zero, 'archive_res' => [$r1, $r2]]));
            $label = "product_<i>='$zero'";
            t_eq([$L3 => 2], db_stock($pdo, $p), $label.': lageret er uændret');
            t_eq([], db_movements($pdo, $p), $label.': ingen lagerbevægelse');
            t_eq(['reserved', 'reserved'], [res_status($pdo, $r1), res_status($pdo, $r2)], $label.': begge reservationer er stadig reserveret');
            t_eq($events, count_of($pdo, "SELECT COUNT(*) FROM hsg_audit_log WHERE action='reservation.archive'"), $label.': ingen ny reservation.archive');
            $audit = $applyAudit($lastAudit);
            t_eq([1, 0, 0], [$audit['rows_skipped'] ?? null, $audit['products_updated'] ?? null, $audit['reservations_archived'] ?? null], $label.': audit rows_skipped 1, products_updated 0, reservations_archived 0');
            t_true(has_flash($page['body'], 'success', '1 række uden match er sprunget over.'), $label.': flashen nævner den oversprungne række', json_encode(flashes($page['body']), JSON_UNESCAPED_UNICODE));
        }
        // Kontrol: mangler product_<i> helt, bruges previewets match stadig – og r1 og r2 arkiveres.
        [$tm, $pvm] = $upload([['TSTKIM-9601', 'TSTKIM Intet Match Lager', 'TSTKIM Dist', '0', '0', '', '0']], 'stock_only', $GLOBALS['lMap']);
        $im = item_index($pvm, 'TSTKIM-9601');
        $apply($tm, [$im], [], [$r1, $r2]);
        t_eq(['archived', 'archived'], [res_status($pdo, $r1), res_status($pdo, $r2)], 'kontrol uden product_<i>: matchet bruges, og r1 og r2 er arkiveret');
    });

    t_case('Q9 B1: "– Intet match –" springer rækken over i Produktdata og lager – tom værdi + "Opret nyt" virker stadig', function () use ($pdo, $L1, $L3, $upload, $apply, $applyAudit) {
        $p = db_product($pdo, 'TSTKIM-9602', 'TSTKIM Intet Match Fuld', [$L3 => 2]);
        $pdo->prepare('UPDATE lager_products SET distillery=NULL WHERE id=?')->execute([$p]);
        $r1 = db_reserve($pdo, $p, $L3, 1, 'TSTKIM F1'); $r2 = db_reserve($pdo, $p, $L3, 1, 'TSTKIM F2');
        [$t, $pv] = $upload([['TSTKIM-9602', 'TSTKIM Intet Match Fuld', 'TSTKIM Ny Dist', '0', '0', '', '0'], ['TSTKIM-9699', 'TSTKIM Helt Ny Opret B1', 'TSTKIM Dist', '3', '3', '', '']], 'full', $GLOBALS['lMap']);
        $i = item_index($pv, 'TSTKIM-9602'); $n = item_index($pv, 'TSTKIM-9699');
        t_eq($p, (int)($pv['items'][$i]['match']['id'] ?? 0), 'forudsætning: previewet har et match');
        t_eq(true, $pv['items'][$i]['archive']['default'] ?? null, 'forudsætning: arkiveringen er forvalgt');
        t_true(isset($pv['items'][$i]['changes']['distillery']), 'forudsætning: filen ville skrive destilleriet');
        t_eq(true, $pv['items'][$n]['is_create_new'] ?? null, 'forudsætning: TSTKIM-9699 tilbydes som nyt produkt');
        $events = count_of($pdo, "SELECT COUNT(*) FROM hsg_audit_log WHERE action='reservation.archive'");
        $lastAudit = (int)q1($pdo, 'SELECT COALESCE(MAX(id),0) FROM hsg_audit_log');
        $apply($t, [$i, $n], [$i => '', $n => ''], [$r1, $r2]);
        t_eq(null, q1($pdo, 'SELECT distillery FROM lager_products WHERE id=?', [$p]), 'produktdata er uændret (destilleri stadig NULL)');
        t_eq([$L3 => 2], db_stock($pdo, $p), 'lageret er uændret');
        t_eq([], db_movements($pdo, $p), 'ingen lagerbevægelse');
        t_eq(['reserved', 'reserved'], [res_status($pdo, $r1), res_status($pdo, $r2)], 'begge reservationer er stadig reserveret');
        t_eq($events, count_of($pdo, "SELECT COUNT(*) FROM hsg_audit_log WHERE action='reservation.archive'"), 'ingen reservation.archive');
        $newId = (int)q1($pdo, "SELECT id FROM lager_products WHERE sku='TSTKIM-9699'");
        t_true($newId > 0, 'tom værdi + intet match + "Opret nyt" opretter stadig produktet');
        t_eq([$L1 => 3], $newId > 0 ? db_stock($pdo, $newId) : [], 'det nye produkt har lager 3 på første lokation');
        $audit = $applyAudit($lastAudit);
        t_eq([0, 1, 0, 0], [$audit['products_updated'] ?? null, $audit['products_created'] ?? null, $audit['rows_skipped'] ?? null, $audit['reservations_archived'] ?? null], 'audit: intet opdateret, ét oprettet, rows_skipped 0, intet arkiveret');

        // Kriterie 179: product_<i>='0' i Produktdata og lager skriver hverken produktdata, lager eller arkivering og opretter intet.
        [$t0, $pv0] = $upload([['TSTKIM-9602', 'TSTKIM Intet Match Fuld', 'TSTKIM Ny Dist', '0', '0', '', '0']], 'full', $GLOBALS['lMap']);
        $i0 = item_index($pv0, 'TSTKIM-9602');
        t_true(isset($pv0['items'][$i0]['changes']['distillery']), '179 forudsætning: filen ville skrive destilleriet');
        $products = count_of($pdo, "SELECT COUNT(*) FROM lager_products WHERE sku LIKE 'TSTKIM-%'");
        $lastAudit = (int)q1($pdo, 'SELECT COALESCE(MAX(id),0) FROM hsg_audit_log');
        $apply($t0, [$i0], [$i0 => '0'], [$r1, $r2]);
        t_eq(null, q1($pdo, 'SELECT distillery FROM lager_products WHERE id=?', [$p]), '179: destilleriet er stadig NULL');
        t_eq([$L3 => 2], db_stock($pdo, $p), '179: lageret er uændret');
        t_eq([], db_movements($pdo, $p), '179: ingen lagerbevægelse');
        t_eq(['reserved', 'reserved'], [res_status($pdo, $r1), res_status($pdo, $r2)], '179: reservationerne er uændrede');
        t_eq($products, count_of($pdo, "SELECT COUNT(*) FROM lager_products WHERE sku LIKE 'TSTKIM-%'"), '179: der er ikke oprettet et nyt produkt');
        $audit = $applyAudit($lastAudit);
        t_eq([0, 0], [$audit['products_updated'] ?? null, $audit['products_created'] ?? null], '179: audit products_updated 0 og products_created 0');
    });

    // ------------------------------------------------------------------------------------------
    // Opfølgning på review af PR #181 (spec 2026-10-26-kims-upload-opfoelgning-review).
    t_case('Q10 Negativ lagercelle: arkiveringen tilbydes, men er ikke forvalgt (kriterie 173)', function () use ($pdo, $L3, $http, $upload, $apply) {
        $p = db_product($pdo, 'TSTKIM-9701', 'TSTKIM Negativ Whisky', [$L3 => 2]);
        $r1 = db_reserve($pdo, $p, $L3, 1, 'TSTKIM N1'); $r2 = db_reserve($pdo, $p, $L3, 1, 'TSTKIM N2');
        $row = ['TSTKIM-9701', 'TSTKIM Negativ Whisky', 'TSTKIM Dist', '0', '0', '0', '-1'];
        [$t, $pv] = $upload([$row], 'stock_only', $GLOBALS['lMap']);
        $i = item_index($pv, 'TSTKIM-9701');
        t_eq([false, [$r1, $r2]], [$pv['items'][$i]['archive']['default'] ?? null, array_column($pv['items'][$i]['archive']['reservations'] ?? [], 'id')], 'previewet tilbyder r1 og r2 uden forvalg');
        $page = $http->get('supplier_upload.php?preview='.$t);
        $text = page_text($page['body']);
        t_true(str_contains($text, 'Negativ værdi „-1“ for TSTKIM Gert behandles som 0.'), 'previewet viser N1 for Gert');
        t_true(str_contains($text, 'Bemærk: Arkiveringen er ikke valgt på forhånd, fordi Kims fil har en negativ værdi for varen.'), 'previewet viser N3a');
        // Kriterie 192 (O4): ingen "Arkiveres: 0 reservationer …", når intet er forvalgt.
        t_true(!str_contains($text, 'Arkiveres: 0 reservationer'), '192: oversigten har ikke "Arkiveres: 0 reservationer"');
        t_true(str_contains($text, 'Ingen reservationer er valgt til arkivering. 2 reservationer (2 stk.) på varer, der kræver din vurdering, er ikke valgt. Du kan vælge dem på de enkelte varer.'), '192: oversigten siger, at ingen er valgt, og hvor mange der kræver vurdering');
        foreach ([$r1, $r2] as $r) {
            $found = preg_match('#<input type="checkbox" name="archive_res\[\]" value="'.$r.'"([^>]*)>#', $page['body'], $m) === 1;
            t_true($found && !str_contains($m[1], 'checked'), 'arkiveringsfeltet for #'.$r.' findes og er uden checked');
        }
        // Anvend med rækken markeret og uden archive_res[]: intet arkiveres, og "læg oven i" giver 0 + 2 = 2.
        $apply($t, [$i], [$i => $p], []);
        t_eq(['reserved', 'reserved'], [res_status($pdo, $r1), res_status($pdo, $r2)], 'uden afkrydsning: r1 og r2 er stadig reserveret');
        t_eq([$L3 => 2], db_stock($pdo, $p), 'uden afkrydsning: lageret er {L3: 2}');
        // Ny upload, og admin afkrydser selv r1.
        [$t2, $pv2] = $upload([$row], 'stock_only', $GLOBALS['lMap']);
        $i2 = item_index($pv2, 'TSTKIM-9701');
        $apply($t2, [$i2], [$i2 => $p], [$r1]);
        t_eq(['archived', 'reserved'], [res_status($pdo, $r1), res_status($pdo, $r2)], 'med archive_res=[r1]: r1 arkiveret, r2 reserveret');
        t_eq([$L3 => 1], db_stock($pdo, $p), 'med archive_res=[r1]: lageret er {L3: 1}');
    });

    // ------------------------------------------------------------------------------------------
    t_case('Q11 Upload rydder previews op, der er ældre end 24 timer (kriterie 175)', function () use ($http, $csvOf, $root, &$tokens) {
        $dir = $root.'/storage/import-previews';
        if (!is_dir($dir)) mkdir($dir, 0770, true);
        $old = $dir.'/'.bin2hex(random_bytes(20)).'.json'; $young = $dir.'/'.bin2hex(random_bytes(20)).'.json';
        file_put_contents($old, '{}'); touch($old, time() - 25 * 3600);
        file_put_contents($young, '{}'); touch($young, time() - 3600);
        try {
            $r = $http->upload('supplier_upload.php', ['csrf' => $http->csrf(), 'action' => 'upload', 'import_scope' => 'full'], 'file', 'tstkim-qa.csv', $csvOf([['TSTKIM-9951', 'TSTKIM Oprydning Whisky', 'TSTKIM Dist', '1', '1', '', '']]));
            $t = token_of($r['loc']);
            if ($t !== '') $tokens[] = $t;
            t_true(!file_exists($old), 'den gamle fil (25 timer) er slettet');
            t_true(is_file($young), 'den nye fil (1 time) findes stadig');
            t_true($t !== '' && is_file(preview_path($root, $t)), 'uploaden giver et preview som normalt', (string)$r['loc']);
        } finally {
            @unlink($old); @unlink($young);
        }
    });

    // ------------------------------------------------------------------------------------------
    $T1 = 'Bemærk: Varenummer og produktnavn er ikke valgt, så ingen rækker kan matches. Tryk "Gæt kolonner forfra", eller vælg kolonnerne under Kolonnemapping.';
    $T2 = 'Bemærk: Filen gav ingen rækker. Ingen rækker har et varenummer, et produktnavn eller et fadnummer i de valgte kolonner. Tjek kolonnerne under Kolonnemapping, eller tryk "Gæt kolonner forfra".';
    $T3 = '⚠ Husket valg "Ignorer kolonne" er ikke brugt: uden varenummer og produktnavn kan ingen rækker matches.';
    t_case('Q12 Husket "Ignorer" på Varenr./Variant: og tomt preview (kriterie 183-185)', function () use ($pdo, $L1, $L2, $L3, $http, $csvOf, $root, $upload, $col, &$tokens, $T1, $T2, $T3) {
        $p = db_product($pdo, 'TSTKIM-9801', 'TSTKIM Husket Ignorer', [$L3 => 1]);
        // Hændelsen: det huskede valg har Varenr., Variant: og Lagerbeholdning som "Ignorer kolonne" (nøglerne er de normaliserede overskrifter).
        setting_put($pdo, 'supplier_import_header_map', json_encode(['varenr' => '', 'variant' => '', 'lagerbeholdning' => '', 'destilleri' => 'distillery',
            'christiansfeld' => 'stock_loc_'.$L1, 'hosmichael' => 'stock_loc_'.$L2, 'hosgert' => 'stock_loc_'.$L3], JSON_FORCE_OBJECT));
        $iV = $col['Varenr.']; $iN = $col['Variant:'];

        // 183: upload direkte (uden remap) i "Produktdata og lager".
        $r = $http->upload('supplier_upload.php', ['csrf' => $http->csrf(), 'action' => 'upload', 'import_scope' => 'full'], 'file', 'tstkim-qa.csv', $csvOf([['TSTKIM-9801', 'TSTKIM Husket Ignorer', 'TSTKIM Dist', '1', '0', '0', '1']]));
        $t = token_of($r['loc']);
        if ($t === '') { t_true(false, '183: upload gav intet preview', (string)$r['loc']); return; }
        $tokens[] = $t;
        $pv = preview_json($root, $t);
        t_eq(['sku', 'name', '', 'distillery'], [$pv['col_mapping'][$iV] ?? null, $pv['col_mapping'][$iN] ?? null, $pv['col_mapping'][$col['Lagerbeholdning']] ?? null, $pv['col_mapping'][$col['Destilleri:']] ?? null], '183: sku på Varenr., name på Variant:, Lagerbeholdning ignoreret, destilleri husket');
        t_eq([$iV => 'sku', $iN => 'name'], $pv['col_key_restored'] ?? null, '183: col_key_restored har de to kolonner');
        t_eq([1, $p], [count($pv['items']), (int)($pv['items'][0]['match']['id'] ?? 0)], '183: én vare med match på TSTKIM-9801');
        $body = $http->get('supplier_upload.php?preview='.$t)['body'];
        $text = page_text($body);
        t_true(str_contains($body, '<details class="card" open>'), '183: kolonnemapping-panelet er åbent');
        t_eq(2, substr_count($text, $T3), '183: T3 står ved begge reddede kolonner');
        t_true(has_apply_button($body), '183: "Anvend valgte ændringer" vises');
        t_true(!str_contains($text, $T1) && !str_contains($text, $T2), '183: hverken T1 eller T2');
        foreach (['reservation_mode' => ['reservation_mode' => 'add'], 'import_scope' => ['import_scope' => 'full']] as $action => $extra) {
            $http->follow($http->post('supplier_upload.php', ['action' => $action, 'preview_token' => $t] + $extra));
            t_eq([$iV => 'sku', $iN => 'name'], preview_json($root, $t)['col_key_restored'] ?? null, '183: col_key_restored er uændret efter '.$action);
        }
        $map = (array)preview_json($root, $t)['col_mapping'];
        $http->follow($http->post('supplier_upload.php', ['action' => 'remap', 'preview_token' => $t, 'col_map' => $map]));
        t_eq([], preview_json($root, $t)['col_key_restored'] ?? null, '183: col_key_restored er [] efter remap');
        $saved = json_decode((string)setting_raw($pdo, 'supplier_import_header_map'), true) ?: [];
        t_eq(['sku', 'name'], [$saved['varenr'] ?? null, $saved['variant'] ?? null], '183: det gemte valg har varenr → sku og variant → name');

        // 184 K1: remap med Varenr. og Variant: som "Ignorer kolonne".
        $map[$iV] = ''; $map[$iN] = '';
        $http->follow($http->post('supplier_upload.php', ['action' => 'remap', 'preview_token' => $t, 'col_map' => $map]));
        $pv = preview_json($root, $t);
        t_eq([[], []], [$pv['items'], $pv['col_key_restored'] ?? null], 'K1: ingen rækker og col_key_restored []');
        $emptyPage = static function (string $body, string $label, string $notice, string $notNotice): void {
            $text = page_text($body);
            t_true(str_contains($text, $notice), $label.': forklaringen vises');
            t_true(!str_contains($text, $notNotice), $label.': den anden forklaring vises ikke');
            t_true(str_contains($text, 'Der er ingen rækker at anvende.') && str_contains($text, 'Annuller preview'), $label.': "Der er ingen rækker at anvende." og "Annuller preview"');
            t_true(str_contains($body, '<details class="card" open>'), $label.': panelet åbner automatisk');
            t_true(!has_apply_button($body) && !str_contains($body, 'name="apply_rows[]"'), $label.': hverken tabel eller "Anvend valgte ændringer"');
        };
        $emptyPage($http->get('supplier_upload.php?preview='.$t)['body'], 'K1', $T1, $T2);   // uden &remap=1
        $page = $http->follow($http->post('supplier_upload.php', ['action' => 'import_scope', 'preview_token' => $t, 'import_scope' => 'stock_only']));
        $emptyPage($page['body'], 'K1 efter Kun lagerantal', $T1, $T2);

        // 185: "Anvend" på K1-previewet afvises, før noget gemmes.
        $runs = count_of($pdo, 'SELECT COUNT(*) FROM hsg_supplier_import_runs');
        $applies = count_of($pdo, "SELECT COUNT(*) FROM hsg_audit_log WHERE action='supplier_import.apply'");
        $mapBefore = setting_raw($pdo, 'supplier_import_header_map');
        $page = $http->follow($http->post('supplier_upload.php', ['action' => 'apply', 'preview_token' => $t, 'apply_rows' => [0]]));
        t_true(has_flash($page['body'], 'error', 'Kunne ikke anvende ændringer: Previewet har ingen rækker, der kan anvendes.'), '185: fejl-flash', json_encode(flashes($page['body']), JSON_UNESCAPED_UNICODE));
        t_eq($runs, count_of($pdo, 'SELECT COUNT(*) FROM hsg_supplier_import_runs'), '185: ingen ny række i hsg_supplier_import_runs');
        t_eq($applies, count_of($pdo, "SELECT COUNT(*) FROM hsg_audit_log WHERE action='supplier_import.apply'"), '185: ingen ny supplier_import.apply');
        t_eq($mapBefore, setting_raw($pdo, 'supplier_import_header_map'), '185: supplier_import_header_map er uændret');

        // 184 K2: nøglekolonnerne er valgt, men tomme i alle rækker.
        [$t2, $pv2] = $upload([['', '', 'TSTKIM Dist', '1', '1', '', ''], ['', '', 'TSTKIM Dist', '2', '2', '', '']], 'full', $GLOBALS['lMap']);
        t_eq([], $pv2['items'], 'K2: ingen rækker');
        $emptyPage($http->get('supplier_upload.php?preview='.$t2)['body'], 'K2', $T2, $T1);
    });

    // ------------------------------------------------------------------------------------------
    t_case('Q13 KAN-2: samme række to gange i apply_rows behandles én gang (kriterie 187)', function () use ($pdo, $L3, $http, $upload, $applyAudit) {
        $p = db_product($pdo, 'TSTKIM-9901', 'TSTKIM Dobbelt Række', [$L3 => 2]);
        $r = db_reserve($pdo, $p, $L3, 1, 'TSTKIM D1');
        [$t, $pv] = $upload([['TSTKIM-9901', 'TSTKIM Dobbelt Række', 'TSTKIM Dist', '0', '0', '', '0'], ['TSTKIM-9902', 'TSTKIM Findes Ikke Dobbelt', 'TSTKIM Dist', '3', '3', '', '']], 'stock_only', $GLOBALS['lMap']);
        $i = item_index($pv, 'TSTKIM-9901'); $j = item_index($pv, 'TSTKIM-9902');
        t_true($i >= 0 && $j >= 0 && !empty($pv['items'][$j]['skipped']), 'forudsætning: TSTKIM-9902 springes over (intet match)');
        $events = count_of($pdo, "SELECT COUNT(*) FROM hsg_audit_log WHERE action='reservation.archive'");
        $lastAudit = (int)q1($pdo, 'SELECT COALESCE(MAX(id),0) FROM hsg_audit_log');
        $page = $http->follow($http->post('supplier_upload.php', ['action' => 'apply', 'preview_token' => $t, 'apply_rows' => [$i, $i, $j, $j], 'product_'.$i => (string)$p, 'product_'.$j => '', 'archive_res' => [$r]]));
        t_eq('archived', res_status($pdo, $r), 'reservationen er arkiveret');
        t_eq($events + 1, count_of($pdo, "SELECT COUNT(*) FROM hsg_audit_log WHERE action='reservation.archive'"), 'præcis én ny reservation.archive');
        $audit = $applyAudit($lastAudit);
        t_eq([1, 1], [$audit['reservations_archived'] ?? null, $audit['rows_skipped'] ?? null], 'audit: reservations_archived 1 og rows_skipped 1');
        t_true(!has_flash($page['body'], 'warning', 'blev ikke arkiveret'), 'ingen advarsel om "blev ikke arkiveret"', json_encode(flashes($page['body']), JSON_UNESCAPED_UNICODE));
        t_eq([$L3 => 0], db_stock($pdo, $p), 'lageret er skrevet én gang (0)');
        t_eq(1, count(db_movements($pdo, $p)), 'én lagerbevægelse');
    });

    // ==========================================================================================
    // TESTERENS SUPPLEMENT til opfølgningen på review (spec 2026-10-26-kims-upload-opfoelgning-review, kriterie 167-191).
    // Q14-Q16: redningen af husket "Ignorer" (punkt 4) – nulstilling, ingen lækage mellem uploads, bevidst "Ignorer" og vejen ud.
    // Q17:     de ændrede handlinger afvises uden login og uden CSRF, og oprydningen kan ikke udløses af andre end en admin.
    // Q18:     product_<i> som liste og skæve tal.
    // Q19:     negative tal i hele flowet: decimaler og hårde mellemrum (tilstand L), negativ total (tilstand T).
    // Q20:     KAN-3: en hook, der kaster efter commit, vælter ikke en gemt import (egen server med en hook, der kaster).
    // ==========================================================================================
    $T1 = 'Bemærk: Varenummer og produktnavn er ikke valgt, så ingen rækker kan matches. Tryk "Gæt kolonner forfra", eller vælg kolonnerne under Kolonnemapping.';
    $T3 = '⚠ Husket valg "Ignorer kolonne" er ikke brugt: uden varenummer og produktnavn kan ingen rækker matches.';
    $N3A = 'Bemærk: Arkiveringen er ikke valgt på forhånd, fordi Kims fil har en negativ værdi for varen.';
    /** Husket kolonnevalg (nøglerne er de normaliserede overskrifter). $keySku/$keyName: '' = husket som "Ignorer kolonne". */
    $remember = static function (string $keySku, string $keyName) use ($pdo, $L1, $L2, $L3): void {
        setting_put($pdo, 'supplier_import_header_map', json_encode(['varenr' => $keySku, 'variant' => $keyName, 'lagerbeholdning' => '', 'destilleri' => 'distillery',
            'christiansfeld' => 'stock_loc_'.$L1, 'hosmichael' => 'stock_loc_'.$L2, 'hosgert' => 'stock_loc_'.$L3], JSON_FORCE_OBJECT));
    };
    /** Upload uden remap (det huskede valg bruges). Returnerer [token, side-HTML uden remap-parameter]. */
    $plainUpload = static function (array $rows, string $scope = 'full') use ($http, $csvOf, &$tokens): array {
        $r = $http->upload('supplier_upload.php', ['csrf' => $http->csrf(), 'action' => 'upload', 'import_scope' => $scope], 'file', 'tstkim-qa.csv', $csvOf($rows));
        $t = token_of($r['loc']);
        if ($t === '') throw new RuntimeException('Upload gav intet preview: '.(string)$r['loc']);
        $tokens[] = $t;
        return [$t, $http->get('supplier_upload.php?preview='.$t)['body']];
    };

    // ------------------------------------------------------------------------------------------
    t_case('Q14 "Gæt kolonner forfra" nulstiller col_key_restored (kriterie 183, punkt 4: nulstillet ved remap og reguess)', function () use ($pdo, $http, $remember, $plainUpload, $T3) {
        $p = db_product($pdo, 'TSTKIM-9811', 'TSTKIM Reguess Whisky', [$GLOBALS['L3'] => 1]);
        $remember('', '');
        [$t, $body] = $plainUpload([['TSTKIM-9811', 'TSTKIM Reguess Whisky', 'TSTKIM Dist', '1', '0', '0', '1']]);
        $text = page_text($body);
        t_eq(2, substr_count($text, $T3), 'forudsætning: T3 står ved begge reddede kolonner');
        t_true(str_contains($body, '<details class="card" open>'), 'forudsætning: panelet er åbent');
        $page = $http->follow($http->post('supplier_upload.php', ['action' => 'reguess', 'preview_token' => $t]));
        t_true(has_flash($page['body'], 'success', 'Kolonnerne er gættet forfra'), 'reguess lykkedes', json_encode(flashes($page['body']), JSON_UNESCAPED_UNICODE));
        t_eq(0, substr_count(page_text($page['body']), $T3), 'efter reguess står T3 ikke længere');
        t_eq(0, substr_count(page_text($http->get('supplier_upload.php?preview='.$t)['body']), $T3), 'og heller ikke ved næste visning (nulstillingen er gemt)');
        t_true(has_apply_button($page['body']), 'rækkerne er der stadig (Varenr. og Variant: er sku/name efter gættet)');
    });

    // ------------------------------------------------------------------------------------------
    t_case('Q15 col_key_restored lækker ikke ind i en anden upload (kriterie 183)', function () use ($pdo, $http, $remember, $plainUpload, $T1, $T3) {
        db_product($pdo, 'TSTKIM-9821', 'TSTKIM Lækage A', [$GLOBALS['L3'] => 1]);
        db_product($pdo, 'TSTKIM-9822', 'TSTKIM Lækage B', [$GLOBALS['L3'] => 1]);
        $remember('', '');
        [$a, $bodyA] = $plainUpload([['TSTKIM-9821', 'TSTKIM Lækage A', 'TSTKIM Dist', '1', '0', '0', '1']]);
        t_eq(2, substr_count(page_text($bodyA), $T3), 'upload A: T3 ved begge kolonner');
        // Admin retter valget (Genberegn gemmer det): næste upload skal slet ikke have noget at redde.
        $remember('sku', 'name');
        [$b, $bodyB] = $plainUpload([['TSTKIM-9822', 'TSTKIM Lækage B', 'TSTKIM Dist', '1', '0', '0', '1']]);
        t_eq(0, substr_count(page_text($bodyB), $T3), 'upload B: ingen T3');
        t_true(!str_contains($bodyB, '<details class="card" open>'), 'upload B: panelet er lukket (intet at gøre opmærksom på)');
        t_true(has_apply_button($bodyB) && !str_contains(page_text($bodyB), $T1), 'upload B: rækker og Anvend, ingen T1');
        $http->follow($http->post('supplier_upload.php', ['action' => 'reservation_mode', 'preview_token' => $b, 'reservation_mode' => 'add']));
        t_eq(0, substr_count(page_text($http->get('supplier_upload.php?preview='.$b)['body']), $T3), 'upload B efter reservation_mode: stadig ingen T3');
        // A's eget preview er uberørt af B.
        t_eq(2, substr_count(page_text($http->get('supplier_upload.php?preview='.$a)['body']), $T3), 'preview A viser stadig sin egen T3 (hvert preview har sin egen tilstand)');
        // Et preview uden token viser ingen T3 (kun uploadformularen).
        t_eq(0, substr_count(page_text($http->get('supplier_upload.php')['body']), $T3), 'siden uden token viser ingen T3');
    });

    // ------------------------------------------------------------------------------------------
    t_case('Q16 Bevidst "Ignorer" på begge nøglekolonner respekteres, og "Gæt kolonner forfra" er vejen ud (kriterie 184-185)', function () use ($pdo, $http, $remember, $plainUpload, $T1, $T3) {
        $p = db_product($pdo, 'TSTKIM-9831', 'TSTKIM Bevidst Ignorer', [$GLOBALS['L3'] => 1]);
        $remember('sku', 'name');
        [$t, $body] = $plainUpload([['TSTKIM-9831', 'TSTKIM Bevidst Ignorer', 'TSTKIM Dist', '1', '0', '0', '1']]);
        t_true(has_apply_button($body), 'forudsætning: rækken vises med det sunde valg');
        $iV = $GLOBALS['col']['Varenr.']; $iN = $GLOBALS['col']['Variant:'];
        $map = $GLOBALS['lMap']; $map[$iV] = ''; $map[$iN] = '';
        $page = $http->follow($http->post('supplier_upload.php', ['action' => 'remap', 'preview_token' => $t, 'col_map' => $map]));
        $text = page_text($page['body']);
        t_true(str_contains($text, $T1), 'bevidst Ignorer på begge giver T1 (ikke redning)');
        t_eq(0, substr_count($text, $T3), 'ingen T3 (valget er manuelt og respekteres)');
        t_true(!has_apply_button($page['body']) && str_contains($text, 'Der er ingen rækker at anvende.'), 'ingen Anvend, men "Der er ingen rækker at anvende."');
        // Skift af reservationsvalg og af opdateringsvalg på det tomme preview redder ikke noget bagefter.
        foreach ([['reservation_mode', ['reservation_mode' => 'none']], ['reservation_mode', ['reservation_mode' => 'add']], ['import_scope', ['import_scope' => 'stock_only']], ['import_scope', ['import_scope' => 'full']]] as [$action, $extra]) {
            $page = $http->follow($http->post('supplier_upload.php', ['action' => $action, 'preview_token' => $t] + $extra));
            $text = page_text($page['body']);
            t_true(str_contains($text, $T1) && !has_apply_button($page['body']) && substr_count($text, $T3) === 0, $action.' '.json_encode($extra).': stadig T1 og ingen redning');
        }
        // Det gemte valg har nu Ignorer på begge (Genberegn gemmer et manuelt valg). Næste upload redder dem igen (spec: "Næste upload redder det").
        [$t2, $body2] = $plainUpload([['TSTKIM-9831', 'TSTKIM Bevidst Ignorer', 'TSTKIM Dist', '1', '0', '0', '1']]);
        t_eq(2, substr_count(page_text($body2), $T3), 'næste upload: redningen virker igen (T3 ×2)');
        t_true(has_apply_button($body2), 'næste upload: rækken vises');
        // Vejen ud for det tomme preview: "Gæt kolonner forfra" giver rækkerne tilbage.
        $page = $http->follow($http->post('supplier_upload.php', ['action' => 'reguess', 'preview_token' => $t]));
        $text = page_text($page['body']);
        t_true(has_apply_button($page['body']) && !str_contains($text, $T1) && !str_contains($text, 'Der er ingen rækker at anvende.'), 'reguess på det tomme preview: rækkerne er tilbage, T1 væk');
        // Og det kan anvendes uden fejl (rækken matcher TSTKIM-9831).
        $pv = $http->get('supplier_upload.php?preview='.$t)['body'];
        t_true(preg_match('#name="apply_rows\[\]" value="0"#', $pv) === 1, 'række 0 kan afkrydses');
    });

    // ------------------------------------------------------------------------------------------
    t_case('Q17 Ændrede handlinger afvises uden login og uden CSRF, og oprydningen kan ikke udløses af andre (kriterie 175, 185)', function () use ($http, $csvOf, $base, $root, $pdo) {
        $dir = $root.'/storage/import-previews';
        if (!is_dir($dir)) mkdir($dir, 0770, true);
        $old = $dir.'/'.bin2hex(random_bytes(20)).'.json';
        file_put_contents($old, '{}'); touch($old, time() - 25 * 3600);
        // Serveren sletter filen i en anden proces: PHP's stat-cache her skal ryddes, før filen tjekkes igen (ellers kan en sletning gå ubemærket hen).
        $exists = static function () use ($old): bool { clearstatcache(true, $old); return is_file($old); };
        try {
            $anon = new Http($base);
            foreach (['upload', 'remap', 'apply', 'reguess', 'reservation_mode', 'import_scope'] as $action) {
                $r = $anon->request('POST', 'supplier_upload.php', http_build_query(['action' => $action, 'preview_token' => str_repeat('a', 40), 'apply_rows' => [0], 'csrf' => 'x']), 'application/x-www-form-urlencoded');
                t_true(in_array($r['code'], [401, 403, 419], true), $action.' uden login afvises (HTTP '.$r['code'].')');
            }
            $b = 'hsgtest'.bin2hex(random_bytes(4));
            $body = "--$b\r\nContent-Disposition: form-data; name=\"action\"\r\n\r\nupload\r\n--$b\r\nContent-Disposition: form-data; name=\"file\"; filename=\"x.csv\"\r\nContent-Type: text/csv\r\n\r\na;b\r\n--$b--\r\n";
            $r = $anon->request('POST', 'supplier_upload.php', $body, 'multipart/form-data; boundary='.$b);
            t_true(in_array($r['code'], [401, 403, 419], true), 'upload uden login (multipart) afvises (HTTP '.$r['code'].')');
            t_true($exists(), 'en anonym upload rydder ikke op i previews');
            // Logget ind, men uden (eller med forkert) CSRF-token: 419, og oprydningen kører ikke.
            foreach (['upload', 'remap', 'apply', 'reguess', 'reservation_mode', 'import_scope'] as $action) {
                $r = $http->request('POST', 'supplier_upload.php', http_build_query(['action' => $action, 'preview_token' => str_repeat('a', 40), 'apply_rows' => [0]]), 'application/x-www-form-urlencoded');
                t_eq(419, $r['code'], $action.' uden CSRF-token giver 419');
                $r = $http->request('POST', 'supplier_upload.php', http_build_query(['action' => $action, 'preview_token' => str_repeat('a', 40), 'apply_rows' => [0], 'csrf' => 'forkert']), 'application/x-www-form-urlencoded');
                t_eq(419, $r['code'], $action.' med forkert CSRF-token giver 419');
            }
            $r = $http->request('POST', 'supplier_upload.php', http_build_query(['action' => 'upload', 'csrf' => ['x']]), 'application/x-www-form-urlencoded');
            t_eq(419, $r['code'], 'csrf som liste giver 419');
            t_true($exists(), 'et afvist POST (419) rydder heller ikke op i previews');
            // Kontrol: en gyldig upload rydder op (så ovenstående ikke blot skyldes, at oprydningen aldrig kører).
            $http->upload('supplier_upload.php', ['csrf' => $http->csrf(), 'action' => 'upload', 'import_scope' => 'full'], 'file', 'tstkim-qa.csv', $csvOf([]));
            t_true(!$exists(), 'kontrol: en gyldig upload sletter den gamle fil');
        } finally { @unlink($old); }
    });

    // ------------------------------------------------------------------------------------------
    t_case('Q18 product_<i> som liste og skæve tal springer rækken over uden at ændre noget (kriterie 178)', function () use ($pdo, $L3, $http, $upload, $applyAudit) {
        $p = db_product($pdo, 'TSTKIM-9841', 'TSTKIM Skæv Produktværdi', [$L3 => 2]);
        $r1 = db_reserve($pdo, $p, $L3, 1, 'TSTKIM P1'); $r2 = db_reserve($pdo, $p, $L3, 1, 'TSTKIM P2');
        $other = db_product($pdo, 'TSTKIM-9842', 'TSTKIM Anden Vare Skæv', [$L3 => 7]);
        foreach (['stock_only', 'full'] as $scope) {
            foreach (['product_%d[]=%d' => 'liste med matchets id', 'product_%d[]=' => 'liste med tom værdi', 'product_%d[x]=%d' => 'liste med nøgle'] as $tpl => $label) {
                [$t, $pv] = $upload([['TSTKIM-9841', 'TSTKIM Skæv Produktværdi', 'TSTKIM Dist', '0', '0', '', '0']], $scope, $GLOBALS['lMap']);
                $i = item_index($pv, 'TSTKIM-9841');
                $lastAudit = (int)q1($pdo, 'SELECT COALESCE(MAX(id),0) FROM hsg_audit_log');
                $created = count_of($pdo, "SELECT COUNT(*) FROM lager_products WHERE sku LIKE 'TSTKIM-%'");
                $raw = 'action=apply&preview_token='.$t.'&apply_rows[]='.$i.'&archive_res[]='.$r1.'&archive_res[]='.$r2.'&'.sprintf($tpl, $i, $p);
                $page = $http->follow($http->postRaw('supplier_upload.php', $raw));
                $tag = $scope.' / '.$label;
                t_true(!array_filter(flashes($page['body']), static fn($f) => $f[0] === 'error'), $tag.': ingen fejl-flash', json_encode(flashes($page['body']), JSON_UNESCAPED_UNICODE));
                t_eq(['reserved', 'reserved'], [res_status($pdo, $r1), res_status($pdo, $r2)], $tag.': reservationerne er uændrede');
                t_eq([$L3 => 2], db_stock($pdo, $p), $tag.': lageret er uændret');
                t_eq($created, count_of($pdo, "SELECT COUNT(*) FROM lager_products WHERE sku LIKE 'TSTKIM-%'"), $tag.': intet produkt oprettet');
                $audit = $applyAudit($lastAudit);
                t_eq([0, 0, 0], [$audit['products_updated'] ?? null, $audit['products_created'] ?? null, $audit['reservations_archived'] ?? null], $tag.': audit: intet opdateret, oprettet eller arkiveret');
                if ($scope === 'stock_only') t_eq(1, $audit['rows_skipped'] ?? null, $tag.': rows_skipped 1');
            }
            // Skæve tal: ingen af dem peger på et produkt, så rækken springes over (ikke på matchet).
            foreach (['-5', 'abc', '99999999999999999999', '0x1F', '0.0', '+0', "\u{00A0}0\u{00A0}"] as $val) {
                [$t, $pv] = $upload([['TSTKIM-9841', 'TSTKIM Skæv Produktværdi', 'TSTKIM Dist', '0', '0', '', '0']], $scope, $GLOBALS['lMap']);
                $i = item_index($pv, 'TSTKIM-9841');
                $page = $http->follow($http->post('supplier_upload.php', ['action' => 'apply', 'preview_token' => $t, 'apply_rows' => [$i], 'product_'.$i => $val, 'archive_res' => [$r1, $r2]]));
                t_eq(['reserved', 'reserved'], [res_status($pdo, $r1), res_status($pdo, $r2)], $scope.' product='.json_encode($val, JSON_UNESCAPED_UNICODE).': reservationerne er uændrede');
                t_eq([$L3 => 2], db_stock($pdo, $p), $scope.' product='.json_encode($val, JSON_UNESCAPED_UNICODE).': lageret er uændret');
            }
            // En anden vare som produktværdi: lageret skrives på DEN vare, og previewets reservationer arkiveres aldrig (kun for previewets match).
            [$t, $pv] = $upload([['TSTKIM-9841', 'TSTKIM Skæv Produktværdi', 'TSTKIM Dist', '0', '0', '', '0']], $scope, $GLOBALS['lMap']);
            $i = item_index($pv, 'TSTKIM-9841');
            $http->follow($http->post('supplier_upload.php', ['action' => 'apply', 'preview_token' => $t, 'apply_rows' => [$i], 'product_'.$i => (string)$other, 'archive_res' => [$r1, $r2]]));
            t_eq(['reserved', 'reserved'], [res_status($pdo, $r1), res_status($pdo, $r2)], $scope.': valgt en anden vare: previewets reservationer arkiveres aldrig');
            t_eq([$L3 => 2], db_stock($pdo, $p), $scope.': valgt en anden vare: den oprindelige vare er uændret');
            $pdo->prepare('UPDATE lager_stock SET quantity=7 WHERE product_id=?')->execute([$other]);   // nulstil til næste runde
        }
    });

    // ------------------------------------------------------------------------------------------
    t_case('Q19 Negative tal i hele flowet: decimaler og hårde mellemrum (tilstand L), negativ total (tilstand T) (kriterie 173)', function () use ($pdo, $L1, $L2, $L3, $http, $upload, $apply, $N3A) {
        // --- Tilstand L: "-0,5" i første lokation (rundes væk fra 0 til -1) og " -1 " med hårde mellemrum i anden.
        $a = db_product($pdo, 'TSTKIM-9851', 'TSTKIM Negativ Decimal', [$L1 => 1]); $ra = db_reserve($pdo, $a, $L1, 1, 'TSTKIM ND1');
        $b = db_product($pdo, 'TSTKIM-9852', 'TSTKIM Negativ Mellemrum', [$L2 => 1]); $rb = db_reserve($pdo, $b, $L2, 1, 'TSTKIM NM1');
        $c = db_product($pdo, 'TSTKIM-9853', 'TSTKIM Minus Nul', [$L3 => 1]); $rc = db_reserve($pdo, $c, $L3, 1, 'TSTKIM MN1');
        [$t, $pv] = $upload([
            ['TSTKIM-9851', 'TSTKIM Negativ Decimal', 'TSTKIM Dist', '0', '-0,5', '0', '0'],
            ['TSTKIM-9852', 'TSTKIM Negativ Mellemrum', 'TSTKIM Dist', '0', '0', "\u{00A0}-1\u{00A0}", '0'],
            ['TSTKIM-9853', 'TSTKIM Minus Nul', 'TSTKIM Dist', '0', '0', '0', '-0'],
        ], 'stock_only', $GLOBALS['lMap']);
        $ia = item_index($pv, 'TSTKIM-9851'); $ib = item_index($pv, 'TSTKIM-9852'); $ic = item_index($pv, 'TSTKIM-9853');
        t_eq([false, false, true], [$pv['items'][$ia]['archive']['default'] ?? null, $pv['items'][$ib]['archive']['default'] ?? null, $pv['items'][$ic]['archive']['default'] ?? null], '-0,5 og " -1 " er negative (ikke forvalgt); -0 er ikke negativ (forvalgt som før)');
        $body = $http->get('supplier_upload.php?preview='.$t)['body'];
        $text = page_text($body);
        t_true(str_contains($text, 'Negativ værdi „-0,5“ for TSTKIM Christiansfeld behandles som 0.'), 'N1 med -0,5');
        t_true(str_contains($text, 'Negativ værdi „-1“ for TSTKIM Michael behandles som 0.'), 'N1 med -1 (hårde mellemrum fjernet)');
        t_true(!str_contains($text, 'Negativ værdi „-0“'), 'ingen N1 for -0');
        t_eq(2, substr_count($text, $N3A), 'N3a står præcis ved de to negative varer');
        foreach ([[$ra, false], [$rb, false], [$rc, true]] as [$rid, $checked]) {
            $found = preg_match('#<input type="checkbox" name="archive_res\[\]" value="'.$rid.'"([^>]*)>#', $body, $m) === 1;
            t_true($found && str_contains($m[1], 'checked') === $checked, 'arkiveringsfeltet #'.$rid.($checked ? ' er forvalgt' : ' er uden checked'));
        }
        t_true(str_contains($text, 'på varer, der kræver din vurdering, er ikke valgt'), 'oversigten nævner de reservationer, der ikke er valgt');
        // Anvend alle tre rækker uden at krydse noget af: kun -0-varen arkiveres ikke heller (feltet var forvalgt i browseren, men POST'en er tom).
        $apply($t, [$ia, $ib, $ic], [$ia => $a, $ib => $b, $ic => $c], []);
        t_eq(['reserved', 'reserved', 'reserved'], [res_status($pdo, $ra), res_status($pdo, $rb), res_status($pdo, $rc)], 'uden afkrydsning arkiveres intet');

        // --- Tilstand T: "Lagerantal i alt" = -1. Reservationerne ligger på første lokation.
        $d = db_product($pdo, 'TSTKIM-9854', 'TSTKIM Negativ Total', [$L1 => 2]);
        $r1 = db_reserve($pdo, $d, $L1, 1, 'TSTKIM NT1'); $r2 = db_reserve($pdo, $d, $L1, 1, 'TSTKIM NT2');
        $row = ['TSTKIM-9854', 'TSTKIM Negativ Total', 'TSTKIM Dist', '-1', '', '', ''];
        [$t2, $pv2] = $upload([$row], 'stock_only', $GLOBALS['tMap']);
        $i2 = item_index($pv2, 'TSTKIM-9854');
        t_eq([false, [$r1, $r2], '-1'], [$pv2['items'][$i2]['archive']['default'] ?? null, array_column($pv2['items'][$i2]['archive']['reservations'] ?? [], 'id'), $pv2['items'][$i2]['stock_file']['total_negative'] ?? null], 'T: arkivering tilbydes uden forvalg, total_negative -1');
        $body2 = $http->get('supplier_upload.php?preview='.$t2)['body'];
        $text2 = page_text($body2);
        t_true(str_contains($text2, 'Negativ værdi „-1“ for lagerantal i alt behandles som 0.'), 'T: N2');
        t_true(str_contains($text2, $N3A), 'T: N3a');
        foreach ([$r1, $r2] as $rid) {
            $found = preg_match('#<input type="checkbox" name="archive_res\[\]" value="'.$rid.'"([^>]*)>#', $body2, $m) === 1;
            t_true($found && !str_contains($m[1], 'checked'), 'T: arkiveringsfeltet #'.$rid.' er uden checked');
        }
        $apply($t2, [$i2], [$i2 => $d], []);
        t_eq(['reserved', 'reserved'], [res_status($pdo, $r1), res_status($pdo, $r2)], 'T uden afkrydsning: r1 og r2 er stadig reserveret');
        t_eq([$L1 => 2], db_stock($pdo, $d), 'T uden afkrydsning: 0 + 2 reserveret = 2 (uændret)');
        [$t3, $pv3] = $upload([$row], 'stock_only', $GLOBALS['tMap']);
        $i3 = item_index($pv3, 'TSTKIM-9854');
        $apply($t3, [$i3], [$i3 => $d], [$r1, $r2]);
        t_eq(['archived', 'archived'], [res_status($pdo, $r1), res_status($pdo, $r2)], 'T med begge afkrydset: r1 og r2 er arkiveret');
        t_eq([$L1 => 0], db_stock($pdo, $d), 'T med begge afkrydset: lageret er 0');
    });

    // ------------------------------------------------------------------------------------------
    t_case('Q20 KAN-3: en hook, der kaster efter commit, vælter ikke en gemt import (kriterie 188)', function () use ($pdo, $L3, $root, $adminUser, $adminPass, $csvOf, &$tokens) {
        $prepend = $root.'/tests/hook_throw_prepend.php';
        if (!is_file($prepend)) { t_true(false, 'tests/hook_throw_prepend.php findes ikke'); return; }
        $sock = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($sock === false) { fwrite(STDOUT, "  (Q20: ingen ledig port – sprunget over)\n"); return; }
        $port = (int)substr((string)stream_socket_get_name($sock, false), strrpos((string)stream_socket_get_name($sock, false), ':') + 1);
        fclose($sock);
        $logFile = sys_get_temp_dir().'/hsg-q20-'.bin2hex(random_bytes(4)).'.log';
        $cmd = [PHP_BINARY, '-d', 'auto_prepend_file='.$prepend, '-d', 'log_errors=1', '-d', 'display_errors=0', '-d', 'upload_max_filesize=16M', '-d', 'post_max_size=24M', '-S', '127.0.0.1:'.$port, '-t', $root];
        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', $logFile, 'a'], 2 => ['file', $logFile, 'a']], $pipes);
        if (!is_resource($proc)) { t_true(false, 'kunne ikke starte den ekstra server'); return; }
        try {
            $up = false;
            for ($n = 0; $n < 50 && !$up; $n++) { $c = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2); if ($c) { fclose($c); $up = true; } else usleep(100000); }
            t_true($up, 'den ekstra server svarer');
            if (!$up) return;
            $h2 = new Http('http://127.0.0.1:'.$port);
            preg_match('/name="csrf" value="([^"]+)"/', $h2->get('admin-login.php')['body'], $m);
            $h2->request('POST', 'admin-login.php', http_build_query(['csrf' => $m[1] ?? '', 'username' => $adminUser, 'password' => $adminPass]), 'application/x-www-form-urlencoded');
            t_true(str_contains($h2->get('supplier_upload.php')['body'], 'Kims uploadfil'), 'login på den ekstra server');

            $p = db_product($pdo, 'TSTKIM-9861', 'TSTKIM Hook Whisky', [$L3 => 2]);
            $r1 = db_reserve($pdo, $p, $L3, 1, 'TSTKIM H1'); $r2 = db_reserve($pdo, $p, $L3, 1, 'TSTKIM H2');
            $r = $h2->upload('supplier_upload.php', ['csrf' => $h2->csrf(), 'action' => 'upload', 'import_scope' => 'stock_only'], 'file', 'tstkim-qa.csv', $csvOf([['TSTKIM-9861', 'TSTKIM Hook Whisky', 'TSTKIM Dist', '0', '0', '', '0']]));
            $t = token_of($r['loc']); $tokens[] = $t;
            $h2->follow($h2->post('supplier_upload.php', ['action' => 'remap', 'preview_token' => $t, 'col_map' => $GLOBALS['lMap']]));
            $i = 0;   // filen har én række
            $runs = count_of($pdo, 'SELECT COUNT(*) FROM hsg_supplier_import_runs');
            $page = $h2->follow($h2->post('supplier_upload.php', ['action' => 'apply', 'preview_token' => $t, 'apply_rows' => [$i], 'product_'.$i => (string)$p, 'archive_res' => [$r1, $r2]]));
            t_eq(['archived', 'archived'], [res_status($pdo, $r1), res_status($pdo, $r2)], 'reservationerne er arkiveret (importen er gemt)');
            t_eq($runs + 1, count_of($pdo, 'SELECT COUNT(*) FROM hsg_supplier_import_runs'), 'importkørslen er registreret');
            t_true(has_flash($page['body'], 'success', '2 reservationer (2 stk.) er arkiveret'), 'success-flashen vises', json_encode(flashes($page['body']), JSON_UNESCAPED_UNICODE));
            t_true(!array_filter(flashes($page['body']), static fn($f) => $f[0] === 'error'), 'ingen fejl-flash', json_encode(flashes($page['body']), JSON_UNESCAPED_UNICODE));
            $log = (string)@file_get_contents($logFile);
            foreach ([$r1, $r2] as $rid) t_true(str_contains($log, 'hooken reservation.archived fejlede for reservation #'.$rid.': QA: hooken fejler med vilje'), 'fejlen for reservation #'.$rid.' står i fejlloggen (hver hook har sin egen try/catch)');
        } finally {
            foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
            proc_terminate($proc);
            usleep(300000);
            proc_close($proc);
            @unlink($logFile);
        }
    });

    // ------------------------------------------------------------------------------------------
    t_case('Q21 Tomt preview med kun fadnummer valgt viser T2 (ikke T1) – fadnummer tæller som nøglekolonne (kriterie 184)', function () use ($upload, $http, $T1, $T2) {
        $map = $GLOBALS['lMap'];
        $map[$GLOBALS['col']['Varenr.']] = ''; $map[$GLOBALS['col']['Variant:']] = ''; $map[$GLOBALS['col']['Destilleri:']] = 'cask_number';
        foreach (['full', 'stock_only'] as $scope) {
            // Fadnummeret (Destilleri:-kolonnen) er tomt i alle rækker, så filen giver ingen rækker.
            [$t, $pv] = $upload([['', '', '', '1', '1', '', ''], ['', '', '', '2', '2', '', '']], $scope, $map);
            t_eq([], $pv['items'], $scope.': ingen rækker');
            $body = $http->get('supplier_upload.php?preview='.$t)['body']; $text = page_text($body);
            t_true(str_contains($text, $T2) && !str_contains($text, $T1), $scope.': T2 (fadnummer er valgt), ikke T1');
            t_true(!has_apply_button($body) && str_contains($text, 'Der er ingen rækker at anvende.'), $scope.': ingen Anvend, men "Der er ingen rækker at anvende."');
        }
    });

    // ------------------------------------------------------------------------------------------
    t_case('Q22 Oversigtslinjen "Arkiveres: …" (kriterie 192, O4): forvalgt, blandet, kun vurdering og ingen arkivering', function () use ($pdo, $L3, $http, $upload) {
        $dist = 'TSTKIM Dist';
        $a = db_product($pdo, 'TSTKIM-9911', 'TSTKIM Oversigt Forvalgt A', [$L3 => 2]); db_reserve($pdo, $a, $L3, 1, 'TSTKIM OA1'); db_reserve($pdo, $a, $L3, 1, 'TSTKIM OA2');
        $a2 = db_product($pdo, 'TSTKIM-9913', 'TSTKIM Oversigt Forvalgt A2', [$L3 => 1]); db_reserve($pdo, $a2, $L3, 1, 'TSTKIM OB1');
        $b = db_product($pdo, 'TSTKIM-9912', 'TSTKIM Oversigt Vurdering B', [$L3 => 2]);
        db_reserve($pdo, $b, $L3, 1, "TSTKIM <b>Fed</b> & 'æøå' <script>alert(1)</script>");   // kundenavnet står i en afkrydsningsetiket
        $c = db_product($pdo, 'TSTKIM-9914', 'TSTKIM Oversigt Ingen Reservation', [$L3 => 2]);
        $d = db_product($pdo, 'TSTKIM-9915', 'TSTKIM Usikker Match Whisky Fadstyrke', [$L3 => 1]); db_reserve($pdo, $d, $L3, 1, 'TSTKIM OD1');
        $rowA = ['TSTKIM-9911', 'TSTKIM Oversigt Forvalgt A', $dist, '0', '0', '', '0'];
        $rowA2 = ['TSTKIM-9913', 'TSTKIM Oversigt Forvalgt A2', $dist, '0', '0', '', '0'];
        $rowB = ['TSTKIM-9912', 'TSTKIM Oversigt Vurdering B', $dist, '0', '0', '', '-1'];            // negativ værdi: arkiveringen tilbydes, ikke forvalgt
        $rowC = ['TSTKIM-9914', 'TSTKIM Oversigt Ingen Reservation', $dist, '0', '0', '', '0'];       // ingen reservationer: ingen arkivering
        $rowC2 = ['TSTKIM-9914', 'TSTKIM Oversigt Ingen Reservation', $dist, '5', '5', '', '0'];      // lager ikke 0: ingen arkivering
        $rowD = ['ZZ-9915', 'TSTKIM Usikker Match Whisky Fadstyrke Special', $dist, '0', '0', '', '0']; // usikkert match (under 90 %), ingen negativ værdi
        $archiveLine = static fn(string $t): string => str_contains($t, 'Arkiveres:') ? 'Arkiveres' : (str_contains($t, 'Ingen reservationer er valgt til arkivering') ? 'Ingen' : 'ingen linje');
        $text = static function (array $rows) use ($upload, $http): array {
            [$t, $pv] = $upload($rows, 'stock_only', $GLOBALS['lMap']);
            $body = $http->get('supplier_upload.php?preview='.$t)['body'];
            return [page_text($body), $body, $pv];
        };

        // Kun forvalgt (to varer): den gamle sætning, uden tilføjelsen om vurdering og uden den nye sætning.
        [$t1, $b1] = $text([$rowA, $rowA2]);
        t_true(str_contains($t1, 'Arkiveres: 3 reservationer (3 stk.) på 2 varer, fordi Kims lager i alt er 0. Du kan fravælge dem på de enkelte varer.'), 'kun forvalgt: "Arkiveres: 3 reservationer (3 stk.) på 2 varer …"');
        t_true(!str_contains($t1, 'på varer, der kræver din vurdering, er ikke valgt') && !str_contains($t1, 'Ingen reservationer er valgt'), 'kun forvalgt: ingen tilføjelse om vurdering og ingen "Ingen reservationer er valgt"');

        // Blandet: én vare forvalgt, én til vurdering – den gamle sætning med tilføjelsen (ental, fordi der kun er én reservation).
        [$t2, $b2, $pv2] = $text([$rowA, $rowB]);
        t_true(str_contains($t2, 'Arkiveres: 2 reservationer (2 stk.) på 1 vare, fordi Kims lager i alt er 0. Du kan fravælge dem på de enkelte varer. 1 reservation (1 stk.) på varer, der kræver din vurdering, er ikke valgt.'), 'blandet: "Arkiveres: 2 reservationer … på 1 vare … 1 reservation (1 stk.) på varer, der kræver din vurdering, er ikke valgt."');
        t_true(!str_contains($t2, 'Ingen reservationer er valgt til arkivering') && !str_contains($t2, 'Arkiveres: 0'), 'blandet: ikke den nye sætning og ikke "Arkiveres: 0"');
        // Escaping: kundenavnet i etiketten er HTML-escapet, og scriptet kører ikke.
        t_true(str_contains($b2, '&lt;b&gt;Fed&lt;/b&gt; &amp; &#039;æøå&#039; &lt;script&gt;alert(1)&lt;/script&gt;') || str_contains($b2, '&lt;b&gt;Fed&lt;/b&gt; &amp; &#039;&aelig;&oslash;&aring;&#039;'), 'kundenavnet er escapet i HTML');
        t_true(!str_contains($b2, '<b>Fed</b>') && !str_contains($b2, '<script>alert(1)</script>'), 'ingen rå <b> eller <script> fra kundenavnet');

        // Kun til vurdering (negativ værdi, ental): den nye sætning, og aldrig "Arkiveres: 0 …".
        [$t3, $b3] = $text([$rowB]);
        t_true(str_contains($t3, 'Ingen reservationer er valgt til arkivering. 1 reservation (1 stk.) på varer, der kræver din vurdering, er ikke valgt. Du kan vælge dem på de enkelte varer.'), 'kun vurdering: den nye sætning (ental)');
        t_eq('Ingen', $archiveLine($t3), 'kun vurdering: ingen "Arkiveres:"-linje');
        t_true(!str_contains($t3, 'på 0 varer') && !str_contains($t3, '0 reservationer'), 'kun vurdering: hverken "på 0 varer" eller "0 reservationer"');
        t_true(preg_match('#<p>Ingen reservationer er valgt til arkivering\. 1 reservation \(1 stk\.\) på varer, der kræver din vurdering, er ikke valgt\. Du kan vælge dem på de enkelte varer\.</p>#u', $b3) === 1, 'kun vurdering: sætningen står i sit eget <p>…</p>');
        t_true(!str_contains($b3, 'O4:') && !str_contains($b3, 'ingen "0 reservationer'), 'kun vurdering: PHP-kommentaren i skabelonen siver ikke ud i HTML');

        // Kun til vurdering (usikkert match, ingen negativ værdi), flertal.
        [$t4, $b4, $pv4] = $text([$rowD]);
        $d4 = $pv4['items'][0] ?? [];
        t_eq([$d, false, true], [(int)($d4['match']['id'] ?? 0), $d4['archive']['default'] ?? null, (int)($d4['match']['score'] ?? 100) < 90], 'forudsætning: usikkert match (under 90 %) med tilbudt, ikke forvalgt arkivering');
        t_true(str_contains($t4, 'Ingen reservationer er valgt til arkivering. 1 reservation (1 stk.) på varer, der kræver din vurdering, er ikke valgt. Du kan vælge dem på de enkelte varer.'), 'usikkert match: den nye sætning');
        t_true(!str_contains($t4, 'negativ værdi for varen'), 'usikkert match: ingen N3a (der er ingen negativ værdi)');
        // To varer til vurdering: flertal (2 reservationer (2 stk.) – B og D).
        [$t5] = $text([$rowB, $rowD]);
        t_true(str_contains($t5, 'Ingen reservationer er valgt til arkivering. 2 reservationer (2 stk.) på varer, der kræver din vurdering, er ikke valgt. Du kan vælge dem på de enkelte varer.'), 'to varer til vurdering: "2 reservationer (2 stk.)"');

        // Ingen arkivering overhovedet: hverken reservationer, lager 0 eller nogen af de tre sætninger.
        foreach ([[$rowC], [$rowC2], [$rowC, $rowC2]] as $k => $rows) {
            [$t6] = $text($rows);
            t_eq('ingen linje', $archiveLine($t6), 'ingen arkivering '.$k.': ingen oversigtslinje');
            t_true(!str_contains($t6, 'kræver din vurdering, er ikke valgt') && !str_contains($t6, 'Arkiveres: 0'), 'ingen arkivering '.$k.': hverken tilføjelse eller "Arkiveres: 0"');
        }
        // Samme tre tilstande i "Produktdata og lager" (siden bruger samme linje i begge scopes).
        [$tf, $pf] = $upload([$rowB], 'full', $GLOBALS['lMap']);
        $tf = page_text($http->get('supplier_upload.php?preview='.$tf)['body']);
        t_true(str_contains($tf, 'Ingen reservationer er valgt til arkivering. 1 reservation (1 stk.)') && !str_contains($tf, 'Arkiveres: 0'), 'Produktdata og lager: samme ny sætning, ikke "Arkiveres: 0"');
    });

    // ------------------------------------------------------------------------------------------
    t_case('Q23 Hvornår kolonnevalget gemmes (docs): kun "Genberegn preview med tilpasset kolonnemapping" og "Anvend valgte ændringer"', function () use ($pdo, $L3, $http, $remember, $plainUpload) {
        $p = db_product($pdo, 'TSTKIM-9921', 'TSTKIM Gemmer Kolonnevalg', [$L3 => 1]);
        $row = ['TSTKIM-9921', 'TSTKIM Gemmer Kolonnevalg', 'TSTKIM Dist', '1', '0', '0', '1'];
        $saved = static fn(): array => json_decode((string)setting_raw($GLOBALS['pdo'], 'supplier_import_header_map'), true) ?: [];
        $iV = $GLOBALS['col']['Varenr.']; $iN = $GLOBALS['col']['Variant:'];
        $remember('', '');   // varenr og variant er husket som "Ignorer kolonne" → uploaden redder dem (sku/name), men gemmer ikke noget
        $before = setting_raw($pdo, 'supplier_import_header_map');
        [$t, $body] = $plainUpload([$row]);
        t_eq($before, setting_raw($pdo, 'supplier_import_header_map'), 'selve uploaden gemmer ikke kolonnevalget');
        // Kriterie 193: panelteksten siger præcis, hvornår et husket "Ignorer" ikke bruges, og hvilke knapper der gemmer.
        $panel = preg_match('#<details class="card"[^>]*>(.*?)</details>#s', $body, $pm) === 1 ? page_text($pm[1]) : '';
        t_true(str_contains($panel, 'Undtagelse: Er ingen kolonne valgt som varenummer, produktnavn eller fadnummer, bruges et husket "Ignorer kolonne" ikke på en kolonne med overskriften for varenummer eller produktnavn (se ⚠ ved kolonnen).'), '193: panelet har undtagelsessætningen');
        t_true(str_contains($panel, 'Dit valg gemmes, når du trykker "Genberegn preview med tilpasset kolonnemapping" eller "Anvend valgte ændringer".'), '193: panelet nævner begge fulde knapnavne');
        t_true(!str_contains($panel, 'genberegner eller anvender ændringerne'), '193: panelet har ikke "genberegner eller anvender ændringerne"');
        t_true(!str_contains($panel, '(undtagen varenummer og produktnavn – se ⚠ ved kolonnen)'), '193: panelet har ikke den brede undtagelse fra O4');
        // Disse knapper gemmer ikke kolonnevalget: "Hvad skal opdateres?", "Reservationer" og "Gæt kolonner forfra".
        foreach ([['import_scope', ['import_scope' => 'stock_only']], ['import_scope', ['import_scope' => 'full']], ['reservation_mode', ['reservation_mode' => 'none']], ['reservation_mode', ['reservation_mode' => 'add']], ['reguess', []]] as [$action, $extra]) {
            $http->follow($http->post('supplier_upload.php', ['action' => $action, 'preview_token' => $t] + $extra));
            t_eq($before, setting_raw($pdo, 'supplier_import_header_map'), $action.' '.json_encode($extra).' gemmer ikke kolonnevalget');
        }
        // "Genberegn preview med tilpasset kolonnemapping" gemmer det rettede valg (Varenr. → sku, Variant: → name).
        [$t2] = $plainUpload([$row]);
        $map = $GLOBALS['lMap'];
        $http->follow($http->post('supplier_upload.php', ['action' => 'remap', 'preview_token' => $t2, 'col_map' => $map]));
        t_eq(['sku', 'name'], [$saved()['varenr'] ?? null, $saved()['variant'] ?? null], 'remap gemmer varenr → sku og variant → name');
        // "Anvend valgte ændringer" gemmer det også.
        $remember('', '');
        [$t3] = $plainUpload([$row]);
        t_eq(['', ''], [$saved()['varenr'] ?? null, $saved()['variant'] ?? null], 'forudsætning: valget er stadig "Ignorer", før der anvendes');
        $http->follow($http->post('supplier_upload.php', ['action' => 'apply', 'preview_token' => $t3, 'apply_rows' => [0], 'product_0' => (string)$p]));
        t_eq(['sku', 'name'], [$saved()['varenr'] ?? null, $saved()['variant'] ?? null], 'apply gemmer varenr → sku og variant → name');
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
fwrite(STDOUT, sprintf("Kun lagerantal og arkivering, testerens supplement (flow via HTTP): %d tjek bestået, %d fejlede.\n", $GLOBALS['t_pass'], count($fails)));
exit($fails ? 1 : 0);
