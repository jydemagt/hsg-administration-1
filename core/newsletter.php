<?php
declare(strict_types=1);

/**
 * Nyhedsbrev (modul newsletter) – trin 1: modtagere, import og afmelding.
 *
 * Første del af filen er rene funktioner uden database (testes i tests/newsletter_test.php).
 * Anden del bruger PDO. Mailadresser må aldrig stå i URL'er eller i audit-loggen.
 */

require_once __DIR__.'/supplier_import.php';
require_once __DIR__.'/access_links.php';

const HSG_NEWSLETTER_MAX_ROWS = 5000;
const HSG_NEWSLETTER_PREVIEW_TTL = 7200;

/* ---------------------------------------------------------------------------
 * Rene funktioner (ingen database, ingen globale variabler)
 * ------------------------------------------------------------------------- */

function hsg_newsletter_lower(string $s): string {
    return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
}

function hsg_newsletter_truncate(string $s, int $max): string {
    if (function_exists('mb_substr')) return mb_substr($s, 0, $max, 'UTF-8');
    return preg_match('/^.{0,'.$max.'}/su', $s, $m) ? $m[0] : substr($s, 0, $max);
}

/**
 * Renser en celle fra Excel/CSV: fjerner BOM og kontroltegn, omsætter Windows-1252 (CSV gemt
 * fra Excel) til UTF-8 og fjerner mellemrum i begge ender (også hårde mellemrum).
 */
function hsg_newsletter_clean_cell(mixed $value): string {
    $s = (string)$value;
    if (str_starts_with($s, "\xEF\xBB\xBF")) $s = substr($s, 3);
    if ($s !== '' && !preg_match('//u', $s)) {
        if (function_exists('mb_convert_encoding')) $s = (string)mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
        elseif (function_exists('iconv')) $s = (string)@iconv('Windows-1252', 'UTF-8//IGNORE', $s);
        if (!preg_match('//u', $s)) $s = preg_replace('/[\x80-\xFF]/', '', $s) ?? '';
    }
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s) ?? '';
    return preg_replace('/^[\s\x{00A0}\x{200B}\x{FEFF}]+|[\s\x{00A0}\x{200B}\x{FEFF}]+$/u', '', $s) ?? '';
}

/** Navn og firma: renset, mellemrum samlet til ét, højst 180 tegn. */
function hsg_newsletter_clean_text(mixed $value, int $max = 180): string {
    $s = hsg_newsletter_clean_cell($value);
    $s = preg_replace('/[\s\x{00A0}\x{200B}\x{FEFF}]+/u', ' ', $s) ?? '';
    return trim(hsg_newsletter_truncate($s, $max));
}

/** Normaliserer én adresse: mellemrum, "mailto:" og <> fjernes, og alt skrives med små bogstaver. */
function hsg_newsletter_normalize_email(string $raw): string {
    $s = hsg_newsletter_clean_cell($raw);
    $s = preg_replace('/[\s\x{00A0}\x{200B}\x{FEFF}]+/u', '', $s) ?? '';
    $s = str_replace(['<', '>'], '', $s);
    $s = preg_replace('/^mailto:/i', '', $s) ?? '';
    return hsg_newsletter_lower($s);
}

/** En celle med flere adresser adskilt af ;, , eller mellemrum giver flere adresser. */
function hsg_newsletter_split_emails(string $cell): array {
    $cell = hsg_newsletter_clean_cell($cell);
    if ($cell === '') return [];
    $parts = preg_split('/[;,\s\x{00A0}\x{200B}\x{FEFF}]+/u', $cell, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    return array_values(array_filter(array_map('trim', $parts), static fn(string $p): bool => $p !== ''));
}

/**
 * Gyldig adresse: FILTER_VALIDATE_EMAIL, højst 190 tegn og et punktum i domænet.
 * Adresser med æøå i domænet (IDN) afvises af FILTER_VALIDATE_EMAIL og er derfor ugyldige.
 */
function hsg_newsletter_is_valid_email(string $email): bool {
    if ($email === '' || strlen($email) > 190) return false;
    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) return false;
    $at = strrpos($email, '@');
    if ($at === false) return false;
    return str_contains(substr($email, $at + 1), '.');
}

function hsg_newsletter_email_hash(string $normalizedEmail): string {
    return hash('sha256', $normalizedEmail);
}

/** k***@firma.dk – første tegn, tre stjerner og domænet. */
function hsg_newsletter_mask_email(?string $email): string {
    $email = (string)$email;
    $at = strrpos($email, '@');
    if ($at === false || $at === 0) return '***';
    $first = function_exists('mb_substr') ? mb_substr($email, 0, 1, 'UTF-8') : substr($email, 0, 1);
    return $first.'***'.substr($email, $at);
}

/** Celler, der starter med =, +, -, @ (eller tabulator/CR), får et foranstillet ' i CSV. */
function hsg_newsletter_csv_safe(string $value): string {
    if ($value === '') return $value;
    return in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
}

