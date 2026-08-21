<?php
declare(strict_types=1);
require __DIR__.'/auth.php';require_capability('quality.manage');require_once __DIR__.'/core/quality.php';
$defs=hsg_quality_field_definitions();
$filter=(string)($_GET['filter']??'needs_action');if(!in_array($filter,['needs_action','missing_data','missing_image','ready','approved','all'],true))$filter='needs_action';

if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        $action=(string)($_POST['action']??'');$pid=(int)($_POST['product_id']??0);
        if($action==='save_required'){
            hsg_quality_save_required_fields($pdo,(array)($_POST['required_fields']??[]));
            audit_log($pdo,'quality.required_fields','system',null,['fields'=>(array)($_POST['required_fields']??[])]);flash('success','Obligatoriske kvalitetsfelter er gemt.');redirect('quality.php?filter='.$filter);
        }
        if(!$pid)throw new RuntimeException('Produkt mangler.');
        if($action==='delete_no_stock'){
            $pdo->beginTransaction();
            try{
                $ps=$pdo->prepare('SELECT id,sku,name,image_path FROM lager_products WHERE id=? FOR UPDATE');$ps->execute([$pid]);$product=$ps->fetch(PDO::FETCH_ASSOC);if(!$product)throw new RuntimeException('Produktet findes ikke.');
                $ss=$pdo->prepare('SELECT location_id,quantity FROM lager_stock WHERE product_id=? FOR UPDATE');$ss->execute([$pid]);$stockRows=$ss->fetchAll(PDO::FETCH_ASSOC);
                $physicalTotal=0;foreach($stockRows as $sr)$physicalTotal+=(int)$sr['quantity'];
                if($physicalTotal>0)throw new RuntimeException('Produktet kan kun slettes direkte fra Datakvalitet, når fysisk lager er 0 eller lavere.');
                $ar=$pdo->prepare("SELECT COUNT(*) FROM lager_reservations WHERE product_id=? AND status='reserved'");$ar->execute([$pid]);$activeReservations=(int)$ar->fetchColumn();
                if($activeReservations>0)throw new RuntimeException('Produktet har aktive reservationer. Annuller eller afslut dem før sletning.');
                $snapshot=['sku'=>(string)$product['sku'],'name'=>(string)$product['name'],'physical_total'=>$physicalTotal,'stock'=>$stockRows,'source'=>'quality'];
                if(db_table_exists($pdo,'lager_image_candidates'))$pdo->prepare('DELETE FROM lager_image_candidates WHERE product_id=?')->execute([$pid]);
                if(db_table_exists($pdo,'lager_image_rejections'))$pdo->prepare('DELETE FROM lager_image_rejections WHERE product_id=?')->execute([$pid]);
                $pdo->prepare('DELETE FROM lager_stock_movements WHERE product_id=?')->execute([$pid]);
                $pdo->prepare("DELETE FROM lager_reservations WHERE product_id=? AND status<>'reserved'")->execute([$pid]);
                $pdo->prepare('DELETE FROM lager_products WHERE id=?')->execute([$pid]);
                $pdo->commit();audit_log($pdo,'product.delete_no_stock','product',(string)$pid,$snapshot);flash('success','Produktet blev slettet, fordi det ikke havde fysisk lager.');
            }catch(Throwable $deleteError){if($pdo->inTransaction())$pdo->rollBack();throw $deleteError;}
            redirect('quality.php?filter='.$filter);
        }
        if($action==='exempt'){
            $field=(string)($_POST['field_key']??'');if(!isset($defs[$field]))throw new RuntimeException('Feltet er ugyldigt.');
            $reason=trim((string)($_POST['reason']??'Ikke nødvendig for denne vare'));
            $pdo->prepare('INSERT INTO hsg_product_field_exemptions(product_id,field_key,reason,created_by_admin) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE reason=VALUES(reason),created_by_admin=VALUES(created_by_admin),updated_at=CURRENT_TIMESTAMP')->execute([$pid,$field,$reason,current_admin_id()]);
            hsg_quality_invalidate($pdo,$pid);audit_log($pdo,'quality.field_exempt','product',(string)$pid,['field'=>$field,'reason'=>$reason]);flash('success','Feltet er markeret som ikke nødvendigt for varen.');
        }elseif($action==='unexempt'){
            $field=(string)($_POST['field_key']??'');$pdo->prepare('DELETE FROM hsg_product_field_exemptions WHERE product_id=? AND field_key=?')->execute([$pid,$field]);hsg_quality_invalidate($pdo,$pid);audit_log($pdo,'quality.field_require','product',(string)$pid,['field'=>$field]);flash('success','Feltet tæller igen med i kvalitetskontrollen.');
        }elseif($action==='approve'){
            $st=$pdo->prepare("SELECT p.*,b.name brand_name,(COALESCE(st.physical,0)-COALESCE(rs.reserved,0)) available FROM lager_products p LEFT JOIN lager_brands b ON b.id=p.brand_id LEFT JOIN (SELECT product_id,SUM(quantity) physical FROM lager_stock GROUP BY product_id) st ON st.product_id=p.id LEFT JOIN (SELECT product_id,SUM(quantity) reserved FROM lager_reservations WHERE status='reserved' GROUP BY product_id) rs ON rs.product_id=p.id WHERE p.id=?");$st->execute([$pid]);$product=$st->fetch(PDO::FETCH_ASSOC);if(!$product)throw new RuntimeException('Produktet findes ikke.');
            $state=hsg_quality_state($pdo,$product);if(!$state['complete'])throw new RuntimeException('Varen kan ikke godkendes endnu. Udfyld eller markér de manglende felter som ikke nødvendige.');
            $pdo->prepare('UPDATE lager_products SET quality_approved_at=NOW(),quality_approved_by_admin=? WHERE id=?')->execute([current_admin_id(),$pid]);audit_log($pdo,'quality.product_approve','product',(string)$pid,[]);flash('success','Varen er kvalitetsgodkendt.');
        }else throw new RuntimeException('Ukendt handling.');
    }catch(Throwable $e){flash('error',$e->getMessage());}
    redirect('quality.php?filter='.$filter);
}

