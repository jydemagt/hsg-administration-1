<?php
require __DIR__.'/auth.php';
require_capability('products.manage');
require_once __DIR__.'/core/quality.php';
require_once __DIR__.'/core/catalog_layout.php';

if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        $action=(string)($_POST['action']??'');
        if($action==='toggle_flag'){
            $id=(int)($_POST['id']??0);
            $field=(string)($_POST['field']??'');
            if($id<=0 || !in_array($field,['is_new','show_in_catalog'],true)) throw new RuntimeException('Ugyldig handling.');
            $val=!empty($_POST['value'])?1:0;
            $pdo->prepare("UPDATE lager_products SET {$field}=? WHERE id=?")->execute([$val,$id]);
            audit_log($pdo,'product.toggle_'.$field,'product',(string)$id,['field'=>$field,'value'=>$val]);

            if(!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH'])==='xmlhttprequest'){
                header('Content-Type: application/json');
                echo json_encode(['ok'=>true,'id'=>$id,'field'=>$field,'value'=>$val]);
                exit;
            }
            flash('success','Produktoplysninger opdateret.');
            redirect('products.php');
        }
        if($action==='merge_products'){
            $sourceId=(int)($_POST['source_id']??0);
            $targetId=(int)($_POST['target_id']??0);
            if($sourceId<=0 || $targetId<=0) throw new RuntimeException('Vælg både kilde- og målprodukt.');
            if($sourceId===$targetId) throw new RuntimeException('Kildeprodukt og målprodukt skal være forskellige.');

            $pdo->beginTransaction();
            $stSrc=$pdo->prepare('SELECT * FROM lager_products WHERE id=? FOR UPDATE');$stSrc->execute([$sourceId]);$src=$stSrc->fetch(PDO::FETCH_ASSOC);
            $stTgt=$pdo->prepare('SELECT * FROM lager_products WHERE id=? FOR UPDATE');$stTgt->execute([$targetId]);$tgt=$stTgt->fetch(PDO::FETCH_ASSOC);
            if(!$src || !$tgt) throw new RuntimeException('Et af de valgte produkter findes ikke længere.');

            $stStock=$pdo->prepare('SELECT location_id, quantity FROM lager_stock WHERE product_id=? FOR UPDATE');$stStock->execute([$sourceId]);$stockRows=$stStock->fetchAll(PDO::FETCH_ASSOC);
            foreach($stockRows as $sr){
                $locId=(int)$sr['location_id'];
                $srcQty=(int)$sr['quantity'];
                if($srcQty===0) continue;

                $stTgtLoc=$pdo->prepare('SELECT quantity FROM lager_stock WHERE product_id=? AND location_id=? FOR UPDATE');
                $stTgtLoc->execute([$targetId, $locId]);
                $oldTgtQty=(int)($stTgtLoc->fetchColumn()?:0);
                $newTgtQty=$oldTgtQty + $srcQty;

                $pdo->prepare('INSERT INTO lager_stock(product_id,location_id,quantity) VALUES(?,?,?) ON DUPLICATE KEY UPDATE quantity=VALUES(quantity)')->execute([$targetId,$locId,$newTgtQty]);
                $pdo->prepare('INSERT INTO lager_stock_movements(product_id,location_id,change_qty,balance_after,movement_type,reference,created_by,created_by_admin) VALUES(?,?,?,?,?,?,?,?)')
                    ->execute([$targetId,$locId,$srcQty,$newTgtQty,'transfer_in','Flettet fra '.($src['sku']?:$src['id']),null,current_admin_id()]);
            }
            $pdo->prepare('DELETE FROM lager_stock WHERE product_id=?')->execute([$sourceId]);

            $pdo->prepare('UPDATE lager_reservations SET product_id=? WHERE product_id=?')->execute([$targetId, $sourceId]);

            $fillable=['call_name','brand_id','category','distillery','country','age_text','vintage_year','abv','bottle_size_cl','cask_type','cask_number','bottle_count','wholesale_price','retail_price','supplier_name','supplier_domain','supplier_url','notes','image_path'];
            $sets=[];$params=[];
            foreach($fillable as $f){
                $tgtVal=$tgt[$f]??null;$srcVal=$src[$f]??null;
                if(($tgtVal===null||trim((string)$tgtVal)==='') && $srcVal!==null && trim((string)$srcVal)!==''){
                    $sets[]="$f=?";$params[]=$srcVal;
                }
            }
            if($sets){
                $params[]=$targetId;
                $pdo->prepare('UPDATE lager_products SET '.implode(',',$sets).' WHERE id=?')->execute($params);
            }

            if(db_table_exists($pdo,'lager_image_candidates'))$pdo->prepare('DELETE FROM lager_image_candidates WHERE product_id=?')->execute([$sourceId]);
            if(db_table_exists($pdo,'lager_image_rejections'))$pdo->prepare('DELETE FROM lager_image_rejections WHERE product_id=?')->execute([$sourceId]);
            $pdo->prepare('DELETE FROM lager_stock_movements WHERE product_id=?')->execute([$sourceId]);
            $pdo->prepare('DELETE FROM lager_products WHERE id=?')->execute([$sourceId]);

            hsg_sync_product_stock_status($pdo,$targetId);
            $pdo->commit();
            hsg_quality_invalidate($pdo,$targetId);
            audit_log($pdo,'product.merge','product',(string)$targetId,['source_id'=>$sourceId,'source_sku'=>$src['sku'],'target_sku'=>$tgt['sku']]);
            flash('success','Produkt '.h($src['sku']).' blev flettet ind i '.h($tgt['sku']).'.');
            redirect('products.php');
        }
        if($action==='delete_negative'){
            $id=(int)($_POST['id']??0);
            if($id<=0) throw new RuntimeException('Produktet mangler.');
            $pdo->beginTransaction();
            $ps=$pdo->prepare('SELECT id,sku,name,image_path FROM lager_products WHERE id=? FOR UPDATE');$ps->execute([$id]);$product=$ps->fetch();
            if(!$product) throw new RuntimeException('Produktet findes ikke.');
            $ss=$pdo->prepare('SELECT location_id,quantity FROM lager_stock WHERE product_id=? FOR UPDATE');$ss->execute([$id]);$stockRows=$ss->fetchAll();
            $physicalTotal=0;$negativeLocations=0;foreach($stockRows as $sr){$q=(int)$sr['quantity'];$physicalTotal+=$q;if($q<0)$negativeLocations++;}
            if($physicalTotal>=0 && $negativeLocations===0) throw new RuntimeException('Produktet kan kun slettes, når det fysiske lager er negativt.');
            $ar=$pdo->prepare("SELECT COUNT(*) FROM lager_reservations WHERE product_id=? AND status='reserved'");$ar->execute([$id]);$activeReservations=(int)$ar->fetchColumn();
            if($activeReservations>0) throw new RuntimeException('Produktet har aktive reservationer. Annuller eller afslut dem før sletning.');
            $snapshot=['sku'=>(string)$product['sku'],'name'=>(string)$product['name'],'physical_total'=>$physicalTotal,'negative_locations'=>$negativeLocations,'stock'=>$stockRows];
            if(db_table_exists($pdo,'lager_image_candidates')){$pdo->prepare('DELETE FROM lager_image_candidates WHERE product_id=?')->execute([$id]);}
            if(db_table_exists($pdo,'lager_image_rejections')){$pdo->prepare('DELETE FROM lager_image_rejections WHERE product_id=?')->execute([$id]);}
            $pdo->prepare('DELETE FROM lager_stock_movements WHERE product_id=?')->execute([$id]);
            $pdo->prepare("DELETE FROM lager_reservations WHERE product_id=? AND status<>'reserved'")->execute([$id]);
            $pdo->prepare('DELETE FROM lager_products WHERE id=?')->execute([$id]);
            $pdo->commit();
            audit_log($pdo,'product.delete_negative','product',(string)$id,$snapshot);
            flash('success','Produktet blev slettet, fordi det havde negativt fysisk lager.');redirect('products.php?status=all&filter=negative_stock');
        }
    }catch(Throwable $e){if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();flash('error','Handling fejlede: '.$e->getMessage());}
}

