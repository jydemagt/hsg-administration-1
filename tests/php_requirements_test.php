<?php
declare(strict_types=1);
/**
 * Test af de krævede PHP-udvidelser (core/php_requirements.php) og updaterens tjek af dem.
 * Ingen database. Kun udvikling – kommer ikke med i release.
 *
 *   php tests/php_requirements_test.php
 *   php -c <ini-uden-mbstring> tests/php_requirements_test.php --uden-mbstring
 *
 * Med --uden-mbstring skal miljøet mangle mbstring (og have alle andre krævede udvidelser).
 * Uden flaget afgøres det af, om mbstring er indlæst.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = dirname(__DIR__);
chdir($root);
require_once $root.'/functions.php';
require_once $root.'/core/updater.php';

$fails = 0; $passes = 0;
function t(string $name, bool $ok, string $detail = ''): void {
    global $fails, $passes;
    if ($ok) { $passes++; return; }
    $fails++;
    echo "FEJL: $name".($detail !== '' ? " ($detail)" : '')."\n";
}
function eq(string $name, mixed $expected, mixed $actual): void {
    t($name, $expected === $actual, 'forventet '.var_export($expected, true).', fik '.var_export($actual, true));
}

// core/updater.php indlæser selv fællesfunktionerne
t('core/updater.php indlæser core/php_requirements.php', function_exists('hsg_missing_extensions') && function_exists('hsg_missing_extensions_message'));
require_once $root.'/core/php_requirements.php';

// Kriterie 111: filen alene, fra roden, i en ny PHP-proces med samme ini-fil
$cmd = [PHP_BINARY];
$ini = php_ini_loaded_file();
if ($ini !== false) { $cmd[] = '-c'; $cmd[] = $ini; }
$cmd[] = '-r';
$cmd[] = "require 'core/php_requirements.php'; echo json_encode(hsg_required_extensions()), session_status();";
$proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
$out = '';
if (is_resource($proc)) {
    $out = (string)stream_get_contents($pipes[1]).(string)stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    proc_close($proc);
}
eq('111: listen i rækkefølge, ingen session og intet andet output', '["pdo","pdo_mysql","zip","json","gd","curl","mbstring","openssl"]1', $out);

// Filen må kun indeholde declare og funktioner: ingen require/include og intet output
$tokens = token_get_all((string)file_get_contents($root.'/core/php_requirements.php'));
$forbidden = [];
foreach ($tokens as $tok) {
    if (is_array($tok) && in_array($tok[0], [T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE, T_ECHO, T_PRINT, T_EXIT, T_INLINE_HTML, T_OPEN_TAG_WITH_ECHO, T_CLOSE_TAG], true)) {
        $forbidden[] = token_name($tok[0]);
    }
}
eq('php_requirements.php har ingen require, include eller output', [], $forbidden);

// Kriterie 112: hsg_missing_extensions
eq('112: kun ukendte udvidelser, tomme springes over, rækkefølgen bevares', ['hsg_test_a', 'hsg_test_b'], hsg_missing_extensions(['pdo', '', 'hsg_test_a', 'zip', 'hsg_test_b']));
eq('112: tom liste', [], hsg_missing_extensions([]));
eq('112: listen er en liste (array_values)', [0, 1], array_keys(hsg_missing_extensions(['x' => 'hsg_test_a', 'y' => 'pdo', 'z' => 'hsg_test_b'])));

$udenMb = in_array('--uden-mbstring', $argv ?? [], true);
if ($udenMb) t('miljøet mangler mbstring (--uden-mbstring)', !extension_loaded('mbstring'), 'ini-filen indlæser stadig mbstring – testen er ugyldig');
$expected = ($udenMb || !extension_loaded('mbstring')) ? ['mbstring'] : [];
eq('112: hsg_missing_extensions() '.($expected === [] ? 'med alle udvidelser' : 'uden mbstring'), $expected, hsg_missing_extensions());

// Kriterie 112: hsg_missing_extensions_message
$help = 'til i webhotellets kontrolpanel under PHP-indstillinger, eller bed webhotellets support om det.';
eq('112: besked uden manglende udvidelser', '', hsg_missing_extensions_message([]));
eq('112: besked med én udvidelse', 'Webhotellet mangler PHP-udvidelsen mbstring. Slå udvidelsen '.$help, hsg_missing_extensions_message(['mbstring']));
eq('112: besked med tre udvidelser', 'Webhotellet mangler PHP-udvidelserne gd, mbstring og zip. Slå udvidelserne '.$help, hsg_missing_extensions_message(['gd', 'mbstring', 'zip']));
eq('112: besked med to udvidelser', 'Webhotellet mangler PHP-udvidelserne gd og mbstring. Slå udvidelserne '.$help, hsg_missing_extensions_message(['gd', 'mbstring']));

// Kriterie 121: updateren bruger pakkens egen required_extensions
if (class_exists('ZipArchive')) {
    $zips = [];
    $validate = static function (array $required) use (&$zips): string {
        $zipPath = sys_get_temp_dir().'/hsg-php-krav-'.bin2hex(random_bytes(4)).'.zip';
        $zips[] = $zipPath;
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) return 'ZIP kunne ikke oprettes';
        $zip->addFromString('hsg-package.json', json_encode([
            'product' => 'HSG Administration', 'package_type' => 'distribution', 'version' => '99.0.0',
            'min_php' => '8.1.0', 'required_extensions' => $required,
        ]));
        $zip->close();
        try {
            hsg_update_validate_package($zipPath);
            return 'ingen fejl';
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }
    };
    eq('121: én manglende udvidelse i pakken', 'Opgraderingen kan ikke installeres. Webhotellet mangler PHP-udvidelsen hsg_test_a. Slå udvidelsen '.$help.' Intet er installeret eller ændret.', $validate(['pdo', 'hsg_test_a']));
    $msg = $validate(['hsg_test_b', 'pdo', 'hsg_test_a']);
    t('121: to manglende udvidelser i én besked i manifestets rækkefølge', str_contains($msg, 'PHP-udvidelserne hsg_test_b og hsg_test_a') && str_contains($msg, 'Slå udvidelserne til'), $msg);
    eq('121: tom liste i pakken giver ingen besked om udvidelser', 'Pakken mangler den nødvendige fil app_version.php.', $validate([]));
    foreach ($zips as $z) @unlink($z);
    t('121: test-ZIP-filerne er slettet', array_filter($zips, 'is_file') === []);
} else {
    t('121: ZipArchive findes (zip er en krævet udvidelse)', false);
}

echo "PHP-krav: $passes bestået, $fails fejlet\n";
exit($fails === 0 ? 0 : 1);
