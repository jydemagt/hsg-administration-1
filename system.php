<?php
declare(strict_types=1);
require __DIR__.'/auth.php';
require_admin();

$tab = (string)($_GET['tab'] ?? 'settings');
if (!in_array($tab, ['settings', 'modules', 'audit', 'activity'], true)) {
    $tab = 'settings';
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    $action=(string)($_POST['action']??'settings');
    try {
        if($action==='toggle_module'){
            $moduleId=(string)($_POST['module_id']??'');
            $enabled=(string)($_POST['enabled']??'0')==='1';
            hsg_set_module_enabled($pdo,$moduleId,$enabled);
            audit_log($pdo,$enabled?'module.enable':'module.disable','module',$moduleId,['enabled'=>$enabled]);
            flash('success','Modulet er '.($enabled?'aktiveret.':'deaktiveret.'));
        } else {
            $name=trim((string)($_POST['platform_name']??'HSG Administration'));
            $company=trim((string)($_POST['company_name']??'HSG Whisky ApS'));
            if($name==='') $name='HSG Administration';
            setting_set($pdo,'platform_name',$name);
            setting_set($pdo,'company_name',$company);
            audit_log($pdo,'settings.update','system',null,['platform_name'=>$name,'company_name'=>$company]);
            flash('success','Systemindstillinger gemt.');
        }
    } catch(Throwable $e){
        flash('error',$e->getMessage());
    }
    redirect('system.php?tab='.$tab);
}

$modules=hsg_module_manifests();
$states=hsg_module_state($pdo);

$qLog = trim((string)($_GET['q'] ?? ''));
$entityLog = trim((string)($_GET['entity'] ?? ''));

$logConds = [];
$logParams = [];

if ($qLog !== '') {
    $logConds[] = "(action LIKE ? OR actor_name LIKE ? OR details_json LIKE ?)";
    $like = '%' . $qLog . '%';
    $logParams = [$like, $like, $like];
}
if ($entityLog !== '') {
    $logConds[] = "entity_type = ?";
    $logParams[] = $entityLog;
}

$logWhereSql = $logConds ? 'WHERE ' . implode(' AND ', $logConds) : '';

$audits = $pdo->prepare("SELECT * FROM hsg_audit_log {$logWhereSql} ORDER BY created_at DESC, id DESC LIMIT 150");
$audits->execute($logParams);
$auditRows = $audits->fetchAll(PDO::FETCH_ASSOC);

page_header('System & Auditlog');
?>

<div class="simple-tabs" style="margin-bottom:1rem;">
  <a class="button <?=$tab==='settings'?'':'secondary'?>" href="system.php?tab=settings">⚙️ Indstillinger</a>
  <a class="button <?=$tab==='modules'?'':'secondary'?>" href="system.php?tab=modules">🧩 Moduler</a>
  <a class="button <?=$tab==='audit'?'':'secondary'?>" href="system.php?tab=audit">📜 Auditlog</a>
  <a class="button <?=$tab==='activity'?'':'secondary'?>" href="system.php?tab=activity">📈 Aktivitetsside</a>
</div>

<?php if($tab === 'settings'): ?>

<div class="grid">
  <div class="card metric"><strong><?=h(app_version())?></strong><span>Platformversion</span></div>
  <div class="card metric"><strong><?=count($modules)?></strong><span>Installerede moduler</span></div>
  <div class="card metric"><strong><?=count(array_filter($modules,fn($m)=>hsg_module_is_enabled((string)$m['id'])))?></strong><span>Aktive moduler</span></div>
</div>

<div class="card">
  <h2>Platformindstillinger</h2>
  <p class="muted">Fælles indstillinger, som kan bruges af alle nuværende og fremtidige HSG-moduler.</p>
  <form method="post"><?=csrf_field()?>
    <input type="hidden" name="action" value="settings">
    <div class="split">
      <label>Systemnavn<input name="platform_name" value="<?=h(setting_get($pdo,'platform_name','HSG Administration'))?>"></label>
      <label>Virksomhed<input name="company_name" value="<?=h(setting_get($pdo,'company_name','HSG Whisky ApS'))?>"></label>
    </div>
    <button type="submit">Gem indstillinger</button>
  </form>
