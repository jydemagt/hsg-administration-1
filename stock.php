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
$products=$pdo->query("SELECT id,sku,name,status FROM lager_products WHERE status<>'discontinued' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$locations=$pdo->query("SELECT id,name FROM lager_locations WHERE active=1 ORDER BY sort_order, name")->fetchAll(PDO::FETCH_ASSOC);
$qFilter=trim((string)($_GET['q']??''));
$locFilter=(int)($_GET['location']??0);
$statusFilter=in_array($_GET['status']??'active', ['active','inactive','all'], true) ? $_GET['status'] : 'active';

$whereConds=[]; $whereParams=[];
if($statusFilter === 'active') {
    $whereConds[] = "p.status='active'";
} elseif($statusFilter === 'inactive') {
    $whereConds[] = "p.status='inactive'";
} else {
    $whereConds[] = "p.status<>'discontinued'";
}

if($qFilter!=='') {
    $whereConds[] = "(p.name LIKE ? OR p.sku LIKE ? OR b.name LIKE ? OR pb.name LIKE ? OR p.cask_number LIKE ? OR p.call_name LIKE ?)";
    $term = '%'.$qFilter.'%';
    $whereParams[] = $term;
    $whereParams[] = $term;
    $whereParams[] = $term;
    $whereParams[] = $term;
    $whereParams[] = $term;
    $whereParams[] = $term;
}

$whereSql = implode(' AND ', $whereConds);
$pSql = "SELECT DISTINCT p.id product_id, p.sku, p.name, p.call_name, p.cask_number, p.status
         FROM lager_products p
         LEFT JOIN lager_brands b ON b.id=p.brand_id
         LEFT JOIN lager_brands pb ON pb.id=b.parent_id
         WHERE {$whereSql}
         ORDER BY p.name";
$stP = $pdo->prepare($pSql); $stP->execute($whereParams);
$gridProducts = $stP->fetchAll(PDO::FETCH_ASSOC);

$pLocStock = [];
$pLocRes = [];
if($gridProducts) {
    $pids = array_column($gridProducts, 'product_id');
    $inClause = implode(',', array_fill(0, count($pids), '?'));

    $stStock = $pdo->prepare("SELECT product_id, location_id, quantity FROM lager_stock WHERE product_id IN ({$inClause})");
    $stStock->execute($pids);
    foreach($stStock->fetchAll(PDO::FETCH_ASSOC) as $sRow) {
        $pLocStock[(int)$sRow['product_id']][(int)$sRow['location_id']] = (int)$sRow['quantity'];
    }

    $stRes = $pdo->prepare("SELECT product_id, location_id, SUM(quantity) reserved FROM lager_reservations WHERE product_id IN ({$inClause}) AND status='reserved' GROUP BY product_id, location_id");
    $stRes->execute($pids);
    foreach($stRes->fetchAll(PDO::FETCH_ASSOC) as $rRow) {
        $pLocRes[(int)$rRow['product_id']][(int)$rRow['location_id']] = (int)$rRow['reserved'];
    }
}

page_header('Lager');
?>
<form class="stock-filter-bar" method="get">
  <div class="filter-group filter-search">
    <label for="stock_q">Søg</label>
    <input id="stock_q" name="q" value="<?=h($qFilter)?>" placeholder="Søg produkt, SKU, brand, fadnr, call name...">
  </div>
  <div class="filter-group">
    <label for="stock_status">Status</label>
    <select id="stock_status" name="status">
      <option value="active" <?=$statusFilter==='active'?'selected':''?>>Aktive</option>
      <option value="inactive" <?=$statusFilter==='inactive'?'selected':''?>>Inaktive</option>
      <option value="all" <?=$statusFilter==='all'?'selected':''?>>Alle</option>
    </select>
  </div>
  <div class="filter-group">
    <label for="stock_location">Lokation</label>
    <select id="stock_location" name="location">
      <option value="0">Alle lokationer</option>
      <?php foreach($locations as $l): ?>
        <option value="<?=$l['id']?>" <?=$locFilter===(int)$l['id']?'selected':''?>><?=h($l['name'])?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="filter-actions">
    <button type="submit">Søg & filtrér</button>
    <?php if($qFilter !== '' || $locFilter || $statusFilter !== 'active'): ?>
      <a class="button secondary" href="stock.php">Nulstil filtre</a>
    <?php endif; ?>
  </div>
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
          <button type="submit">Flyt lager</button>
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
      <button type="submit" class="button">Gem alle lagerrettelser</button>
    </div>
  </div>
  <div style="margin-bottom:12px; max-width:320px;">
    <label>Reference / Note ved gem<input name="batch_reference" value="Hurtig lagerrettelse"></label>
  </div>
</div>

<div class="table-wrap stock-table-container">
  <table class="stock-table">
    <thead>
      <tr>
        <th class="sticky-col sticky-sku">SKU</th>
        <th class="sticky-col sticky-product">Produkt</th>
        <?php if($statusFilter !== 'active'): ?>
          <th style="text-align:center;">Status</th>
        <?php endif; ?>
        <?php foreach($locations as $l): if($locFilter && (int)$l['id'] !== $locFilter) continue; ?>
          <th style="text-align:center; min-width:115px;"><?=h($l['name'])?></th>
        <?php endforeach; ?>
        <th style="text-align:right;">Fysisk</th>
        <th style="text-align:right;">Reserveret</th>
        <th style="text-align:right;">Disponibelt</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach($gridProducts as $gp):
        $pid=(int)$gp['product_id'];
        $locStocks = $pLocStock[$pid] ?? [];
        $locRes = $pLocRes[$pid] ?? [];

        $totalPhys = 0;
        $totalRes = 0;
        $totalAvail = 0;
      ?>
        <tr>
          <td class="sticky-col sticky-sku">
            <code class="stock-sku-badge"><?=h($gp['sku'])?></code>
          </td>
          <td class="sticky-col sticky-product">
            <div class="stock-product-cell">
              <strong><?=h($gp['name'])?></strong>
              <?php if(!empty($gp['call_name'])): ?>
                <div class="muted stock-subinfo"><em><?=h($gp['call_name'])?></em></div>
              <?php endif; ?>
              <?php if(!empty($gp['cask_number'])): ?>
                <div class="muted stock-subinfo">Fad: <?=h($gp['cask_number'])?></div>
              <?php endif; ?>
            </div>
          </td>
          <?php if($statusFilter !== 'active'): ?>
            <td style="text-align:center;">
              <span class="badge <?=$gp['status']==='active'?'green':'red'?>"><?=$gp['status']==='active'?'Aktiv':'Inaktiv'?></span>
            </td>
          <?php endif; ?>
          <?php foreach($locations as $l):
            $lid=(int)$l['id']; if($locFilter && $lid !== $locFilter) continue;
            $curPhys = $locStocks[$lid] ?? 0;
            $curRes = $locRes[$lid] ?? 0;
            $curAvail = $curPhys - $curRes;

            $totalPhys += $curPhys;
            $totalRes += $curRes;
            $totalAvail += $curAvail;
          ?>
            <td style="text-align:center;" class="stock-loc-cell">
              <div class="stock-input-wrap">
                <input type="number" min="0" name="stock[<?=$pid?>][<?=$lid?>]" value="<?=$curPhys?>" class="stock-phys-input">
                <?php if($curRes > 0): ?>
                  <span class="stock-res-badge" title="Reserveret på <?=h($l['name'])?>">Res: <?=$curRes?></span>
                <?php endif; ?>
              </div>
            </td>
          <?php endforeach; ?>
          <td style="text-align:right; font-weight:600;"><?=$totalPhys?></td>
          <td style="text-align:right; font-weight:600; color:var(--amber);"><?=$totalRes > 0 ? $totalRes : 0?></td>
          <td style="text-align:right;" class="stock-avail-cell <?=$totalAvail<0?'negative':''?>">
            <span class="stock-avail-badge <?=$totalAvail<0?'negative':($totalAvail>0?'positive':'zero')?>"><?=$totalAvail?></span>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if(!$gridProducts): ?>
        <tr>
          <td colspan="12" class="muted" style="text-align:center; padding:32px;">
            <div style="font-size:1.05rem; font-weight:600; margin-bottom:8px;">Ingen produkter matcher din søgning.</div>
            <?php if($qFilter !== ''): ?>
              <div style="margin-bottom:12px;">Søgning: <strong><?=h($qFilter)?></strong> (Filter: <?=h($statusFilter==='active'?'Aktive':($statusFilter==='inactive'?'Inaktive':'Alle'))?>)</div>
            <?php endif; ?>
            <a class="button secondary small" href="stock.php">Nulstil filtre</a>
          </td>
        </tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>
</form>

<?php else: ?>

<div class="table-wrap stock-table-container">
  <table class="stock-table">
    <thead>
      <tr>
        <th class="sticky-col sticky-sku">SKU</th>
        <th class="sticky-col sticky-product">Produkt</th>
        <?php if($statusFilter !== 'active'): ?>
          <th style="text-align:center;">Status</th>
        <?php endif; ?>
        <?php foreach($locations as $l): if($locFilter && (int)$l['id'] !== $locFilter) continue; ?>
          <th style="text-align:center; min-width:115px;"><?=h($l['name'])?></th>
        <?php endforeach; ?>
        <th style="text-align:right;">Fysisk</th>
        <th style="text-align:right;">Reserveret</th>
        <th style="text-align:right;">Disponibelt</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach($gridProducts as $gp):
        $pid=(int)$gp['product_id'];
        $locStocks = $pLocStock[$pid] ?? [];
        $locRes = $pLocRes[$pid] ?? [];

        $totalPhys = 0;
        $totalRes = 0;
        $totalAvail = 0;
      ?>
        <tr>
          <td class="sticky-col sticky-sku">
            <code class="stock-sku-badge"><?=h($gp['sku'])?></code>
          </td>
          <td class="sticky-col sticky-product">
            <div class="stock-product-cell">
              <strong><?=h($gp['name'])?></strong>
              <?php if(!empty($gp['call_name'])): ?>
                <div class="muted stock-subinfo"><em><?=h($gp['call_name'])?></em></div>
              <?php endif; ?>
              <?php if(!empty($gp['cask_number'])): ?>
                <div class="muted stock-subinfo">Fad: <?=h($gp['cask_number'])?></div>
              <?php endif; ?>
            </div>
          </td>
          <?php if($statusFilter !== 'active'): ?>
            <td style="text-align:center;">
              <span class="badge <?=$gp['status']==='active'?'green':'red'?>"><?=$gp['status']==='active'?'Aktiv':'Inaktiv'?></span>
            </td>
          <?php endif; ?>
          <?php foreach($locations as $l):
            $lid=(int)$l['id']; if($locFilter && $lid !== $locFilter) continue;
            $curPhys = $locStocks[$lid] ?? 0;
            $curRes = $locRes[$lid] ?? 0;
            $curAvail = $curPhys - $curRes;

            $totalPhys += $curPhys;
            $totalRes += $curRes;
            $totalAvail += $curAvail;
          ?>
            <td style="text-align:center;" class="stock-loc-cell">
              <span style="font-weight:600;"><?=$curPhys?></span>
              <?php if($curRes > 0): ?>
                <span class="stock-res-badge">Res: <?=$curRes?></span>
              <?php endif; ?>
            </td>
          <?php endforeach; ?>
          <td style="text-align:right; font-weight:600;"><?=$totalPhys?></td>
          <td style="text-align:right; font-weight:600; color:var(--amber);"><?=$totalRes > 0 ? $totalRes : 0?></td>
          <td style="text-align:right;" class="stock-avail-cell <?=$totalAvail<0?'negative':''?>">
            <span class="stock-avail-badge <?=$totalAvail<0?'negative':($totalAvail>0?'positive':'zero')?>"><?=$totalAvail?></span>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if(!$gridProducts): ?>
        <tr>
          <td colspan="12" class="muted" style="text-align:center; padding:32px;">
            <div style="font-size:1.05rem; font-weight:600; margin-bottom:8px;">Ingen produkter matcher din søgning.</div>
            <?php if($qFilter !== ''): ?>
              <div style="margin-bottom:12px;">Søgning: <strong><?=h($qFilter)?></strong> (Filter: <?=h($statusFilter==='active'?'Aktive':($statusFilter==='inactive'?'Inaktive':'Alle'))?>)</div>
            <?php endif; ?>
            <a class="button secondary small" href="stock.php">Nulstil filtre</a>
          </td>
        </tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<?php endif; ?>
<?php page_footer();
