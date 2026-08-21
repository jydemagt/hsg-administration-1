<?php
declare(strict_types=1);
require __DIR__.'/auth.php';require_module_enabled('access');require_capability('users.manage');require_once __DIR__.'/core/access_links.php';
$status=(string)($_GET['status']??'active');if(!in_array($status,['active','inactive','all'],true))$status='active';
if($_SERVER['REQUEST_METHOD']==='POST'){
 $action=(string)($_POST['action']??'');
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
     redirect('users.php?status=active');
   }
 }
 if($action==='regenerate'){
   try{
     $id=(int)($_POST['id']??0);$raw=bin2hex(random_bytes(32));$hash=hash('sha256',$raw);$cipher=hsg_access_link_encrypt($raw);
     $st=$pdo->prepare('UPDATE lager_users SET token_hash=?,token_last4=?,token_cipher=?,active=1 WHERE id=?');$st->execute([$hash,substr($raw,-4),$cipher,$id]);
     audit_log($pdo,'access_link.regenerate','access_link',(string)$id);$_SESSION['new_link']=base_url().'/?k='.$raw;flash('success','Nyt adgangslink oprettet. Det gamle virker ikke længere.');
   }catch(Throwable $e){flash('error','Kunne ikke generere nyt link: '.$e->getMessage());}
   redirect('users.php?status=active');
 }
 if($action==='toggle'){
   $id=(int)($_POST['id']??0);$pdo->prepare('UPDATE lager_users SET active=1-active WHERE id=?')->execute([$id]);audit_log($pdo,'access_link.toggle','access_link',(string)$id);flash('success','Linkstatus ændret.');$returnStatus=(string)($_POST['status']??$status);if(!in_array($returnStatus,['active','inactive','all'],true))$returnStatus='active';redirect('users.php?status='.$returnStatus);
 }
}
$newLink=$_SESSION['new_link']??null;unset($_SESSION['new_link']);
$where=$status==='active'?'WHERE active=1':($status==='inactive'?'WHERE active=0':'');
$rows=$pdo->query("SELECT * FROM lager_users $where ORDER BY active DESC,name")->fetchAll();
$counts=$pdo->query("SELECT SUM(active=1) active_count,SUM(active=0) inactive_count,COUNT(*) total_count FROM lager_users")->fetch();
page_header('Brugere & adgang');
?>
<?php if($newLink):?><div class="card"><h2>Nyt personligt link</h2><p class="muted">Linket kan senere ses igen af administrator under Aktive links.</p><div class="link-address-wrap"><input class="link-address" readonly value="<?=h($newLink)?>" onclick="this.select()"><button type="button" class="secondary copy-link" data-copy-link="<?=h($newLink)?>">Kopiér</button></div></div><?php endif;?>
<div class="grid overview-primary"><div class="card metric"><strong><?=intval($counts['active_count']??0)?></strong><span>Aktive links</span></div><div class="card metric"><strong><?=intval($counts['inactive_count']??0)?></strong><span>Deaktiverede</span></div><div class="card metric"><strong><?=intval($counts['total_count']??0)?></strong><span>I alt</span></div></div>
<div class="card"><div class="page-title"><div><h2 style="margin:0">Personlige adgangslinks</h2><p class="muted" style="margin:5px 0 0">Kun administrator kan se den fulde linkadresse. Et deaktiveret link virker ikke, før det aktiveres igen.</p></div></div>
<div class="simple-tabs"><a class="button <?=$status==='active'?'':'secondary'?>" href="users.php?status=active">Aktive (<?=intval($counts['active_count']??0)?>)</a><a class="button <?=$status==='inactive'?'':'secondary'?>" href="users.php?status=inactive">Deaktiverede (<?=intval($counts['inactive_count']??0)?>)</a><a class="button <?=$status==='all'?'':'secondary'?>" href="users.php?status=all">Alle</a></div>
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
<?php page_footer();
