<?php
declare(strict_types=1);
/**
 * Test af nyhedsbrevets rene funktioner (ingen database). Kun udvikling – kommer ikke med i release.
 *
 *   php tests/newsletter_test.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('memory_limit', '512M'); // zip-bombe-testen bygger et ark på 30 MB

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

// En række med en mailadresse er aldrig en overskriftsrække (fx en kunde ved navn "Firma")
$c = hsg_newsletter_detect_columns([['Kim', 'Firma', 'x', 'a@x.dk'], ['', '', '', 'b@y.dk']]);
eq('datarække med "Firma" er ikke overskrift', null, $c['header_row']);
// Mailkolonnen genkendes, selv om "E-mail" ikke blev brugt sidst, når den huskede "Mail" mangler
$c = hsg_newsletter_detect_columns([['Navn', 'E-mail'], ['Pia', 'pia@x.dk']], ['navn' => 'name', 'email' => '', 'mail' => 'email']);
eq('mailkolonne falder ikke tilbage til D', 1, $c['email']);
eq('navn fra husket valg', 0, $c['name']);
// Rækkenumre fra filen bruges, når de findes (tomme rækker i xlsx springes over af læseren)
$a = hsg_newsletter_analyse([['E-mail'], ['kim(at)firma.dk'], ['kim@']], ['header_row' => 0, 'email' => 0, 'name' => null, 'company' => null, 'segment' => null], 'customer', [], [1, 2, 4]);
eq('rækkenumre fra filen', [2, 4], array_column($a['invalid'], 'row'));
$cs = hsg_newsletter_clean_sheets(['Ark 1' => [['E-mail'], ['a@x.dk'], ['', ''], ['']]], ['Ark 1' => [1, 3, 7, 9]]);
eq('rensning fjerner tomme rækker i slutningen', [['E-mail'], ['a@x.dk']], $cs['sheets']['Ark 1']);
eq('rensning bevarer rækkenumre', [1, 3], $cs['rownums']['Ark 1']);

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

// Input fra $_POST/$_GET: felter sendt som liste (fx mode[]=x) giver standardværdien
eq('input: tekst', 'dealer', hsg_newsletter_input(['mode' => 'dealer'], 'mode', 'customer'));
eq('input: mangler', 'customer', hsg_newsletter_input([], 'mode', 'customer'));
eq('input: liste giver standardværdien', 'customer', hsg_newsletter_input(['mode' => ['x']], 'mode', 'customer'));
eq('input: tom tekst bevares', '', hsg_newsletter_input(['email' => ''], 'email', 'x'));
eq('id: cifre', 42, hsg_newsletter_input_id(['id' => '42'], 'id'));
eq('id: liste (id[]=5) giver 0 og ikke 1', 0, hsg_newsletter_input_id(['id' => ['5']], 'id'));
eq('id: tekst og negative tal giver 0', [0, 0, 0], [hsg_newsletter_input_id(['id' => 'abc'], 'id'), hsg_newsletter_input_id(['id' => '-3'], 'id'), hsg_newsletter_input_id(['id' => '12abc'], 'id')]);
eq('id: mangler', 0, hsg_newsletter_input_id([], 'id'));
t('liste i formularen opdages', hsg_newsletter_has_list(['action' => 'update', 'name' => ['x']]) && hsg_newsletter_has_list(['id' => ['a' => ['b' => '1']]]));
t('almindelig formular har ingen liste', !hsg_newsletter_has_list(['action' => 'update', 'name' => 'Kim', 'self_requested' => '1']) && !hsg_newsletter_has_list([]));

// Grænser i importen (reviewet af trin 1, BØR 1 og 2)
function nl_throws(callable $fn): string {
    try { $fn(); return ''; } catch (Throwable $e) { return $e->getMessage(); }
}
eq('celle afkortes midt i et ord: "…" sættes direkte på ordet', str_repeat('x', 318).'…', hsg_newsletter_cap_cell(str_repeat('x', 400)));
eq('celle på præcis 320 tegn afkortes ikke', str_repeat('x', 320), hsg_newsletter_cap_cell(str_repeat('x', 320)));
$long = hsg_newsletter_cap_cell(str_repeat('æ', 5000));
t('lang celle med æ: højst 320 tegn og gyldig UTF-8', $long === str_repeat('æ', 318).'…' && preg_match('//u', $long) === 1);
eq('lang Windows-1252-celle omsættes og afkortes', str_repeat('ø', 318).'…', hsg_newsletter_cap_cell(str_repeat("\xF8", 5000)));
eq('kort celle renses som før', 'Søren', hsg_newsletter_cap_cell(" S\xF8ren "));
// Testerens fund: afkortningen må aldrig gøre en adresse til en anden, gyldig adresse (kim@firma.com → kim@firma.co)
$many = '';
for ($i = 1; strlen($many) < 290; $i++) $many .= 'a'.str_pad((string)$i, 6, '0', STR_PAD_LEFT).'@x.dk; '; // 14 tegn pr. adresse
$cell = $many.str_repeat('b', 306 - strlen($many)).'; kim@firma.com';
eq('testcellen: 321 tegn, kim@firma.com starter på tegn 309', [321, 309], [strlen($cell), strpos($cell, 'kim@firma.com') + 1]);
$a = hsg_newsletter_analyse([hsg_newsletter_cap_row([$cell])], ['header_row' => null, 'email' => 0, 'name' => null, 'company' => null, 'segment' => null], 'customer', []);
$emails = array_column($a['entries'], 'email');
t('afkortet celle: kim@firma.co bliver ikke en ny modtager', !in_array('kim@firma.co', $emails, true) && !in_array('kim@firma.c', $emails, true));
t('afkortet celle: de hele adresser før klippet bevares', in_array('a000001@x.dk', $emails, true) && in_array('a000021@x.dk', $emails, true));
t('afkortet celle: den afklippede adresse står som ugyldig med "…"', in_array('kim@firma.…', array_column($a['invalid'], 'value'), true));
$exact = str_repeat('c', 318 - strlen('ok@firma.dk')).'ok@firma.dk; næste@x.dk';
eq('klippes der lige efter en hel adresse, bevares den', str_repeat('c', 307).'ok@firma.dk …', hsg_newsletter_cap_cell($exact));
// Testerens tilføjelse: klippet ligger ved tegn 318, så testcellen ovenfor klipper "kim@firma" (ugyldig) og viser ikke, om
// klip-midt-i-et-ord virker. Her flyttes adressen gennem alle positioner omkring klippet (tegn 295-325), og der må aldrig
// komme en gyldig adresse ud, som ikke stod i cellen ("kim@firma.com" → "kim@firma.co", "ole@firma.dk" → "ole@firma.d").
$inventedAt = [];
for ($start = 295; $start <= 325; $start++) {
    $cell = str_repeat('b', $start - 3).'; kim@firma.com; ole@firma.dk; pia@firma.com';
    $out = array_map('hsg_newsletter_normalize_email', hsg_newsletter_split_emails(hsg_newsletter_cap_cell($cell)));
    foreach ($out as $e) if (hsg_newsletter_is_valid_email($e) && !in_array($e, ['kim@firma.com', 'ole@firma.dk', 'pia@firma.com'], true)) $inventedAt[] = $start.': '.$e;
}
eq('klip midt i en adresse (adressen flyttet gennem tegn 295-325): ingen opfundne adresser', [], $inventedAt);
$mid = str_repeat('b', 304).'; kim@firma.com; ole@firma.dk';
eq('testcellen: kim@firma.com starter på tegn 307, så klippet ved tegn 318 rammer "kim@firma.co"', 307, strpos($mid, 'kim@firma.com') + 1);
eq('klip midt i adressen: "…" på det afklippede stykke gør det ugyldigt', str_repeat('b', 304).'; kim@firma.co…', hsg_newsletter_cap_cell($mid));
$nl = str_repeat('c', 318 - strlen("; ok@firma.dk\n"))."; ok@firma.dk\nnæste@x.dk";
eq('klippes der lige efter en nylinje, bevares adressen før den (testerens KAN 6)', str_repeat('c', 304).'; ok@firma.dk …', hsg_newsletter_cap_cell($nl));
// Reviewerens fund: kun skilletegn (eller <>) før et langt ord må heller ikke give en gyldig adresse
$invented = [];
foreach ([str_repeat(';', 305).'kim@firma.community.dk', str_repeat(',', 300).'ole@firma.dk.example.org', str_repeat('<', 305).'kim@firma.community.dk', str_repeat(' ', 2).str_repeat(';', 303).'kim@firma.community.dk',
    str_repeat(' ', 1268).'kim@firma.community.dk', str_repeat("\x01", 1268).'kim@firma.community.dk', str_repeat("\u{00A0}", 634).'kim@firma.community.dk'] as $cell) {
    foreach (hsg_newsletter_split_emails(hsg_newsletter_cap_cell($cell)) as $part) {
        $e = hsg_newsletter_normalize_email($part);
        if (hsg_newsletter_is_valid_email($e)) $invented[] = $e;
    }
}
eq('lange rækker af skilletegn eller < før et langt ord: ingen opfundne adresser', [], $invented);
eq('række afkortes til 30 kolonner', 30, count(hsg_newsletter_cap_row(range(1, 40))));
$cs = hsg_newsletter_clean_sheets(['Ark 1' => [array_merge([str_repeat('y', 400)], array_fill(0, 39, 'z'))]]);
t('clean_sheets: 30 kolonner og 320 tegn pr. celle', count($cs['sheets']['Ark 1'][0]) === 30 && $cs['sheets']['Ark 1'][0][0] === str_repeat('y', 318).'…');
eq('upload med file[] (flere filer) giver "Vælg en fil", ikke "for stor"', 'Vælg en Excel-fil (.xlsx) eller CSV-fil.', nl_throws(static fn() => hsg_newsletter_read_upload(['error' => [0, 0], 'size' => [1, 1], 'tmp_name' => ['a', 'b'], 'name' => ['a.csv', 'b.csv']])));
eq('fil over 5 MB afvises før læsning', 'Filen er for stor (højst 5 MB).', nl_throws(static fn() => hsg_newsletter_read_upload(['error' => UPLOAD_ERR_OK, 'size' => 5 * 1024 * 1024 + 1, 'tmp_name' => __FILE__, 'name' => 'liste.csv'])));
t('fil på præcis 5 MB afvises ikke på størrelsen', nl_throws(static fn() => hsg_newsletter_read_upload(['error' => UPLOAD_ERR_OK, 'size' => 5 * 1024 * 1024, 'tmp_name' => __FILE__, 'name' => 'liste.csv'])) === 'Vælg en Excel-fil (.xlsx) eller CSV-fil.');

$tmpFiles = [];
$tmp = static function (string $content, string $ext) use (&$tmpFiles): string {
    $f = tempnam(sys_get_temp_dir(), 'nl').'.'.$ext;
    file_put_contents($f, $content);
    return $tmpFiles[] = $f;
};

// CSV
$csv = $tmp("\xEF\xBB\xBFNavn;E-mail\r\nS\xF8ren;s@x.dk\r\n\r\n;;\r\n\"Kim; H\";k@x.dk\r\n\r\n", 'csv');
$d = hsg_newsletter_read_file($csv, 'csv');
eq('CSV: tomme rækker springes over', [['Navn', 'E-mail'], ['Søren', 's@x.dk'], ['Kim; H', 'k@x.dk']], $d['sheets']['CSV']);
eq('CSV: rækkenumre følger filen', [1, 2, 5], $d['rownums']['CSV']);
$rows5000 = '';
for ($i = 1; $i <= 5000; $i++) $rows5000 .= "a$i@x.dk\n";
eq('CSV: 5.000 rækker og tomme rækker til sidst godtages', 5000, count(hsg_newsletter_read_file($tmp($rows5000.str_repeat(",,,\n", 3000), 'csv'), 'csv')['sheets']['CSV']));
eq('CSV: række 5.001 afvises', 'Filen har mere end 5.000 rækker.', nl_throws(static fn() => hsg_newsletter_read_file($tmp("E-mail\n".$rows5000, 'csv'), 'csv')));
eq('CSV: tom fil', 'Filen indeholder ingen gyldige mailadresser.', nl_throws(static fn() => hsg_newsletter_read_file($tmp('', 'csv'), 'csv')));
eq('CSV: omvendt skråstreg er ikke et escape-tegn (RFC 4180)', [['Navn', 'E-mail'], ['Pia \\', 'pia@x.dk']], hsg_newsletter_read_file($tmp("Navn,E-mail\n\"Pia \\\",pia@x.dk\n", 'csv'), 'csv')['sheets']['CSV']);
eq('CSV: dobbelt anførselstegn i et felt', [['Skål "A"', 'a@x.dk']], hsg_newsletter_read_file($tmp("\"Skål \"\"A\"\"\",a@x.dk\n", 'csv'), 'csv')['sheets']['CSV']);

// xlsx (kræver zip og xmlreader, som på webhotellet)
if (class_exists('ZipArchive') && class_exists('XMLReader')) {
    $ns = 'xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"';
    $xlsx = static function (string $sheet, ?string $shared = null, ?string $sheet2 = null) use ($tmp): string {
        $f = $tmp('', 'xlsx'); unlink($f);
        $z = new ZipArchive();
        $z->open($f, ZipArchive::CREATE);
        $z->addFromString('xl/worksheets/sheet1.xml', $sheet);
        if ($sheet2 !== null) $z->addFromString('xl/worksheets/sheet2.xml', $sheet2);
        if ($shared !== null) $z->addFromString('xl/sharedStrings.xml', $shared);
        $z->close();
        return $f;
    };
    $ws = static fn(string $rows, string $ns): string => '<?xml version="1.0"?><worksheet '.$ns.'><sheetData>'.$rows.'</sheetData></worksheet>';

    $f = $xlsx($ws('<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c></row>'
        .'<row r="3"><c r="A3" t="inlineStr"><is><t>Kim</t></is></c><c r="B3" t="s"><v>2</v></c></row>'
        .'<row r="4"><c r="A4" s="1"/><c r="B4" s="1"/></row><row r="5"/>'
        .'<row><c t="inlineStr"><is><t>Pia</t></is></c><c><v>pia@x.dk</v></c></row>', $ns),
        '<?xml version="1.0"?><sst '.$ns.'><si><t>Navn</t></si><si><r><t>E-</t></r><r><t>mail</t></r><rPh sb="0" eb="1"><t>X</t></rPh></si><si><t xml:space="preserve"> kim@x.dk </t></si></sst>');
    $d = hsg_newsletter_read_file($f, 'xlsx');
    eq('xlsx: delte strenge, tekstceller og rich text (uden fonetisk afsnit)', [['Navn', 'E-mail'], ['Kim', 'kim@x.dk'], ['Pia', 'pia@x.dk']], $d['sheets']['Ark 1']);
    eq('xlsx: Excel-rækkenumre, tomme og formaterede tomme rækker springes over, r mangler', [1, 3, 6], $d['rownums']['Ark 1']);

    $f = $xlsx('<?xml version="1.0"?><x:worksheet xmlns:x="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><x:sheetData><x:row r="2"><x:c r="D2" t="inlineStr"><x:is><x:t>a@x.dk</x:t></x:is></x:c></x:row></x:sheetData></x:worksheet>');
    eq('xlsx: navnerum med præfiks', ['Ark 1' => [['', '', '', 'a@x.dk']]], hsg_newsletter_read_file($f, 'xlsx')['sheets']);
    $f = $xlsx($ws('<row r="1"><c r="A1"><v>x</v></c><c r="'.str_repeat('A', 30).'1" t="inlineStr"><is><t>b@x.dk</t></is></c></row>', $ns));
    eq('xlsx: ugyldig kolonnereference (30 bogstaver) bliver kolonnen efter den forrige', ['Ark 1' => [['x', 'b@x.dk']]], hsg_newsletter_read_file($f, 'xlsx')['sheets']);

    $cells = '';
    for ($i = 0; $i < 40; $i++) $cells .= '<c r="'.hsg_newsletter_col_letter($i).'1" t="inlineStr"><is><t>K'.$i.'</t></is></c>';
    $f = $xlsx($ws('<row r="1">'.$cells.'<c r="XFD1"><v>9</v></c></row><row r="2"><c r="D2" t="inlineStr"><is><t>a@x.dk</t></is></c><c r="XFD2"><v>9</v></c></row>', $ns));
    $d = hsg_newsletter_read_file($f, 'xlsx');
    t('xlsx: kun kolonne A–AD læses (også ved en celle i XFD)', count($d['sheets']['Ark 1'][0]) === 30 && $d['sheets']['Ark 1'][0][29] === 'K29' && count($d['sheets']['Ark 1'][1]) === 4);

    $f = $xlsx($ws('<row r="1"><c r="A1" t="inlineStr"><is><t>'.str_repeat('A', HSG_NEWSLETTER_MAX_XLSX_XML + 1).'</t></is></c></row>', $ns));
    t('zip-bomben er lille pakket', filesize($f) < 1024 * 1024);
    $t0 = microtime(true);
    eq('xlsx: over 30 MB udpakket afvises', HSG_NEWSLETTER_XLSX_TOO_BIG, nl_throws(static fn() => hsg_newsletter_read_file($f, 'xlsx')));
    t('xlsx: zip-bomben afvises uden at blive pakket ud', microtime(true) - $t0 < 0.5);
    $half = str_repeat('B', intdiv(HSG_NEWSLETTER_MAX_XLSX_XML, 2));
    eq('xlsx: arkene og de delte strenge lægges sammen', HSG_NEWSLETTER_XLSX_TOO_BIG, nl_throws(static fn() => hsg_newsletter_read_file(
        $xlsx($ws('<row r="1"><c r="A1"><v>'.$half.'</v></c></row>', $ns), '<sst '.$ns.'><si><t>'.$half.'</t></si></sst>'), 'xlsx')));
    eq('xlsx: for mange delte strenge afvises', HSG_NEWSLETTER_XLSX_TOO_BIG, nl_throws(static fn() => hsg_newsletter_read_file(
        $xlsx($ws('<row r="1"><c r="A1" t="s"><v>0</v></c></row>', $ns), '<sst '.$ns.'>'.str_repeat('<si/>', HSG_NEWSLETTER_MAX_SHARED + 1).'</sst>'), 'xlsx')));

    $rows = '';
    for ($i = 1; $i <= 2000; $i++) {
        $rows .= '<row r="'.$i.'">';
        for ($c = 0; $c < 30; $c++) $rows .= '<c r="'.hsg_newsletter_col_letter($c).$i.'" t="s"><v>0</v></c>';
        $rows .= '</row>';
    }
    eq('xlsx: én delt streng genbrugt i mange celler (over 10 MB tekst) afvises', 'Filen indeholder for meget tekst. Gem kun kolonnerne med mail, navn, firma og type i en ny fil, og prøv igen.',
        nl_throws(static fn() => hsg_newsletter_read_file($xlsx($ws($rows, $ns), '<sst '.$ns.'><si><t>'.str_repeat('s', 320).'</t></si></sst>'), 'xlsx')));
    $rows = '';
    for ($i = 1; $i <= 3000; $i++) $rows .= '<row r="'.$i.'"><c r="D'.$i.'"><v>'.$i.'</v></c></row>';
    eq('xlsx: 6.000 rækker fordelt på to ark afvises', 'Filen har mere end 5.000 rækker.', nl_throws(static fn() => hsg_newsletter_read_file($xlsx($ws($rows, $ns), null, $ws($rows, $ns)), 'xlsx')));
    eq('xlsx: ødelagt XML', 'Excel-filen kunne ikke læses. Gem den igen som Excel-fil (.xlsx) eller CSV-fil, og prøv igen.', nl_throws(static fn() => hsg_newsletter_read_file($xlsx($ws('<row r="1"><c r="A1"><v>1</v></row>', $ns)), 'xlsx')));
    eq('xlsx: tom fil', 'Kunne ikke åbne Excel-filen.', nl_throws(static fn() => hsg_newsletter_read_file($tmp('', 'xlsx'), 'xlsx')));
    eq('xlsx: ikke en zip-fil', 'Kunne ikke åbne Excel-filen.', nl_throws(static fn() => hsg_newsletter_read_file($tmp("E-mail\na@x.dk\n", 'xlsx'), 'xlsx')));
} else {
    echo "(xlsx-test sprunget over: zip eller xmlreader mangler)\n";
}
foreach ($tmpFiles as $f) { @unlink($f); @unlink(substr($f, 0, -strlen(pathinfo($f, PATHINFO_EXTENSION)) - 1)); }

// Forhåndsvisningen i sessionen
$_SESSION = ['newsletter_import' => ['token' => 'abc', 'created' => time() - HSG_NEWSLETTER_PREVIEW_TTL - 1, 'sheets' => []], 'andet' => 1];
hsg_newsletter_preview_expire();
t('udløbet forhåndsvisning slettes uanset token', !isset($_SESSION['newsletter_import']) && $_SESSION['andet'] === 1);
$_SESSION['newsletter_import'] = ['token' => 'abc', 'created' => time(), 'sheets' => []];
hsg_newsletter_preview_expire();
t('ny forhåndsvisning bevares', isset($_SESSION['newsletter_import']));
t('forkert token giver null, men sletter ikke en gyldig forhåndsvisning', hsg_newsletter_preview_load('forkert') === null && isset($_SESSION['newsletter_import']));
$_SESSION['newsletter_import'] = 'ødelagt';
hsg_newsletter_preview_expire();
t('ugyldig forhåndsvisning slettes', !isset($_SESSION['newsletter_import']));
$_SESSION['newsletter_import'] = ['token' => 'abc', 'created' => time() - HSG_NEWSLETTER_PREVIEW_TTL - 1];
t('udløbet forhåndsvisning med rigtigt token giver null og slettes', hsg_newsletter_preview_load('abc') === null && !isset($_SESSION['newsletter_import']));

echo "Nyhedsbrev: $passes bestået, $fails fejlet\n";
exit($fails === 0 ? 0 : 1);
