<?php
require __DIR__.'/auth.php';
require_capability('products.manage');
require_once __DIR__.'/core/quality.php';

if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        $action=(string)($_POST['action']??'save');
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

            // 1. Move stock from Source to Target for each location
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

            // 2. Reassign reservations
            $pdo->prepare('UPDATE lager_reservations SET product_id=? WHERE product_id=?')->execute([$targetId, $sourceId]);

            // 3. Fill missing attributes on Target from Source
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

            // 4. Cleanup candidates/rejections and remove Source product
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
            flash('success','Produktet blev slettet, fordi det havde negativt fysisk lager.');redirect('products.php?negative_stock=1');
        }
        $id=(int)($_POST['id']??0);
        $sku=trim((string)($_POST['sku']??''));
        $name=trim((string)($_POST['name']??''));
        $callName=trim((string)($_POST['call_name']??''));
        if($sku===''||$name==='') throw new RuntimeException('SKU og navn skal udfyldes.');
        $brand=(int)($_POST['brand_id']??0)?:null;
        $status=in_array($_POST['status']??'active',['active','inactive','discontinued'],true)?$_POST['status']:'active';
        $vintage=trim((string)($_POST['vintage_year']??''));
        $vintage=$vintage!==''?(int)$vintage:null;
        if($vintage!==null && ($vintage<1900 || $vintage>(int)date('Y'))) throw new RuntimeException('Årgang skal være et gyldigt årstal.');
        $vals=[
            $sku,$name,$callName?:null,$brand,trim((string)($_POST['category']??'')),trim((string)($_POST['distillery']??'')),
            trim((string)($_POST['country']??'')),trim((string)($_POST['age_text']??'')),$vintage,
            parse_decimal($_POST['abv']??''),parse_decimal($_POST['bottle_size_cl']??''),trim((string)($_POST['cask_type']??'')),trim((string)($_POST['cask_number']??'')),
            trim((string)($_POST['bottle_count']??'')),parse_decimal($_POST['wholesale_price']??''),parse_decimal($_POST['retail_price']??''),
            !empty($_POST['is_new'])?1:0,!empty($_POST['show_in_catalog'])?1:0,$status,trim((string)($_POST['supplier_name']??'')),
            trim((string)($_POST['supplier_domain']??'')),trim((string)($_POST['supplier_url']??'')),trim((string)($_POST['notes']??''))
        ];
        if($id){
            $sql='UPDATE lager_products SET sku=?,name=?,call_name=?,brand_id=?,category=?,distillery=?,country=?,age_text=?,vintage_year=?,abv=?,bottle_size_cl=?,cask_type=?,cask_number=?,bottle_count=?,wholesale_price=?,retail_price=?,is_new=?,show_in_catalog=?,status=?,supplier_name=?,supplier_domain=?,supplier_url=?,notes=? WHERE id=?';
            $vals[]=$id;$pdo->prepare($sql)->execute($vals);
            hsg_sync_product_stock_status($pdo,$id);
        }else{
            $sql='INSERT INTO lager_products(sku,name,call_name,brand_id,category,distillery,country,age_text,vintage_year,abv,bottle_size_cl,cask_type,cask_number,bottle_count,wholesale_price,retail_price,is_new,show_in_catalog,status,supplier_name,supplier_domain,supplier_url,notes) VALUES('.implode(',',array_fill(0,23,'?')).')';
            $pdo->prepare($sql)->execute($vals);$id=(int)$pdo->lastInsertId();
            hsg_sync_product_stock_status($pdo,$id);
        }
        hsg_quality_invalidate($pdo,$id);
        audit_log($pdo,'product.save','product',(string)$id,['sku'=>$sku,'name'=>$name,'status'=>$status]);
        hsg_do_action('product.saved',['product_id'=>$id,'sku'=>$sku,'name'=>$name,'status'=>$status]);
        flash('success','Produkt gemt.');redirect('products.php'.($id?'?edit='.$id:''));
    }catch(Throwable $e){if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();flash('error','Kunne ikke gemme: '.$e->getMessage());}
}

