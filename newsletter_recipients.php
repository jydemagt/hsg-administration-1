<?php
declare(strict_types=1);
require __DIR__.'/auth.php';
require_module_enabled('newsletter');
require_capability('newsletter.send');
require_once __DIR__.'/core/newsletter.php';

// Mailadresser må aldrig stå i URL'er: siderne bruger ?id=, søgning sker i browseren,
// og eksport og sletning sker via POST.

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = hsg_newsletter_input($_POST, 'action');
    $id = hsg_newsletter_input_id($_POST, 'id');
    $back = $id > 0 ? 'newsletter_recipients.php?id='.$id : 'newsletter_recipients.php';
    if (hsg_newsletter_has_list($_POST)) { flash('error', HSG_NEWSLETTER_INVALID_FORM); redirect($back); }
    try {
        if ($action === 'create') {
            $email = hsg_newsletter_input($_POST, 'email');
            $name = hsg_newsletter_input($_POST, 'name');
            $company = hsg_newsletter_input($_POST, 'company');
            $segment = hsg_newsletter_segment(hsg_newsletter_input($_POST, 'segment', 'customer'));
            $r = hsg_newsletter_add($pdo, $email, $name, $company, $segment, !empty($_POST['self_requested']));
            if ($r['result'] === 'created') { flash('success', 'Modtageren er tilføjet.'); redirect('newsletter_recipients.php'); }
            if ($r['result'] === 'reactivated') { flash('success', 'Modtageren er tilmeldt igen.'); redirect('newsletter_recipients.php?id='.(int)$r['id']); }
            $messages = [
                'invalid' => 'Mailadressen er ugyldig. Kontrollér stavemåden.',
                'exists' => 'Adressen findes allerede.',
                'exists_stopped' => 'Adressen findes allerede, men modtageren er stoppet. Åbn modtageren for at genaktivere den.',
                'needs_confirmation' => 'Adressen er tidligere afmeldt/slettet. Sæt flueben, hvis modtageren selv har bedt om at blive tilmeldt igen.',
            ];
            $_SESSION['newsletter_add_form'] = [
                'email' => hsg_newsletter_truncate(trim($email), 190), 'name' => hsg_newsletter_clean_text($name), 'company' => hsg_newsletter_clean_text($company),
                'segment' => $segment, 'confirm' => $r['result'] === 'needs_confirmation',
                'existing_id' => in_array($r['result'], ['exists', 'exists_stopped'], true) ? (int)($r['id'] ?? 0) : 0,
            ];
            flash('error', $messages[$r['result']] ?? 'Modtageren kunne ikke tilføjes.');
            redirect('newsletter_recipients.php');
        }

        if ($action === 'update') {
            $r = hsg_newsletter_update($pdo, $id, hsg_newsletter_input($_POST, 'email'), hsg_newsletter_input($_POST, 'name'), hsg_newsletter_input($_POST, 'company'), hsg_newsletter_input($_POST, 'segment', 'customer'));
            if (!$r['ok']) flash('error', (string)$r['error']);
            else flash('success', $r['fields'] ? 'Ændringerne er gemt.' : 'Der var ingen ændringer at gemme.');
            redirect($back);
        }

        if ($action === 'unsubscribe') {
            if (hsg_newsletter_unsubscribe($pdo, $id, 'admin')) flash('success', 'Modtageren er afmeldt.');
            else flash('error', 'Kun aktive modtagere kan afmeldes.');
            redirect($back);
        }

        if ($action === 'stop') {
            if (hsg_newsletter_stop($pdo, $id)) flash('success', 'Modtageren er stoppet og får ikke flere nyhedsbreve.');
            else flash('error', 'Kun aktive modtagere kan stoppes.');
            redirect($back);
        }

        if ($action === 'reactivate') {
            $r = hsg_newsletter_reactivate($pdo, $id, !empty($_POST['self_requested']));
            if ($r === 'ok') flash('success', 'Modtageren er aktiv igen.');
            elseif ($r === 'needs_confirmation') flash('error', 'En afmeldt modtager kan kun genaktiveres, hvis modtageren selv har bedt om det. Sæt flueben for at bekræfte.');
            else flash('error', 'Modtageren kan ikke genaktiveres.');
            redirect($back);
        }

        if ($action === 'export') {
            $r = hsg_newsletter_get($pdo, $id);
            if ($r === null || $r['status'] === 'deleted') { flash('error', 'Modtageren findes ikke.'); redirect('newsletter_recipients.php'); }
            $csv = hsg_newsletter_csv(hsg_newsletter_export_rows($r));
            audit_log($pdo, 'newsletter.recipient.export', 'newsletter_recipient', (string)$id);
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="nyhedsbrev-modtager-'.$id.'.csv"');
            header('Cache-Control: no-store');
            header('X-Content-Type-Options: nosniff');
            echo $csv;
            exit;
        }

        if ($action === 'delete') {
            if (hsg_newsletter_delete($pdo, $id)) {
                flash('success', 'Modtageren er slettet. Mailadressen gemmes kun som en hash (SHA-256), så den ikke importeres igen.');
                redirect('newsletter_recipients.php');
            }
            flash('error', 'Modtageren findes ikke eller er allerede slettet.');
            redirect('newsletter_recipients.php');
        }
    } catch (PDOException $e) {
        flash('error', 'Databasefejl – handlingen blev ikke gennemført.');
        redirect($back);
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
        redirect($back);
    }
    flash('error', 'Ukendt handling.');
    redirect($back);
}

