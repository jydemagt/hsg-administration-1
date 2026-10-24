<?php
declare(strict_types=1);
/**
 * PHP-krav – testerens supplement til release-delen (kriterie 122 og 123 i
 * docs/specs/2026-10-23-mbstring-krav.md). Ingen database. Kun udvikling – kommer ikke med i release.
 *
 *   php tests/php_krav_release_test.php
 *
 * Kræver et miljø med alle krævede udvidelser (ellers kan bygget ikke godkende sin egen ZIP)
 * og en Git-arbejdskopi (bygget eksporterer de sporede filer).
 *
 *   122: en rigtig bygget ZIP har required_extensions = hsg_required_extensions() (inkl. mbstring og openssl),
 *        core/php_requirements.php er med (med rigtig hash), og build-release.php har ikke længere listen hårdkodet.
 *   124: README.txt nævner de samme krævede udvidelser som hsg_required_extensions().
 *   123: release-negativtesten består, og test B og C afvises af den tilsigtede grund
 *        (uventet fil og filhash) – ikke fordi en PHP-udvidelse mangler på maskinen, der bygger.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = dirname(__DIR__);
chdir($root);
require_once $root.'/core/php_requirements.php';

$fails = 0; $passes = 0;
function t(string $name, bool $ok, string $detail = ''): void {
    global $fails, $passes;
    if ($ok) { $passes++; return; }
    $fails++;
    echo "FEJL: $name".($detail !== '' ? " ($detail)" : '')."\n";
}

/** Kører PHP med samme ini-fil som denne proces. Returnerer [exitkode, output]. */
function run_php(array $args, string $cwd): array {
    $cmd = [PHP_BINARY];
    $ini = php_ini_loaded_file();
    if ($ini !== false) { $cmd[] = '-c'; $cmd[] = $ini; }
    $proc = proc_open(array_merge($cmd, $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
    if (!is_resource($proc)) return [-1, 'proc_open fejlede'];
    $out = (string)stream_get_contents($pipes[1]).(string)stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($proc), $out];
}

$missing = hsg_missing_extensions();
if ($missing !== []) {
    echo "FEJL: release-testen kræver alle krævede udvidelser; mangler: ".implode(', ', $missing)."\n";
    exit(1);
}
if (!is_dir($root.'/.git') && !is_file($root.'/.git')) {
    echo "PHP-krav (release): sprunget over, ingen Git-arbejdskopi\n";
    exit(0);
}

// 122: listen står ikke længere hårdkodet i bygget
$builder = (string)file_get_contents($root.'/tools/build-release.php');
t('122: tools/build-release.php indeholder ikke strengen \'curl\'', !str_contains($builder, "'curl'"));
t('122: tools/build-release.php bruger hsg_required_extensions()', str_contains($builder, 'hsg_required_extensions()'));

// 123: negativtesten, og at B og C afvises af den tilsigtede grund
[$code, $neg] = run_php(['tools/build-release.php', '--test-negative'], $root);
$neg = (string)preg_replace('/\e\[[0-9;]*m/', '', $neg);
t('123: --test-negative afsluttes med kode 0', $code === 0, 'kode '.$code);
t('123: "ALLE 7 NEGATIVE TESTS (A-G) BESTÅET"', str_contains($neg, 'ALLE 7 NEGATIVE TESTS (A-G) BESTÅET'));
$b = strpos($neg, 'Test B BESTÅET'); $c = strpos($neg, 'Test C BESTÅET'); $d = strpos($neg, 'Test D BESTÅET');
if ($b === false || $c === false || $d === false || !($b < $c && $c < $d)) {
    t('123: logteksten har test B, C og D i rækkefølge', false, 'uventet format');
} else {
    $logB = substr($neg, $b, $c - $b); $logC = substr($neg, $c, $d - $c);
    t('123: test B afvises fordi filen ikke står i manifestet', str_contains($logB, 'Uventet fil'), substr($logB, 0, 200));
    t('123: test C afvises fordi filhashen er ændret', str_contains($logC, 'Expected SHA-256'), substr($logC, 0, 200));
    t('123: logteksten for B indeholder ikke "PHP-udvidelse"', !str_contains($logB, 'PHP-udvidelse'));
    t('123: logteksten for C indeholder ikke "PHP-udvidelse"', !str_contains($logC, 'PHP-udvidelse'));
}

// 122: en rigtig ZIP
$dir = sys_get_temp_dir().'/hsg-php-krav-release-'.bin2hex(random_bytes(4));
if (!mkdir($dir, 0775, true) && !is_dir($dir)) { t('122: midlertidig mappe kunne oprettes', false); }
else {
    $zipPath = $dir.'/hsg.zip';
    [$code, $out] = run_php(['tools/build-release.php', '--output='.$zipPath], $root);
    t('122: bygget lykkes (kode 0)', $code === 0 && is_file($zipPath), 'kode '.$code.' '.substr((string)preg_replace('/\e\[[0-9;]*m/', '', $out), -300));
    if (is_file($zipPath)) {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) { t('122: ZIP kunne åbnes', false); }
        else {
            $m = json_decode((string)$zip->getFromName('hsg-package.json'), true);
            t('122: hsg-package.json i ZIP kunne læses', is_array($m));
            $ext = is_array($m) ? ($m['required_extensions'] ?? null) : null;
            t('122: required_extensions i ZIP er lig hsg_required_extensions()', $ext === hsg_required_extensions(), json_encode($ext));
            t('122: required_extensions indeholder mbstring og openssl', is_array($ext) && in_array('mbstring', $ext, true) && in_array('openssl', $ext, true));
            $php = $zip->getFromName('core/php_requirements.php');
            t('122: core/php_requirements.php er med i ZIP', $php !== false);
            $listed = is_array($m) ? ($m['manifest']['core/php_requirements.php'] ?? null) : null;
            t('122: manifestet har filens SHA-256', is_string($listed) && $php !== false && hash_equals(strtolower($listed), hash('sha256', (string)$php)));
            $dev = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $n = (string)$zip->getNameIndex($i);
                if (preg_match('#^(tests/|docs/specs/|\.claude/|\.github/|CLAUDE\.md$|config\.php$)#', $n)) $dev[] = $n;
            }
            t('122: ingen test-/dev-filer i ZIP', $dev === [], implode(', ', array_slice($dev, 0, 5)));
            $zip->close();
        }
    }
    @unlink($zipPath);
    @rmdir($dir);
}

// 124: README nævner præcis de samme krævede udvidelser som hsg_required_extensions()
$readme = (string)file_get_contents($root.'/README.txt');
if (preg_match('/^- Krævede PHP-udvidelser: ([^\r\n]+)\.\r?$/m', $readme, $rm)) {
    $inReadme = preg_split('/, | og /', $rm[1]);
    t('124: README.txt nævner de krævede udvidelser i samme rækkefølge som hsg_required_extensions()', $inReadme === hsg_required_extensions(), json_encode($inReadme));
} else {
    t('124: README.txt har linjen "- Krævede PHP-udvidelser: ..."', false);
}

echo "PHP-krav (release): $passes bestået, $fails fejlet\n";
exit($fails === 0 ? 0 : 1);
