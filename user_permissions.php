<?php
declare(strict_types=1);
require __DIR__.'/auth.php';require_module_enabled('access');require_capability('users.manage');
$id=(int)($_GET['id']??$_POST['id']??0);
$st=$pdo->prepare('SELECT * FROM lager_users WHERE id=?');$st->execute([$id]);$user=$st->fetch();if(!$user){http_response_code(404);exit('Brugeren findes ikke.');}
$modules=hsg_link_accessible_modules();
if(!isset($modules['reservations'])){
    $modules['reservations']=['id'=>'reservations','name'=>'Reservationer','href'=>'reservations.php','icon'=>'▣','description'=>'Se og opret reservationer.'];
}
if($_SERVER['REQUEST_METHOD']==='POST'){
    $pdo->beginTransaction();
    try{
        $up=$pdo->prepare('INSERT INTO hsg_user_module_access(user_id,module_id,can_view,can_operate) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE can_view=VALUES(can_view),can_operate=VALUES(can_operate),updated_at=NOW()');
        foreach($modules as $moduleId=>$m){
            $view=!empty($_POST['view'][$moduleId])?1:0;
            if($moduleId==='reservations'){
                $reserve=!empty($_POST['reserve_create'][$moduleId]) && $view ? 1 : 0;
                $editAll=!empty($_POST['operate'][$moduleId]) && $view && $reserve ? 1 : 0;
                $opLevel = 0;
                if($reserve && $editAll) $opLevel = 2;
                elseif($reserve) $opLevel = 1;
                $up->execute([$id,$moduleId,$view,$opLevel]);
            } else {
                $operate=0;
                $up->execute([$id,$moduleId,$view,$operate]);
            }
        }
        $pdo->commit();audit_log($pdo,'access_link.permissions','access_link',(string)$id,['user'=>$user['name']]);flash('success','Modulrettigheder gemt.');redirect('user_permissions.php?id='.$id);
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();flash('error',$e->getMessage());redirect('user_permissions.php?id='.$id);}
}
$access=[];$st=$pdo->prepare('SELECT module_id,can_view,can_operate FROM hsg_user_module_access WHERE user_id=?');$st->execute([$id]);foreach($st->fetchAll() as $r)$access[$r['module_id']]=$r;
page_header('Rettigheder · '.$user['name']);
?>
<div class="card"><h2>Moduladgang for <?=h($user['name'])?></h2><p class="muted">Et direkte link giver aldrig administratoradgang. Her vælger du hvilke moduler linket har adgang til, og om reservationer må oprettes og/eller redigeres.</p>
<form method="post"><?=csrf_field()?><input type="hidden" name="id" value="<?=$id?>">
<div class="table-wrap"><table><thead><tr><th>Modul</th><th>Adgang</th><th>Opret reservationer</th><th>Rediger/administrer reservationer</th><th>Bemærkning</th></tr></thead><tbody>
<?php foreach($modules as $moduleId=>$m):$a=$access[$moduleId]??['can_view'=>0,'can_operate'=>0];
$opVal = (int)($a['can_operate']??0);
$canReserve = ($opVal >= 1);
$canEditAll = ($opVal >= 2);
?>
<tr><td><strong><?=h($m['name'])?></strong><br><small class="muted"><?=h($m['description']??'')?></small></td>
<td><label class="check"><input type="checkbox" name="view[<?=h($moduleId)?>]" id="view_<?=h($moduleId)?>" value="1" <?=$a['can_view']?'checked':''?> onchange="updateResCheckboxes()"></label></td>
<?php if($moduleId==='reservations'):?>
<td><label class="check"><input type="checkbox" name="reserve_create[<?=h($moduleId)?>]" id="res_create" value="1" <?=$canReserve?'checked':''?> onchange="updateResCheckboxes()"> Må reservere</label></td>
<td><label class="check"><input type="checkbox" name="operate[<?=h($moduleId)?>]" id="res_edit" value="1" <?=$canEditAll?'checked':''?> onchange="updateResCheckboxes()"> Må redigere reservationer</label></td>
<td class="muted">Redigering kræver at 'Må reservere' også er valgt.</td>
<?php else:?>
<td colspan="2"><span class="muted">Læseadgang via link</span></td>
<td class="muted">Ændringer kræver admin-login.</td>
<?php endif;?>
</tr>
<?php endforeach;?></tbody></table></div><button>Gem rettigheder</button> <a class="button secondary" href="users.php">Tilbage</a></form></div>
<script>
function updateResCheckboxes(){
  const viewEl = document.getElementById('view_reservations');
  const createEl = document.getElementById('res_create');
  const editEl = document.getElementById('res_edit');
  if(!createEl || !editEl) return;
  if(viewEl && !viewEl.checked){
    createEl.checked = false;
    createEl.disabled = true;
    editEl.checked = false;
    editEl.disabled = true;
    return;
  }
  createEl.disabled = false;
  if(!createEl.checked){
    editEl.checked = false;
    editEl.disabled = true;
  } else {
    editEl.disabled = false;
  }
}
document.addEventListener('DOMContentLoaded', updateResCheckboxes);
</script>
<?php page_footer();
