<?php
declare(strict_types=1);
/**
 * Test af nyhedsbrevets rene funktioner (ingen database). Kun udvikling – kommer ikke med i release.
 *
 *   php tests/newsletter_test.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = dirname(__DIR__);
require_once $root.'/functions.php';
require_once $root.'/core/modules.php';
require_once $root.'/core/permissions.php';
require_once $root.'/core/newsletter.php';

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

// Normalisering, opdeling og gyldighed
eq('normalisering med mellemrum og store bogstaver', 'kim@firma.dk', hsg_newsletter_normalize_email(' Kim@Firma.DK '));
eq('normalisering af mailto: og <>', 'kim@x.dk', hsg_newsletter_normalize_email('<mailto:Kim@X.dk>'));
eq('normalisering af hårdt mellemrum', 'kim@x.dk', hsg_newsletter_normalize_email("\u{00A0}kim@x.dk\u{00A0}"));
eq('to adresser i én celle', ['a@x.dk', 'b@y.dk'], hsg_newsletter_split_emails('a@x.dk; b@y.dk'));
eq('komma og mellemrum som skilletegn', ['a@x.dk', 'b@y.dk', 'c@z.dk'], hsg_newsletter_split_emails('a@x.dk,b@y.dk  c@z.dk'));
eq('tom celle', [], hsg_newsletter_split_emails('   '));
t('gyldig adresse', hsg_newsletter_is_valid_email('kim@firma.dk'));
t('kim(at)firma.dk er ugyldig', !hsg_newsletter_is_valid_email(hsg_newsletter_normalize_email('kim(at)firma.dk')));
t('kim@ er ugyldig', !hsg_newsletter_is_valid_email('kim@'));
t('kim@firma er ugyldig (intet punktum i domænet)', !hsg_newsletter_is_valid_email('kim@firma'));
t('IDN-domæne er ugyldigt', !hsg_newsletter_is_valid_email('kim@æble.dk'));
t('over 190 tegn er ugyldig', !hsg_newsletter_is_valid_email(str_repeat('a', 180).'@firma.dk'));
eq('hash er SHA-256 af normaliseret adresse', hash('sha256', 'kim@firma.dk'), hsg_newsletter_email_hash(hsg_newsletter_normalize_email(' KIM@firma.dk')));

// Maskering, CSV og tekster
eq('maskering', 'k***@firma.dk', hsg_newsletter_mask_email('kim@firma.dk'));
eq('maskering uden @', '***', hsg_newsletter_mask_email(''));
foreach (['=CMD()', '+45', '-1', '@SUM(A1)'] as $v) eq('CSV-formel '.$v, "'".$v, hsg_newsletter_csv_safe($v));
eq('CSV almindelig tekst', 'Kim Hansen', hsg_newsletter_csv_safe('Kim Hansen'));
$csv = hsg_newsletter_csv([['Mail', 'Navn'], ['kim@firma.dk', '=CMD()']]);
t('CSV starter med UTF-8 BOM', str_starts_with($csv, "\xEF\xBB\xBF"));
t('CSV bruger semikolon', str_contains($csv, 'Mail;Navn'));
t("CSV har '=CMD()", str_contains($csv, "'=CMD()"));
eq('Windows-1252 omsættes til UTF-8', 'Søren', hsg_newsletter_clean_cell("S\xF8ren"));
eq('BOM fjernes', 'E-mail', hsg_newsletter_clean_cell("\xEF\xBB\xBFE-mail"));
t('filnavn i audit indeholder ikke @', !str_contains(hsg_newsletter_safe_filename('liste kim@firma.dk.xlsx'), '@'));
eq('kolonnebogstav A', 'A', hsg_newsletter_col_letter(0));
eq('kolonnebogstav D', 'D', hsg_newsletter_col_letter(3));
eq('kolonnebogstav AA', 'AA', hsg_newsletter_col_letter(26));
t('token-format gyldigt', hsg_newsletter_is_token_format(str_repeat('ab', 32)));
t('token for kort', !hsg_newsletter_is_token_format(str_repeat('a', 63)));
t('token for langt', !hsg_newsletter_is_token_format(str_repeat('a', 65)));
t('token med ikke-hex', !hsg_newsletter_is_token_format(str_repeat('g', 64)));
t('token med store bogstaver afvises', !hsg_newsletter_is_token_format(str_repeat('A', 64)));
$tabs = hsg_newsletter_tabs_html('recipients');
t('faner i trin 1: Modtagere og Import', str_contains($tabs, 'Modtagere') && str_contains($tabs, 'Import'));
t('faner i trin 1: ingen Udsendelser/Indstillinger', !str_contains($tabs, 'Udsendelser') && !str_contains($tabs, 'Indstillinger'));
t('aktiv fane har klassen active', str_contains($tabs, 'class="active"'));

// Forhandler/kunde (antagelse 2)
eq('Forhandler', 'dealer', hsg_newsletter_segment_from_value('Forhandler'));
eq('FORHANDLER A/S', 'dealer', hsg_newsletter_segment_from_value(' FORHANDLER A/S'));
eq('Kunde', 'customer', hsg_newsletter_segment_from_value('Kunde'));
eq('tom type', 'customer', hsg_newsletter_segment_from_value(''));
eq('Ikke forhandler', 'customer', hsg_newsletter_segment_from_value('Ikke forhandler'));

// Standardadgang for login-brugere (admin_default_access)
t('newsletter: ingen adgang uden række', hsg_admin_module_default_access('newsletter') === false);
t('catalog: adgang uden række som før', hsg_admin_module_default_access('catalog') === true);
t('ukendt menupunkt: adgang som før', hsg_admin_module_default_access('users') === true);

// Kolonnevalg
$noHeader = [['', '', '', 'a@x.dk'], ['', '', '', 'b@y.dk'], ['', '', '', 'kim(at)firma.dk'], ['', '', '', '']];
$c = hsg_newsletter_detect_columns($noHeader);
eq('fil uden overskrifter: ingen overskriftsrække', null, $c['header_row']);
eq('fil uden overskrifter: kolonne D', 3, $c['email']);
$a = hsg_newsletter_analyse($noHeader, $c, 'customer', []);
$n = hsg_newsletter_analysis_counts($a);
eq('kriterie 6: nye', 2, $n['new']);
eq('kriterie 6: ugyldige', 1, $n['invalid']);
eq('kriterie 6: rækkenummer for ugyldig', 3, $a['invalid'][0]['row']);

$withHeader = [['Navn', 'Firma', 'Telefon', 'E-mail', 'Mail'], ['Kim', 'Firma ApS', '1234', 'kim@firma.dk', 'k@privat.dk']];
$c = hsg_newsletter_detect_columns($withHeader);
eq('overskrift i række 1', 0, $c['header_row']);
eq('E-mail genkendes', 3, $c['email']);
eq('Navn genkendes', 0, $c['name']);
eq('Firma genkendes', 1, $c['company']);
$c['email'] = 4; // ændret manuelt til "Mail"
$saved = hsg_newsletter_columns_to_saved($withHeader[0], $c);
eq('husket valg: mail', 'email', $saved['mail'] ?? null);
eq('husket valg: e-mail ikke brugt', '', $saved['email'] ?? null);
$c2 = hsg_newsletter_detect_columns([['Navn', 'Firma', 'Telefon', 'E-mail', 'Mail'], ['X', '', '', 'x@x.dk', 'y@y.dk']], $saved);
eq('kriterie 15: "Mail" er valgt på forhånd', 4, $c2['email']);
eq('kriterie 15: navn huskes', 0, $c2['name']);

// Kriterie 7: dublet med anden skrivemåde
$a = hsg_newsletter_analyse([['', '', '', ' Kim@Firma.DK '], ['', '', '', 'kim@firma.dk']], ['header_row' => null, 'email' => 3, 'name' => null, 'company' => null, 'segment' => null], 'customer', []);
eq('kriterie 7: én modtager', 1, count($a['entries']));
eq('kriterie 7: normaliseret adresse', 'kim@firma.dk', reset($a['entries'])['email']);
eq('kriterie 7: dubletter i filen', 1, $a['duplicates']);

// Kriterie 8: to adresser i én celle
$a = hsg_newsletter_analyse([['', '', '', 'a@x.dk; b@y.dk']], ['header_row' => null, 'email' => 3, 'name' => null, 'company' => null, 'segment' => null], 'customer', []);
eq('kriterie 8: to nye', 2, count($a['new']));

// Kriterie 9: ugyldige med rækkenummer, tomme celler tæller ikke
$rows9 = [['E-mail'], ['kim(at)firma.dk'], [''], ['kim@'], ['kim@firma'], ['ok@firma.dk']];
$c9 = hsg_newsletter_detect_columns($rows9);
eq('kriterie 9: mailkolonne A', 0, $c9['email']);
$a = hsg_newsletter_analyse($rows9, $c9, 'customer', []);
eq('kriterie 9: tre ugyldige', 3, count($a['invalid']));
eq('kriterie 9: rækkenumre', [2, 4, 5], array_column($a['invalid'], 'row'));
eq('kriterie 9: én ny', 1, count($a['new']));

// Kriterie 10-12 mod eksisterende modtagere
$h = static fn(string $e): string => hsg_newsletter_email_hash($e);
$existing = [
    $h('kim@firma.dk') => ['id' => 1, 'status' => 'active', 'segment' => 'customer', 'name' => 'Kim', 'company' => null],
    $h('afmeldt@firma.dk') => ['id' => 2, 'status' => 'unsubscribed', 'segment' => 'customer', 'name' => null, 'company' => null],
    $h('stop@firma.dk') => ['id' => 3, 'status' => 'stopped', 'segment' => 'customer', 'name' => null, 'company' => null],
    $h('slettet@firma.dk') => ['id' => 4, 'status' => 'deleted', 'segment' => 'customer', 'name' => null, 'company' => null],
    $h('mangler@firma.dk') => ['id' => 5, 'status' => 'active', 'segment' => 'customer', 'name' => null, 'company' => null],
];
$cols = ['header_row' => null, 'email' => 0, 'name' => null, 'company' => null, 'segment' => null];
$a = hsg_newsletter_analyse([['kim@firma.dk'], ['AFMELDT@Firma.dk'], ['stop@firma.dk'], ['Slettet@firma.dk']], $cols, 'customer', $existing);
eq('kriterie 10: 0 nye ved genimport', 0, count($a['new']));
eq('kriterie 10: uændret', 1, count($a['unchanged']));
eq('kriterie 11: tre springes over', ['unsubscribed', 'stopped', 'deleted'], array_column($a['skipped'], 'status'));
eq('aktive der ikke står i filen', 1, $a['missing_active']);
$a = hsg_newsletter_analyse([['kim@firma.dk']], $cols, 'dealer', $existing);
eq('kriterie 12: typeskift', [['row' => 1, 'email' => 'kim@firma.dk', 'from' => 'customer', 'to' => 'dealer']], $a['type_changes']);
eq('kriterie 12: opdateres', 1, count($a['update']));

// Kriterie 13: konflikt med "Brug kolonne"
$rows13 = [['E-mail', 'Type'], ['kim@firma.dk', 'Forhandler'], ['KIM@firma.dk', 'Kunde'], ['f@firma.dk', 'forhandler']];
$c13 = hsg_newsletter_detect_columns($rows13);
$c13['segment'] = 1;
$a = hsg_newsletter_analyse($rows13, $c13, 'column', []);
eq('kriterie 13: én konflikt', 1, count($a['conflicts']));
eq('kriterie 13: konflikt bliver kunde', 'customer', $a['entries'][$h('kim@firma.dk')]['segment']);
eq('kriterie 13: forhandler fra kolonne', 'dealer', $a['entries'][$h('f@firma.dk')]['segment']);
$c13['segment'] = null;
t('"Brug kolonne" uden type-kolonne markeres', hsg_newsletter_analyse($rows13, $c13, 'column', [])['segment_missing'] === true);

// Navn og firma: escape og længde
$a = hsg_newsletter_analyse([['Navn', 'E-mail'], ['<script>alert(1)</script>', 'x@x.dk']], ['header_row' => 0, 'email' => 1, 'name' => 0, 'company' => null, 'segment' => null], 'customer', []);
eq('kriterie 28: navn escapes med h()', '&lt;script&gt;alert(1)&lt;/script&gt;', h(reset($a['entries'])['name']));
eq('navn afkortes til 180 tegn', 180, preg_match_all('/./su', hsg_newsletter_clean_text(str_repeat('æ', 300))));

// Eksportrækker
$rows = hsg_newsletter_export_rows(['email' => 'kim@firma.dk', 'name' => '=CMD()', 'company' => '', 'segment' => 'dealer', 'status' => 'active', 'source' => 'manual', 'created_at' => '2026-10-20 10:42:00', 'last_import_at' => null, 'unsubscribed_at' => null, 'unsubscribe_via' => null]);
eq('eksport: kolonner', ['Mail', 'Navn', 'Firma', 'Type', 'Status', 'Kilde', 'Oprettet', 'Sidst set i import', 'Afmeldt', 'Afmeldt hvordan'], $rows[0]);
t('eksport: navn som formel neutraliseres', str_contains(hsg_newsletter_csv($rows), "'=CMD()"));

echo "Nyhedsbrev: $passes bestået, $fails fejlet\n";
exit($fails === 0 ? 0 : 1);
