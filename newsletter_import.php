<?php
declare(strict_types=1);
require __DIR__.'/auth.php';
require_module_enabled('newsletter');
require_capability('newsletter.send');
require_once __DIR__.'/core/newsletter.php';

// Forhåndsvisningen ligger kun i sessionen under et tilfældigt token – aldrig i storage/ eller i databasen,
// fordi Kims fil kan indeholde andre persondata. Den slettes, når importen er gennemført eller annulleret,
// og en udløbet forhåndsvisning slettes her, uanset om siden åbnes med dens token.
hsg_newsletter_preview_expire();
$expiredMessage = 'Forhåndsvisningen er udløbet – upload filen igen.';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'analyse') {
        try {
            $mode = hsg_newsletter_mode((string)($_POST['mode'] ?? 'customer'));
            $file = is_array($_FILES['file'] ?? null) ? $_FILES['file'] : [];
            $data = hsg_newsletter_read_upload($file);
            $sheet = (string)array_key_first($data['sheets']);
            $cols = hsg_newsletter_detect_columns($data['sheets'][$sheet], hsg_newsletter_saved_columns($pdo));
            $token = hsg_newsletter_preview_create([
                'filename' => hsg_newsletter_clean_text(basename(str_replace('\\', '/', (string)($file['name'] ?? 'fil'))), 180),
                'sheets' => $data['sheets'], 'rownums' => $data['rownums'], 'sheet' => $sheet, 'mode' => $mode, 'cols' => $cols,
            ]);
            redirect('newsletter_import.php?preview='.$token);
        } catch (PDOException $e) {
            flash('error', 'Databasefejl – filen blev ikke analyseret.');
        } catch (RuntimeException $e) {
            flash('error', $e->getMessage());
        } catch (Throwable $e) {
            flash('error', 'Filen kunne ikke læses. Gem den som Excel-fil (.xlsx) eller CSV-fil, og prøv igen.');
        }
        redirect('newsletter_import.php');
    }

    $p = hsg_newsletter_preview_load((string)($_POST['preview_token'] ?? ''));
    if ($p === null) { flash('error', $expiredMessage); redirect('newsletter_import.php'); }
    $previewUrl = 'newsletter_import.php?preview='.$p['token'];

    if ($action === 'cancel') {
        hsg_newsletter_preview_clear();
        flash('success', 'Importen er annulleret. Intet er ændret.');
        redirect('newsletter_import.php');
    }

    if ($action === 'remap') {
        $sheet = (string)($_POST['sheet'] ?? $p['sheet']);
        if (!isset($p['sheets'][$sheet])) $sheet = (string)$p['sheet'];
        $rows = $p['sheets'][$sheet];
        $mode = hsg_newsletter_mode((string)($_POST['mode'] ?? $p['mode']));
        if ($sheet !== $p['sheet']) {
            $cols = hsg_newsletter_detect_columns($rows, hsg_newsletter_saved_columns($pdo));
        } else {
            $max = hsg_newsletter_column_count($rows);
            $pick = static function (string $key, bool $optional) use ($max): ?int {
                $v = trim((string)($_POST[$key] ?? ''));
                if ($v !== '' && ctype_digit($v) && (int)$v < $max) return (int)$v;
                return $optional ? null : 3;
            };
            $cols = (array)$p['cols'];
            $cols['email'] = $pick('col_email', false);
            $cols['name'] = $pick('col_name', true);
            $cols['company'] = $pick('col_company', true);
            $cols['segment'] = $pick('col_segment', true);
            hsg_newsletter_remember_columns($pdo, $rows, $cols);
        }
        $p['sheet'] = $sheet; $p['mode'] = $mode; $p['cols'] = $cols;
        hsg_newsletter_preview_save($p);
        flash('success', 'Forhåndsvisningen er genberegnet.');
        redirect($previewUrl);
    }

    if ($action === 'apply') {
        try {
            $rows = $p['sheets'][$p['sheet']] ?? [];
            $res = hsg_newsletter_import_apply($pdo, $rows, (array)$p['cols'], (string)$p['mode'], (string)$p['filename'], (string)$p['sheet']);
            hsg_newsletter_remember_columns($pdo, $rows, (array)$p['cols']);
            hsg_newsletter_preview_clear();
            flash('success', sprintf('Importen er gennemført: %d nye, %d opdateret, %d uændrede og %d sprunget over.', $res['created'], $res['updated'], $res['unchanged'], $res['skipped']));
            redirect('newsletter_recipients.php');
        } catch (PDOException $e) {
            flash('error', 'Databasefejl – importen blev ikke gennemført, og intet er ændret.');
        } catch (RuntimeException $e) {
            flash('error', $e->getMessage());
        }
        redirect($previewUrl);
    }

    flash('error', 'Ukendt handling.');
    redirect('newsletter_import.php');
}