/** CSV med UTF-8 BOM, semikolon og CRLF, så Excel åbner den korrekt. */
function hsg_newsletter_csv(array $rows): string {
    $fh = fopen('php://temp', 'r+');
    if ($fh === false) throw new RuntimeException('Kunne ikke danne CSV-filen.');
    foreach ($rows as $row) {
        fputcsv($fh, array_map(static fn($v): string => hsg_newsletter_csv_safe((string)$v), (array)$row), ';', '"', '', "\r\n");
    }
    rewind($fh);
    $csv = (string)stream_get_contents($fh);
    fclose($fh);
    return "\xEF\xBB\xBF".$csv;
}

/** Værdier, der starter med "forhandler" (uanset store/små bogstaver), er Forhandler – alt andet er Kunde. */
function hsg_newsletter_segment_from_value(string $value): string {
    return str_starts_with(hsg_newsletter_lower(hsg_newsletter_clean_cell($value)), 'forhandler') ? 'dealer' : 'customer';
}

function hsg_newsletter_segment(string $value): string {
    return $value === 'dealer' ? 'dealer' : 'customer';
}

/** Valget "Hvem er i filen?": customer, dealer eller column. */
function hsg_newsletter_mode(string $value): string {
    return in_array($value, ['customer', 'dealer', 'column'], true) ? $value : 'customer';
}

function hsg_newsletter_segment_label(string $segment): string {
    return ['dealer' => 'Forhandler', 'customer' => 'Kunde'][$segment] ?? $segment;
}

function hsg_newsletter_segment_badge(string $segment): string {
    return $segment === 'dealer' ? 'blue' : '';
}

function hsg_newsletter_status_label(string $status): string {
    return ['active' => 'Aktiv', 'unsubscribed' => 'Afmeldt', 'stopped' => 'Stoppet', 'deleted' => 'Slettet'][$status] ?? $status;
}

function hsg_newsletter_status_badge(string $status): string {
    return ['active' => 'green', 'unsubscribed' => 'amber', 'stopped' => 'red'][$status] ?? '';
}

function hsg_newsletter_source_label(string $source): string {
    return ['import' => 'Import', 'manual' => 'Tilføjet manuelt'][$source] ?? $source;
}

function hsg_newsletter_via_label(?string $via): string {
    return ['link' => 'via afmeldingslinket', 'one_click' => 'med ét klik i mailprogrammet', 'admin' => 'af en administrator'][(string)$via] ?? '';
}

function hsg_newsletter_mode_label(string $mode): string {
    return ['customer' => 'Alle i filen er kunder', 'dealer' => 'Alle i filen er forhandlere', 'column' => 'Brug kolonne'][$mode] ?? $mode;
}

/** Hvad typeskiftet betyder for modtageren (vises i forhåndsvisningen af importen). */
function hsg_newsletter_segment_effect(string $segment): string {
    return $segment === 'dealer' ? 'får engrospriser' : 'får vejledende udsalgspriser';
}

/** 0 → A, 3 → D, 26 → AA. */
function hsg_newsletter_col_letter(int $index): string {
    $n = $index + 1; $s = '';
    while ($n > 0) { $m = ($n - 1) % 26; $s = chr(65 + $m).$s; $n = intdiv($n - 1, 26); }
    return $s;
}

/** Dato/tid fra databasen som "20.10.2026 kl. 10.42". */
function hsg_newsletter_fmt_datetime(?string $value): string {
    $value = trim((string)$value);
    if ($value === '') return '';
    $ts = strtotime($value);
    return $ts === false ? $value : date('d.m.Y', $ts).' kl. '.date('H.i', $ts);
}

/** Overskrifter, der genkendes automatisk (sammenlignes normaliseret som i hsg_supplier_norm()). */
function hsg_newsletter_column_aliases(): array {
    return [
        'email' => ['email', 'e-mail', 'mail', 'emailadresse', 'mailadresse', 'epost'],
        'name' => ['navn', 'name', 'kontakt', 'kontaktperson'],
        'company' => ['firma', 'virksomhed', 'company'],
    ];
}

function hsg_newsletter_column_count(array $rows): int {
    $max = 0;
    foreach ($rows as $row) $max = max($max, count((array)$row));
    return max(4, $max);
}

/**
 * Finder overskriftsrække og kolonner.
 *  - Overskriftsrækken er den af de første 10 rækker, der har flest kendte overskrifter
 *    (aliaser eller overskrifter fra seneste import). Findes ingen, har filen ingen overskrifter.
 *  - Overskrifter fra seneste import får præcis det valg, der blev brugt sidst (også "ingen").
 *  - Ellers genkendes mail, navn og firma ud fra aliaser. Mailkolonnen er ellers kolonne D.
 *
 * @param array $saved Husket valg: normaliseret overskrift => felt ('' = ikke brugt)
 * @return array{header_row:?int,email:int,name:?int,company:?int,segment:?int}
 */