$segmentOptions = ['customer' => 'Kunde', 'dealer' => 'Forhandler'];
$id = hsg_newsletter_input_id($_GET, 'id');

/* ---------- Redigér én modtager ---------- */
if ($id > 0) {
    $r = hsg_newsletter_get($pdo, $id);
    if ($r === null || $r['status'] === 'deleted') {
        http_response_code(404);
        page_header('Nyhedsbrev');
        echo hsg_newsletter_tabs_html('recipients');
        echo '<div class="card"><p>Modtageren findes ikke eller er slettet.</p><p><a class="button secondary" href="newsletter_recipients.php">Til modtagerlisten</a></p></div>';
        page_footer();
        exit;
    }
    $status = (string)$r['status'];
    $isActive = $status === 'active';
    $unsubscribeUrl = !empty($r['token_cipher']) ? hsg_newsletter_unsubscribe_url(base_url(), (string)$r['token_cipher']) : null;
    page_header('Nyhedsbrev');
    echo hsg_newsletter_tabs_html('recipients');
    ?>
<p><a href="newsletter_recipients.php">‹ Tilbage til modtagerlisten</a></p>
<div class="card">
  <div class="page-title" style="margin-bottom:8px">
    <h2 style="margin:0">Modtager #<?=intval($r['id'])?></h2>
    <div class="actions"><span class="badge <?=h(hsg_newsletter_status_badge($status))?>"><?=h(hsg_newsletter_status_label($status))?></span><span class="badge <?=h(hsg_newsletter_segment_badge((string)$r['segment']))?>"><?=h(hsg_newsletter_segment_label((string)$r['segment']))?></span></div>
  </div>
  <form method="post"><?=csrf_field()?><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?=intval($r['id'])?>">
    <div class="split">
      <label>Mail<input type="email" name="email" value="<?=h($r['email'])?>" maxlength="190" required autocomplete="off" <?=$isActive ? '' : 'readonly'?>></label>
      <label>Type<select name="segment"><?php foreach ($segmentOptions as $k => $label): ?><option value="<?=h($k)?>" <?=$r['segment'] === $k ? 'selected' : ''?>><?=h($label)?></option><?php endforeach; ?></select></label>
      <label>Navn<input name="name" value="<?=h($r['name'])?>" maxlength="180" autocomplete="off"></label>
      <label>Firma<input name="company" value="<?=h($r['company'])?>" maxlength="180" autocomplete="off"></label>
    </div>
    <?php if (!$isActive): ?><p class="muted">Mailadressen kan ikke ændres, mens modtageren er <?=h(hsg_newsletter_status_label($status))?>, så spærringen af adressen bevares.</p><?php endif; ?>
    <button type="submit">Gem</button>
  </form>
</div>

<div class="card">
  <h2>Oplysninger</h2>
  <div class="split">
    <p><strong>Kilde</strong><br><?=h(hsg_newsletter_source_label((string)$r['source']))?></p>
    <p><strong>Oprettet</strong><br><?=h(hsg_newsletter_fmt_datetime($r['created_at']))?></p>
    <p><strong>Sidst set i import</strong><br><?=h(hsg_newsletter_fmt_datetime($r['last_import_at']) ?: '–')?></p>
    <p><strong>Afmeldt</strong><br><?=!empty($r['unsubscribed_at']) ? h(hsg_newsletter_fmt_datetime($r['unsubscribed_at']).' '.hsg_newsletter_via_label($r['unsubscribe_via'])) : '–'?></p>
  </div>
  <?php if ($unsubscribeUrl !== null): ?>
  <label>Personligt afmeldingslink<input type="text" readonly value="<?=h($unsubscribeUrl)?>" onclick="this.select()"></label>
  <p class="muted">Til manuel hjælp og test. Linket viser først en bekræftelse – der afmeldes ikke, før modtageren trykker "Ja, afmeld mig".</p>
  <?php else: ?>
  <p class="muted">Det personlige afmeldingslink kan ikke vises, fordi krypteringsnøglen mangler eller er ændret.</p>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Handlinger</h2>
  <div class="actions">
    <?php if ($isActive): ?>
    <form method="post" onsubmit="if(!confirm('Afmeld modtageren? En afmeldt modtager kan kun tilmeldes igen, hvis vedkommende selv beder om det.')){event.stopPropagation();return false;}"><?=csrf_field()?><input type="hidden" name="action" value="unsubscribe"><input type="hidden" name="id" value="<?=intval($r['id'])?>"><button type="submit" class="secondary">Afmeld</button></form>
    <form method="post"><?=csrf_field()?><input type="hidden" name="action" value="stop"><input type="hidden" name="id" value="<?=intval($r['id'])?>"><button type="submit" class="secondary">Stop</button></form>
    <?php elseif ($status === 'stopped'): ?>
    <form method="post"><?=csrf_field()?><input type="hidden" name="action" value="reactivate"><input type="hidden" name="id" value="<?=intval($r['id'])?>"><button type="submit" class="secondary">Genaktivér</button></form>
    <?php endif; ?>
    <form method="post" data-no-disable="1"><?=csrf_field()?><input type="hidden" name="action" value="export"><input type="hidden" name="id" value="<?=intval($r['id'])?>"><button type="submit" class="secondary">Eksportér data (CSV)</button></form>
    <form method="post" onsubmit="if(!confirm('Slet modtageren permanent? Mailadressen gemmes kun som en hash (SHA-256), så den ikke importeres igen.')){event.stopPropagation();return false;}"><?=csrf_field()?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=intval($r['id'])?>"><button type="submit" class="danger">Slet modtager</button></form>
  </div>
  <?php if ($status === 'unsubscribed'): ?>
  <form method="post" style="margin-top:14px"><?=csrf_field()?><input type="hidden" name="action" value="reactivate"><input type="hidden" name="id" value="<?=intval($r['id'])?>">
    <label class="check"><input type="checkbox" name="self_requested" value="1" required style="width:auto;min-height:0;margin:0"> Modtageren har selv bedt om at blive tilmeldt igen</label>
    <div><button type="submit" class="secondary">Genaktivér</button></div>
  </form>
  <?php endif; ?>
  <p class="muted"><strong>Stop</strong> bruges fx ved afviste mails. <strong>Eksportér data</strong> giver en CSV-fil til den registrerede (ret til indsigt). <strong>Slet modtager</strong> sletter mail, navn og firma (ret til sletning). Mailadressen gemmes kun som en hash (SHA-256), så den ikke importeres igen.</p>
</div>
<?php
    page_footer();
    exit;
}