$p = null;
$previewToken = (string)($_GET['preview'] ?? '');
if ($previewToken !== '') {
    $p = hsg_newsletter_preview_load($previewToken);
    if ($p === null) { flash('error', $expiredMessage); redirect('newsletter_import.php'); }
}

$chk = 'style="width:auto;min-height:0;margin:0"';
$modeOptions = ['customer' => 'Kunder', 'dealer' => 'Forhandlere', 'column' => 'Brug kolonne'];
$listLimit = 200;

if ($p) {
    $rows = $p['sheets'][$p['sheet']] ?? [];
    $rowNumbers = (array)($p['rownums'][$p['sheet']] ?? []);
    $cols = (array)$p['cols'];
    $mode = (string)$p['mode'];
    $a = hsg_newsletter_analyse($rows, $cols, $mode, hsg_newsletter_existing_map($pdo), $rowNumbers);
    $headerRowNo = $cols['header_row'] !== null ? (int)($rowNumbers[(int)$cols['header_row']] ?? ((int)$cols['header_row'] + 1)) : null;
    $n = hsg_newsletter_analysis_counts($a);
    if ($n['valid'] === 0) flash('error', 'Filen indeholder ingen gyldige mailadresser.');
    if ($a['segment_missing']) flash('error', 'Vælg den kolonne, der angiver forhandler eller kunde, under "Type-kolonne", og tryk Genberegn.');
    $canImport = $n['valid'] > 0 && !$a['segment_missing'];
    $maxCols = hsg_newsletter_column_count($rows);
    $headerCells = $cols['header_row'] !== null ? (array)($rows[(int)$cols['header_row']] ?? []) : [];
    $colLabel = static function (int $i) use ($headerCells): string {
        $header = hsg_newsletter_truncate(trim((string)($headerCells[$i] ?? '')), 40);
        return 'Kolonne '.hsg_newsletter_col_letter($i).($header !== '' ? ' – "'.$header.'"' : '');
    };
}

page_header('Nyhedsbrev');
echo hsg_newsletter_tabs_html('import');
?>
<div class="card">
  <h2>Import af modtagere</h2>
  <p class="muted">Indlæs Kims kundeliste. Systemet gemmer kun mail, navn, firma og type – andre kolonner gemmes aldrig i databasen. Afmeldte, stoppede og slettede modtagere springes altid over og tilmeldes aldrig igen af en import.</p>
  <form method="post" enctype="multipart/form-data"><?=csrf_field()?><input type="hidden" name="action" value="analyse">
    <label>Excel/CSV-fil<input type="file" name="file" accept=".xlsx,.csv" required></label>
    <p class="muted" style="margin-top:-4px">.xlsx eller .csv, højst 5 MB og 5.000 rækker. Kun kolonne A–AD læses. Mailkolonnen findes ud fra overskriften (fx "E-mail" eller "Mail") – ellers bruges kolonne D.</p>
    <fieldset style="border:0;padding:0;margin:10px 0">
      <legend style="font-weight:700;font-size:13px">Hvem er i filen?</legend>
      <div class="actions" style="margin-top:6px">
        <?php foreach ($modeOptions as $k => $label): ?>
          <label class="check" style="margin:0;min-height:var(--touch-min)"><input type="radio" name="mode" value="<?=h($k)?>" <?=$k === 'customer' ? 'checked' : ''?> <?=$chk?>> <?=h($label)?></label>
        <?php endforeach; ?>
      </div>
      <p class="muted">Ved "Brug kolonne" bliver værdier, der starter med "forhandler", til Forhandler – alt andet til Kunde.</p>
    </fieldset>
    <button type="submit">Analysér fil</button>
  </form>