function hsg_newsletter_detect_columns(array $rows, array $saved = []): array {
    $reverse = [];
    foreach (hsg_newsletter_column_aliases() as $field => $names) {
        foreach ($names as $n) $reverse[hsg_supplier_norm($n)] = $field;
    }
    $headerRow = null; $best = 0;
    foreach (array_slice($rows, 0, 10, true) as $ri => $row) {
        $cells = array_map(static fn($c): string => (string)$c, (array)$row);
        $score = 0;
        foreach (hsg_supplier_header_keys($cells) as $ci => $key) {
            if ($key === '' || str_contains($cells[$ci] ?? '', '@')) continue;
            if (array_key_exists($key, $saved)) $score += 2;
            elseif (isset($reverse[$key])) $score += 1;
        }
        if ($score > $best) { $best = $score; $headerRow = (int)$ri; }
    }

    $cols = ['header_row' => $headerRow, 'email' => null, 'name' => null, 'company' => null, 'segment' => null];
    if ($headerRow !== null) {
        $keys = hsg_supplier_header_keys(array_map(static fn($c): string => (string)$c, (array)$rows[$headerRow]));
        $seen = [];
        foreach ($keys as $ci => $key) {
            if ($key === '' || !array_key_exists($key, $saved)) continue;
            $seen[$ci] = true;
            $field = (string)$saved[$key];
            if (array_key_exists($field, $cols) && $field !== 'header_row' && $cols[$field] === null) $cols[$field] = (int)$ci;
        }
        foreach ($keys as $ci => $key) {
            if ($key === '' || isset($seen[$ci])) continue;
            $field = $reverse[$key] ?? null;
            if ($field !== null && $cols[$field] === null) $cols[$field] = (int)$ci;
        }
    }
    if ($cols['email'] === null) $cols['email'] = 3; // Kolonne D
    return $cols;
}

/** Kolonnevalget som det huskes i hsg_settings: normaliseret overskrift => felt ('' = ikke brugt). */
function hsg_newsletter_columns_to_saved(array $headers, array $cols): array {
    $map = [];
    foreach (hsg_supplier_header_keys(array_map(static fn($c): string => (string)$c, $headers)) as $ci => $key) {
        if ($key === '') continue;
        $field = '';
        foreach (['email', 'name', 'company', 'segment'] as $f) {
            if (isset($cols[$f]) && $cols[$f] !== null && (int)$cols[$f] === (int)$ci) { $field = $f; break; }
        }
        $map[$key] = $field;
    }
    return $map;
}

/**
 * Analyserer importrækker mod de eksisterende modtagere.
 *
 * @param array  $rows     arkets rækker (0-baseret; række 1 i filen er indeks 0)
 * @param array  $cols     fra hsg_newsletter_detect_columns()
 * @param string $mode     customer | dealer | column
 * @param array  $existing email_hash => ['id','status','segment','name','company']
 */
function hsg_newsletter_analyse(array $rows, array $cols, string $mode, array $existing): array {
    $mode = hsg_newsletter_mode($mode);
    $emailCol = (int)($cols['email'] ?? 3);
    $nameCol = isset($cols['name']) && $cols['name'] !== null ? (int)$cols['name'] : null;
    $companyCol = isset($cols['company']) && $cols['company'] !== null ? (int)$cols['company'] : null;
    $segmentCol = isset($cols['segment']) && $cols['segment'] !== null ? (int)$cols['segment'] : null;
    $start = isset($cols['header_row']) && $cols['header_row'] !== null ? (int)$cols['header_row'] + 1 : 0;

    $res = [
        'entries' => [], 'invalid' => [], 'duplicates' => 0, 'conflicts' => [],
        'new' => [], 'update' => [], 'unchanged' => [], 'skipped' => [], 'type_changes' => [],
        'missing_active' => 0, 'segment_missing' => ($mode === 'column' && $segmentCol === null),
    ];

    foreach ($rows as $ri => $row) {
        $ri = (int)$ri;
        if ($ri < $start) continue;
        $row = (array)$row;
        $parts = hsg_newsletter_split_emails((string)($row[$emailCol] ?? ''));
        if (!$parts) continue;
        $rowNo = $ri + 1;
        $name = $nameCol !== null ? hsg_newsletter_clean_text($row[$nameCol] ?? '') : '';
        $company = $companyCol !== null ? hsg_newsletter_clean_text($row[$companyCol] ?? '') : '';
        $segment = match ($mode) {
            'dealer' => 'dealer',
            'column' => $segmentCol !== null ? hsg_newsletter_segment_from_value((string)($row[$segmentCol] ?? '')) : 'customer',
            default => 'customer',
        };
        foreach ($parts as $part) {
            $email = hsg_newsletter_normalize_email($part);
            if ($email === '') continue;
            if (!hsg_newsletter_is_valid_email($email)) {
                $res['invalid'][] = ['row' => $rowNo, 'value' => $part];
                continue;
            }
            $hash = hsg_newsletter_email_hash($email);
            if (isset($res['entries'][$hash])) {
                $res['duplicates']++;
                $res['entries'][$hash]['segments'][$segment] = true;
                $res['entries'][$hash]['rows'][] = $rowNo;
                if ($res['entries'][$hash]['name'] === '' && $name !== '') $res['entries'][$hash]['name'] = $name;
                if ($res['entries'][$hash]['company'] === '' && $company !== '') $res['entries'][$hash]['company'] = $company;
                continue;
            }
            $res['entries'][$hash] = [
                'email' => $email, 'name' => $name, 'company' => $company, 'segment' => $segment,
                'segments' => [$segment => true], 'row' => $rowNo, 'rows' => [$rowNo], 'conflict' => false,
            ];
        }
    }

    foreach ($res['entries'] as $hash => $e) {
        // Står adressen både som forhandler og kunde, importeres den som Kunde (den sikre side).
        if (count($e['segments']) > 1) {
            $e['segment'] = 'customer';
            $e['conflict'] = true;
            $res['entries'][$hash] = $e;
            $res['conflicts'][] = ['rows' => $e['rows'], 'email' => $e['email']];
        }
        $ex = $existing[$hash] ?? null;
        if ($ex === null) { $res['new'][] = $hash; continue; }
        $status = (string)($ex['status'] ?? '');
        if ($status !== 'active') {
            $res['skipped'][] = ['row' => $e['row'], 'email' => $e['email'], 'status' => $status];
            continue;
        }
        $changes = [];
        $oldSegment = (string)($ex['segment'] ?? 'customer');
        if ($oldSegment !== $e['segment']) {
            $changes['segment'] = [$oldSegment, $e['segment']];
            $res['type_changes'][] = ['row' => $e['row'], 'email' => $e['email'], 'from' => $oldSegment, 'to' => $e['segment']];
        }
        if ($e['name'] !== '' && $e['name'] !== (string)($ex['name'] ?? '')) $changes['name'] = [(string)($ex['name'] ?? ''), $e['name']];
        if ($e['company'] !== '' && $e['company'] !== (string)($ex['company'] ?? '')) $changes['company'] = [(string)($ex['company'] ?? ''), $e['company']];
        if ($changes) $res['update'][$hash] = ['id' => (int)$ex['id'], 'changes' => $changes];
        else $res['unchanged'][$hash] = (int)$ex['id'];
    }

    foreach ($existing as $hash => $ex) {
        if ((string)($ex['status'] ?? '') === 'active' && !isset($res['entries'][$hash])) $res['missing_active']++;
    }
    return $res;
}

