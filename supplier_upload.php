<?php
require __DIR__.'/auth.php';require_module_enabled('supplier_upload');require_capability('imports.manage');require_once __DIR__.'/core/supplier_import.php';

$fieldLabels=[
 'sku'=>'SKU / Varenummer','name'=>'Produktnavn','brand_name'=>'Brand / Mærke','cask_number'=>'Fadnummer','cask_type'=>'Fadtype',
 'wholesale_price'=>'Engrospris','retail_price'=>'Udsalgspris','abv'=>'ABV','distillery'=>'Destilleri',
 'age_text'=>'Alder','vintage_year'=>'Årgang','category'=>'Kategori','country'=>'Land','bottle_size_cl'=>'Flaskestørrelse','bottle_count'=>'Antal flasker',
 'stock_quantity'=>'Lagerantal'
];
$assignableFields=[
 ''=>'– Ignorer kolonne –',
 'sku'=>'SKU / Varenummer',
 'name'=>'Produktnavn',
 'cask_number'=>'Fadnummer (Cask #)',
 'cask_type'=>'Fadtype',
 'wholesale_price'=>'Engrospris / Indkøbspris',
 'retail_price'=>'Udsalgspris (RRP)',
 'abv'=>'ABV / Alkoholprocent (% Vol.)',
 'distillery'=>'Destilleri',
 'age_text'=>'Alder (f.eks. 12 Years)',
 'vintage_year'=>'Årgang / Destilleringsår',
 'brand_name'=>'Brand / Mærke / Aftapper',
 'category'=>'Kategori (f.eks. Single Malt)',
 'country'=>'Land',
 'bottle_size_cl'=>'Flaskestørrelse (cl)',
 'bottle_count'=>'Antal flasker (Outturn)',
 'stock_quantity'=>'Lagerantal / Beholdning'
];
$assignableFields=[
 ''=>'– Ignorer kolonne –',
 'sku'=>'SKU / Varenummer',
 'name'=>'Produktnavn',
 'cask_number'=>'Fadnummer (Cask #)',
 'cask_type'=>'Fadtype',
 'wholesale_price'=>'Engrospris / Indkøbspris',
 'retail_price'=>'Udsalgspris (RRP)',
 'abv'=>'ABV / Alkoholprocent (% Vol.)',
 'distillery'=>'Destilleri',
 'age_text'=>'Alder (f.eks. 12 Years)',
 'vintage_year'=>'Årgang / Destilleringsår',
 'brand_name'=>'Brand / Mærke / Aftapper',
 'category'=>'Kategori (f.eks. Single Malt)',
 'country'=>'Land',
 'bottle_size_cl'=>'Flaskestørrelse (cl)',
 'bottle_count'=>'Antal flasker (Outturn)'
];
$token=trim((string)($_GET['preview']??$_POST['preview_token']??''));

if($_SERVER['REQUEST_METHOD']==='POST' && (string)($_POST['action']??'')==='upload'){
    try{
        if(!isset($_FILES['file'])||$_FILES['file']['error']!==UPLOAD_ERR_OK)throw new RuntimeException('Upload af filen fejlede.');
        $name=(string)$_FILES['file']['name'];$ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));if(!in_array($ext,['xlsx','csv'],true))throw new RuntimeException('Brug en Excel-fil (.xlsx) eller CSV-fil.');
        $sheets=$ext==='xlsx'?hsg_supplier_read_xlsx($_FILES['file']['tmp_name']):hsg_supplier_read_csv($_FILES['file']['tmp_name']);
        $brandId=(int)($_POST['brand_id']??0)?:null;$preview=hsg_supplier_prepare_preview($pdo,$sheets,$name,$brandId);$token=hsg_supplier_preview_save($preview);
        audit_log($pdo,'supplier_import.preview','import',null,['filename'=>$name,'sheet'=>$preview['sheet'],'rows'=>count($preview['items']),'brand_id'=>$brandId]);
        redirect('supplier_upload.php?preview='.$token);
    }catch(Throwable $e){flash('error',$e->getMessage());redirect('supplier_upload.php');}
}

