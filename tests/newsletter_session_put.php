<?php
declare(strict_types=1);
/**
 * Hjælper til tests/newsletter_post_input.sh: lægger en importforhåndsvisning i (eller fjerner den fra) en sessions
 * fil, så oprydningen kan prøves med en session, som ikke selv kan lave en forhåndsvisning (fx en linkbruger).
 * Kun udvikling/CI – kommer ikke med i release.
 *
 *   php tests/newsletter_session_put.php put  <cookie-fil> <alder-i-sekunder>   -> "put"
 *   php tests/newsletter_session_put.php drop <cookie-fil>                      -> "drop"
 *
 * <cookie-fil> er den fil, curl skriver med -c (Netscape-format). Som tests/newsletter_session.php rører hjælperen
 * kun sessionsfilen for det PHPSESSID, der står i den, og bruger samme session.save_path som php -S.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

[, $cmd, $jar, $secs] = $argv + [null, '', '', '0'];
if (!in_array($cmd, ['put', 'drop'], true) || !is_file((string)$jar)) {
    fwrite(STDERR, "Brug: php tests/newsletter_session_put.php put|drop <cookie-fil> [alder-i-sekunder]\n");
    exit(2);
}
if (!preg_match('/PHPSESSID\s+([A-Za-z0-9,-]+)/', (string)file_get_contents($jar), $m)) { fwrite(STDERR, "ingen PHPSESSID i cookie-filen\n"); exit(1); }

$path = (string)ini_get('session.save_path');
if (str_contains($path, ';')) $path = substr($path, strrpos($path, ';') + 1);
if ($path === '') $path = sys_get_temp_dir();
session_save_path($path);
session_id($m[1]);
session_start(['use_cookies' => 0, 'use_strict_mode' => 0, 'cache_limiter' => '']);

if ($cmd === 'put') {
    // Samme form som core/newsletter.php: hsg_newsletter_preview_create() + en lille arkopsætning.
    $_SESSION['newsletter_import'] = [
        'token' => bin2hex(random_bytes(16)), 'created' => time() - (int)$secs,
        'sheet' => 'Ark1', 'sheets' => ['Ark1' => [['E-mail'], ['put@x.dk']]], 'rownums' => ['Ark1' => [1, 2]],
        'cols' => ['email' => 0, 'name' => null, 'company' => null, 'segment' => null, 'header_row' => 0],
        'mode' => 'customer', 'filename' => 'put.csv',
    ];
} else {
    unset($_SESSION['newsletter_import']);
}
session_write_close();
echo $cmd, "\n";
