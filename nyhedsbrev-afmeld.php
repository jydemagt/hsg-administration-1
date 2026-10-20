<?php
declare(strict_types=1);
/**
 * Offentlig afmelding af nyhedsbrevet (uden login).
 *
 * - GET ændrer aldrig noget (linkscannere som Outlook Safe Links følger GET-links).
 * - POST fra formularen kræver CSRF og afmelder med via='link'.
 * - POST med body "List-Unsubscribe=One-Click" (RFC 8058) afmelder uden CSRF med via='one_click'.
 *   Undtagelsen fra CSRF-reglen er godkendt af Michael 19-10-2026: kun denne side, kun med et
 *   gyldigt token og kun til afmelding.
 * - Siden virker også, når modulet er deaktiveret – det skal altid være muligt at afmelde sig.
 * - Siden inkluderer ikke auth.php (som ville afvise uden login).
 */
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function hsg_newsletter_public_page(int $code, string $heading, string $bodyHtml): never {
    http_response_code($code);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="da"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        .'<meta name="robots" content="noindex,nofollow"><meta name="referrer" content="no-referrer">'
        .'<link rel="stylesheet" href="assets/style.css"><title>'.htmlspecialchars($heading, ENT_QUOTES, 'UTF-8').' · HSG Whisky ApS</title></head>'
        .'<body><main class="public"><div class="card narrow"><p class="muted" style="margin-top:0"><strong>HSG Whisky ApS</strong></p>'
        .'<h1>'.htmlspecialchars($heading, ENT_QUOTES, 'UTF-8').'</h1>'.$bodyHtml.'</div></main></body></html>';
    exit;
}

// Vedligeholdelsestilstand under en selvopgradering (samme regel som auth.php: en lås ældre end 30 minutter ignoreres).
$maintenanceFile = __DIR__.'/.hsg-maintenance';
if (is_file($maintenanceFile) && time() - (int)@filemtime($maintenanceFile) <= 1800) {
    header('Retry-After: 60');
    hsg_newsletter_public_page(503, 'Prøv igen om lidt', '<p>Systemet opdateres lige nu. Prøv linket igen om et øjeblik.</p>');
}

require_once __DIR__.'/session.php';
require_once __DIR__.'/functions.php';
require_once __DIR__.'/db.php';
require_once __DIR__.'/core/settings.php';
require_once __DIR__.'/core/audit.php';
require_once __DIR__.'/core/access_links.php';
require_once __DIR__.'/core/newsletter.php';

$company = trim((string)setting_get($pdo, 'company_name', 'HSG Whisky ApS')) ?: 'HSG Whisky ApS';
$invalidHtml = '<p>Linket er ugyldigt eller udløbet. Kontakt '.h($company).', hvis du vil afmeldes.</p>';

// Kun t (64 hex-tegn) accepteres. Formatet kontrolleres, før databasen spørges.
$token = $_GET['t'] ?? '';
if (!is_string($token) || !hsg_newsletter_is_token_format($token) || !db_table_exists($pdo, 'hsg_newsletter_recipients')) {
    hsg_newsletter_public_page(404, 'Afmeld nyhedsbrevet', $invalidHtml);
}
$recipient = hsg_newsletter_find_by_token($pdo, $token);
if ($recipient === null) {
    hsg_newsletter_public_page(404, 'Afmeld nyhedsbrevet', $invalidHtml);
}
$id = (int)$recipient['id'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    // RFC 8058 ét-klik-afmelding fra mailprogrammet: ingen CSRF, ingen session, svar "Afmeldt".
    $oneClick = ($_POST['List-Unsubscribe'] ?? null) === 'One-Click'
        || trim((string)file_get_contents('php://input')) === 'List-Unsubscribe=One-Click';
    if ($oneClick) {
        hsg_newsletter_unsubscribe($pdo, $id, 'one_click');
        http_response_code(200);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Afmeldt';
        exit;
    }

    // verify_csrf() godtager en tom formular, hvis sessionen heller ikke har et token (fx en POST
    // uden cookies). Her kræves derfor også, at både formular og session har et token.
    $postedCsrf = $_POST['csrf'] ?? '';
    if (!is_string($postedCsrf) || $postedCsrf === '' || (string)($_SESSION['csrf'] ?? '') === '' || !hash_equals((string)$_SESSION['csrf'], $postedCsrf)) {
        hsg_newsletter_public_page(419, 'Afmeld nyhedsbrevet', '<p>Formularen er udløbet. <a href="nyhedsbrev-afmeld.php?t='.h($token).'">Åbn siden igen</a>, og tryk på knappen.</p>');
    }
    verify_csrf();
    $changed = hsg_newsletter_unsubscribe($pdo, $id, 'link');
    if (!$changed) {
        hsg_newsletter_public_page(200, 'Afmeld nyhedsbrevet', '<p>Du er allerede afmeldt nyhedsbrevet.</p>');
    }
    $contact = trim((string)setting_get($pdo, 'newsletter_reply_to', '')) ?: trim((string)setting_get($pdo, 'newsletter_from_email', ''));
    $regret = $contact !== '' && hsg_newsletter_is_valid_email($contact)
        ? '<p class="muted">Har du afmeldt ved en fejl, så skriv til '.h($contact).'.</p>'
        : '<p class="muted">Har du afmeldt ved en fejl, så kontakt '.h($company).'.</p>';
    hsg_newsletter_public_page(200, 'Afmeld nyhedsbrevet', '<p><strong>Du er nu afmeldt.</strong> Du modtager ikke flere nyhedsbreve fra '.h($company).'. Afmeldingen gælder med det samme.</p>'.$regret);
}

// GET (og HEAD) viser kun en bekræftelse og ændrer intet.
if ($recipient['status'] === 'unsubscribed') {
    hsg_newsletter_public_page(200, 'Afmeld nyhedsbrevet', '<p>Du er allerede afmeldt nyhedsbrevet.</p>');
}
$body = '<p>Vil du afmelde '.h(hsg_newsletter_mask_email((string)$recipient['email'])).' fra nyhedsbrevet fra '.h($company).'?</p>'
    .'<form method="post" action="nyhedsbrev-afmeld.php?t='.h($token).'">'.csrf_field()
    .'<button type="submit" style="width:100%">Ja, afmeld mig</button></form>';
hsg_newsletter_public_page(200, 'Afmeld nyhedsbrevet', $body);
