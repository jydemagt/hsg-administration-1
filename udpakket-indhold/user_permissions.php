<?php
declare(strict_types=1);
require __DIR__.'/auth.php';require_module_enabled('access');require_capability('users.manage');
$id=(int)($_GET['id']??$_POST['id']??0);
$st=$pdo->prepare('SELECT * FROM lager_users WHERE id=?');$st->execute([$id]);$user=$st->fetch();if(!$user){http_response_code(404);exit('Brugeren findes ikke.');}
$modules=hsg_link_accessible_modules();
if($_SERVER['REQUEST_METHOD']==='POST'){
    $pdo->beginTransaction();
    try{
        $up=$pdo->prepare('INSERT INTO hsg_user_module_access(user_id,module_id,can_view,can_operate) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE can_view=VALUES(can_view),can_operate=VALUES(can_operate),updated_at=NOW()');
        foreach($modules as $moduleId=>$m){
            $view=!empty($_POST['view'][$moduleId])?1:0;
            $operate=($moduleId==='reservations' && !empty($_POST['operate'][$moduleId]) && $view)?1:0;
            $up->execute([$id,$moduleId,$view,$operate]);
        }
        $pdo->commit();audit_log($pdo,'access_link.permissions','access_link',(string)$id,['user'=>$user['name']]);flash('success','Modulrettigheder gemt.');redirect('user_permissions.php?id='.$id);
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();flash('error',$e->getMessage());redirect('user_permissions.php?id='.$id);}
}
$access=[];$st=$pdo->prepare('SELECT module_id,can_view,can_operate FROM hsg_user_module_access WHERE user_id=?');$st->execute([$id]);foreach($st->fetchAll() as $r)$access[$r['module_id']]=$r;
page_header('Rettigheder · '.$user['name']);
?>
<div class="card"><h2>Moduladgang for <?=h($user['name'])?></h2><p class="muted">Et direkte link giver aldrig administratoradgang. Her vælger du kun hvilke brugerrettede moduler linket må se. Reservation kan desuden få arbejdsadgang til at oprette og annullere egne aktive reservationer.</p>
<form method="post"><?=csrf_field()?><input type="hidden" name="id" value="<?=$id?>">
<div class="table-wrap"><table><thead><tr><th>Modul</th><th>Se</th><th>Arbejde</th><th>Bemærkning</th></tr></thead><tbody>
<?php foreach($modules as $moduleId=>$m):$a=$access[$moduleId]??['can_view'=>0,'can_operate'=>0];?>
<tr><td><strong><?=h($m['name'])?></strong><br><small class="muted"><?=h($m['description']??'')?></small></td>
<td><label class="check"><input type="checkbox" name="view[<?=h($moduleId)?>]" value="1" <?=$a['can_view']?'checked':''?>> Adgang</label></td>
<td><?php if($moduleId==='reservations'):?><label class="check"><input type="checkbox" name="operate[<?=h($moduleId)?>]" value="1" <?=$a['can_operate']?'checked':''?>> Må reservere</label><?php else:?><span class="muted">Read-only via link</span><?php endif;?></td>
<td class="muted"><?=$moduleId==='reservations'?'Opret + annullér egne aktive reservationer.':'Ændringer kræver admin-login.'?></td></tr>
<?php endforeach;?></tbody></table></div><button>Gem rettigheder</button> <a class="button secondary" href="users.php">Tilbage</a></form></div>
<?php page_footer();