$required=hsg_quality_required_fields($pdo);$rows=hsg_quality_product_rows($pdo);$summary=['total'=>count($rows),'needs_action'=>0,'missing_data'=>0,'missing_image'=>0,'ready'=>0,'approved'=>0];$render=[];
foreach($rows as $r){$ex=hsg_quality_exemptions($pdo,(int)$r['id']);$s=hsg_quality_state($pdo,$r,$required,$ex);if($s['missing'])$summary['needs_action']++;if($s['missing_data'])$summary['missing_data']++;if($s['missing_image'])$summary['missing_image']++;if($s['ready'])$summary['ready']++;if($s['approved'])$summary['approved']++;
    $include=match($filter){'needs_action'=>(bool)$s['missing'],'missing_data'=>(bool)$s['missing_data'],'missing_image'=>(bool)$s['missing_image'],'ready'=>$s['ready'],'approved'=>$s['approved'],default=>true};if($include)$render[]=[$r,$s,$ex];
}
$nextId=0;foreach($rows as $r){$s=hsg_quality_state($pdo,$r,$required);if($s['missing']){$nextId=(int)$r['id'];break;}}
page_header('Datakvalitet');
?>
<div class="grid quality-metrics">
  <a class="card metric quality-card-link" href="quality.php?filter=needs_action"><strong><?=$summary['needs_action']?></strong><span>Kræver handling</span></a>
  <a class="card metric quality-card-link" href="quality.php?filter=missing_data"><strong><?=$summary['missing_data']?></strong><span>Mangler data</span></a>
  <a class="card metric quality-card-link" href="quality.php?filter=missing_image"><strong><?=$summary['missing_image']?></strong><span>Mangler godkendt billede</span></a>
  <a class="card metric quality-card-link" href="quality.php?filter=ready"><strong><?=$summary['ready']?></strong><span>Klar til godkendelse</span></a>
  <a class="card metric quality-card-link" href="quality.php?filter=approved"><strong><?=$summary['approved']?></strong><span>Godkendte varer</span></a>
</div>
<div class="card">
  <div class="page-title"><div><h2>Kvalitetskø</h2><p class="muted">Et manglende felt kan udfyldes eller markeres <strong>Ikke nødvendig</strong> for netop varen. Når alle punkter er afklaret, kan varen godkendes og forsvinder fra fejllisten.</p></div><?php if($nextId):?><a class="button" href="products.php?edit=<?=$nextId?>">Ret næste fejl</a><?php endif;?></div>
  <div class="actions"><a class="button <?=$filter==='needs_action'?'':'secondary'?>" href="quality.php?filter=needs_action">Kræver handling</a><a class="button <?=$filter==='missing_data'?'':'secondary'?>" href="quality.php?filter=missing_data">Mangler data</a><a class="button <?=$filter==='missing_image'?'':'secondary'?>" href="quality.php?filter=missing_image">Mangler billede</a><a class="button <?=$filter==='ready'?'':'secondary'?>" href="quality.php?filter=ready">Klar</a><a class="button <?=$filter==='approved'?'':'secondary'?>" href="quality.php?filter=approved">Godkendt</a><a class="button <?=$filter==='all'?'':'secondary'?>" href="quality.php?filter=all">Alle</a></div>