/* ---------- Modtagerlisten ---------- */
$filters = hsg_newsletter_filters();
$filter = hsg_newsletter_input($_GET, 'filter', 'active');
if (!isset($filters[$filter])) $filter = 'active';
$counts = hsg_newsletter_counts($pdo);
$filterCounts = ['active' => $counts['active'] ?? 0, 'dealer' => $counts['dealers'] ?? 0, 'customer' => $counts['customers'] ?? 0, 'unsubscribed' => $counts['unsubscribed'] ?? 0, 'stopped' => $counts['stopped'] ?? 0];
$list = hsg_newsletter_list($pdo, $filter);
$form = $_SESSION['newsletter_add_form'] ?? null;
unset($_SESSION['newsletter_add_form']);
if (!is_array($form)) $form = null;

page_header('Nyhedsbrev');
echo hsg_newsletter_tabs_html('recipients');
?>
<div class="grid">
  <div class="card metric"><strong><?=intval($counts['active'] ?? 0)?></strong><span>Aktive</span></div>
  <div class="card metric"><strong><?=intval($counts['dealers'] ?? 0)?></strong><span>Forhandlere</span></div>
  <div class="card metric"><strong><?=intval($counts['customers'] ?? 0)?></strong><span>Kunder</span></div>
  <div class="card metric"><strong><?=intval($counts['unsubscribed'] ?? 0)?></strong><span>Afmeldte</span></div>
</div>

