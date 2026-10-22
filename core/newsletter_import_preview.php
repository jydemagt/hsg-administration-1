<?php
declare(strict_types=1);

/**
 * Nyhedsbrev: importens forhåndsvisning i sessionen. Ligger i sin egen lille fil, så modules/newsletter/hooks.php
 * kan rydde en udløbet forhåndsvisning op på alle sider uden at indlæse hele core/newsletter.php.
 *
 * Forhåndsvisningen ligger kun i sessionen og aldrig i databasen (Kims fil kan indeholde andre persondata).
 * Den slettes, når importen er gennemført eller annulleret, og når den er udløbet.
 */

const HSG_NEWSLETTER_PREVIEW_TTL = 7200;

function hsg_newsletter_preview_create(array $data): string {
    $token = bin2hex(random_bytes(16));
    $_SESSION['newsletter_import'] = ['token' => $token, 'created' => time()] + $data;
    return $token;
}

/** Sletter en udløbet forhåndsvisning uanset token, så filens indhold ikke bliver liggende i sessionen. */
function hsg_newsletter_preview_expire(): void {
    if (!array_key_exists('newsletter_import', $_SESSION ?? [])) return;
    $p = $_SESSION['newsletter_import'];
    if (!is_array($p) || time() - (int)($p['created'] ?? 0) > HSG_NEWSLETTER_PREVIEW_TTL) unset($_SESSION['newsletter_import']);
}

function hsg_newsletter_preview_load(string $token): ?array {
    hsg_newsletter_preview_expire();
    $p = $_SESSION['newsletter_import'] ?? null;
    if (!is_array($p) || $token === '' || !hash_equals((string)($p['token'] ?? ''), $token)) return null;
    return $p;
}

function hsg_newsletter_preview_save(array $preview): void {
    $_SESSION['newsletter_import'] = $preview;
}

function hsg_newsletter_preview_clear(): void {
    unset($_SESSION['newsletter_import']);
}
