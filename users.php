<?php
declare(strict_types=1);
require __DIR__.'/auth.php';require_module_enabled('access');
if(!is_superadmin()){ http_response_code(403); exit('Oprettelse og brugestyring er forbeholdt hoved-administrator.'); }
require_capability('users.manage');require_once __DIR__.'/core/access_links.php';
$tab=(string)($_GET['tab']??'links'); if(!in_array($tab,['links','admins'],true))$tab='links';
$status=(string)($_GET['status']??'active');if(!in_array($status,['active','inactive','all'],true))$status='active';
$assignableModules = array_filter(hsg_module_manifests(), static fn(array $m): bool => $m['id'] !== 'access' && hsg_module_is_enabled((string)$m['id']));

if($_SERVER['REQUEST_METHOD']==='POST'){
 $action=(string)($_POST['action']??'');
 if($action==='create_admin'){
   $username=trim((string)($_POST['username']??''));
   $displayName=trim((string)($_POST['display_name']??''));
   $password=(string)($_POST['password']??'');
   $selectedMods=(array)($_POST['modules']??[]);
   if($username===''||$displayName===''||$password===''){
     flash('error','Brugernavn, visningsnavn og adgangskode skal udfyldes.');
   } else {
     try{
       $hash=password_hash($password,PASSWORD_DEFAULT);
       $st=$pdo->prepare('INSERT INTO lager_admins(username,display_name,password_hash,is_superadmin,active) VALUES(?,?,?,0,1)');
       $st->execute([$username,$displayName,$hash]);
       $newAdminId=(int)$pdo->lastInsertId();
       $insPerm=$pdo->prepare('INSERT INTO hsg_admin_module_access(admin_id,module_id,can_view) VALUES(?,?,?)');
       foreach($assignableModules as $mId=>$m){
         $canView=in_array($mId,$selectedMods,true)?1:0;
         $insPerm->execute([$newAdminId,$mId,$canView]);
       }
       audit_log($pdo,'admin_user.create','admin_user',(string)$newAdminId,['username'=>$username]);
       flash('success','Ny login-bruger oprettet med valgte modulrettigheder.');
     }catch(Throwable $e){flash('error','Kunne ikke oprette login-bruger: '.$e->getMessage());}
     redirect('users.php?tab=admins');
   }
 }
 if($action==='update_admin_user'){
   $adminId=(int)($_POST['admin_id']??0);
   $username=trim((string)($_POST['username']??''));
   $displayName=trim((string)($_POST['display_name']??''));
   $newPassword=(string)($_POST['password']??'');
   $selectedMods=(array)($_POST['modules']??[]);

   if($adminId<=0 || $username==='' || $displayName===''){
     flash('error','Brugernavn og visningsnavn skal udfyldes.');
   } else {
     try{
       $stCheck=$pdo->prepare('SELECT id, is_superadmin FROM lager_admins WHERE id=?');
       $stCheck->execute([$adminId]);
       $target=$stCheck->fetch();
       if($target){
         $isSuper=!empty($target['is_superadmin']);
         if($newPassword!==''){
           $hash=password_hash($newPassword, PASSWORD_DEFAULT);
           $pdo->prepare('UPDATE lager_admins SET username=?, display_name=?, password_hash=? WHERE id=?')->execute([$username,$displayName,$hash,$adminId]);
         } else {
           $pdo->prepare('UPDATE lager_admins SET username=?, display_name=? WHERE id=?')->execute([$username,$displayName,$adminId]);
         }
         if(!$isSuper){
           $delPerm=$pdo->prepare('DELETE FROM hsg_admin_module_access WHERE admin_id=?');
           $delPerm->execute([$adminId]);
           $insPerm=$pdo->prepare('INSERT INTO hsg_admin_module_access(admin_id,module_id,can_view) VALUES(?,?,?)');
           foreach($assignableModules as $mId=>$m){
             $canView=in_array($mId,$selectedMods,true)?1:0;
             $insPerm->execute([$adminId,$mId,$canView]);
           }
         }
         audit_log($pdo,'admin_user.update','admin_user',(string)$adminId,['username'=>$username,'display_name'=>$displayName]);
         flash('success','Brugeroplysninger og moduladgange er opdateret.');
       }
     }catch(Throwable $e){flash('error','Kunne ikke opdatere bruger: '.$e->getMessage());}
   }
   redirect('users.php?tab=admins');
 }
 if($action==='toggle_admin'){
   $adminId=(int)($_POST['admin_id']??0);
   $st=$pdo->prepare('SELECT id,is_superadmin FROM lager_admins WHERE id=?');$st->execute([$adminId]);$target=$st->fetch();
   if($target && empty($target['is_superadmin'])){
     $pdo->prepare('UPDATE lager_admins SET active=1-active WHERE id=?')->execute([$adminId]);
     flash('success','Status for login-bruger er ændret.');
   }
   redirect('users.php?tab=admins');
 }
 if($action==='create'){
   $name=trim((string)($_POST['name']??''));
   if($name==='') flash('error','Navn skal udfyldes.');
   else{
     try{
       $raw=bin2hex(random_bytes(32));$hash=hash('sha256',$raw);$cipher=hsg_access_link_encrypt($raw);
       $st=$pdo->prepare("INSERT INTO lager_users(name,role,token_hash,token_last4,token_cipher,active) VALUES(?,'user',?,?,?,1)");$st->execute([$name,$hash,substr($raw,-4),$cipher]);$newId=(int)$pdo->lastInsertId();
       $perm=$pdo->prepare('INSERT INTO hsg_user_module_access(user_id,module_id,can_view,can_operate) VALUES(?,?,?,?)');
       foreach([['dashboard',1,0],['inventory',1,0],['reservations',1,1],['catalog',1,0]] as $d)$perm->execute([$newId,$d[0],$d[1],$d[2]]);
       audit_log($pdo,'access_link.create','access_link',(string)$newId,['name'=>$name]);$_SESSION['new_link']=base_url().'/?k='.$raw;
       flash('success','Adgangslink oprettet. Linket er synligt for admin under Aktive links.');
     }catch(Throwable $e){flash('error','Kunne ikke oprette link: '.$e->getMessage());}
     redirect('users.php?tab=links&status=active');
   }
 }
 if($action==='regenerate'){
   try{
     $id=(int)($_POST['id']??0);$raw=bin2hex(random_bytes(32));$hash=hash('sha256',$raw);$cipher=hsg_access_link_encrypt($raw);
     $st=$pdo->prepare('UPDATE lager_users SET token_hash=?,token_last4=?,token_cipher=?,active=1 WHERE id=?');$st->execute([$hash,substr($raw,-4),$cipher,$id]);
     audit_log($pdo,'access_link.regenerate','access_link',(string)$id);$_SESSION['new_link']=base_url().'/?k='.$raw;flash('success','Nyt adgangslink oprettet. Det gamle virker ikke længere.');
   }catch(Throwable $e){flash('error','Kunne ikke generere nyt link: '.$e->getMessage());}
   redirect('users.php?tab=links&status=active');
 }
 if($action==='toggle'){
   $id=(int)($_POST['id']??0);$pdo->prepare('UPDATE lager_users SET active=1-active WHERE id=?')->execute([$id]);audit_log($pdo,'access_link.toggle','access_link',(string)$id);flash('success','Linkstatus ændret.');$returnStatus=(string)($_POST['status']??$status);if(!in_array($returnStatus,['active','inactive','all'],true))$returnStatus='active';redirect('users.php?tab=links&status='.$returnStatus);
 }
}
$newLink=$_SESSION['new_link']??null;unset($_SESSION['new_link']);
$where=$status==='active'?'WHERE active=1':($status==='inactive'?'WHERE active=0':'');
$rows=$pdo->query("SELECT * FROM lager_users $where ORDER BY active DESC,name")->fetchAll();
$counts=$pdo->query("SELECT SUM(active=1) active_count,SUM(active=0) inactive_count,COUNT(*) total_count FROM lager_users")->fetch();
page_header('Brugere & adgang');
?>
<div class="simple-tabs" style="margin-bottom:1rem;">
  <a class="button <?=$tab==='links'?'':'secondary'?>" href="users.php?tab=links">Personlige adgangslinks</a>
  <a class="button <?=$tab==='admins'?'':'secondary'?>" href="users.php?tab=admins">Login-brugere (Adgangskode)</a>