</div>

<?php if ($p): ?>
<div class="card">
  <h2 style="margin-bottom:4px">Forhåndsvisning: <?=h($p['filename'])?></h2>
  <p class="muted" style="margin-top:0">(<?=h($p['sheet'])?>, <?=$headerRowNo !== null ? 'overskrift i række '.$headerRowNo : 'ingen overskriftsrække fundet'?>) · <?=h(hsg_newsletter_mode_label($mode))?></p>

  <form method="post"><?=csrf_field()?><input type="hidden" name="action" value="remap"><input type="hidden" name="preview_token" value="<?=h($p['token'])?>">
    <?php if (count($p['sheets']) > 1): ?>
      <label>Ark<select name="sheet"><?php foreach (array_keys($p['sheets']) as $sheetName): ?><option value="<?=h($sheetName)?>" <?=$sheetName === $p['sheet'] ? 'selected' : ''?>><?=h($sheetName)?></option><?php endforeach; ?></select></label>
    <?php endif; ?>
    <div class="split">
      <label>Mailkolonne<select name="col_email"><?php for ($i = 0; $i < $maxCols; $i++): ?><option value="<?=$i?>" <?=(int)$cols['email'] === $i ? 'selected' : ''?>><?=h($colLabel($i))?></option><?php endfor; ?></select></label>
      <label>Navn<select name="col_name"><option value="">– ingen –</option><?php for ($i = 0; $i < $maxCols; $i++): ?><option value="<?=$i?>" <?=$cols['name'] !== null && (int)$cols['name'] === $i ? 'selected' : ''?>><?=h($colLabel($i))?></option><?php endfor; ?></select></label>
      <label>Firma<select name="col_company"><option value="">– ingen –</option><?php for ($i = 0; $i < $maxCols; $i++): ?><option value="<?=$i?>" <?=$cols['company'] !== null && (int)$cols['company'] === $i ? 'selected' : ''?>><?=h($colLabel($i))?></option><?php endfor; ?></select></label>
      <label>Type-kolonne (kun ved "Brug kolonne")<select name="col_segment"><option value="">– ingen –</option><?php for ($i = 0; $i < $maxCols; $i++): ?><option value="<?=$i?>" <?=$cols['segment'] !== null && (int)$cols['segment'] === $i ? 'selected' : ''?>><?=h($colLabel($i))?></option><?php endfor; ?></select></label>
    </div>
    <fieldset style="border:0;padding:0;margin:4px 0 10px">
      <legend style="font-weight:700;font-size:13px">Hvem er i filen?</legend>
      <div class="actions" style="margin-top:6px">
        <?php foreach ($modeOptions as $k => $label): ?>
          <label class="check" style="margin:0;min-height:var(--touch-min)"><input type="radio" name="mode" value="<?=h($k)?>" <?=$mode === $k ? 'checked' : ''?> <?=$chk?>> <?=h($label)?></label>
        <?php endforeach; ?>
      </div>
    </fieldset>
    <button type="submit" class="secondary">Genberegn</button>
  </form>
</div>

<div class="grid">
  <div class="card metric"><strong><?=$n['new']?></strong><span>Nye</span></div>
  <div class="card metric"><strong><?=$n['update']?></strong><span>Opdateres</span></div>
  <div class="card metric"><strong><?=$n['unchanged']?></strong><span>Uændrede</span></div>
  <div class="card metric"><strong><?=$n['skipped']?></strong><span>Afmeldt/stoppet – springes over</span></div>
  <div class="card metric"><strong><?=$n['invalid']?></strong><span>Ugyldige</span></div>
</div>

