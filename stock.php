<?php
require __DIR__.'/auth.php';require_module_enabled('stock');require_capability('inventory.manage');

if($_SERVER['REQUEST_METHOD']==='POST'){
 require_capability('inventory.manage');
 $action=$_POST['action']??'';
 try{
  if($action==='set' || $action==='adjust'){
    $pid=(int)$_POST['product_id'];$lid=(int)$_POST['location_id'];$qty=(int)$_POST['quantity'];$ref=trim($_POST['reference']??'');
    $pdo->beginTransaction();
    $st=$pdo->prepare('SELECT quantity FROM lager_stock WHERE product_id=? AND location_id=? FOR UPDATE');$st->execute([$pid,$lid]);$old=(int)($st->fetchColumn()?:0);
    $new=$action==='set'?$qty:$old+$qty;
    if($new<0) throw new RuntimeException('Lageret kan ikke blive negativt.');
    $pdo->prepare('INSERT INTO lager_stock(product_id,location_id,quantity) VALUES(?,?,?) ON DUPLICATE KEY UPDATE quantity=VALUES(quantity)')->execute([$pid,$lid,$new]);
    $change=$new-$old;
    $pdo->prepare('INSERT INTO lager_stock_movements(product_id,location_id,change_qty,balance_after,movement_type,reference,created_by,created_by_admin) VALUES(?,?,?,?,?,?,?,?)')->execute([$pid,$lid,$change,$new,$action==='set'?'set':'adjust',$ref,null,current_admin_id()]);
    hsg_sync_product_stock_status($pdo,$pid);
    $pdo->commit(); audit_log($pdo,'stock.'.($action==='set'?'set':'adjust'),'stock',$pid.':'.$lid,['old'=>$old,'new'=>$new,'change'=>$change,'reference'=>$ref]);hsg_do_action('stock.changed',['product_id'=>$pid,'location_id'=>$lid,'old'=>$old,'new'=>$new,'change'=>$change,'type'=>$action]); flash('success','Lagerbeholdning opdateret.'); redirect('stock.php');
  }
  if($action==='transfer'){
    $pid=(int)$_POST['product_id'];$from=(int)$_POST['from_location'];$to=(int)$_POST['to_location'];$qty=(int)$_POST['quantity'];$ref=trim($_POST['reference']??'Intern flytning');
    if($from===$to||$qty<=0) throw new RuntimeException('Vælg to forskellige lokationer og et antal over 0.');
    $pdo->beginTransaction();
    $st=$pdo->prepare('SELECT quantity FROM lager_stock WHERE product_id=? AND location_id=? FOR UPDATE');$st->execute([$pid,$from]);$fromOld=(int)($st->fetchColumn()?:0);
    $rs=$pdo->prepare("SELECT COALESCE(SUM(quantity),0) FROM lager_reservations WHERE product_id=? AND location_id=? AND status='reserved'");$rs->execute([$pid,$from]);$reserved=(int)$rs->fetchColumn();
    if(($fromOld-$reserved)<$qty) throw new RuntimeException('Der er ikke nok disponibelt lager på afsenderlokationen.');
    $st->execute([$pid,$to]);$toOld=(int)($st->fetchColumn()?:0);
    $fromNew=$fromOld-$qty;$toNew=$toOld+$qty;
    $up=$pdo->prepare('INSERT INTO lager_stock(product_id,location_id,quantity) VALUES(?,?,?) ON DUPLICATE KEY UPDATE quantity=VALUES(quantity)');$up->execute([$pid,$from,$fromNew]);$up->execute([$pid,$to,$toNew]);
    $mv=$pdo->prepare('INSERT INTO lager_stock_movements(product_id,location_id,change_qty,balance_after,movement_type,reference,created_by,created_by_admin) VALUES(?,?,?,?,?,?,?,?)');
    $mv->execute([$pid,$from,-$qty,$fromNew,'transfer_out',$ref,null,current_admin_id()]);$mv->execute([$pid,$to,$qty,$toNew,'transfer_in',$ref,null,current_admin_id()]);
    hsg_sync_product_stock_status($pdo,$pid);
    $pdo->commit();audit_log($pdo,'stock.transfer','stock',(string)$pid,['from_location'=>$from,'to_location'=>$to,'quantity'=>$qty,'reference'=>$ref]);hsg_do_action('stock.transferred',['product_id'=>$pid,'from_location'=>$from,'to_location'=>$to,'quantity'=>$qty]);flash('success','Varerne er flyttet mellem lokationerne.');redirect('stock.php');
  }
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();flash('error',$e->getMessage());}
}
$products=$pdo->query("SELECT id,sku,name FROM lager_products WHERE status='active' ORDER BY name")->fetchAll();
$locations=$pdo->query("SELECT id,name FROM lager_locations WHERE active=1 ORDER BY name")->fetchAll();
$locFilter=(int)($_GET['location']??0);$params=[];$where='';if($locFilter){$where=' WHERE l.id=?';$params[]=$locFilter;}
$sql="SELECT p.id product_id,p.sku,p.name,l.id location_id,l.name location,s.quantity physical,COALESCE(r.reserved,0) reserved,(s.quantity-COALESCE(r.reserved,0)) available
FROM lager_stock s JOIN lager_products p ON p.id=s.product_id JOIN lager_locations l ON l.id=s.location_id
LEFT JOIN (SELECT product_id,location_id,SUM(quantity) reserved FROM lager_reservations WHERE status='reserved' GROUP BY product_id,location_id) r ON r.product_id=s.product_id AND r.location_id=s.location_id $where ORDER BY p.name,l.name";
$st=$pdo->prepare($sql);$st->execute($params);$rows=$st->fetchAll();
page_header('Lager');
?>
<form class="searchbar" method="get"><select name="location"><option value="0">Alle lokationer</option><?php foreach($locations as $l):?><option value="<?=$l['id']?>" <?=$locFilter===$l['id']?'selected':''?>><?=h($l['name'])?></option><?php endforeach;?></select><button>Filtrer</button></form>
<?php if(is_admin()): ?><div class="split">
<div class="card"><h2>Sæt eller regulér lager</h2><form method="post"><?=csrf_field()?><input type="hidden" name="action" id="stock_action" value="set"><label>Produkt<select name="product_id" required><?php foreach($products as $p):?><option value="<?=$p['id']?>"><?=h($p['sku'].' – '.$p['name'])?></option><?php endforeach;?></select></label><label>Lokation<select name="location_id" required><?php foreach($locations as $l):?><option value="<?=$l['id']?>"><?=h($l['name'])?></option><?php endforeach;?></select></label><label>Antal<input type="number" name="quantity" required value="0"></label><label>Reference / note<input name="reference"></label><div class="actions"><button type="submit" onclick="document.getElementById('stock_action').value='set'">Sæt beholdning</button><button class="secondary" type="submit" onclick="document.getElementById('stock_action').value='adjust'">Regulér +/-</button></div></form></div>
<div class="card"><h2>Flyt mellem lokationer</h2><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="transfer"><label>Produkt<select name="product_id" required><?php foreach($products as $p):?><option value="<?=$p['id']?>"><?=h($p['sku'].' – '.$p['name'])?></option><?php endforeach;?></select></label><div class="split"><label>Fra<select name="from_location" required><?php foreach($locations as $l):?><option value="<?=$l['id']?>"><?=h($l['name'])?></option><?php endforeach;?></select></label><label>Til<select name="to_location" required><?php foreach($locations as $l):?><option value="<?=$l['id']?>"><?=h($l['name'])?></option><?php endforeach;?></select></label></div><label>Antal<input type="number" min="1" name="quantity" required></label><label>Reference<input name="reference" value="Intern flytning"></label><button>Flyt lager</button></form></div>
</div><?php endif; ?>
<div class="table-wrap"><table><thead><tr><th>SKU</th><th>Produkt</th><th>Lokation</th><th>Fysisk</th><th>Reserveret</th><th>Disponibelt</th></tr></thead><tbody><?php foreach($rows as $r):?><tr><td><?=h($r['sku'])?></td><td><?=h($r['name'])?></td><td><?=h($r['location'])?></td><td><?=$r['physical']?></td><td><?=$r['reserved']?></td><td class="available <?=$r['available']<0?'negative':''?>"><?=$r['available']?></td></tr><?php endforeach;?><?php if(!$rows):?><tr><td colspan="6">Intet lager endnu.</td></tr><?php endif;?></tbody></table></div>
<?php page_footer();