</div>

<?php if($tab==='admins'):
  $admins=$pdo->query('SELECT * FROM lager_admins ORDER BY is_superadmin DESC, id ASC')->fetchAll();
  $adminAccess=[];
  $stPerm=$pdo->query('SELECT admin_id,module_id,can_view FROM hsg_admin_module_access');
  foreach($stPerm->fetchAll() as $ap){
    if($ap['can_view']) $adminAccess[(int)$ap['admin_id']][]=(string)$ap['module_id'];
  }
?>
  <div class="card">
    <h2>Opret ny login-bruger</h2>
    <p class="muted">Opret brugere, der kan logge ind med brugernavn og adgangskode. Som administrator vælger du hvilke moduler brugeren har adgang til. <strong>Oprettelse og brugestyring kan ikke tildeles andre brugere.</strong></p>
    <form method="post"><?=csrf_field()?><input type="hidden" name="action" value="create_admin">
      <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));">
        <label>Brugernavn<input name="username" required placeholder="fx peter"></label>
        <label>Visningsnavn<input name="display_name" required placeholder="fx Peter Hansen"></label>
        <label>Adgangskode<input type="password" name="password" required></label>
      </div>
      <div style="margin: 1rem 0;">
        <strong>Moduladgang:</strong>
        <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); margin-top: 0.5rem;">
          <?php foreach($assignableModules as $mId=>$m): ?>
            <label class="check"><input type="checkbox" name="modules[]" value="<?=h($mId)?>" checked> <?=h($m['name'])?></label>
          <?php endforeach; ?>
        </div>
      </div>
      <button>Opret login-bruger</button>
    </form>
  </div>

  <div class="card">
    <h2>Eksisterende login-brugere</h2>
    <div class="table-wrap"><table><thead><tr><th>Brugernavn</th><th>Navn</th><th>Rolle</th><th>Sidst logget ind</th><th>Moduladgang</th><th>Status & Handlinger</th></tr></thead><tbody>
    <?php foreach($admins as $a):
      $isSuper=!empty($a['is_superadmin']);
      $userMods=$isSuper ? array_keys($assignableModules) : ($adminAccess[(int)$a['id']] ?? array_keys($assignableModules));
    ?>
      <tr>
        <td><strong><?=h($a['username'])?></strong></td>
        <td><?=h($a['display_name'])?></td>
        <td><?=$isSuper?'<span class="badge blue">Hoved-administrator</span>':'<span class="badge green">Bruger (Login)</span>'?></td>
        <td><?=h($a['last_login_at']??'Aldrig')?></td>
        <td>
          <?php if($isSuper): ?>
            <em>Fuld adgang til alt (herunder brugestyring)</em>
          <?php else: ?>
            <div style="font-size:0.85rem; color:#444;">
              <?php
                $activeModNames = [];
                foreach($assignableModules as $mId=>$m){
                  if(in_array($mId, $userMods, true)) $activeModNames[] = $m['name'];
                }
                echo h(implode(', ', $activeModNames) ?: 'Ingen moduler valgt');
              ?>
            </div>
          <?php endif; ?>
        </td>
        <td>
          <details>
            <summary class="button secondary small">Redigér bruger & adgange</summary>
            <form method="post" style="margin-top:10px; padding:12px; background:var(--bg-card,#f9f9f9); border:1px solid #ddd; border-radius:6px; min-width:280px; text-align:left; color:var(--text-color,#222);">
              <?=csrf_field()?>
              <input type="hidden" name="action" value="update_admin_user">
              <input type="hidden" name="admin_id" value="<?=$a['id']?>">
              <div style="display:flex; flex-direction:column; gap:8px;">
                <label style="font-size:0.85rem;">Brugernavn<input name="username" value="<?=h($a['username'])?>" required style="padding:4px 6px; font-size:0.85rem;"></label>
                <label style="font-size:0.85rem;">Visningsnavn<input name="display_name" value="<?=h($a['display_name'])?>" required style="padding:4px 6px; font-size:0.85rem;"></label>
                <label style="font-size:0.85rem;">Ny adgangskode (valgfri)<input type="password" name="password" placeholder="Lad stå tom for uændret" style="padding:4px 6px; font-size:0.85rem;"></label>
              </div>
              <?php if(!$isSuper): ?>
                <div style="margin:10px 0 6px 0;">
                  <strong style="font-size:0.85rem;">Moduladgang:</strong>
                  <div style="display:flex; flex-direction:column; gap:4px; margin-top:4px;">
                    <?php foreach($assignableModules as $mId=>$m):
                      $checked=in_array($mId,$userMods,true);
                    ?>
                      <label class="check" style="font-size:0.85rem;">
                        <input type="checkbox" name="modules[]" value="<?=h($mId)?>" <?=$checked?'checked':''?>> <?=h($m['name'])?>
                      </label>
                    <?php endforeach; ?>
                  </div>
                </div>
              <?php else: ?>
                <p class="muted" style="margin:8px 0; font-size:0.8rem;">Hoved-administrator har automatisk fuld adgang til alle moduler.</p>
              <?php endif; ?>
              <div style="margin-top:8px;">
                <button class="button small">Gem ændringer</button>
              </div>
            </form>
          </details>
          <?php if(!$isSuper): ?>
            <form method="post" style="margin-top:6px; display:inline-block;"><?=csrf_field()?><input type="hidden" name="action" value="toggle_admin"><input type="hidden" name="admin_id" value="<?=$a['id']?>"><button class="<?=$a['active']?'danger':'secondary'?> small"><?=$a['active']?'Deaktivér':'Aktivér'?></button></form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
  </div>