// Auto-deactivate products with total available stock <= 0
$pdo->exec("UPDATE lager_products p SET status='inactive' WHERE p.status='active' AND COALESCE((SELECT SUM(s.quantity) FROM lager_stock s WHERE s.product_id=p.id),0) - COALESCE((SELECT SUM(r.quantity) FROM lager_reservations r WHERE r.product_id=p.id AND r.status='reserved'),0) <= 0");

$q=trim((string)($_GET['q']??''));$missingCaskFilter=!empty($_GET['missing_cask']);$negativeStockFilter=!empty($_GET['negative_stock']);$showStatus=trim((string)($_GET['status']??'active'));$edit=null;
if(is_admin()&&isset($_GET['edit'])){$st=$pdo->prepare('SELECT * FROM lager_products WHERE id=?');$st->execute([(int)$_GET['edit']]);$edit=$st->fetch();}
$brands=$pdo->query('SELECT b.id,b.name,b.parent_id,pb.name parent_name FROM lager_brands b LEFT JOIN lager_brands pb ON pb.id=b.parent_id WHERE b.active=1 ORDER BY COALESCE(pb.sort_order,b.sort_order),COALESCE(pb.name,b.name),b.parent_id IS NOT NULL,b.sort_order,b.name')->fetchAll();
$params=[];$conditions=[];
if($q!==''){$conditions[]='(p.sku LIKE ? OR p.name LIKE ? OR p.call_name LIKE ? OR b.name LIKE ? OR p.cask_number LIKE ?)';$params=array_fill(0,5,'%'.$q.'%');}
if($missingCaskFilter)$conditions[]="(p.status<>'discontinued' AND (p.cask_number IS NULL OR p.cask_number=''))";
if($negativeStockFilter)$conditions[]="(COALESCE(st.physical_total,0)<0 OR COALESCE(st.negative_locations,0)>0)";
if(!$missingCaskFilter && !$negativeStockFilter && in_array($showStatus,['active','inactive','discontinued'],true)){
    $conditions[]="p.status=?"; $params[]=$showStatus;
}
$where=$conditions?'WHERE '.implode(' AND ',$conditions):'';
$st=$pdo->prepare("SELECT p.*,b.name brand_name,COALESCE(st.physical_total,0) physical_total,COALESCE(st.negative_locations,0) negative_locations,COALESCE(rr.active_reservations,0) active_reservations FROM lager_products p LEFT JOIN lager_brands b ON b.id=p.brand_id LEFT JOIN (SELECT product_id,SUM(quantity) physical_total,SUM(CASE WHEN quantity<0 THEN 1 ELSE 0 END) negative_locations FROM lager_stock GROUP BY product_id) st ON st.product_id=p.id LEFT JOIN (SELECT product_id,COUNT(*) active_reservations FROM lager_reservations WHERE status='reserved' GROUP BY product_id) rr ON rr.product_id=p.id $where ORDER BY p.name");$st->execute($params);$products=$st->fetchAll();
$missingIds=$pdo->query("SELECT id FROM lager_products WHERE status<>'discontinued' AND (distillery IS NULL OR distillery='' OR abv IS NULL OR age_text IS NULL OR age_text='' OR category IS NULL OR category='') ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
$requiredQualityFields=hsg_quality_required_fields($pdo);
$reqMark=static fn(string $field): string => in_array($field,$requiredQualityFields,true) ? ' *' : '';
$missingCaskCount=(int)$pdo->query("SELECT COUNT(*) FROM lager_products WHERE status<>'discontinued' AND (cask_number IS NULL OR cask_number='')")->fetchColumn();
$negativeStockCount=(int)$pdo->query("SELECT COUNT(*) FROM lager_products p LEFT JOIN (SELECT product_id,SUM(quantity) physical_total,SUM(CASE WHEN quantity<0 THEN 1 ELSE 0 END) negative_locations FROM lager_stock GROUP BY product_id) st ON st.product_id=p.id WHERE COALESCE(st.physical_total,0)<0 OR COALESCE(st.negative_locations,0)>0")->fetchColumn();
page_header('Produkter');
?>
<form class="searchbar" method="get"><input name="q" value="<?=h($q)?>" placeholder="Søg produkt, SKU, fadnummer eller brand"><button>Søg</button></form>
<div class="card"><div class="actions"><a class="button <?=$showStatus==='active'&&!$missingCaskFilter&&!$negativeStockFilter?'':'secondary'?>" href="products.php?status=active">Aktive</a><a class="button <?=$showStatus==='inactive'&&!$missingCaskFilter&&!$negativeStockFilter?'':'secondary'?>" href="products.php?status=inactive">Inaktive</a><a class="button <?=$showStatus==='discontinued'&&!$missingCaskFilter&&!$negativeStockFilter?'':'secondary'?>" href="products.php?status=discontinued">Udgåede</a><a class="button <?=$showStatus==='all'&&!$missingCaskFilter&&!$negativeStockFilter?'':'secondary'?>" href="products.php?status=all">Alle produkter</a><a class="button <?=$missingCaskFilter?'':'secondary'?>" href="products.php?missing_cask=1">Fadnummer mangler (<?=$missingCaskCount?>)</a><a class="button <?=$negativeStockFilter?'danger':'secondary'?>" href="products.php?negative_stock=1">Negativt lager (<?=$negativeStockCount?>)</a></div><p class="muted">Produkter med 0 eller mindre på disponibelt lager deaktiveres automatisk. Liste-visningen filtreres som standard til <strong>Aktive</strong> produkter.</p></div>

<?php if(is_admin()):?>
<div class="card">
  <details>
    <summary style="cursor:pointer;font-weight:600;font-size:1.1rem;">🔀 Flet to varenumre / produkter</summary>
    <p class="muted" style="margin-top:8px">Vælg et kildeprodukt, der skal flettes ind i et målprodukt. Kildeproduktets lagerbeholdning overføres til målproduktet, reservationer flyttes, og manglende felter udfyldes automatisk. Kildeproduktet slettes derefter.</p>
    <form method="post" onsubmit="return confirm('Er du sikker på, at du vil flette disse to produkter? Kildeproduktet vil blive slettet og lageret lagt sammen med målproduktet.');"><?=csrf_field()?><input type="hidden" name="action" value="merge_products">
      <div class="split">
        <label>Kildeprodukt (Slettes efter fletning)
          <select name="source_id" required>
            <option value="">– Vælg kildeprodukt –</option>
            <?php foreach($products as $p):?><option value="<?=$p['id']?>"><?=h($p['sku'].' · '.$p['name'].(!empty($p['cask_number'])?' · #'.$p['cask_number']:''))?></option><?php endforeach;?>
          </select>
        </label>
        <label>Målprodukt (Beholdes og opdateres)
          <select name="target_id" required>
            <option value="">– Vælg målprodukt –</option>
            <?php foreach($products as $p):?><option value="<?=$p['id']?>"><?=h($p['sku'].' · '.$p['name'].(!empty($p['cask_number'])?' · #'.$p['cask_number']:''))?></option><?php endforeach;?>
          </select>
        </label>
      </div>
      <button class="button">Flet varenumre og saml lager</button>
    </form>
  </details>
</div>
<?php endif;?>

<?php if(is_admin()):?>
<div class="card">
  <div class="page-title" style="margin-bottom:8px"><div><h2 style="margin:0">Produktdata-assistent</h2><p class="muted" style="margin:5px 0 0">Aflæser vareteksten og udfylder manglende ABV, alder, årgang, destilleri, kategori, flaskestørrelse, fadtype og eksplicit fadnummer. Sikre mønstre læses lokalt; Groq bruges kun som ekstra hjælp, hvis en API-nøgle er sat op.</p></div></div>
  <div class="actions"><button type="button" class="secondary" id="enrichAllBtn">Udfyld manglende data på alle (<?=count($missingIds)?>)</button></div>
  <div id="enrichAllStatus" class="muted" style="margin-top:8px"></div>
</div>

<div class="card"><h2><?=$edit?'Rediger produkt':'Nyt produkt'?></h2>
<form method="post" id="productForm"><?=csrf_field()?><input type="hidden" name="id" value="<?=$edit['id']??0?>">
<div class="three"><label>SKU / nummer *<input name="sku" required value="<?=h($edit['sku']??'')?>"></label><label>Produktnavn / varetekst *<input name="name" required value="<?=h($edit['name']??'')?>"></label><label>Kaldenavn (Valgfri underoverskrift)<input name="call_name" value="<?=h($edit['call_name']??'')?>" placeholder="fx The Chain - Chapter 2"></label></div>
<div class="product-assistant-box">
  <div class="actions"><button type="button" id="enrichProductBtn">✨ Udfyld fra varetekst</button><label class="check" style="margin:0"><input type="checkbox" id="enrichUseAi" checked> Brug AI til usikre/manglende felter</label></div>
  <div id="enrichProductStatus" class="muted" style="margin-top:8px">Eksisterende værdier overskrives ikke automatisk.</div>
</div>
<div class="three"><label>Brand<?=$reqMark('brand_id')?><select name="brand_id" id="brand_id"><option value="">– Intet brand –</option><?php foreach($brands as $b): $bDisplayName = !empty($b['parent_id']) ? $b['parent_name'].' › '.$b['name'] : $b['name']; ?><option value="<?=$b['id']?>" data-brand-name="<?=h($b['name'])?>" <?=($edit['brand_id']??null)==$b['id']?'selected':''?>><?=h($bDisplayName)?></option><?php endforeach;?></select></label><label>Kategori<?=$reqMark('category')?><input name="category" value="<?=h($edit['category']??'')?>" placeholder="Single Malt, Rom..."></label><label>Destilleri<?=$reqMark('distillery')?><input name="distillery" value="<?=h($edit['distillery']??'')?>"></label></div>
<div class="three"><label>Land<?=$reqMark('country')?><input name="country" value="<?=h($edit['country']??'')?>"></label><label>Alder<?=$reqMark('age_text')?><input name="age_text" value="<?=h($edit['age_text']??'')?>" placeholder="12 år"></label><label>Årgang / destilleret<?=$reqMark('vintage_year')?><input type="number" min="1900" max="<?=date('Y')?>" name="vintage_year" value="<?=h($edit['vintage_year']??'')?>" placeholder="2013"></label></div>
<div class="three"><label>Alc. %<?=$reqMark('abv')?><input inputmode="decimal" name="abv" value="<?=h($edit['abv']??'')?>"></label><label>Flaskestørrelse (cl)<?=$reqMark('bottle_size_cl')?><input inputmode="decimal" name="bottle_size_cl" value="<?=h($edit['bottle_size_cl']??70)?>"></label><label>Fadtype<?=$reqMark('cask_type')?><input name="cask_type" value="<?=h($edit['cask_type']??'')?>"></label></div>
<div class="split"><label>Fadnummer<?=$reqMark('cask_number')?><input name="cask_number" value="<?=h($edit['cask_number']??'')?>" placeholder="fx 300805 eller 892-4"><span class="muted">Nummeret efter # / Cask No. bruges som stærkt match ved leverandørupload og billedtjek.</span></label><label>Antal flasker i aftapning<?=$reqMark('bottle_count')?><input name="bottle_count" value="<?=h($edit['bottle_count']??'')?>"></label></div>
<div class="split"><label>Engrospris (ekskl. moms)<?=$reqMark('wholesale_price')?><input inputmode="decimal" name="wholesale_price" value="<?=h($edit['wholesale_price']??'')?>"></label><label>Udsalgspris (inkl. moms)<?=$reqMark('retail_price')?><input inputmode="decimal" name="retail_price" value="<?=h($edit['retail_price']??'')?>"></label></div>
<div class="split"><label>Leverandør<input name="supplier_name" value="<?=h($edit['supplier_name']??'')?>"></label><label>Leverandør-domæne<input name="supplier_domain" value="<?=h($edit['supplier_domain']??'')?>" placeholder="example.com"></label></div>
<label>Produktets direkte leverandør-URL (valgfri)<input type="url" name="supplier_url" value="<?=h($edit['supplier_url']??'')?>"><span class="muted">Overstyrer brandets leverandør-URL for netop dette produkt.</span></label>
<div class="three"><label>Status<select name="status"><?php foreach(['active'=>'Aktiv','inactive'=>'Inaktiv','discontinued'=>'Udgået'] as $k=>$v):?><option value="<?=$k?>" <?=($edit['status']??'active')===$k?'selected':''?>><?=$v?></option><?php endforeach;?></select></label><label class="check"><input type="checkbox" name="is_new" value="1" <?=!empty($edit['is_new'])?'checked':''?>> Nyhed</label><label class="check"><input type="checkbox" name="show_in_catalog" value="1" <?=!$edit||!empty($edit['show_in_catalog'])?'checked':''?>> Vis i katalog</label></div>
<label>Noter<textarea name="notes"><?=h($edit['notes']??'')?></textarea></label>
<?php if($edit && !empty($edit['data_enriched_at'])):?><div class="flash success"><strong>Senest analyseret:</strong> <?=h($edit['data_enriched_at'])?> · <?=h($edit['data_enrichment_source']??'')?> · score <?=intval($edit['data_enrichment_score']??0)?>%<?php if($edit['data_enrichment_note']):?><br><span class="muted"><?=h($edit['data_enrichment_note'])?></span><?php endif;?></div><?php endif;?>
<div class="actions"><button>Gem produkt</button><?php if($edit):?><a class="button secondary" href="products.php">Nyt produkt</a><a class="button secondary" href="image_check.php?product=<?=$edit['id']?>">Billede</a><?php endif;?></div>
</form></div>
<?php endif;?>

<div class="table-wrap"><table><thead><tr><th>SKU</th><th>Produkt</th><th>Brand</th><th>Fysisk lager</th><th>Priser</th><th>Nyhed</th><th>Katalog</th><th>Status</th><?php if(is_admin()):?><th></th><?php endif;?></tr></thead><tbody>
<?php foreach($products as $p): $isNegative=((int)$p['physical_total']<0||(int)$p['negative_locations']>0);?><tr class="<?=$isNegative?'validation-flagged-row':''?>"><td><?=h($p['sku'])?></td><td><div class="product-row"><img class="product-thumb" src="<?=h(product_image_url($p['image_path']))?>"><div><div class="product-title"><?=h($p['name'])?><?php if(!empty($p['call_name'])):?> <small class="muted">(<?=h($p['call_name'])?>)</small><?php endif;?></div><span class="product-meta"><?=h($p['distillery'])?><?=!empty($p['vintage_year'])?' · '.intval($p['vintage_year']):''?><?=!empty($p['age_text'])?' · '.h($p['age_text']):''?><?=($p['abv']!==null?' · '.h(rtrim(rtrim(number_format((float)$p['abv'],2,',',''),'0'),',')).'%':'')?><?=!empty($p['cask_number'])?' · Fad #'.h($p['cask_number']):' · Fadnr. mangler'?></span></div></div></td><td><?=h($p['brand_name']??'–')?></td><td><span class="badge <?=$isNegative?'red':'green'?>"><?=intval($p['physical_total'])?> stk.</span><?php if((int)$p['negative_locations']>0):?><br><small class="muted"><?=intval($p['negative_locations'])?> lokation(er) under 0</small><?php endif;?></td><td><span class="muted">Engros:</span> <?=money_dkk($p['wholesale_price'])?><br><span class="muted">Udsalg:</span> <?=money_dkk($p['retail_price'])?></td><td><form method="post" style="margin:0;display:inline;"><?=csrf_field()?><input type="hidden" name="action" value="toggle_flag"><input type="hidden" name="id" value="<?=$p['id']?>"><input type="hidden" name="field" value="is_new"><input type="checkbox" name="value" value="1" <?=$p['is_new']?'checked':''?> onchange="toggleProductFlag(this)" <?=is_admin()?'':'disabled'?>></form></td><td><form method="post" style="margin:0;display:inline;"><?=csrf_field()?><input type="hidden" name="action" value="toggle_flag"><input type="hidden" name="id" value="<?=$p['id']?>"><input type="hidden" name="field" value="show_in_catalog"><input type="checkbox" name="value" value="1" <?=$p['show_in_catalog']?'checked':''?> onchange="toggleProductFlag(this)" <?=is_admin()?'':'disabled'?>></form></td><td><span class="badge"><?=h(product_status_label($p['status']))?></span></td><?php if(is_admin()):?><td><div class="actions"><a class="button secondary" href="?edit=<?=$p['id']?>">Rediger</a><?php if($isNegative):?><form method="post" onsubmit="return confirm('Slet <?=h(addslashes($p['name']))?> permanent? Historiske lagerbevægelser og afsluttede reservationer for produktet slettes også.');"><?=csrf_field()?><input type="hidden" name="action" value="delete_negative"><input type="hidden" name="id" value="<?=$p['id']?>"><button type="submit" class="danger" <?=((int)$p['active_reservations']>0)?'disabled title="Produktet har aktive reservationer"':''?>>Slet produkt</button></form><?php endif;?></div></td><?php endif;?></tr><?php endforeach;?>
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
const productForm=document.getElementById('productForm');
const statusEl=document.getElementById('enrichProductStatus');
function field(name){return productForm?.querySelector('[name="'+name+'"]')||null;}
function brandName(){const s=field('brand_id');return s?.selectedOptions?.[0]?.dataset?.brandName||'';}
function setIfEmpty(name,value){
  const el=field(name);if(!el||value===undefined||value===null||String(value).trim()==='')return false;
  const current=String(el.value??'').trim();
  if(current!=='' && !(name==='bottle_size_cl' && current==='70' && String(value)!=='70'))return false;
  el.value=value;el.classList.add('assistant-filled');setTimeout(()=>el.classList.remove('assistant-filled'),1800);return true;
}
function setBrandIfMatch(value){
  if(!value||!field('brand_id')||field('brand_id').value)return false;
  const wanted=String(value).trim().toLocaleLowerCase('da');
  for(const opt of field('brand_id').options){if((opt.dataset.brandName||'').trim().toLocaleLowerCase('da')===wanted){field('brand_id').value=opt.value;return true;}}
  return false;
}
async function enrichRequest(fd){
  const r=await fetch('product_enrich.php',{method:'POST',body:fd,credentials:'same-origin'});let j={};try{j=await r.json();}catch(e){}
  if(!r.ok||!j.ok){const err=new Error(j.error||('HTTP '+r.status));err.retryAfter=Number(j.retry_after||r.headers.get('Retry-After')||0);throw err;}return j;
}
document.getElementById('enrichProductBtn')?.addEventListener('click',async()=>{
  const btn=document.getElementById('enrichProductBtn');btn.disabled=true;statusEl.textContent='Analyserer vareteksten…';
  try{
    const fd=new FormData();fd.append('csrf',enrichCsrf);fd.append('text',field('name')?.value||'');fd.append('product_id',field('id')?.value||'0');fd.append('brand_name',brandName());fd.append('supplier_name',field('supplier_name')?.value||'');fd.append('notes',field('notes')?.value||'');fd.append('use_ai',document.getElementById('enrichUseAi')?.checked?'1':'0');
    const j=await enrichRequest(fd),f=j.result.fields||{};let n=0;
    for(const k of ['distillery','country','age_text','vintage_year','abv','bottle_size_cl','cask_type','cask_number','category'])if(setIfEmpty(k,f[k]))n++;
    if(setBrandIfMatch(f.brand_name))n++;
    statusEl.innerHTML='<strong>'+n+' felt(er) foreslået</strong> · score '+Number(j.result.confidence||0)+'% · '+String(j.result.source||'')+(j.result.reason?' · '+String(j.result.reason).replace(/[&<>]/g,s=>({'&':'&amp;','<':'&lt;','>':'&gt;'}[s])):'');
  }catch(e){statusEl.textContent='Kunne ikke analysere: '+e.message;}finally{btn.disabled=false;}
});

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
