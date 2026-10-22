<?php
declare(strict_types=1);
/**
 * Hjælper til tests/newsletter_post_input.sh: indlæser auth.php som en almindelig side (med sessionen fra en cookie-fil)
 * og skriver, om core/newsletter.php blev indlæst, og om importens forhåndsvisning stadig ligger i sessionen.
 * Kun udvikling/CI – kommer ikke med i release.
 *
 *   php tests/newsletter_probe.php <cookie-fil>   -> "PROBE nl=<ja|nej> preview=<ja|nej>"
 *
 * Kræver config.php (tests/run.sh har lige skrevet den). Sessionen gemmes som ved et almindeligt sidekald.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$jar = (string)($argv[1] ?? '');
if (!is_file($jar) || !preg_match('/PHPSESSID\s+([A-Za-z0-9,-]+)/', (string)file_get_contents($jar), $m)) {
    fwrite(STDERR, "Brug: php tests/newsletter_probe.php <cookie-fil med PHPSESSID>\n");
    exit(2);
}
// Værn som i tests/newsletter_sql.php: auth.php kører migrationer, så kun mod testdatabasen fra tests/run.sh.
$c = is_file(dirname(__DIR__).'/config.php') ? require dirname(__DIR__).'/config.php' : [];
$testDb = (string)getenv('HSG_TEST_DB_NAME');
if ($testDb === '' || (string)($c['db_name'] ?? '') !== $testDb) {
    fwrite(STDERR, "Afvist: config.php peger ikke på testdatabasen i HSG_TEST_DB_NAME.\n");
    exit(3);
}
chdir(dirname(__DIR__));
$_COOKIE['PHPSESSID'] = $m[1];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/probe.php';
$_SERVER['REQUEST_URI'] = '/probe.php';

// Shutdown-funktioner kører, før sessionen skrives, så $_SESSION kan aflæses her, også hvis auth.php kalder exit.
register_shutdown_function(static function (): void {
    // nl = om hele core/newsletter.php blev indlæst (hooks.php må kun indlæse den lille core/newsletter_import_preview.php)
    $nl = in_array(realpath(dirname(__DIR__).'/core/newsletter.php'), array_map('realpath', get_included_files()), true);
    echo "\nPROBE nl=", $nl ? 'ja' : 'nej',
        ' preview=', isset($_SESSION['newsletter_import']) ? 'ja' : 'nej', "\n";
});
require dirname(__DIR__).'/auth.php';
