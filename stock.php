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
  if($action==='batch_inline_update'){
    $batch=(array)($_POST['stock']??[]);
    $ref=trim((string)($_POST['batch_reference']??'Hurtig lagerrettelse'));
    $pdo->beginTransaction();
    $updatedCount=0;$affectedProducts=[];
    $stOld=$pdo->prepare('SELECT quantity FROM lager_stock WHERE product_id=? AND location_id=? FOR UPDATE');
    $stUp=$pdo->prepare('INSERT INTO lager_stock(product_id,location_id,quantity) VALUES(?,?,?) ON DUPLICATE KEY UPDATE quantity=VALUES(quantity)');
    $stMv=$pdo->prepare('INSERT INTO lager_stock_movements(product_id,location_id,change_qty,balance_after,movement_type,reference,created_by,created_by_admin) VALUES(?,?,?,?,?,?,?,?)');

    foreach($batch as $pidRaw=>$locsData){
        $pid=(int)$pidRaw; if($pid<=0) continue;
        foreach((array)$locsData as $lidRaw=>$qtyRaw){
            $lid=(int)$lidRaw; if($lid<=0) continue;
            if(trim((string)$qtyRaw)==='') continue;
            $newQty=max(0,(int)$qtyRaw);
            $stOld->execute([$pid,$lid]);
            $oldQty=(int)($stOld->fetchColumn()?:0);
            if($oldQty!==$newQty){
                $changeQty=$newQty-$oldQty;
                $stUp->execute([$pid,$lid,$newQty]);
                $stMv->execute([$pid,$lid,$changeQty,$newQty,'set',$ref,null,current_admin_id()]);
                $updatedCount++;
                $affectedProducts[$pid]=true;
            }
        }
    }
    foreach(array_keys($affectedProducts) as $pid){
        hsg_sync_product_stock_status($pdo,(int)$pid);
    }
    $pdo->commit();
    audit_log($pdo,'stock.batch_inline_update','stock','batch',['updated_records'=>$updatedCount,'reference'=>$ref]);
    flash('success', $updatedCount > 0 ? "Lagerbeholdning opdateret for $updatedCount lokation(er)." : "Ingen lagerændringer registreret.");
    redirect('stock.php');
  }
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();flash('error',$e->getMessage());}
}
$products=$pdo->query("SELECT id,sku,name FROM lager_products WHERE status='active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$locations=$pdo->query("SELECT id,name FROM lager_locations WHERE active=1 ORDER BY sort_order, name")->fetchAll(PDO::FETCH_ASSOC);
$qFilter=trim((string)($_GET['q']??''));
$locFilter=(int)($_GET['location']??0);

$whereConds=["p.status='active'"]; $whereParams=[];
if($qFilter!=='') {
    $whereConds[] = "(p.name LIKE ? OR p.sku LIKE ?)";
    $whereParams[] = '%'.$qFilter.'%';
    $whereParams[] = '%'.$qFilter.'%';
}

$whereSql = implode(' AND ', $whereConds);
$pSql = "SELECT p.id product_id, p.sku, p.name FROM lager_products p WHERE {$whereSql} ORDER BY p.name";
$stP = $pdo->prepare($pSql); $stP->execute($whereParams);
$gridProducts = $stP->fetchAll(PDO::FETCH_ASSOC);

$stStockGrid = $pdo->prepare('SELECT location_id, quantity FROM lager_stock WHERE product_id=?');
$stResGrid = $pdo->prepare("SELECT location_id, SUM(quantity) reserved FROM lager_reservations WHERE product_id=? AND status='reserved' GROUP BY location_id");

page_header('Lager');
?>
<form class="searchbar" method="get">
  <input name="q" value="<?=h($qFilter)?>" placeholder="Søg produkt eller SKU...">
  <select name="location">
    <option value="0">Alle lokationer</option>
    <?php foreach($locations as $l): ?>
      <option value="<?=$l['id']?>" <?=$locFilter===(int)$l['id']?'selected':''?>><?=h($l['name'])?></option>
    <?php endforeach; ?>
  </select>
  <button>Søg & Filtrér</button>
</form>

<?php if(is_admin()): ?>
<div class="card">
  <details>
    <summary style="cursor:pointer; font-weight:600; font-size:1.1rem;">⚡ Regulér enkeltvare eller flyt lager</summary>
    <div class="split" style="margin-top:12px;">
      <div>
        <h3>Sæt eller regulér lager</h3>
        <form method="post"><?=csrf_field()?><input type="hidden" name="action" id="stock_action" value="set">
          <label>Produkt<select name="product_id" required><?php foreach($products as $p):?><option value="<?=$p['id']?>"><?=h($p['sku'].' – '.$p['name'])?></option><?php endforeach;?></select></label>
          <label>Lokation<select name="location_id" required><?php foreach($locations as $l):?><option value="<?=$l['id']?>"><?=h($l['name'])?></option><?php endforeach;?></select></label>
          <label>Antal<input type="number" name="quantity" required value="0"></label>
          <label>Reference / note<input name="reference"></label>
          <div class="actions">
            <button type="submit" onclick="document.getElementById('stock_action').value='set'">Sæt beholdning</button>
            <button class="secondary" type="submit" onclick="document.getElementById('stock_action').value='adjust'">Regulér +/-</button>
          </div>
        </form>
      </div>
      <div>
        <h3>Flyt mellem lokationer</h3>
        <form method="post"><?=csrf_field()?><input type="hidden" name="action" value="transfer">
          <label>Produkt<select name="product_id" required><?php foreach($products as $p):?><option value="<?=$p['id']?>"><?=h($p['sku'].' – '.$p['name'])?></option><?php endforeach;?></select></label>
          <div class="split">
            <label>Fra<select name="from_location" required><?php foreach($locations as $l):?><option value="<?=$l['id']?>"><?=h($l['name'])?></option><?php endforeach;?></select></label>
            <label>Til<select name="to_location" required><?php foreach($locations as $l):?><option value="<?=$l['id']?>"><?=h($l['name'])?></option><?php endforeach;?></select></label>
          </div>
          <label>Antal<input type="number" min="1" name="quantity" required></label>
          <label>Reference<input name="reference" value="Intern flytning"></label>
          <button>Flyt lager</button>
        </form>
      </div>
    </div>
  </details>
</div>

<form method="post"><?=csrf_field()?><input type="hidden" name="action" value="batch_inline_update">
<div class="card">
  <div class="page-title" style="margin-bottom:8px;">
    <div>
      <h2 style="margin:0;">Lagerstyring (1 produkt pr. linje)</h2>
      <p class="muted" style="margin:4px 0 0;">Ret de fysiske lagerantal direkte i tekstfelterne og tryk Gem. Hver lokation vises overskueligt på samme produktlinje.</p>
    </div>
    <div>
      <button class="button">Gem alle lagerrettelser</button>
    </div>
  </div>
  <div style="margin-bottom:12px; max-width:320px;">
    <label>Reference / Note ved gem<input name="batch_reference" value="Hurtig lagerrettelse"></label>
  </div>
</div>

<div class="table-wrap">
  <table>
    <thead>
      <tr>
        <th>SKU</th>
        <th>Produkt</th>
        <?php foreach($locations as $l): if($locFilter && (int)$l['id'] !== $locFilter) continue; ?>
          <th style="text-align:center;"><?=h($l['name'])?></th>
        <?php endforeach; ?>
        <th style="text-align:center;">Totalt disponibelt</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach($gridProducts as $gp):
        $pid=(int)$gp['product_id'];
        $stStockGrid->execute([$pid]);
        $pLocStock=[]; foreach($stStockGrid->fetchAll(PDO::FETCH_ASSOC) as $sRow) $pLocStock[(int)$sRow['location_id']] = (int)$sRow['quantity'];

        $stResGrid->execute([$pid]);
        $pLocRes=[]; foreach($stResGrid->fetchAll(PDO::FETCH_ASSOC) as $rRow) $pLocRes[(int)$rRow['location_id']] = (int)$rRow['reserved'];

        $totalAvailable = 0;
      ?>
        <tr>
          <td><strong><?=h($gp['sku'])?></strong></td>
          <td><strong><?=h($gp['name'])?></strong></td>
          <?php foreach($locations as $l):
            $lid=(int)$l['id']; if($locFilter && $lid !== $locFilter) continue;
            $curPhys = $pLocStock[$lid] ?? 0;
            $curRes = $pLocRes[$lid] ?? 0;
            $curAvail = $curPhys - $curRes;
            $totalAvailable += $curAvail;
          ?>
            <td style="text-align:center; width:110px; background:var(--bg-card,#fdfdfd);">
              <input type="number" min="0" name="stock[<?=$pid?>][<?=$lid?>]" value="<?=$curPhys?>" style="width:75px; padding:6px 8px; text-align:center; font-weight:600; font-size:1rem; border:1px solid #ccc; border-radius:4px;">
              <?php if($curRes > 0): ?>
                <div style="font-size:0.75rem; color:#d97706; margin-top:3px; font-weight:600;">Res: <?=$curRes?></div>
              <?php endif; ?>
            </td>
          <?php endforeach; ?>
          <td style="text-align:center; font-size:1.1rem; font-weight:bold;" class="available <?=$totalAvailable<0?'negative':''?>"><?=$totalAvailable?></td>
        </tr>
      <?php endforeach; ?>
      <?php if(!$gridProducts): ?>
        <tr><td colspan="10" class="muted">Ingen produkter fundet.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>
</form>

<?php else: ?>

<div class="table-wrap">
  <table>
    <thead>
      <tr>
        <th>SKU</th>
        <th>Produkt</th>
        <?php foreach($locations as $l): if($locFilter && (int)$l['id'] !== $locFilter) continue; ?>
          <th><?=h($l['name'])?> (Disponibelt)</th>
        <?php endforeach; ?>
        <th>Totalt disponibelt</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach($gridProducts as $gp):
        $pid=(int)$gp['product_id'];
        $stStockGrid->execute([$pid]);
        $pLocStock=[]; foreach($stStockGrid->fetchAll(PDO::FETCH_ASSOC) as $sRow) $pLocStock[(int)$sRow['location_id']] = (int)$sRow['quantity'];

        $stResGrid->execute([$pid]);
        $pLocRes=[]; foreach($stResGrid->fetchAll(PDO::FETCH_ASSOC) as $rRow) $pLocRes[(int)$rRow['location_id']] = (int)$rRow['reserved'];

        $totalAvailable = 0;
      ?>
        <tr>
          <td><strong><?=h($gp['sku'])?></strong></td>
          <td><?=h($gp['name'])?></td>
          <?php foreach($locations as $l):
            $lid=(int)$l['id']; if($locFilter && $lid !== $locFilter) continue;
            $curPhys = $pLocStock[$lid] ?? 0;
            $curRes = $pLocRes[$lid] ?? 0;
            $curAvail = $curPhys - $curRes;
            $totalAvailable += $curAvail;
          ?>
            <td class="available <?=$curAvail<0?'negative':''?>"><?=$curAvail?></td>
          <?php endforeach; ?>
          <td style="font-weight:bold;" class="available <?=$totalAvailable<0?'negative':''?>"><?=$totalAvailable?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php endif; ?>
<?php page_footer();