function hsg_newsletter_analysis_counts(array $a): array {
    return [
        'valid' => count($a['entries'] ?? []),
        'new' => count($a['new'] ?? []),
        'update' => count($a['update'] ?? []),
        'unchanged' => count($a['unchanged'] ?? []),
        'skipped' => count($a['skipped'] ?? []),
        'invalid' => count($a['invalid'] ?? []),
        'duplicates' => (int)($a['duplicates'] ?? 0),
        'conflicts' => count($a['conflicts'] ?? []),
        'type_changes' => count($a['type_changes'] ?? []),
        'missing_active' => (int)($a['missing_active'] ?? 0),
    ];
}

/** Rensning af indlæste ark: cellerne renses, og tomme rækker i slutningen af arket fjernes. */
function hsg_newsletter_clean_sheets(array $sheets): array {
    $out = [];
    foreach ($sheets as $name => $rows) {
        $clean = [];
        foreach ((array)$rows as $row) $clean[] = array_map('hsg_newsletter_clean_cell', array_values((array)$row));
        while ($clean && implode('', end($clean)) === '') array_pop($clean);
        if ($clean) $out[(string)$name] = $clean;
    }
    return $out;
}

/** Rækkerne i CSV-eksporten (ret til indsigt). */
function hsg_newsletter_export_rows(array $r): array {
    return [
        ['Mail', 'Navn', 'Firma', 'Type', 'Status', 'Kilde', 'Oprettet', 'Sidst set i import', 'Afmeldt', 'Afmeldt hvordan'],
        [
            (string)($r['email'] ?? ''), (string)($r['name'] ?? ''), (string)($r['company'] ?? ''),
            hsg_newsletter_segment_label((string)($r['segment'] ?? '')), hsg_newsletter_status_label((string)($r['status'] ?? '')),
            hsg_newsletter_source_label((string)($r['source'] ?? '')), (string)($r['created_at'] ?? ''),
            (string)($r['last_import_at'] ?? ''), (string)($r['unsubscribed_at'] ?? ''), hsg_newsletter_via_label($r['unsubscribe_via'] ?? null),
        ],
    ];
}

/** Audit-details må aldrig indeholde en mailadresse – heller ikke i et filnavn. */
function hsg_newsletter_safe_filename(string $filename): string {
    $name = basename(str_replace('\\', '/', $filename));
    $name = str_replace('@', '(at)', $name);
    return hsg_newsletter_truncate(hsg_newsletter_clean_text($name, 180), 180);
}

function hsg_newsletter_is_token_format(string $token): bool {
    return preg_match('/^[a-f0-9]{64}$/', $token) === 1;
}

function hsg_newsletter_tabs_html(string $active): string {
    // Trin 1: kun Modtagere og Import. Udsendelser og Indstillinger kommer i trin 2.
    $tabs = ['recipients' => ['newsletter_recipients.php', 'Modtagere'], 'import' => ['newsletter_import.php', 'Import']];
    $out = '<nav class="simple-tabs" aria-label="Nyhedsbrev">';
    foreach ($tabs as $key => [$href, $label]) {
        $isActive = $key === $active;
        $out .= '<a href="'.h($href).'"'.($isActive ? ' class="active" aria-current="page"' : '').'>'.h($label).'</a>';
    }
    return $out.'</nav>';
}