<div class="card">
  <div class="actions" role="group" aria-label="Vis modtagere">
    <span class="muted">Vis:</span>
    <?php foreach ($filters as $key => $label): ?>
      <a class="button small <?=$filter === $key ? '' : 'secondary'?>" href="newsletter_recipients.php?filter=<?=h($key)?>" <?=$filter === $key ? 'aria-current="page"' : ''?>><?=h($label)?> (<?=intval($filterCounts[$key])?>)</a>
    <?php endforeach; ?>
  </div>
  <label for="nl-search">Søg i listen</label>
  <input type="search" id="nl-search" placeholder="Mail, navn eller firma" autocomplete="off">
  <p class="muted" style="margin:6px 0 0">Søgningen sker kun i listen herunder i din browser.</p>
</div>

<details class="card" <?=$form ? 'open' : ''?>>
  <summary style="cursor:pointer;font-weight:700">+ Tilføj modtager</summary>
  <form method="post" style="margin-top:10px"><?=csrf_field()?><input type="hidden" name="action" value="create">
    <div class="split">
      <label>Mail *<input type="email" name="email" value="<?=h($form['email'] ?? '')?>" maxlength="190" required autocomplete="off"></label>
      <label>Type<select name="segment"><?php foreach ($segmentOptions as $k => $label): ?><option value="<?=h($k)?>" <?=($form['segment'] ?? 'customer') === $k ? 'selected' : ''?>><?=h($label)?></option><?php endforeach; ?></select></label>
      <label>Navn<input name="name" value="<?=h($form['name'] ?? '')?>" maxlength="180" autocomplete="off"></label>
      <label>Firma<input name="company" value="<?=h($form['company'] ?? '')?>" maxlength="180" autocomplete="off"></label>
    </div>
    <?php if (!empty($form['confirm'])): ?>
      <p class="flash warning" style="margin:8px 0">Adressen er tidligere afmeldt/slettet. Sæt flueben, hvis modtageren selv har bedt om at blive tilmeldt igen.</p>
      <label class="check"><input type="checkbox" name="self_requested" value="1" style="width:auto;min-height:0;margin:0"> Modtageren har selv bedt om at blive tilmeldt igen</label>
    <?php endif; ?>
    <?php if (!empty($form['existing_id'])): ?>
      <p><a href="newsletter_recipients.php?id=<?=intval($form['existing_id'])?>">Åbn den eksisterende modtager</a></p>
    <?php endif; ?>
    <button type="submit">Tilføj</button>
  </form>
</details>

<?php if (!$list): ?>
<div class="card"><p class="muted" style="margin:0"><?=$filter === 'active' && ($counts['active'] ?? 0) === 0 ? 'Der er ingen aktive modtagere endnu. Importér Kims liste under Import, eller tilføj en modtager.' : 'Der er ingen modtagere i denne visning.'?></p></div>
<?php else: ?>
<div class="table-wrap">
  <table id="nl-list">
    <thead><tr><th>Mail</th><th>Navn / firma</th><th>Type</th><th>Status</th><th aria-label="Handling"></th></tr></thead>
    <tbody>
    <?php foreach ($list as $row): ?>
      <tr>
        <td><a href="newsletter_recipients.php?id=<?=intval($row['id'])?>"><?=h($row['email'])?></a></td>
        <td><?=h($row['name'] ?? '')?><?php if (!empty($row['company'])): ?><br><small class="muted"><?=h($row['company'])?></small><?php endif; ?></td>
        <td><span class="badge <?=h(hsg_newsletter_segment_badge((string)$row['segment']))?>"><?=h(hsg_newsletter_segment_label((string)$row['segment']))?></span></td>
        <td><span class="badge <?=h(hsg_newsletter_status_badge((string)$row['status']))?>"><?=h(hsg_newsletter_status_label((string)$row['status']))?></span></td>
        <td><a class="button small secondary" href="newsletter_recipients.php?id=<?=intval($row['id'])?>">Rediger</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<p class="muted" id="nl-empty" hidden>Ingen modtagere matcher søgningen.</p>
<?php endif; ?>
<script>
(function(){
  var input = document.getElementById('nl-search');
  var table = document.getElementById('nl-list');
  var empty = document.getElementById('nl-empty');
  if (!input || !table) return;
  var rows = table.tBodies[0] ? table.tBodies[0].rows : [];
  input.addEventListener('input', function(){
    var q = input.value.trim().toLowerCase(), shown = 0;
    for (var i = 0; i < rows.length; i++) {
      var match = q === '' || rows[i].textContent.toLowerCase().indexOf(q) !== -1;
      rows[i].hidden = !match;
      if (match) shown++;
    }
    if (empty) empty.hidden = shown !== 0;
  });
})();
</script>
<?php page_footer();