</div>
<?php if(!$render):?><div class="card"><strong>Ingen varer i dette filter.</strong></div><?php endif;?>
<?php foreach($render as [$p,$state,$ex]):?>
<div class="card quality-product-card">
  <div class="page-title"><div><h2 style="margin:0"><?=h($p['name'])?></h2><div class="muted"><?=h($p['sku'])?><?=!empty($p['brand_name'])?' · '.h($p['brand_name']):''?> · Fysisk lager: <?=intval($p['physical_total']??0)?> · Disponibelt: <?=max(0,(int)$p['available'])?></div></div><div class="actions"><a class="button secondary" href="products.php?edit=<?=$p['id']?>">Ret produkt</a><?php if((int)($p['physical_total']??0)<=0):?><form method="post" style="display:inline" onsubmit="return confirm('Slet <?=h(addslashes($p['name']))?> permanent? Produktet har ikke fysisk lager, og historiske bevægelser/afsluttede reservationer fjernes sammen med varen.');"><?=csrf_field()?><input type="hidden" name="action" value="delete_no_stock"><input type="hidden" name="product_id" value="<?=$p['id']?>"><button class="danger" <?=((int)($p['active_reservations']??0)>0)?'disabled title="Produktet har aktive reservationer"':''?>>Slet produkt</button></form><?php endif;?><?php if($state['complete']&&!$state['approved']):?><form method="post" style="display:inline"><?=csrf_field()?><input type="hidden" name="action" value="approve"><input type="hidden" name="product_id" value="<?=$p['id']?>"><button class="success">Godkend vare</button></form><?php endif;?></div></div>
  <?php if($state['approved']):?><div class="quality-approved-line"><span class="badge green">✓ Godkendt</span> <span class="muted"><?=h($p['quality_approved_at'])?><?=!empty($p['quality_approved_by_name'])?' af '.h($p['quality_approved_by_name']):''?></span></div><?php elseif($state['ready']):?><div class="flash success">Alle obligatoriske punkter er afklaret. Varen er klar til manuel godkendelse.</div><?php endif;?>
  <?php if($state['missing']):?><div class="quality-field-list"><strong>Mangler:</strong><?php foreach($state['missing'] as $key=>$meta):?><div class="quality-field-item"><span class="badge red"><?=h($meta['label'])?></span><form method="post" class="quality-inline-form"><?=csrf_field()?><input type="hidden" name="action" value="exempt"><input type="hidden" name="product_id" value="<?=$p['id']?>"><input type="hidden" name="field_key" value="<?=h($key)?>"><input type="hidden" name="reason" value="Ikke nødvendig for denne vare"><button class="secondary small">Ikke nødvendig</button></form></div><?php endforeach;?></div><?php endif;?>
  <?php if($state['exempted']):?><details class="quality-exemptions"><summary>Felter markeret ikke nødvendige (<?=count($state['exempted'])?>)</summary><div class="quality-field-list"><?php foreach($state['exempted'] as $key=>$meta):?><div class="quality-field-item"><span class="badge"><?=h($meta['label'])?> · ikke nødvendig</span><form method="post" class="quality-inline-form"><?=csrf_field()?><input type="hidden" name="action" value="unexempt"><input type="hidden" name="product_id" value="<?=$p['id']?>"><input type="hidden" name="field_key" value="<?=h($key)?>"><button class="secondary small">Gør relevant igen</button></form></div><?php endforeach;?></div></details><?php endif;?>
</div>
<?php endforeach;?>
<div class="card"><details><summary><strong>Indstil obligatoriske felter</strong></summary><p class="muted">Disse felter kontrolleres globalt. På den enkelte vare kan du stadig markere et obligatorisk felt som “Ikke nødvendig”. Billedkravet gælder kun varer med disponibelt lager over 0.</p><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="save_required"><div class="quality-required-grid"><?php foreach($defs as $key=>$meta):?><label class="check"><input type="checkbox" name="required_fields[]" value="<?=h($key)?>" <?=in_array($key,$required,true)?'checked':''?>> <?=h($meta['label'])?></label><?php endforeach;?></div><button>Gem obligatoriske felter</button></form></details></div>
<?php page_footer();
