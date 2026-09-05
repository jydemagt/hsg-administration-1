<?php
require __DIR__.'/auth.php';require_module_enabled('brands');require_capability('brands.manage');
if($_SERVER['REQUEST_METHOD']==='POST'){
  $action=$_POST['action']??'save';
  try{
    if($action==='save'){
        $id=(int)($_POST['id']??0);
        $parentId=(int)($_POST['parent_id']??0)?:null;
        $name=trim($_POST['name']??'');
        $description=$parentId?null:trim($_POST['description']??'');
        $web=trim($_POST['website_url']??'');
        $order=(int)($_POST['sort_order']??100);
        $showInCatalog=!empty($_POST['show_in_catalog'])?1:0;
        if($name==='')throw new RuntimeException('Brandnavn skal udfyldes.');
        if($parentId && $parentId===$id)throw new RuntimeException('Et brand kan ikke være sit eget hovedbrand.');

        $logoPath=null;
        if(isset($_FILES['logo_file']) && ($_FILES['logo_file']['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_OK){
            $ext=strtolower(pathinfo($_FILES['logo_file']['name'],PATHINFO_EXTENSION));
            if(!in_array($ext,['jpg','jpeg','png','webp','gif','svg'],true)){
                throw new RuntimeException('Ugyldigt billedformat for brandlogo. Brug JPG, PNG, WEBP, GIF eller SVG.');
            }
            $targetDir=__DIR__.'/uploads/brand-logos';
            if(!is_dir($targetDir)) @mkdir($targetDir,0775,true);
            $filename='brand-'.$id.'-'.bin2hex(random_bytes(6)).'.'.$ext;
            $dest=$targetDir.'/'.$filename;
            if(move_uploaded_file($_FILES['logo_file']['tmp_name'],$dest)){
                $logoPath='uploads/brand-logos/'.$filename;
            }
        }

        if($id){
            if($logoPath){
                $pdo->prepare('UPDATE lager_brands SET parent_id=?,name=?,description=?,website_url=?,sort_order=?,show_in_catalog=?,logo_path=? WHERE id=?')->execute([$parentId,$name,$description,$web?:null,$order,$showInCatalog,$logoPath,$id]);
            } else {
                $pdo->prepare('UPDATE lager_brands SET parent_id=?,name=?,description=?,website_url=?,sort_order=?,show_in_catalog=? WHERE id=?')->execute([$parentId,$name,$description,$web?:null,$order,$showInCatalog,$id]);
            }
        }else{
            $pdo->prepare('INSERT INTO lager_brands(parent_id,name,description,website_url,sort_order,show_in_catalog,logo_path,active) VALUES(?,?,?,?,?,?,?,1)')->execute([$parentId,$name,$description,$web?:null,$order,$showInCatalog,$logoPath]);
            $id=(int)$pdo->lastInsertId();
        }
        audit_log($pdo,'brand.save','brand',(string)$id,['name'=>$name,'parent_id'=>$parentId]);
        flash('success','Brand gemt.');redirect('brands.php');
    }
    if($action==='toggle'){$toggleId=(int)$_POST['id'];$pdo->prepare('UPDATE lager_brands SET active=1-active WHERE id=?')->execute([$toggleId]);audit_log($pdo,'brand.toggle','brand',(string)$toggleId);flash('success','Brandstatus ændret.');redirect('brands.php');}
  }catch(Throwable $e){flash('error',$e->getMessage());}
}
$edit=null;if(isset($_GET['edit'])){$st=$pdo->prepare('SELECT * FROM lager_brands WHERE id=?');$st->execute([(int)$_GET['edit']]);$edit=$st->fetch();}
$parentBrands=$pdo->query('SELECT id,name FROM lager_brands WHERE parent_id IS NULL ORDER BY sort_order,name')->fetchAll();
$allRows=$pdo->query('SELECT b.*,pb.name parent_name,COUNT(p.id) product_count FROM lager_brands b LEFT JOIN lager_brands pb ON pb.id=b.parent_id LEFT JOIN lager_products p ON p.brand_id=b.id GROUP BY b.id ORDER BY COALESCE(pb.sort_order,b.sort_order),COALESCE(pb.name,b.name),b.parent_id IS NOT NULL,b.sort_order,b.name')->fetchAll();
page_header('Brands');
?>
<div class="card brand-editor">
  <h2><?=$edit?'Rediger brand / underbrand':'Opret brand / underbrand'?></h2>
  <form method="post" enctype="multipart/form-data"><?=csrf_field()?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?=$edit['id']??0?>">
    <div class="split">
      <label>Brandnavn<input name="name" required value="<?=h($edit['name']??'')?>"></label>
      <label>Hovedbrand (Valgfrit underbrand)
        <select name="parent_id" id="parent_id_select" onchange="document.getElementById('desc_wrapper').style.display=this.value?'none':'block'">
          <option value="">– Selvstændigt hovedbrand –</option>
          <?php foreach($parentBrands as $pb): if($edit && (int)$pb['id']===(int)$edit['id']) continue; ?>
            <option value="<?=$pb['id']?>" <?=($edit['parent_id']??null)==$pb['id']?'selected':''?>><?=h($pb['name'])?></option>
          <?php endforeach;?>
        </select>
      </label>
    </div>
    <div class="split">
      <label>Hjemmeside<input type="url" name="website_url" placeholder="https://..." value="<?=h($edit['website_url']??'')?>"></label>
      <label>Sortering<input type="number" name="sort_order" value="<?=h($edit['sort_order']??100)?>"></label>
    </div>
    <div class="split">
      <label class="check" style="margin-top:22px"><input type="checkbox" name="show_in_catalog" value="1" <?=!$edit||!empty($edit['show_in_catalog'])?'checked':''?>> Vis brand i kataloget</label>
      <label>Brandlogo / billede (JPG, PNG, SVG)
        <input type="file" name="logo_file" accept="image/*">
        <?php if(!empty($edit['logo_path'])):?><span class="muted">Nuværende: <?=h($edit['logo_path'])?></span><?php endif;?>
      </label>
    </div>
    <div id="desc_wrapper" style="display:<?=!empty($edit['parent_id'])?'none':'block'?>">
      <label>Brandbeskrivelse<textarea name="description" placeholder="Denne tekst placeres før brandets flasker i kataloget."><?=h($edit['description']??'')?></textarea></label>
    </div>
    <div class="actions"><button>Gem brand</button><?php if($edit):?><a class="button secondary" href="brands.php">Annuller</a><?php endif;?></div>
  </form>
</div>

<div class="table-wrap">
  <table>
    <thead><tr><th>Logo</th><th>Brand / Underbrand</th><th>Produkter</th><th>Katalog</th><th>Beskrivelse</th><th>Status</th><th></th></tr></thead>
    <tbody>
      <?php foreach($allRows as $b): $isSub=!empty($b['parent_id']); ?>
      <tr>
        <td><?php if(!empty($b['logo_path'])):?><img src="<?=h($b['logo_path'])?>" alt="<?=h($b['name'])?>" style="max-height:36px;max-width:80px;object-fit:contain;"><?php else:?><span class="muted">–</span><?php endif;?></td>
        <td style="<?=$isSub?'padding-left:28px;':''?>">
          <?php if($isSub):?><span class="muted">↳ Underbrand af <strong><?=h($b['parent_name'])?></strong></span><br><?php endif;?>
          <strong><?=h($b['name'])?></strong>
          <?php if($b['website_url']):?><br><span class="muted"><?=h($b['website_url'])?></span><?php endif;?>
        </td>
        <td><?=$b['product_count']?></td>
        <td><span class="badge <?=$b['show_in_catalog']?'green':''?>"><?=$b['show_in_catalog']?'Ja':'Nej'?></span></td>
        <td><?=$isSub?'<span class="muted">(Underbrand - ingen beskrivelse)</span>':h(strlen((string)$b['description'])>120?substr((string)$b['description'],0,117).'...':(string)$b['description'])?></td>
        <td><span class="badge <?=$b['active']?'green':''?>"><?=$b['active']?'Aktiv':'Deaktiveret'?></span></td>
        <td class="actions">
          <a class="button secondary" href="?edit=<?=$b['id']?>">Rediger</a>
          <a class="button secondary" href="image_check.php?brand=<?=$b['id']?>">Billeder</a>
          <form method="post"><?=csrf_field()?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?=$b['id']?>"><button class="secondary"><?=$b['active']?'Deaktivér':'Aktivér'?></button></form>
        </td>
      </tr>
      <?php endforeach;?>
    </tbody>
  </table>
</div>
<?php page_footer();