<div class="card">
  <p style="margin-top:0">Dubletter i filen: <strong><?=$n['duplicates']?></strong> · Konflikter (importeres som Kunde): <strong><?=$n['conflicts']?></strong></p>
  <p>Skifter type: <strong><?=$n['type_changes']?></strong> · Aktive modtagere, der ikke står i filen: <strong><?=$n['missing_active']?></strong></p>
  <p class="muted">Aktive modtagere, der ikke står i filen, ændres ikke af importen.<?php if ($n['valid'] === 0): ?> Kontrollér mailkolonnen, og tryk Genberegn.<?php endif; ?></p>

  <?php
  $more = static function (int $total) use ($listLimit): string {
      return $total > $listLimit ? '<li class="muted">… og '.($total - $listLimit).' flere</li>' : '';
  };
  ?>
  <?php if ($a['invalid']): ?>
  <details><summary style="cursor:pointer;font-weight:700">Ugyldige (<?=count($a['invalid'])?>)</summary><ul>
    <?php foreach (array_slice($a['invalid'], 0, $listLimit) as $x): ?><li>række <?=intval($x['row'])?>: "<?=h($x['value'])?>"</li><?php endforeach; ?>
    <?=$more(count($a['invalid']))?>
  </ul></details>
  <?php endif; ?>
  <?php if ($a['skipped']): ?>
  <details><summary style="cursor:pointer;font-weight:700">Springes over (<?=count($a['skipped'])?>)</summary><ul>
    <?php foreach (array_slice($a['skipped'], 0, $listLimit) as $x): ?><li>række <?=intval($x['row'])?>: <?=h(hsg_newsletter_mask_email($x['email']))?> – <?=h(hsg_newsletter_status_label((string)$x['status']))?></li><?php endforeach; ?>
    <?=$more(count($a['skipped']))?>
  </ul></details>
  <?php endif; ?>
  <?php if ($a['type_changes']): ?>
  <details open><summary style="cursor:pointer;font-weight:700">Skifter type (<?=count($a['type_changes'])?>)</summary><ul>
    <?php foreach (array_slice($a['type_changes'], 0, $listLimit) as $x): ?><li>række <?=intval($x['row'])?>: <?=h($x['email'])?> – <strong><?=h(hsg_newsletter_segment_label((string)$x['from']))?> -> <?=h(hsg_newsletter_segment_label((string)$x['to']))?></strong> (<?=h(hsg_newsletter_segment_effect((string)$x['to']))?>)</li><?php endforeach; ?>
    <?=$more(count($a['type_changes']))?>
  </ul></details>
  <?php endif; ?>
  <?php if ($a['conflicts']): ?>
  <details><summary style="cursor:pointer;font-weight:700">Konflikter (<?=count($a['conflicts'])?>)</summary><ul>
    <?php foreach (array_slice($a['conflicts'], 0, $listLimit) as $x): ?><li>række <?=h(implode(', ', array_map('intval', (array)$x['rows'])))?>: <?=h($x['email'])?> står både som forhandler og kunde – importeres som Kunde</li><?php endforeach; ?>
    <?=$more(count($a['conflicts']))?>
  </ul></details>
  <?php endif; ?>
  <?php if ($a['new']): ?>
  <details><summary style="cursor:pointer;font-weight:700">Nye (<?=count($a['new'])?>)</summary><ul>
    <?php foreach (array_slice($a['new'], 0, $listLimit) as $hash): $e = $a['entries'][$hash]; ?><li>række <?=intval($e['row'])?>: <?=h($e['email'])?><?=$e['name'] !== '' ? ' – '.h($e['name']) : ''?><?=$e['company'] !== '' ? ', '.h($e['company']) : ''?> – <?=h(hsg_newsletter_segment_label($e['segment']))?></li><?php endforeach; ?>
    <?=$more(count($a['new']))?>
  </ul></details>
  <?php endif; ?>

  <div class="actions" style="margin-top:14px">
    <form method="post"><?=csrf_field()?><input type="hidden" name="action" value="apply"><input type="hidden" name="preview_token" value="<?=h($p['token'])?>">
      <button type="submit" <?=$canImport ? '' : 'disabled'?>>Importér <?=$n['new']?> nye og opdatér <?=$n['update']?></button>
    </form>
    <form method="post"><?=csrf_field()?><input type="hidden" name="action" value="cancel"><input type="hidden" name="preview_token" value="<?=h($p['token'])?>">
      <button type="submit" class="secondary">Annullér</button>
    </form>
  </div>
</div>
<?php endif; ?>
<?php page_footer();
