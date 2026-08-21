<?php
declare(strict_types=1);
require __DIR__.'/auth.php';
require_admin();

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
    redirect('system.php');
}

$modules=hsg_module_manifests();
$states=hsg_module_state($pdo);
$audits=$pdo->query("SELECT * FROM hsg_audit_log ORDER BY created_at DESC,id DESC LIMIT 80")->fetchAll();
page_header('System');
?>
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
    <button>Gem indstillinger</button>
  </form>
</div>

<div class="card">
  <h2>Moduler</h2>
  <p class="muted">Nye forretningsområder installeres som selvstændige moduler med eget versionsnummer og egne databaseændringer. Lagerdata behøver ikke ændres, når fx indkøb, kunder eller events tilføjes.</p>
  <div class="table-wrap"><table><thead><tr><th>Modul</th><th>Version</th><th>Type</th><th>Status</th><th>Handling</th></tr></thead><tbody>
  <?php foreach($modules as $id=>$m): $state=$states[$id]??[];$installed=$state['version']??$m['version'];$enabled=hsg_module_is_enabled($id);$core=!empty($m['core']); ?>
    <tr>
      <td><strong><?=h($m['name']??$id)?></strong><br><small class="muted"><?=h($m['description']??'')?></small><br><code><?=h($id)?></code></td>
      <td><?=h($installed)?></td>
      <td><?=$core?'<span class="badge">Kerne</span>':'Udvidelse'?></td>
      <td><?=$enabled?'<span class="badge green">Aktiv</span>':'<span class="badge">Deaktiveret</span>'?></td>
      <td>
        <?php if($core):?><span class="muted">Altid aktiv</span>
        <?php else:?><form method="post" style="display:inline"><?=csrf_field()?><input type="hidden" name="action" value="toggle_module"><input type="hidden" name="module_id" value="<?=h($id)?>"><input type="hidden" name="enabled" value="<?=$enabled?'0':'1'?>"><button class="secondary"><?=$enabled?'Deaktivér':'Aktivér'?></button></form><?php endif;?>
      </td>
    </tr>
  <?php endforeach;?>
  </tbody></table></div>
</div>

<div class="card">
  <h2>Platformfundament</h2>
  <div class="three">
    <div><strong>Fælles kerne</strong><p class="muted">Sikkert admin-login, direkte lagerlinks, rettigheder, indstillinger, audit-log og modulstyring deles af hele platformen.</p></div>
    <div><strong>Adskilte moduler</strong><p class="muted">Hvert forretningsområde har egne filer og kan have egne tabeller og migrations. Et nyt modul skal derfor ikke omskrive lagerdelen.</p></div>
    <div><strong>Opgraderbar</strong><p class="muted">Platformen og hvert modul har versionsnumre. Databaseændringer køres automatisk ved opdatering, mens eksisterende data bevares.</p></div>
  </div>
</div>

<div class="card">
  <h2>Mulige næste HSG-moduler</h2>
  <p class="muted">Disse er ikke aktiveret endnu, men platformen er nu struktureret til at de kan tilføjes separat:</p>
  <div class="three">
    <div><strong>Indkøb & leverandører</strong><p class="muted">Outturn-lister, indkøbsordrer, kostpriser, afgifter, forventet avance og varemodtagelse.</p></div>
    <div><strong>Kunder & salg</strong><p class="muted">Kunder, tilbud, reservationer, salgsordrer og evt. synkronisering med WooCommerce.</p></div>
    <div><strong>Fade & anparter</strong><p class="muted">Fade, anpartshavere, hjemtagelse, betaling, flaskefordeling og historik.</p></div>
    <div><strong>Events</strong><p class="muted">Smagninger, deltagere, flaskeforbrug, økonomi og partnerbesøg.</p></div>
    <div><strong>Økonomi</strong><p class="muted">Dækningsbidrag, lagerbinding, prisberegning og integration til økonomisystem/datasæt.</p></div>
    <div><strong>Rapporter</strong><p class="muted">Salg, lageromsætning, reservationer, indkøb og ledelsesoverblik på tværs af moduler.</p></div>
  </div>
</div>

<div class="card">
  <h2>Audit-log</h2>
  <div class="table-wrap"><table><thead><tr><th>Dato</th><th>Bruger</th><th>Handling</th><th>Objekt</th><th>Detaljer</th></tr></thead><tbody>
  <?php foreach($audits as $a):?>
    <tr><td><?=h($a['created_at'])?></td><td><?=h($a['actor_name'])?><br><span class="muted"><?=h($a['actor_type'])?></span></td><td><?=h($a['action'])?></td><td><?=h($a['entity_type'])?><?=($a['entity_id']!==null?' #'.h($a['entity_id']):'')?></td><td><small><?=h($a['details_json']??'')?></small></td></tr>
  <?php endforeach;?>
  <?php if(!$audits):?><tr><td colspan="5" class="muted">Der er endnu ingen logposter.</td></tr><?php endif;?>
  </tbody></table></div>
</div>
<?php page_footer();