</div>

<?php elseif($tab === 'modules'): ?>

<div class="card">
  <h2>Moduler</h2>
  <p class="muted">Nye forretningsområder installeres som selvstændige moduler med eget versionsnummer og egne databaseændringer.</p>
  <div class="table-wrap"><table><thead><tr><th>Modul</th><th>Version</th><th>Type</th><th>Status</th><th>Handling</th></tr></thead><tbody>
  <?php foreach($modules as $id=>$m): $state=$states[$id]??[];$installed=$state['version']??$m['version'];$enabled=hsg_module_is_enabled($id);$core=!empty($m['core']); ?>
    <tr>
      <td><strong><?=h($m['name']??$id)?></strong><br><small class="muted"><?=h($m['description']??'')?></small><br><code><?=h($id)?></code></td>
      <td><?=h($installed)?></td>
      <td><?=$core?'<span class="badge">Kerne</span>':'Udvidelse'?></td>
      <td><?=$enabled?'<span class="badge green">Aktiv</span>':'<span class="badge">Deaktiveret</span>'?></td>
      <td>
        <?php if($core):?><span class="muted">Altid aktiv</span>
        <?php else:?><form method="post" style="display:inline"><?=csrf_field()?><input type="hidden" name="action" value="toggle_module"><input type="hidden" name="module_id" value="<?=h($id)?>"><input type="hidden" name="enabled" value="<?=$enabled?'0':'1'?>"><button type="submit" class="secondary"><?=$enabled?'Deaktivér':'Aktivér'?></button></form><?php endif;?>
      </td>
    </tr>
  <?php endforeach;?>
  </tbody></table></div>
</div>

<?php elseif($tab === 'audit' || $tab === 'activity'): ?>

<div class="card">
  <h2><?=$tab === 'activity' ? '📈 Aktivitetsside' : '📜 System Auditlog'?></h2>
  <p class="muted">Kronologisk overblik over ændringer af produkter, lager, reservationer og brugere.</p>

  <form class="searchbar" method="get">
    <input type="hidden" name="tab" value="<?=h($tab)?>">
    <input name="q" value="<?=h($qLog)?>" placeholder="Søg på handling, bruger eller detaljer...">
    <select name="entity">
      <option value="">Alle entiteter</option>
      <option value="product" <?=$entityLog==='product'?'selected':''?>>Produkter</option>
      <option value="stock" <?=$entityLog==='stock'?'selected':''?>>Lager</option>
      <option value="reservation" <?=$entityLog==='reservation'?'selected':''?>>Reservationer</option>
      <option value="user" <?=$entityLog==='user'?'selected':''?>>Brugere</option>
      <option value="system" <?=$entityLog==='system'?'selected':''?>>System</option>
    </select>
    <button type="submit">Søg i log</button>
    <?php if($qLog !== '' || $entityLog !== ''):?>
      <a class="button secondary" href="system.php?tab=<?=h($tab)?>">Nulstil filtre</a>
    <?php endif;?>
  </form>

  <div class="table-wrap"><table><thead><tr><th>Tidspunkt</th><th>Bruger</th><th>Handling</th><th>Entitet</th><th>Detaljer</th></tr></thead><tbody>
  <?php foreach($auditRows as $a):?>
    <tr>
      <td><?=h($a['created_at'])?></td>
      <td><strong><?=h($a['actor_name']?:'System')?></strong><br><small class="muted"><?=h($a['actor_type'])?></small></td>
      <td><span class="badge blue"><?=h($a['action'])?></span></td>
      <td><?=h($a['entity_type'])?><?=($a['entity_id']!==null?' #'.h($a['entity_id']):'')?></td>
      <td><small><?=h($a['details_json']??'')?></small></td>
    </tr>
  <?php endforeach;?>
  <?php if(!$auditRows):?>
    <tr><td colspan="5" class="muted" style="text-align:center; padding:24px;">Ingen logposter fundet med de valgte filtre. <a class="button secondary small" href="system.php?tab=<?=h($tab)?>">Ryd filtre</a></td></tr>
  <?php endif;?>
  </tbody></table></div>
</div>

<?php endif; ?>

<?php page_footer();
