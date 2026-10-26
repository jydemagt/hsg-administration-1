<?php
declare(strict_types=1);
/**
 * Enhedstest af verify_csrf() (ingen database). Kun udvikling – kommer ikke med i release.
 * Spec: docs/specs/2026-10-24-csrf-tom-token.md (blok C, sandhedstabellen).
 *
 *   php tests/csrf_test.php
 *
 * verify_csrf() afslutter med exit, så hvert tilfælde kører i sin egen PHP-proces
 * (filen kalder sig selv med PHP_BINARY og tilfældets nummer). Underprocessen kører med
 * error_reporting=E_ALL og display_errors=1, så advarsler, TypeError og Fatal ender i outputtet.
 *   Afvist:   outputtet er præcis 419-teksten, og svarkoden er 419.
 *   Godtaget: outputtet er præcis markøren, og svarkoden er ikke 419.
 * I begge tilfælde må $_SESSION ikke være ændret af verify_csrf(). Alt andet er en fejl.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

const CSRF_TEST_AFVIST = 'Ugyldig formularsession. Genindlæs siden og prøv igen.';
const CSRF_TEST_GODTAGET = 'HSG-CSRF-TEST: verify_csrf() vendte tilbage';
const CSRF_TEST_SLUT = "\n#HSG-CSRF-TEST-SLUT ";

/**
 * Sandhedstabellen fra spec'en: nummer => [beskrivelse, metode, session, post, forventet].
 * En nøgle, der mangler i session eller post, betyder, at feltet mangler.
 * 'csrf_token' => true betyder, at tokenet oprettes med csrf_token() i en tom session (tilfælde 13).
 */
function csrf_test_cases(): array {
    $tok = bin2hex(random_bytes(24)); // et gyldigt token: 48 hex-tegn som csrf_token()
    $s = ['auth_mode' => 'link', 'user_id' => 7]; // en indlogget session uden token
    return [
        1  => ['session mangler, formular mangler', 'POST', $s, [], 'afvist'],
        2  => ['session mangler, formular tom', 'POST', $s, ['csrf' => ''], 'afvist'],
        3  => ['session tom, formular tom', 'POST', $s + ['csrf' => ''], ['csrf' => ''], 'afvist'],
        4  => ['session tom, formular abc', 'POST', $s + ['csrf' => ''], ['csrf' => 'abc'], 'afvist'],
        5  => ['session mangler, formular abc', 'POST', $s, ['csrf' => 'abc'], 'afvist'],
        6  => ['gyldigt token, samme token', 'POST', $s + ['csrf' => $tok], ['csrf' => $tok], 'godtaget'],
        7  => ['gyldigt token, en anden streng', 'POST', $s + ['csrf' => $tok], ['csrf' => strrev($tok)], 'afvist'],
        8  => ['gyldigt token, formular mangler', 'POST', $s + ['csrf' => $tok], [], 'afvist'],
        9  => ['gyldigt token, formular tom', 'POST', $s + ['csrf' => $tok], ['csrf' => ''], 'afvist'],
        10 => ['gyldigt token, formular er en liste (csrf[]=…)', 'POST', $s + ['csrf' => $tok], ['csrf' => [$tok]], 'afvist'],
        11 => ['session er en liste, formular "Array"', 'POST', $s + ['csrf' => ['x']], ['csrf' => 'Array'], 'afvist'],
        12 => ['session er et tal, formular "123"', 'POST', $s + ['csrf' => 123], ['csrf' => '123'], 'afvist'],
        13 => ['token oprettet af csrf_token() i en tom session, samme token', 'POST', [], [], 'godtaget', 'csrf_token' => true],
        14 => ['GET-anmodning: ingen kontrol', 'GET', $s + ['csrf' => ['x']], ['csrf' => ['y']], 'godtaget'],
    ];
}

// Underproces: kør ét tilfælde og skriv kun det, verify_csrf() skriver (plus slutlinjen fra shutdown).
if (isset($argv[1]) && $argv[1] === '--tilfaelde') {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
    $case = csrf_test_cases()[(int)($argv[2] ?? 0)] ?? null;
    if ($case === null) { echo 'Ukendt tilfælde'; exit(2); }
    require dirname(__DIR__).'/functions.php';
    $_SERVER['REQUEST_METHOD'] = $case[1];
    $_SESSION = $case[2];
    $_POST = $case[3];
    if (!empty($case['csrf_token'])) $_POST['csrf'] = csrf_token();
    $before = $_SESSION;
    register_shutdown_function(static function () use ($before): void {
        $code = http_response_code();
        echo CSRF_TEST_SLUT.'status='.($code === false ? '-' : (string)$code).' session='.(($_SESSION ?? null) === $before ? 'uændret' : 'ændret');
    });
    verify_csrf();
    echo CSRF_TEST_GODTAGET;
    exit(0);
}

// Hovedproces: kør alle tilfælde hver for sig og sammenlign outputtet præcist.
$fails = 0; $passes = 0;
foreach (csrf_test_cases() as $n => $case) {
    [$desc, , , , $expected] = $case;
    $cmd = [PHP_BINARY, '-d', 'error_reporting=E_ALL', '-d', 'display_errors=1', '-d', 'display_startup_errors=1',
            '-d', 'log_errors=0', '-d', 'html_errors=0', __FILE__, '--tilfaelde', (string)$n];
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);
    if (!is_resource($proc)) { $fails++; echo "FEJL: tilfælde $n ($desc): kunne ikke starte PHP\n"; continue; }
    $out = (string)stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $exit = proc_close($proc);

    $pos = strrpos($out, CSRF_TEST_SLUT);
    $body = $pos === false ? $out : substr($out, 0, $pos);
    $tail = $pos === false ? '' : substr($out, $pos + strlen(CSRF_TEST_SLUT));
    if ($expected === 'afvist') {
        $ok = $body === CSRF_TEST_AFVIST && $tail === 'status=419 session=uændret';
    } else {
        // Ingen svarkode er sat: CLI giver false ("-"). 200 godtages også, hvis en PHP-version melder standardkoden.
        $ok = $body === CSRF_TEST_GODTAGET && in_array($tail, ['status=- session=uændret', 'status=200 session=uændret'], true);
    }
    $ok = $ok && $exit === 0 && !preg_match('/Warning|Notice|Deprecated|TypeError|Fatal|Uncaught/i', $out);
    if ($ok) { $passes++; continue; }
    $fails++;
    $shown = json_encode(substr($out, 0, 300), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE).(strlen($out) > 300 ? ' …' : '');
    echo "FEJL: tilfælde $n ($desc): forventet $expected, fik exit $exit og output $shown\n";
}

echo "CSRF: $passes bestået, $fails fejlet\n";
exit($fails === 0 ? 0 : 1);