/* ---------------------------------------------------------------------------
 * Databasefunktioner
 * ------------------------------------------------------------------------- */

/** Nyt afmeldingstoken: 32 tilfældige bytes, gemt som SHA-256 (opslag) og krypteret (nyt link). */
function hsg_newsletter_new_token(): array {
    $token = bin2hex(random_bytes(32));
    return ['token' => $token, 'hash' => hash('sha256', $token), 'cipher' => hsg_access_link_encrypt($token)];
}

function hsg_newsletter_unsubscribe_url(string $baseUrl, ?string $cipher): ?string {
    $token = hsg_access_link_decrypt($cipher);
    if ($token === null || !hsg_newsletter_is_token_format($token)) return null;
    return rtrim($baseUrl, '/').'/nyhedsbrev-afmeld.php?t='.$token;
}

function hsg_newsletter_get(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare('SELECT * FROM hsg_newsletter_recipients WHERE id=?');
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

function hsg_newsletter_find_by_hash(PDO $pdo, string $emailHash): ?array {
    $st = $pdo->prepare('SELECT * FROM hsg_newsletter_recipients WHERE email_hash=?');
    $st->execute([$emailHash]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

function hsg_newsletter_find_by_token(PDO $pdo, string $token): ?array {
    if (!hsg_newsletter_is_token_format($token)) return null;
    $st = $pdo->prepare("SELECT * FROM hsg_newsletter_recipients WHERE token_hash=? AND status<>'deleted'");
    $st->execute([hash('sha256', $token)]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

function hsg_newsletter_counts(PDO $pdo): array {
    $r = $pdo->query("SELECT COALESCE(SUM(status='active'),0) active, COALESCE(SUM(status='active' AND segment='dealer'),0) dealers,
        COALESCE(SUM(status='active' AND segment='customer'),0) customers, COALESCE(SUM(status='unsubscribed'),0) unsubscribed,
        COALESCE(SUM(status='stopped'),0) stopped FROM hsg_newsletter_recipients")->fetch(PDO::FETCH_ASSOC) ?: [];
    return array_map('intval', $r);
}

function hsg_newsletter_filters(): array {
    return ['active' => 'Aktive', 'dealer' => 'Forhandlere', 'customer' => 'Kunder', 'unsubscribed' => 'Afmeldte', 'stopped' => 'Stoppede'];
}

function hsg_newsletter_list(PDO $pdo, string $filter): array {
    $where = match ($filter) {
        'dealer' => "status='active' AND segment='dealer'",
        'customer' => "status='active' AND segment='customer'",
        'unsubscribed' => "status='unsubscribed'",
        'stopped' => "status='stopped'",
        default => "status='active'",
    };
    return $pdo->query('SELECT id,email,name,company,segment,status FROM hsg_newsletter_recipients WHERE '.$where.' ORDER BY email')->fetchAll(PDO::FETCH_ASSOC);
}

/** Alle modtagere som email_hash => række (bruges af importen). */
function hsg_newsletter_existing_map(PDO $pdo): array {
    $map = [];
    foreach ($pdo->query('SELECT id,email_hash,status,segment,name,company FROM hsg_newsletter_recipients')->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $map[(string)$r['email_hash']] = $r;
    }
    return $map;
}

/** Opretter en aktiv modtager med nyt token. Returnerer id, eller 0 hvis adressen allerede findes. */
function hsg_newsletter_insert(PDO $pdo, string $email, string $name, string $company, string $segment, string $source): int {
    $t = hsg_newsletter_new_token();
    $st = $pdo->prepare("INSERT IGNORE INTO hsg_newsletter_recipients(email,email_hash,name,company,segment,status,source,token_hash,token_cipher,last_import_at)
        VALUES(?,?,?,?,?,'active',?,?,?,".($source === 'import' ? 'NOW()' : 'NULL').')');
    $st->execute([$email, hsg_newsletter_email_hash($email), $name !== '' ? $name : null, $company !== '' ? $company : null,
        hsg_newsletter_segment($segment), $source === 'import' ? 'import' : 'manual', $t['hash'], $t['cipher']]);
    return $st->rowCount() > 0 ? (int)$pdo->lastInsertId() : 0;
}

/**
 * Manuel tilføjelse. Resultat: created | reactivated | invalid | exists | exists_stopped | needs_confirmation.
 * En afmeldt eller slettet adresse kræver, at modtageren selv har bedt om at blive tilmeldt igen.
 */
function hsg_newsletter_add(PDO $pdo, string $emailRaw, string $name, string $company, string $segment, bool $selfRequested): array {
    $email = hsg_newsletter_normalize_email($emailRaw);
    if (!hsg_newsletter_is_valid_email($email)) return ['result' => 'invalid'];
    $name = hsg_newsletter_clean_text($name);
    $company = hsg_newsletter_clean_text($company);
    $segment = hsg_newsletter_segment($segment);
    $existing = hsg_newsletter_find_by_hash($pdo, hsg_newsletter_email_hash($email));
    if ($existing === null) {
        $id = hsg_newsletter_insert($pdo, $email, $name, $company, $segment, 'manual');
        if ($id === 0) return ['result' => 'exists'];
        audit_log($pdo, 'newsletter.recipient.create', 'newsletter_recipient', (string)$id, ['segment' => $segment]);
        return ['result' => 'created', 'id' => $id];
    }
    $id = (int)$existing['id'];
    $status = (string)$existing['status'];
    if ($status === 'active') return ['result' => 'exists', 'id' => $id];
    if ($status === 'stopped') return ['result' => 'exists_stopped', 'id' => $id];
    if (!$selfRequested) return ['result' => 'needs_confirmation', 'status' => $status];

    if ($status === 'deleted') {
        // Den anonymiserede række genbruges, og der udstedes et nyt token.
        $t = hsg_newsletter_new_token();
        $st = $pdo->prepare("UPDATE hsg_newsletter_recipients SET email=?,name=?,company=?,segment=?,status='active',source='manual',
            token_hash=?,token_cipher=?,unsubscribed_at=NULL,unsubscribe_via=NULL WHERE id=? AND status='deleted'");
        $st->execute([$email, $name !== '' ? $name : null, $company !== '' ? $company : null, $segment, $t['hash'], $t['cipher'], $id]);
    } else {
        $st = $pdo->prepare("UPDATE hsg_newsletter_recipients SET name=?,company=?,segment=?,status='active',unsubscribed_at=NULL,unsubscribe_via=NULL
            WHERE id=? AND status='unsubscribed'");
        $st->execute([$name !== '' ? $name : $existing['name'], $company !== '' ? $company : $existing['company'], $segment, $id]);
        if ($st->rowCount() > 0 && empty($existing['token_hash'])) hsg_newsletter_ensure_token($pdo, $id);
    }
    if ($st->rowCount() < 1) return ['result' => 'exists', 'id' => $id];
    audit_log($pdo, 'newsletter.recipient.reactivate', 'newsletter_recipient', (string)$id, ['self_requested' => true, 'from' => $status]);
    return ['result' => 'reactivated', 'id' => $id];
}

/** Udsteder et token til en modtager, der mangler et (fx ældre data). */
function hsg_newsletter_ensure_token(PDO $pdo, int $id): void {
    $t = hsg_newsletter_new_token();
    $pdo->prepare("UPDATE hsg_newsletter_recipients SET token_hash=?,token_cipher=? WHERE id=? AND token_hash IS NULL AND status<>'deleted'")
        ->execute([$t['hash'], $t['cipher'], $id]);
}

/**
 * Retter mail, navn, firma og type. Mailadressen kan kun ændres for aktive modtagere, så en
 * afmeldt eller stoppet adresse aldrig mister sin spærring.
 * Resultat: ['ok'=>bool,'error'=>?string,'fields'=>list<string>]
 */
function hsg_newsletter_update(PDO $pdo, int $id, string $emailRaw, string $name, string $company, string $segment): array {
    $r = hsg_newsletter_get($pdo, $id);
    if ($r === null || $r['status'] === 'deleted') return ['ok' => false, 'error' => 'Modtageren findes ikke.', 'fields' => []];
    $new = [
        'email' => (string)$r['email'],
        'name' => hsg_newsletter_clean_text($name),
        'company' => hsg_newsletter_clean_text($company),
        'segment' => hsg_newsletter_segment($segment),
    ];
    if ($r['status'] === 'active') {
        $email = hsg_newsletter_normalize_email($emailRaw);
        if (!hsg_newsletter_is_valid_email($email)) return ['ok' => false, 'error' => 'Mailadressen er ugyldig.', 'fields' => []];
        if ($email !== (string)$r['email']) {
            $other = hsg_newsletter_find_by_hash($pdo, hsg_newsletter_email_hash($email));
            if ($other !== null && (int)$other['id'] !== $id) {
                return ['ok' => false, 'fields' => [], 'error' => in_array($other['status'], ['unsubscribed', 'deleted'], true)
                    ? 'Adressen er tidligere afmeldt/slettet og kan ikke bruges her.'
                    : 'Adressen findes allerede.'];
            }
        }
        $new['email'] = $email;
    }
    $fields = [];
    foreach (['email', 'name', 'company', 'segment'] as $f) {
        if ((string)($r[$f] ?? '') !== $new[$f]) $fields[] = $f;
    }
    if (!$fields) return ['ok' => true, 'error' => null, 'fields' => []];
    try {
        $pdo->prepare("UPDATE hsg_newsletter_recipients SET email=?,email_hash=?,name=?,company=?,segment=? WHERE id=? AND status<>'deleted'")
            ->execute([$new['email'], hsg_newsletter_email_hash($new['email']), $new['name'] !== '' ? $new['name'] : null,
                $new['company'] !== '' ? $new['company'] : null, $new['segment'], $id]);
    } catch (PDOException $e) {
        if ((string)$e->getCode() === '23000') return ['ok' => false, 'error' => 'Adressen findes allerede.', 'fields' => []];
        throw $e;
    }
    audit_log($pdo, 'newsletter.recipient.update', 'newsletter_recipient', (string)$id, ['fields' => $fields]);
    return ['ok' => true, 'error' => null, 'fields' => $fields];
}

/**
 * Afmelding. Via link og ét klik afmeldes både aktive og stoppede modtagere, så modtagerens eget
 * ønske altid registreres. Administratoren afmelder kun aktive. Returnerer true, hvis status blev ændret.
 */
function hsg_newsletter_unsubscribe(PDO $pdo, int $id, string $via): bool {
    if (!in_array($via, ['link', 'one_click', 'admin'], true)) throw new InvalidArgumentException('Ugyldig afmeldingsmåde.');
    $allowed = $via === 'admin' ? "status='active'" : "status IN ('active','stopped')";
    $st = $pdo->prepare("UPDATE hsg_newsletter_recipients SET status='unsubscribed',unsubscribed_at=NOW(),unsubscribe_via=? WHERE id=? AND ".$allowed);
    $st->execute([$via, $id]);
    if ($st->rowCount() < 1) return false;
    audit_log($pdo, 'newsletter.recipient.unsubscribe', 'newsletter_recipient', (string)$id, ['via' => $via]);
    return true;
}

function hsg_newsletter_stop(PDO $pdo, int $id): bool {
    $st = $pdo->prepare("UPDATE hsg_newsletter_recipients SET status='stopped' WHERE id=? AND status='active'");
    $st->execute([$id]);
    if ($st->rowCount() < 1) return false;
    audit_log($pdo, 'newsletter.recipient.stop', 'newsletter_recipient', (string)$id);
    return true;
}

/** Genaktivering. Resultat: ok | needs_confirmation | not_allowed. */
function hsg_newsletter_reactivate(PDO $pdo, int $id, bool $selfRequested): string {
    $r = hsg_newsletter_get($pdo, $id);
    if ($r === null) return 'not_allowed';
    if ($r['status'] === 'stopped') {
        $st = $pdo->prepare("UPDATE hsg_newsletter_recipients SET status='active' WHERE id=? AND status='stopped'");
        $st->execute([$id]);
        if ($st->rowCount() < 1) return 'not_allowed';
        if (empty($r['token_hash'])) hsg_newsletter_ensure_token($pdo, $id);
        audit_log($pdo, 'newsletter.recipient.reactivate', 'newsletter_recipient', (string)$id, ['from' => 'stopped']);
        return 'ok';
    }
    if ($r['status'] === 'unsubscribed') {
        if (!$selfRequested) return 'needs_confirmation';
        $st = $pdo->prepare("UPDATE hsg_newsletter_recipients SET status='active',unsubscribed_at=NULL,unsubscribe_via=NULL WHERE id=? AND status='unsubscribed'");
        $st->execute([$id]);
        if ($st->rowCount() < 1) return 'not_allowed';
        if (empty($r['token_hash'])) hsg_newsletter_ensure_token($pdo, $id);
        audit_log($pdo, 'newsletter.recipient.reactivate', 'newsletter_recipient', (string)$id, ['self_requested' => true, 'from' => 'unsubscribed']);
        return 'ok';
    }
    return 'not_allowed';
}

/** Ret til sletning: rækken anonymiseres, og kun email_hash bevares som spærring. */
function hsg_newsletter_delete(PDO $pdo, int $id): bool {
    $st = $pdo->prepare("UPDATE hsg_newsletter_recipients SET email=NULL,name=NULL,company=NULL,token_hash=NULL,token_cipher=NULL,status='deleted'
        WHERE id=? AND status<>'deleted'");
    $st->execute([$id]);
    if ($st->rowCount() < 1) return false;
    audit_log($pdo, 'newsletter.recipient.delete', 'newsletter_recipient', (string)$id);
    return true;
}

/* ---------------------------------------------------------------------------
 * Import
 * ------------------------------------------------------------------------- */

/** Læser en uploadet .xlsx/.csv med de eksisterende læsere i core/supplier_import.php. */
function hsg_newsletter_read_upload(array $file): array {
    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if (in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) throw new RuntimeException('Filen er for stor til at blive uploadet.');
    $tmp = (string)($file['tmp_name'] ?? '');
    $ext = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
    if ($error !== UPLOAD_ERR_OK || $tmp === '' || !is_uploaded_file($tmp) || !in_array($ext, ['xlsx', 'csv'], true)) {
        throw new RuntimeException('Vælg en Excel-fil (.xlsx) eller CSV-fil.');
    }
    $sheets = hsg_newsletter_clean_sheets($ext === 'xlsx' ? hsg_supplier_read_xlsx($tmp) : hsg_supplier_read_csv($tmp));
    if (!$sheets) throw new RuntimeException('Filen indeholder ingen gyldige mailadresser.');
    $total = 0;
    foreach ($sheets as $rows) $total += count($rows);
    if ($total > HSG_NEWSLETTER_MAX_ROWS) throw new RuntimeException('Filen har mere end 5.000 rækker.');
    return $sheets;
}

function hsg_newsletter_saved_columns(PDO $pdo): array {
    $saved = json_decode(trim((string)setting_get($pdo, 'newsletter_import_columns', '')), true);
    return is_array($saved) ? $saved : [];
}

/** Husker kolonnevalget ud fra overskriftsnavn (kun når filen har en overskriftsrække). */
function hsg_newsletter_remember_columns(PDO $pdo, array $rows, array $cols): void {
    if (!isset($cols['header_row']) || $cols['header_row'] === null || !isset($rows[(int)$cols['header_row']])) return;
    $map = hsg_newsletter_columns_to_saved((array)$rows[(int)$cols['header_row']], $cols);
    if ($map) setting_set($pdo, 'newsletter_import_columns', json_encode($map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_FORCE_OBJECT));
}

/**
 * Gennemfører importen. Analysen laves igen mod databasen lige nu, så en modtager, der er
 * afmeldt efter forhåndsvisningen, aldrig genaktiveres. Status ændres aldrig af en import.
 */
function hsg_newsletter_import_apply(PDO $pdo, array $rows, array $cols, string $mode, string $filename, string $sheet): array {
    $mode = hsg_newsletter_mode($mode);
    $pdo->beginTransaction();
    try {
        $a = hsg_newsletter_analyse($rows, $cols, $mode, hsg_newsletter_existing_map($pdo));
        if ($a['segment_missing']) throw new RuntimeException('Vælg en type-kolonne, når "Brug kolonne" er valgt.');
        if (!$a['entries']) throw new RuntimeException('Filen indeholder ingen gyldige mailadresser.');
        $created = 0; $updated = 0; $unchanged = 0; $skipped = count($a['skipped']);
        foreach ($a['new'] as $hash) {
            $e = $a['entries'][$hash];
            if (hsg_newsletter_insert($pdo, $e['email'], $e['name'], $e['company'], $e['segment'], 'import') > 0) $created++;
            else $skipped++;
        }
        // Navn og firma ændres kun, hvis filen har en værdi (NULL = behold den gemte værdi).
        $up = $pdo->prepare("UPDATE hsg_newsletter_recipients SET segment=?,name=COALESCE(?,name),company=COALESCE(?,company),last_import_at=NOW()
            WHERE id=? AND status='active'");
        foreach ($a['update'] as $hash => $u) {
            $e = $a['entries'][$hash]; $ch = $u['changes'];
            $up->execute([$e['segment'], isset($ch['name']) ? $ch['name'][1] : null, isset($ch['company']) ? $ch['company'][1] : null, (int)$u['id']]);
            if ($up->rowCount() > 0) $updated++; else $skipped++;
        }
        $ids = array_values(array_map('intval', $a['unchanged']));
        foreach (array_chunk($ids, 500) as $chunk) {
            $st = $pdo->prepare("UPDATE hsg_newsletter_recipients SET last_import_at=NOW() WHERE status='active' AND id IN (".implode(',', array_fill(0, count($chunk), '?')).')');
            $st->execute($chunk);
            $unchanged += $st->rowCount();
        }
        $counts = hsg_newsletter_analysis_counts($a);
        $result = [
            'created' => $created, 'updated' => $updated, 'unchanged' => $unchanged, 'skipped' => $skipped,
            'invalid' => $counts['invalid'], 'duplicates' => $counts['duplicates'], 'conflicts' => $counts['conflicts'],
            'type_changes' => $counts['type_changes'],
        ];
        audit_log($pdo, 'newsletter.import', 'newsletter', null, [
            'filename' => hsg_newsletter_safe_filename($filename), 'sheet' => hsg_newsletter_clean_text($sheet),
            'segment_choice' => $mode, 'new' => $created, 'updated' => $updated, 'unchanged' => $unchanged,
            'skipped' => $skipped, 'invalid' => $counts['invalid'], 'duplicates' => $counts['duplicates'],
            'conflicts' => $counts['conflicts'], 'type_changes' => $counts['type_changes'],
        ]);
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/* Forhåndsvisningen ligger kun i sessionen (Kims fil kan indeholde andre persondata). */

function hsg_newsletter_preview_create(array $data): string {
    $token = bin2hex(random_bytes(16));
    $_SESSION['newsletter_import'] = ['token' => $token, 'created' => time()] + $data;
    return $token;
}

function hsg_newsletter_preview_load(string $token): ?array {
    $p = $_SESSION['newsletter_import'] ?? null;
    if (!is_array($p) || $token === '' || !hash_equals((string)($p['token'] ?? ''), $token)) return null;
    if (time() - (int)($p['created'] ?? 0) > HSG_NEWSLETTER_PREVIEW_TTL) { unset($_SESSION['newsletter_import']); return null; }
    return $p;
}

function hsg_newsletter_preview_save(array $preview): void {
    $_SESSION['newsletter_import'] = $preview;
}

function hsg_newsletter_preview_clear(): void {
    unset($_SESSION['newsletter_import']);
}