$q=trim((string)($_GET['q']??''));
$showStatus=trim((string)($_GET['status']??'active'));
$subFilter=trim((string)($_GET['filter']??''));

$params=[];$conditions=[];
if($q!==''){$conditions[]='(p.sku LIKE ? OR p.name LIKE ? OR p.call_name LIKE ? OR b.name LIKE ? OR p.cask_number LIKE ?)';$params=array_fill(0,5,'%'.$q.'%');}

if(in_array($showStatus,['active','inactive','discontinued'],true)){
    $conditions[]="p.status=?"; $params[]=$showStatus;
}

if($subFilter==='missing_cask') $conditions[]="(p.cask_number IS NULL OR p.cask_number='')";
if($subFilter==='negative_stock') $conditions[]="(COALESCE(st.physical_total,0)<0 OR COALESCE(st.negative_locations,0)>0)";
if($subFilter==='missing_data') $conditions[]="(p.distillery IS NULL OR p.distillery='' OR p.abv IS NULL OR p.age_text IS NULL OR p.age_text='')";
if($subFilter==='missing_image') $conditions[]="(p.image_path IS NULL OR p.image_path='' OR p.image_approval_status<>'approved')";

$where=$conditions?'WHERE '.implode(' AND ',$conditions):'';
$st=$pdo->prepare("SELECT p.*,b.name brand_name,COALESCE(st.physical_total,0) physical_total,COALESCE(st.negative_locations,0) negative_locations,COALESCE(rr.reserved_total,0) reserved_total,COALESCE(rr.active_reservations,0) active_reservations FROM lager_products p LEFT JOIN lager_brands b ON b.id=p.brand_id LEFT JOIN (SELECT product_id,SUM(quantity) physical_total,SUM(CASE WHEN quantity<0 THEN 1 ELSE 0 END) negative_locations FROM lager_stock GROUP BY product_id) st ON st.product_id=p.id LEFT JOIN (SELECT product_id,SUM(quantity) reserved_total,COUNT(*) active_reservations FROM lager_reservations WHERE status='reserved' GROUP BY product_id) rr ON rr.product_id=p.id $where ORDER BY p.name");$st->execute($params);$products=$st->fetchAll();
$missingIds=$pdo->query("SELECT id FROM lager_products WHERE status<>'discontinued' AND (distillery IS NULL OR distillery='' OR abv IS NULL OR age_text IS NULL OR age_text='' OR category IS NULL OR category='') ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);

$missingCaskCount=(int)$pdo->query("SELECT COUNT(*) FROM lager_products WHERE status<>'discontinued' AND (cask_number IS NULL OR cask_number='')")->fetchColumn();
$negativeStockCount=(int)$pdo->query("SELECT COUNT(*) FROM lager_products p LEFT JOIN (SELECT product_id,SUM(quantity) physical_total,SUM(CASE WHEN quantity<0 THEN 1 ELSE 0 END) negative_locations FROM lager_stock GROUP BY product_id) st ON st.product_id=p.id WHERE COALESCE(st.physical_total,0)<0 OR COALESCE(st.negative_locations,0)>0")->fetchColumn();

page_header('Produkter');
?>
<form class="searchbar" method="get">
  <input name="q" value="<?=h($q)?>" placeholder="Søg produkt, SKU, fadnummer eller brand">
  <?php if($showStatus):?><input type="hidden" name="status" value="<?=h($showStatus)?>"><?php endif;?>
  <button type="submit">Søg</button>
  <?php if($q !== '' || $subFilter !== ''):?>
    <a class="button secondary" href="products.php?status=<?=h($showStatus)?>">Nulstil filtre</a>
  <?php endif;?>
</form>

<div class="card">
  <div class="page-title" style="margin-bottom:12px;">
    <div>
      <h2 style="margin:0;">Produktoversigt</h2>
      <p class="muted" style="margin:4px 0 0;">Se og filtrér produkter. Klik Rediger for at tilpasse produktstammedata.</p>
    </div>
    <?php if(is_admin()): ?>
      <div>
        <a class="button" href="product_edit.php">＋ Opret nyt produkt</a>
      </div>
    <?php endif; ?>
  </div>

  <div class="actions">
    <a class="button <?=$showStatus==='active'&&$subFilter===''?'':'secondary'?>" href="products.php?status=active">Aktive</a>
    <a class="button <?=$showStatus==='inactive'&&$subFilter===''?'':'secondary'?>" href="products.php?status=inactive">Inaktive</a>
    <a class="button <?=$showStatus==='discontinued'&&$subFilter===''?'':'secondary'?>" href="products.php?status=discontinued">Udgåede</a>
    <a class="button <?=$showStatus==='all'&&$subFilter===''?'':'secondary'?>" href="products.php?status=all">Alle m. status</a>
    <span style="border-right: 1px solid #d0d5dd; margin: 0 4px;"></span>
    <a class="button <?=$subFilter==='missing_cask'?'':'secondary'?>" href="products.php?status=<?=$showStatus?>&filter=missing_cask">Mangler fadnr. (<?=$missingCaskCount?>)</a>
    <a class="button <?=$subFilter==='negative_stock'?'danger':'secondary'?>" href="products.php?status=<?=$showStatus?>&filter=negative_stock">Negativt lager (<?=$negativeStockCount?>)</a>
  </div>
</div>

<?php if(is_admin()):?>
<div class="card">
  <details>
    <summary style="cursor:pointer;font-weight:600;font-size:1.1rem;">🔀 Flet to varenumre / produkter</summary>
    <p class="muted" style="margin-top:8px">Vælg et kildeprodukt, der skal flettes ind i et målprodukt. Kildeproduktets lagerbeholdning overføres til målproduktet, reservationer flyttes, og manglende felter udfyldes automatisk. Kildeproduktet slettes derefter.</p>
    <form method="post" onsubmit="return confirm('Er du sikker på, at du vil flette disse to produkter? Kildeproduktet vil blive slettet og lageret lagt sammen med målproduktet.');"><?=csrf_field()?><input type="hidden" name="action" value="merge_products">
      <div class="split">
        <label>Kildeprodukt (Slettes efter fletning) *
          <select name="source_id" required>
            <option value="">– Vælg kildeprodukt –</option>
            <?php foreach($products as $p):?><option value="<?=$p['id']?>"><?=h($p['sku'].' · '.$p['name'].(!empty($p['cask_number'])?' · #'.$p['cask_number']:''))?></option><?php endforeach;?>
          </select>
        </label>
        <label>Målprodukt (Beholdes og opdateres) *
          <select name="target_id" required>
            <option value="">– Vælg målprodukt –</option>
            <?php foreach($products as $p):?><option value="<?=$p['id']?>"><?=h($p['sku'].' · '.$p['name'].(!empty($p['cask_number'])?' · #'.$p['cask_number']:''))?></option><?php endforeach;?>
          </select>
        </label>
      </div>
      <button type="submit" class="button">Flet varenumre og saml lager</button>
    </form>
  </details>
</div>

<div class="card">
  <div class="page-title" style="margin-bottom:8px">
    <div>
      <h2 style="margin:0">Produktdata-assistent</h2>
      <p class="muted" style="margin:5px 0 0">Aflæser vareteksten og udfylder manglende data på produkter uden tilstrækkelig information.</p>
    </div>
  </div>
  <div class="actions"><button type="button" class="secondary" id="enrichAllBtn">Udfyld manglende data på alle (<?=count($missingIds)?>)</button></div>
  <div id="enrichAllStatus" class="muted" style="margin-top:8px"></div>
</div>
<?php endif;?>

<div class="mobile-list">
<?php foreach($products as $p):
  $phys=(int)$p['physical_total'];
  $resQty=(int)($p['reserved_total']??0);
  $avail=$phys - $resQty;
?>
<article class="mobile-product">
  <div class="mobile-product-main">
    <img src="<?=h(product_image_url($p['image_path']))?>" alt="<?=h($p['name'])?>">
    <div>
      <h3><?=h($p['name'])?></h3>
      <div class="sku"><?=h($p['sku'])?><?=!empty($p['brand_name'])?' · '.h($p['brand_name']):''?></div>
      <div class="mobile-stock-line"><span>Disponibelt</span><strong class="available"><?=$avail?> stk.</strong></div>
      <div class="mobile-stock-line"><span>Fysisk / Reserveret</span><span><?=$phys?> / <?=$resQty?></span></div>
      <div class="mobile-stock-line"><span>Pris (Engros/Udsalg)</span><span><?=money_dkk($p['wholesale_price'])?> / <?=money_dkk($p['retail_price'])?></span></div>
      <div style="margin-top:6px; display:flex; gap:6px; flex-wrap:wrap;">
        <span class="badge <?=$p['status']==='active'?'green':($p['status']==='inactive'?'blue':'')?>"><?=h(product_status_label($p['status']))?></span>
        <?php if($p['is_new']):?><span class="badge red">NYHED</span><?php endif;?>
      </div>
      <?php if(is_admin()):?>
        <div class="actions" style="margin-top:8px;">
          <a class="button secondary small" href="product_edit.php?id=<?=$p['id']?>">Rediger</a>
        </div>
      <?php endif;?>
    </div>
  </div>
</article>
<?php endforeach;?>
</div>

<div class="table-wrap desktop-only"><table><thead><tr><th>SKU</th><th>Produkt</th><th>Brand</th><th>Lager (Fysisk / Res / Disp)</th><th>Priser</th><th style="text-align:center;">Nyhed</th><th style="text-align:center;">Katalog</th><th style="text-align:center;">Status</th><?php if(is_admin()):?><th></th><?php endif;?></tr></thead><tbody>
<?php foreach($products as $p):
  $phys=(int)$p['physical_total'];
  $resQty=(int)($p['reserved_total']??0);
  $avail=$phys - $resQty;
  $isNegative=($phys<0||(int)$p['negative_locations']>0);
?>
<tr class="<?=$isNegative?'validation-flagged-row':''?>">
  <td><strong><?=h($p['sku'])?></strong></td>
  <td>
    <div class="product-row">
      <img class="product-thumb" src="<?=h(product_image_url($p['image_path']))?>" alt="<?=h($p['name'])?>">
      <div>
        <div class="product-title"><?=h($p['name'])?><?php if(!empty($p['call_name'])):?> <small class="muted">(<?=h($p['call_name'])?>)</small><?php endif;?></div>
        <span class="product-meta"><?=h($p['distillery'])?><?=!empty($p['vintage_year'])?' · '.intval($p['vintage_year']):''?><?=!empty($p['age_text'])?' · '.h($p['age_text']):''?><?=($p['abv']!==null?' · '.h(rtrim(rtrim(number_format((float)$p['abv'],2,',',''),'0'),',')).'%':'')?><?=!empty($p['cask_number'])?' · Fad #'.h($p['cask_number']):' · Fadnr. mangler'?></span>
      </div>
    </div>
  </td>
  <td><?=h($p['brand_name']??'–')?></td>
  <td>
    <div style="font-size:1.05rem; font-weight:800; color:<?=$avail<0?'#b42318':($avail>0?'#067647':'#667085')?>;">Disp: <?=$avail?> stk.</div>
    <small class="muted">Fysisk: <?=$phys?> · Res: <?=$resQty?></small>
    <?php if((int)$p['negative_locations']>0):?><br><small style="color:#b42318; font-weight:600;"><?=intval($p['negative_locations'])?> lokation(er) under 0</small><?php endif;?>
  </td>
  <td><span class="muted">Engros:</span> <?=money_dkk($p['wholesale_price'])?><br><span class="muted">Udsalg:</span> <?=money_dkk($p['retail_price'])?></td>
  <td style="text-align:center;"><form method="post" style="margin:0;display:inline;"><?=csrf_field()?><input type="hidden" name="action" value="toggle_flag"><input type="hidden" name="id" value="<?=$p['id']?>"><input type="hidden" name="field" value="is_new"><input type="checkbox" name="value" value="1" <?=$p['is_new']?'checked':''?> onchange="toggleProductFlag(this)" <?=is_admin()?'':'disabled'?>></form></td>
  <td style="text-align:center;"><form method="post" style="margin:0;display:inline;"><?=csrf_field()?><input type="hidden" name="action" value="toggle_flag"><input type="hidden" name="id" value="<?=$p['id']?>"><input type="hidden" name="field" value="show_in_catalog"><input type="checkbox" name="value" value="1" <?=$p['show_in_catalog']?'checked':''?> onchange="toggleProductFlag(this)" <?=is_admin()?'':'disabled'?>></form></td>
  <td style="text-align:center;"><span class="badge <?=$p['status']==='active'?'green':($p['status']==='inactive'?'blue':'')?>"><?=h(product_status_label($p['status']))?></span></td>
  <?php if(is_admin()):?>
  <td>
    <div class="actions">
      <a class="button secondary small" href="product_edit.php?id=<?=$p['id']?>">Rediger</a>
      <?php if($isNegative):?>
      <form method="post" onsubmit="return confirm('Slet <?=h(addslashes($p['name']))?> permanent? Historiske lagerbevægelser og afsluttede reservationer for produktet slettes også.');"><?=csrf_field()?><input type="hidden" name="action" value="delete_negative"><input type="hidden" name="id" value="<?=$p['id']?>"><button type="submit" class="danger small" <?=((int)$p['active_reservations']>0)?'disabled title="Produktet har aktive reservationer"':''?>>Slet produkt</button></form>
      <?php endif;?>
    </div>
  </td>
  <?php endif;?>
</tr>
<?php endforeach;?>
<?php if(!$products):?>
<tr>
  <td colspan="<?=is_admin()?9:8?>" class="muted" style="text-align:center; padding:24px;">
    Der blev ikke fundet nogen produkter med de valgte filtre/søgning. <a class="button secondary small" href="products.php">Ryd filtre</a>
  </td>
</tr>
<?php endif;?>
</tbody></table></div>

<?php if(is_admin()):?>
<script>
async function toggleProductFlag(checkbox){
  const form=checkbox.form;if(!form)return;
  const fd=new FormData(form);
  if(!checkbox.checked) fd.delete('value');
  try{
    const r=await fetch('products.php',{
      method:'POST',
      body:fd,
      headers:{'X-Requested-With':'XMLHttpRequest'}
    });
    if(!r.ok) throw new Error('HTTP '+r.status);
  }catch(e){
    checkbox.checked=!checkbox.checked;
    alert('Kunne ikke gemme ændringen: '+e.message);
  }
}
const enrichCsrf=<?=json_encode(csrf_token())?>;
const missingProductIds=<?=json_encode(array_map('intval',$missingIds))?>;

async function enrichRequest(fd){
  const r=await fetch('product_enrich.php',{method:'POST',body:fd,credentials:'same-origin'});let j={};try{j=await r.json();}catch(e){}
  if(!r.ok||!j.ok){const err=new Error(j.error||('HTTP '+r.status));err.retryAfter=Number(j.retry_after||r.headers.get('Retry-After')||0);throw err;}return j;
}

document.getElementById('enrichAllBtn')?.addEventListener('click',async()=>{
  const btn=document.getElementById('enrichAllBtn'),out=document.getElementById('enrichAllStatus');if(!missingProductIds.length){out.textContent='Der er ingen produkter med oplagte manglende data.';return;}
  btn.disabled=true;let i=0,ok=0,changed=0;
  while(i<missingProductIds.length){
    const id=missingProductIds[i];out.textContent='Analyserer '+(i+1)+' af '+missingProductIds.length+'…';
    const fd=new FormData();fd.append('csrf',enrichCsrf);fd.append('product_id',id);fd.append('apply','1');fd.append('use_ai','1');
    try{const j=await enrichRequest(fd);ok++;changed+=Object.keys(j.applied||{}).length;i++;await new Promise(r=>setTimeout(r,350));}
    catch(e){if(e.retryAfter>0){out.textContent='Groq rate limit – fortsætter automatisk om '+e.retryAfter+' sek.';await new Promise(r=>setTimeout(r,(e.retryAfter+1)*1000));continue;}out.textContent='Stoppet ved produkt '+id+': '+e.message;break;}
  }
  if(i>=missingProductIds.length){out.textContent='Færdig: '+ok+' produkter analyseret og '+changed+' manglende felter udfyldt. Genindlæser…';setTimeout(()=>location.reload(),900);}else btn.disabled=false;
});
</script>
<?php endif;?>
<?php page_footer();