<?php else: ?>

  <?php if($newLink):?><div class="card"><h2>Nyt personligt link</h2><p class="muted">Linket kan senere ses igen af administrator under Aktive links.</p><div class="link-address-wrap"><input class="link-address" readonly value="<?=h($newLink)?>" onclick="this.select()"><button type="button" class="secondary copy-link" data-copy-link="<?=h($newLink)?>">Kopiér</button></div></div><?php endif;?>
  <div class="grid overview-primary"><div class="card metric"><strong><?=intval($counts['active_count']??0)?></strong><span>Aktive links</span></div><div class="card metric"><strong><?=intval($counts['inactive_count']??0)?></strong><span>Deaktiverede</span></div><div class="card metric"><strong><?=intval($counts['total_count']??0)?></strong><span>I alt</span></div></div>
  <div class="card"><div class="page-title"><div><h2 style="margin:0">Personlige adgangslinks</h2><p class="muted" style="margin:5px 0 0">Kun administrator kan se den fulde linkadresse. Et deaktiveret link virker ikke, før det aktiveres igen.</p></div></div>
  <div class="simple-tabs"><a class="button <?=$status==='active'?'':'secondary'?>" href="users.php?tab=links&status=active">Aktive (<?=intval($counts['active_count']??0)?>)</a><a class="button <?=$status==='inactive'?'':'secondary'?>" href="users.php?tab=links&status=inactive">Deaktiverede (<?=intval($counts['inactive_count']??0)?>)</a><a class="button <?=$status==='all'?'':'secondary'?>" href="users.php?tab=links&status=all">Alle</a></div>
  <form method="post" class="inline"><?=csrf_field()?><input type="hidden" name="action" value="create"><label>Navn<input name="name" required placeholder="Fx Gert"></label><button>Opret nyt link</button></form></div>
  <div class="table-wrap"><table><thead><tr><th>Navn</th><th>Linkadresse</th><th>Sidst brugt</th><th>Status</th><th>Rettigheder</th><th>Handlinger</th></tr></thead><tbody>
  <?php foreach($rows as $r):$url=hsg_access_link_url($r['token_cipher']??null);?><tr>
  <td><strong><?=h($r['name'])?></strong><br><small class="muted">Nøgle slutter på ••••<?=h($r['token_last4'])?></small></td>
  <td><?php if($url):?><div class="link-address-wrap"><input class="link-address" readonly value="<?=h($url)?>" onclick="this.select()"><button type="button" class="secondary copy-link" data-copy-link="<?=h($url)?>">Kopiér</button></div><?php else:?><span class="link-unavailable">Dette link stammer fra en ældre HSG-version og kan ikke genskabes fra den gemte hash. Tryk <strong>Nyt link</strong> én gang for at gøre den fulde adresse synlig fremover.</span><?php endif;?></td>
  <td><?=h($r['last_used_at']??'Aldrig')?></td><td><span class="badge <?=$r['active']?'green':'red'?>"><?=$r['active']?'Aktiv':'Deaktiveret'?></span></td>
  <td><a class="button secondary small" href="user_permissions.php?id=<?=$r['id']?>">Rettigheder</a></td>
  <td><div class="actions"><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="regenerate"><input type="hidden" name="id" value="<?=$r['id']?>"><button class="secondary small">Nyt link</button></form><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?=$r['id']?>"><input type="hidden" name="status" value="<?=h($status)?>"><button class="<?=$r['active']?'danger':'secondary'?> small"><?=$r['active']?'Deaktivér':'Aktivér'?></button></form></div></td>
  </tr><?php endforeach;?>
  <?php if(!$rows):?><tr><td colspan="6" class="muted">Ingen links i dette filter.</td></tr><?php endif;?></tbody></table></div>
  <script>document.addEventListener('click',async e=>{const b=e.target.closest('[data-copy-link]');if(!b)return;const v=b.dataset.copyLink||'';try{await navigator.clipboard.writeText(v);const old=b.textContent;b.textContent='Kopieret';setTimeout(()=>b.textContent=old,1200);}catch(_){const input=b.parentElement?.querySelector('input');if(input){input.select();document.execCommand('copy');}}});</script>
<?php endif; ?>
<?php page_footer();
