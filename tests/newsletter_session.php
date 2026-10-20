<?php
declare(strict_types=1);
/**
 * Hjælper til de funktionelle test af importens forhåndsvisning i sessionen. Kun udvikling/CI – kommer ikke med i release.
 *
 *   php tests/newsletter_session.php show <cookie-fil>         -> "nej", eller "ja <de 8 første tegn af tokenet>"
 *   php tests/newsletter_session.php age <cookie-fil> <sek>    -> gør forhåndsvisningen <sek> sekunder gammel ("ja" / "nej")
 *
 * <cookie-fil> er den fil, curl skriver med -c (Netscape-format). Hjælperen rører kun sessionsfilen for det
 * PHPSESSID, der står i den, og bruger samme session.save_path som php -S (begge bruger CLI-indstillingerne).
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

[, $cmd, $jar, $secs] = $argv + [null, '', '', '0'];
if (!in_array($cmd, ['show', 'age'], true) || !is_file((string)$jar)) {
    fwrite(STDERR, "Brug: php tests/newsletter_session.php show|age <cookie-fil> [sekunder]\n");
    exit(2);
}
if (!preg_match('/PHPSESSID\s+([A-Za-z0-9,-]+)/', (string)file_get_contents($jar), $m)) { echo "nej\n"; exit(0); }

$path = (string)ini_get('session.save_path');
if (str_contains($path, ';')) $path = substr($path, strrpos($path, ';') + 1);
if ($path === '') $path = sys_get_temp_dir();
session_save_path($path);
session_id($m[1]);
session_start(['use_cookies' => 0, 'use_strict_mode' => 0, 'cache_limiter' => '', 'read_and_close' => $cmd === 'show']);

$p = $_SESSION['newsletter_import'] ?? null;
if ($cmd === 'age' && is_array($p)) {
    $_SESSION['newsletter_import']['created'] = time() - (int)$secs;
    session_write_close();
}
echo is_array($p) ? 'ja'.($cmd === 'show' ? ' '.substr((string)($p['token'] ?? ''), 0, 8) : '') : 'nej', "\n";
