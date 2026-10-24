<?php
declare(strict_types=1);
/**
 * Laver en .xlsx, der ligner en fil fra Excel (sharedStrings, workbook.xml, spans), til import-testene.
 * Kun udvikling – kommer ikke med i release.
 *
 *   php tests/make_xlsx.php ud.xlsx '[["E-mail"],["a@x.dk"],null,["b@y.dk"]]'
 *
 *  - en række er en liste af celler; null i stedet for en række udelader rækken helt (rækkenummeret springes over)
 *  - false i en celle giver en formateret tom celle, [] en række uden celler
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
[$script, $out, $json] = $argv + [null, null, null];
if (!$out || !$json) { fwrite(STDERR, "Brug: php tests/make_xlsx.php ud.xlsx '<json>'\n"); exit(2); }
$rows = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

$shared = []; $idx = [];
$si = static function (string $s) use (&$shared, &$idx): int {
    if (!isset($idx[$s])) { $idx[$s] = count($shared); $shared[] = $s; }
    return $idx[$s];
};
$col = static function (int $i): string { $c = ''; $n = $i + 1; while ($n > 0) { $m = ($n - 1) % 26; $c = chr(65 + $m).$c; $n = intdiv($n - 1, 26); } return $c; };
$xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
foreach ($rows as $ri => $row) {
    if ($row === null) continue;
    $r = $ri + 1;
    $xml .= '<row r="'.$r.'"'.(count($row) ? ' spans="1:'.count($row).'"' : '').'>';
    foreach ($row as $ci => $v) {
        if ($v === null || $v === '') continue;
        $ref = $col($ci).$r;
        $xml .= $v === false ? '<c r="'.$ref.'" s="1"/>' : '<c r="'.$ref.'" t="s"><v>'.$si((string)$v).'</v></c>';
    }
    $xml .= '</row>';
}
$xml .= '</sheetData></worksheet>';
$ss = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
foreach ($shared as $s) $ss .= '<si><t xml:space="preserve">'.htmlspecialchars($s, ENT_XML1).'</t></si>';
$ss .= '</sst>';

@unlink($out);
$z = new ZipArchive();
if ($z->open($out, ZipArchive::CREATE) !== true) { fwrite(STDERR, "Kunne ikke oprette $out\n"); exit(1); }
$z->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="xml" ContentType="application/xml"/></Types>');
$z->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheets><sheet name="Ark1" sheetId="1"/></sheets></workbook>');
$z->addFromString('xl/sharedStrings.xml', $ss);
$z->addFromString('xl/worksheets/sheet1.xml', $xml);
// Windows har en grænse på 260 tegn i stier; ZipArchive skriver en midlertidig fil ved siden af målet og fejler ved lange stier.
if (!$z->close()) { fwrite(STDERR, "Kunne ikke gemme $out (for lang sti? brug en kort mappe som mktemp -d)\n"); exit(1); }