if($_SERVER['REQUEST_METHOD']==='POST' && (string)($_POST['action']??'')==='remap'){
    try{
        $preview=hsg_supplier_preview_load($token);
        $customMap=(array)($_POST['col_map']??[]);
        $preview=hsg_supplier_recalculate_preview($pdo,$preview,$customMap);
        hsg_supplier_preview_save($preview,$token);
        flash('success','Kolonnemapping blev opdateret og preview er genberegnet.');
        redirect('supplier_upload.php?preview='.$token.'&remap=1');
    }catch(Throwable $e){flash('error','Kunne ikke genberegne mapping: '.$e->getMessage());redirect('supplier_upload.php'.($token?'?preview='.$token.'&remap=1':''));}
}

if($_SERVER['REQUEST_METHOD']==='POST' && (string)($_POST['action']??'')==='apply'){
    try{
        $preview=hsg_supplier_preview_load($token);$selected=array_map('intval',(array)($_POST['apply_rows']??[]));if(!$selected)throw new RuntimeException('Vælg mindst én række, der skal opdateres.');
        $products=$pdo->query('SELECT * FROM lager_products ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);$byId=[];foreach($products as $p)$byId[(int)$p['id']]=$p;
        $pdo->beginTransaction();$updated=0;$changedFields=0;$details=[];
        foreach($selected as $idx){if(!isset($preview['items'][$idx]))continue;$item=$preview['items'][$idx];$pid=(int)($_POST['product_'.$idx]??($item['match']['id']??0));if(!$pid||!isset($byId[$pid]))continue;
            $changes=hsg_supplier_apply_product($pdo,$pid,(array)$item['source']);if(!$changes)continue;$updated++;$changedFields+=count($changes);$details[]=['product_id'=>$pid,'fields'=>array_keys($changes),'source_row'=>$item['row']];
        }
        $pdo->prepare('INSERT INTO hsg_supplier_import_runs(filename,sheet_name,rows_detected,rows_updated,created_by_admin) VALUES(?,?,?,?,?)')->execute([
            substr((string)$preview['filename'],0,255),substr((string)$preview['sheet'],0,180),count($preview['items']),$updated,(int)current_admin_id()
        ]);
        $runId=(int)$pdo->lastInsertId();
        audit_log($pdo,'supplier_import.apply','import',(string)$runId,['filename'=>$preview['filename'],'products_updated'=>$updated,'fields_changed'=>$changedFields,'details'=>$details]);
        $pdo->commit();hsg_supplier_preview_delete($token);flash('success',"$updated produkter blev opdateret med i alt $changedFields feltændringer.");redirect('supplier_upload.php');
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();flash('error','Kunne ikke anvende ændringer: '.$e->getMessage());redirect('supplier_upload.php'.($token?'?preview='.$token:''));}
}

$brands=$pdo->query('SELECT id,name FROM lager_brands WHERE active=1 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
$products=$pdo->query('SELECT p.id,p.sku,p.name,p.cask_number,p.brand_id,b.name brand_name FROM lager_products p LEFT JOIN lager_brands b ON b.id=p.brand_id ORDER BY p.name')->fetchAll(PDO::FETCH_ASSOC);
$preview=null;if($token!==''){try{$preview=hsg_supplier_preview_load($token);}catch(Throwable $e){flash('error',$e->getMessage());$token='';}}
$runs=db_table_exists($pdo,'hsg_supplier_import_runs')?$pdo->query('SELECT * FROM hsg_supplier_import_runs ORDER BY id DESC LIMIT 12')->fetchAll(PDO::FETCH_ASSOC):[];
$missingCask=(int)$pdo->query("SELECT COUNT(*) FROM lager_products WHERE status<>'discontinued' AND (cask_number IS NULL OR cask_number='')")->fetchColumn();

function supfmt(mixed $v,string $field): string {if($v===null||$v==='')return '—';if(in_array($field,['wholesale_price','retail_price'],true))return number_format((float)$v,2,',','.').' kr.';if($field==='abv')return rtrim(rtrim(number_format((float)$v,2,',',''),'0'),',').' %';if($field==='bottle_size_cl')return rtrim(rtrim(number_format((float)$v,2,',',''),'0'),',').' cl';return (string)$v;}
page_header('Leverandør-upload');
?>
<div class="grid">
  <div class="card metric"><strong><?=$missingCask?></strong><span>Produkter mangler fadnummer</span></div>
  <div class="card metric"><strong><?=count($runs)?(int)$runs[0]['rows_updated']:0?></strong><span>Opdateret i seneste upload</span></div>
</div>

<div class="card">
  <h2>Upload leverandørfil</h2>
  <p class="muted">Upload forskellige pris-, outturn- eller produktfiler. HSG finder selv tabellen og forsøger at genkende SKU, produktnavn, <strong>fadnummer, fadtype, engrospris, udsalgspris, ABV, destilleri, alder, årgang</strong> m.m. Intet ændres før previewet er godkendt.</p>
  <form method="post" enctype="multipart/form-data"><?=csrf_field()?><input type="hidden" name="action" value="upload">
    <div class="split">
      <label>Excel/CSV<input type="file" name="file" accept=".xlsx,.csv" required></label>
      <label>Begræns til brand/leverandør (valgfri)<select name="brand_id"><option value="">Automatisk på tværs af alle produkter</option><?php foreach($brands as $b):?><option value="<?=$b['id']?>"><?=h($b['name'])?></option><?php endforeach;?></select></label>
    </div>
    <button>Analysér fil og vis preview</button>
  </form>
</div>

<?php if($preview):
$detected=array_keys((array)$preview['mapping']);$detectedLabels=[];foreach($detected as $f)$detectedLabels[]=$fieldLabels[$f]??match($f){'sku'=>'SKU','name'=>'Produktnavn','brand_name'=>'Brand',default=>$f};
?>
<div class="card">
  <div class="page-title"><div><h2>Preview: <?=h($preview['filename'])?></h2><p class="muted">Fundet i <strong><?=h($preview['sheet'])?></strong>, overskriftsrække <?=$preview['header_row']?>. Genkendte felter: <?=h(implode(', ',$detectedLabels))?>.</p></div></div>
  <p><span class="badge green">90–100 %</span> vælges som udgangspunkt automatisk. <span class="badge">70–89 %</span> kræver din vurdering. Usikre/umatchede rækker ændrer intet.</p>
</div>

<details class="card" <?=isset($_GET['remap'])||count($detected)===0?'open':''?>>
  <summary style="cursor:pointer;font-weight:600;font-size:1.1rem;">⚙️ Kolonnemapping (Tilknytning af felter)</summary>
  <p class="muted" style="margin-top:8px;">Vælg hvilken kolonne fra filen der svarer til hvilken egenskab i HSG. Systemet har automatisk forslået de mest sandsynlige matchende felter:</p>
  <form method="post"><?=csrf_field()?><input type="hidden" name="action" value="remap"><input type="hidden" name="preview_token" value="<?=h($token)?>">
    <div class="table-wrap" style="max-height:380px;overflow-y:auto;margin-bottom:12px;">
      <table>
        <thead><tr><th>Fil-kolonne</th><th>Overskrift i dokument</th><th>Tilknyttet HSG-felt</th></tr></thead>
        <tbody>
          <?php
          $headers=(array)($preview['headers']??[]);
          $colMapping=(array)($preview['col_mapping']??[]);
          foreach($headers as $ci=>$headerText):
            $curField=$colMapping[$ci]??'';
          ?>
          <tr>
            <td><strong>Kolonne #<?=($ci+1)?></strong></td>
            <td><code><?=h($headerText!==''?$headerText:'(Tom overskrift)')?></code></td>
            <td>
              <select name="col_map[<?=$ci?>]">
                <?php foreach($assignableFields as $fKey=>$fLabel): ?>
                  <option value="<?=$fKey?>" <?=$curField===$fKey?'selected':''?>><?=h($fLabel)?></option>
                <?php endforeach; ?>
              </select>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <button class="button secondary">Genberegn preview med tilpasset kolonnemapping</button>
  </form>
</details>
<form method="post"><?=csrf_field()?><input type="hidden" name="action" value="apply"><input type="hidden" name="preview_token" value="<?=h($token)?>">
<div class="table-wrap"><table><thead><tr><th>Opdatér</th><th>Filrække</th><th>Leverandørens vare</th><th>Match i HSG</th><th>Foreslåede ændringer</th></tr></thead><tbody>
<?php foreach($preview['items'] as $i=>$item):$src=(array)$item['source'];$mid=(int)($item['match']['id']??0);$score=(int)($item['match']['score']??0);$changes=(array)$item['changes'];?>
<tr>
  <td><input type="checkbox" name="apply_rows[]" value="<?=$i?>" <?=!empty($item['selected'])?'checked':''?> ></td>
  <td>#<?=intval($item['row'])?></td>
  <td><strong><?=h($src['name']??'(uden navn)')?></strong><br><small class="muted"><?php if(!empty($src['sku'])):?>SKU <?=h($src['sku'])?> · <?php endif;?><?php if(!empty($src['cask_number'])):?>Fad #<?=h($src['cask_number'])?><?php endif;?></small></td>
  <td>
    <span class="badge <?=$score>=90?'green':($score>=70?'':'red')?>"><?=$score?> %</span> <small class="muted"><?=h($item['match']['reason']??'')?></small>
    <select name="product_<?=$i?>" style="margin-top:6px"><option value="">– Intet match –</option><?php foreach($products as $p):?><option value="<?=$p['id']?>" <?=$mid===(int)$p['id']?'selected':''?>><?=h($p['sku'].' · '.$p['name'].(!empty($p['cask_number'])?' · #'.$p['cask_number']:''))?></option><?php endforeach;?></select>
  </td>
  <td><?php if(!$changes):?><span class="muted">Ingen ændringer fundet</span><?php else:?><ul style="margin:0;padding-left:18px"><?php foreach($changes as $field=>$c):?><li><strong><?=h($fieldLabels[$field]??$field)?>:</strong> <?=h(supfmt($c['old'],$field))?> → <strong><?=h(supfmt($c['new'],$field))?></strong></li><?php endforeach;?></ul><?php endif;?></td>
</tr>
<?php endforeach;?></tbody></table></div>
<div class="card"><div class="actions"><button>Anvend valgte ændringer</button><a class="button secondary" href="supplier_upload.php">Annuller preview</a></div><p class="muted">Kun de markerede rækker opdateres. Tomme værdier i leverandørfilen overskriver aldrig eksisterende produktdata.</p></div>
</form>
<?php endif;?>

<?php if($runs):?><div class="card"><h2>Seneste leverandøruploads</h2><div class="table-wrap"><table><thead><tr><th>Dato</th><th>Fil</th><th>Ark</th><th>Rækker fundet</th><th>Produkter opdateret</th></tr></thead><tbody><?php foreach($runs as $r):?><tr><td><?=h($r['created_at'])?></td><td><?=h($r['filename'])?></td><td><?=h($r['sheet_name']??'')?></td><td><?=intval($r['rows_detected'])?></td><td><?=intval($r['rows_updated'])?></td></tr><?php endforeach;?></tbody></table></div></div><?php endif;?>
<?php page_footer();
