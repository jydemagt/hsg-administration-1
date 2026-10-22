<?php
declare(strict_types=1);
/**
 * Lille hjælper til tests/newsletter_funktionel.sh: kører ét SQL-udtryk mod testdatabasen
 * (config.php, som tests/run.sh lige har skrevet) og skriver rækkerne ud, kolonner adskilt af tabulator.
 * Kun udvikling – kommer ikke med i release.
 *
 *   php tests/newsletter_sql.php "SELECT COUNT(*) FROM hsg_newsletter_recipients"
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$configFile = dirname(__DIR__).'/config.php';
if (!is_file($configFile) || empty($argv[1])) { fwrite(STDERR, "Brug: php tests/newsletter_sql.php \"SQL\" (kræver config.php)\n"); exit(2); }
$c = require $configFile;
// Værn: hjælperen sletter og ændrer data, så den må kun ramme testdatabasen fra tests/run.sh.
$testDb = (string)getenv('HSG_TEST_DB_NAME');
if ($testDb === '' || (string)($c['db_name'] ?? '') !== $testDb) {
    fwrite(STDERR, "Afvist: config.php peger ikke på testdatabasen i HSG_TEST_DB_NAME.\n");
    exit(3);
}
$pdo = new PDO('mysql:host='.$c['db_host'].';dbname='.$c['db_name'].';charset=utf8mb4', $c['db_user'], $c['db_pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_NUM,
]);
$st = $pdo->query($argv[1]);
if ($st->columnCount() > 0) {
    foreach ($st->fetchAll() as $row) echo implode("\t", array_map(static fn($v): string => $v === null ? 'NULL' : (string)$v, $row)), "\n";
}
