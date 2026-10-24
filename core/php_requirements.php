<?php
declare(strict_types=1);

/**
 * Krævede PHP-udvidelser – én fælles liste.
 *
 * Bruges af tools/build-release.php (CLI), install.php, admin.php og core/updater.php.
 * Filen må kun indeholde funktionsdefinitioner: ingen require, ingen session,
 * ingen database og intet output, så den kan bruges fra CLI og før config.php findes.
 */

/** De krævede udvidelser i fast rækkefølge (den tidligere liste plus de nye sidst). */
function hsg_required_extensions(): array {
    return ['pdo', 'pdo_mysql', 'zip', 'json', 'gd', 'curl', 'mbstring', 'openssl'];
}

/**
 * De udvidelser fra $required (som standard hsg_required_extensions()), som ikke er indlæst,
 * i samme rækkefølge. Tomme navne springes over, ligesom værdier der hverken er tekst eller tal
 * (fx en liste inde i pakkemanifestets required_extensions).
 */
function hsg_missing_extensions(?array $required = null): array {
    $missing = [];
    foreach ($required ?? hsg_required_extensions() as $ext) {
        $ext = is_scalar($ext) ? (string)$ext : '';
        if ($ext !== '' && !extension_loaded($ext)) $missing[] = $ext;
    }
    return array_values($missing);
}

/** Dansk forklaring til administratoren. Tom streng, når intet mangler. */
function hsg_missing_extensions_message(array $missing): string {
    $missing = array_values($missing);
    if ($missing === []) return '';
    $help = ' til i webhotellets kontrolpanel under PHP-indstillinger, eller bed webhotellets support om det.';
    if (count($missing) === 1) {
        return 'Webhotellet mangler PHP-udvidelsen '.$missing[0].'. Slå udvidelsen'.$help;
    }
    $last = array_pop($missing);
    return 'Webhotellet mangler PHP-udvidelserne '.implode(', ', $missing).' og '.$last.'. Slå udvidelserne'.$help;
}
