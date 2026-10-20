<?php
require __DIR__.'/auth.php';require_module_enabled('supplier_upload');require_capability('imports.manage');require_once __DIR__.'/core/supplier_import.php';

$activeLocations = $pdo->query('SELECT id, name FROM lager_locations WHERE active=1 ORDER BY sort_order, id')->fetchAll(PDO::FETCH_ASSOC);
$locationNames = [];
foreach($activeLocations as $al) $locationNames['stock_loc_'.$al['id']] = (string)$al['name'];

$fieldLabels=[
 'sku'=>'SKU / Varenummer','name'=>'Produktnavn','brand_name'=>'Brand / Mærke','cask_number'=>'Fadnummer','cask_type'=>'Fadtype',
 'wholesale_price'=>'Engrospris','retail_price'=>'Udsalgspris','abv'=>'ABV','distillery'=>'Destilleri',
 'age_text'=>'Alder','vintage_year'=>'Årgang','category'=>'Kategori','country'=>'Land','bottle_size_cl'=>'Flaskestørrelse','bottle_count'=>'Antal flasker',
 'stock_quantity'=>'Lagerantal i alt'
];
foreach($activeLocations as $al) {
    $fieldLabels['stock_loc_'.$al['id']] = 'Lagerantal: '.$al['name'];
}

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
 'stock_quantity'=>'Lagerantal i alt (kontroltal / første lokation)'
];
foreach($activeLocations as $al) {
    $assignableFields['stock_loc_'.$al['id']] = 'Lagerantal for '.$al['name'];
}
$reservationModes=[
 'add'=>'Kims tal er efter reservationer – læg dem oven i',
 'none'=>'Kims tal er det fysiske lager – brug dem uændret',
];
$oldPreviewMessage='Previewet er lavet med en ældre version af systemet. Upload filen igen.';
$token=trim((string)($_GET['preview']??$_POST['preview_token']??''));
if(!hsg_supplier_preview_token_valid($token))$token=''; // Ugyldigt token: ingen preview og intet token i redirects.
$postAction=$_SERVER['REQUEST_METHOD']==='POST'?(string)($_POST['action']??''):'';

/** Henter previewet til en handling. Et preview fra en ældre version (uden format 2) afvises, og intet ændres. */
function supplier_upload_preview_for_action(string $token, string $oldPreviewMessage): array {
    $preview=hsg_supplier_preview_load($token);
    if((int)($preview['format']??0)!==2){flash('error',$oldPreviewMessage);redirect('supplier_upload.php');}
    return $preview;
}

if($postAction==='upload'){
    try{
        if(!isset($_FILES['file'])||$_FILES['file']['error']!==UPLOAD_ERR_OK)throw new RuntimeException('Upload af filen fejlede.');
        $name=(string)$_FILES['file']['name'];$ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));if(!in_array($ext,['xlsx','csv'],true))throw new RuntimeException('Brug en Excel-fil (.xlsx) eller CSV-fil.');
        $sheets=$ext==='xlsx'?hsg_supplier_read_xlsx($_FILES['file']['tmp_name']):hsg_supplier_read_csv($_FILES['file']['tmp_name']);
        $brandId=(int)($_POST['brand_id']??0)?:null;$preview=hsg_supplier_prepare_preview($pdo,$sheets,$name,$brandId);$token=hsg_supplier_preview_save($preview);
        audit_log($pdo,'supplier_import.preview','import',null,['filename'=>$name,'sheet'=>$preview['sheet'],'rows'=>count($preview['items']),'brand_id'=>$brandId,'reservation_mode'=>$preview['reservation_mode']]);
        redirect('supplier_upload.php?preview='.$token);
    }catch(Throwable $e){flash('error',$e->getMessage());redirect('supplier_upload.php');}
}

if($postAction==='remap'){
    try{
        $preview=supplier_upload_preview_for_action($token,$oldPreviewMessage);
        $customMap=[];
        foreach((array)($_POST['col_map']??[]) as $ci=>$field){$field=trim((string)$field);$customMap[(int)$ci]=array_key_exists($field,$assignableFields)?$field:'';}
        $preview=hsg_supplier_recalculate_preview($pdo,$preview,$customMap);
        foreach((array)($preview['col_rejected_manual']??[]) as $ci=>$field){
            $headerText=trim((string)($preview['headers'][$ci]??''));
            if($headerText==='')$headerText='Kolonne #'.((int)$ci+1);
            flash('warning','Kolonnen „'.$headerText.'“ kan ikke bruges som lagerantal, fordi overskriften ligner et beløb. Den er sat til „Ignorer kolonne“.');
        }
        unset($preview['col_rejected_manual']);
        hsg_supplier_preview_save($preview,$token);
        hsg_supplier_remember_columns($pdo,(array)($preview['headers']??[]),(array)($preview['col_mapping']??[]));
        flash('success','Kolonnemapping blev opdateret og preview er genberegnet.');
        redirect('supplier_upload.php?preview='.$token.'&remap=1');
    }catch(Throwable $e){flash('error','Kunne ikke genberegne mapping: '.$e->getMessage());redirect('supplier_upload.php'.($token?'?preview='.$token.'&remap=1':''));}
}

if($postAction==='reguess'){
    try{
        $preview=supplier_upload_preview_for_action($token,$oldPreviewMessage);
        $headers=(array)($preview['headers']??[]);
        $savedColumns=hsg_supplier_saved_columns($pdo);
        $colMap=hsg_supplier_reguess_columns($headers,$savedColumns,hsg_supplier_allowed_fields(array_map(static fn($l)=>(int)$l['id'],$activeLocations)));
        $preview=hsg_supplier_recalculate_preview($pdo,$preview,$colMap);
        unset($preview['col_rejected_manual']);
        $remembered=[];
        foreach(hsg_supplier_header_keys($headers) as $ci=>$key){
            $savedField=$key!==''?trim((string)($savedColumns[$key]??'')):'';
            if(str_starts_with($savedField,'stock_loc_')&&($preview['col_mapping'][$ci]??'')===$savedField)$remembered[]=$ci;
        }
        $preview['col_remembered']=$remembered;
        // Kolonnevalget gemmes først, når admin genberegner eller anvender.
        hsg_supplier_preview_save($preview,$token);
        flash('success',"Kolonnerne er gættet forfra. Tjek valget, og tryk 'Genberegn' eller 'Anvend' for at gemme det.");
        redirect('supplier_upload.php?preview='.$token.'&remap=1');
    }catch(Throwable $e){flash('error','Kunne ikke gætte kolonnerne forfra: '.$e->getMessage());redirect('supplier_upload.php'.($token?'?preview='.$token.'&remap=1':''));}
}

if($postAction==='reservation_mode'){
    try{
        $preview=supplier_upload_preview_for_action($token,$oldPreviewMessage);
        $mode=trim((string)($_POST['reservation_mode']??''));
        if(!isset($reservationModes[$mode]))throw new RuntimeException('Vælg, om Kims tal er før eller efter reservationer, før du anvender ændringerne.');
        $keep=['col_remembered'=>(array)($preview['col_remembered']??[]),'col_rejected'=>(array)($preview['col_rejected']??[])];
        $preview=hsg_supplier_recalculate_preview($pdo,$preview,(array)($preview['col_mapping']??[]),$mode);
        unset($preview['col_rejected_manual']);
        $preview=array_merge($preview,$keep);
        hsg_supplier_preview_save($preview,$token);
        setting_set($pdo,'supplier_import_reservation_mode',$mode);
        audit_log($pdo,'supplier_import.settings','import',null,['reservation_mode'=>$mode]);
        flash('success','Reservationsvalget er gemt, og preview er genberegnet.');
        redirect('supplier_upload.php?preview='.$token);
    }catch(Throwable $e){flash('error','Kunne ikke gemme reservationsvalget: '.$e->getMessage());redirect('supplier_upload.php'.($token?'?preview='.$token:''));}
}

if($postAction==='apply'){
    try{
        $preview=supplier_upload_preview_for_action($token,$oldPreviewMessage);$selected=array_map('intval',(array)($_POST['apply_rows']??[]));if(!$selected)throw new RuntimeException('Vælg mindst én række, der skal opdateres.');
        $reservationMode=(string)($preview['reservation_mode']??'');
        if(!isset($reservationModes[$reservationMode]))$reservationMode=hsg_supplier_reservation_mode($pdo);
        $applyOptions=['add_reserved'=>$reservationMode==='add'];
        $products=$pdo->query('SELECT * FROM lager_products ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);$byId=[];foreach($products as $p)$byId[(int)$p['id']]=$p;
        $pdo->beginTransaction();$updated=0;$created=0;$changedFields=0;$details=[];$reservedAddedTotal=0;
        foreach($selected as $idx){
            if(!isset($preview['items'][$idx]))continue;
            $item=$preview['items'][$idx];
            $mergedSkus=array_values(array_filter(array_map('strval',(array)($item['merged_skus']??[])),static fn($s)=>trim($s)!==''));
            $postedVal=trim((string)($_POST['product_'.$idx]??''));
            if($postedVal==='create_new' || ($postedVal==='' && empty($item['match']['id']) && !empty($item['is_create_new']))){
                $newPid=hsg_supplier_create_product($pdo,(array)$item['source']);
                // Rækkens eget varenummer peger fremover på det nye produkt (rydder samtidig gamle, forkerte koblinger op).
                $ownSku=trim((string)($item['source']['sku']??''));
                if($ownSku!=='')hsg_supplier_remember_alias($pdo,$ownSku,$newPid,$item['source']['name']??null);
                foreach($mergedSkus as $ms)hsg_supplier_remember_alias($pdo,$ms,$newPid,$item['source']['name']??null);
                $created++;
                $details[]=['product_id'=>$newPid,'action'=>'created','source_row'=>$item['row']]+($mergedSkus?['merged_skus'=>$mergedSkus]:[]);
            } else {
                $pid=(int)($postedVal ?: ($item['match']['id']??0));
                if(!$pid||!isset($byId[$pid]))continue;
                $changes=hsg_supplier_apply_product($pdo,$pid,(array)$item['source'],$applyOptions);
                if(!empty($item['source']['sku'])){
                    hsg_supplier_remember_alias($pdo, (string)$item['source']['sku'], $pid, $item['source']['name']??null);
                }
                foreach($mergedSkus as $ms)hsg_supplier_remember_alias($pdo,$ms,$pid,$item['source']['name']??null);
                if(!$changes)continue;
                $stockDetails=[];
                foreach($changes as $field=>$c){
                    if(!str_starts_with((string)$field,'stock_loc_'))continue;
                    $entry=['location_id'=>(int)substr((string)$field,10),'old'=>(int)($c['old']??0),'new'=>(int)$c['new'],'file'=>(int)($c['file']??$c['new'])];
                    if((int)($c['reserved']??0)>0){$entry['reserved_added']=(int)$c['reserved'];$reservedAddedTotal+=(int)$c['reserved'];}
                    $stockDetails[]=$entry;
                }
                $updated++;$changedFields+=count($changes);
                $details[]=['product_id'=>$pid,'fields'=>array_keys($changes),'source_row'=>$item['row']]+($mergedSkus?['merged_skus'=>$mergedSkus]:[])+($stockDetails?['stock'=>$stockDetails]:[]);
            }
        }
        hsg_supplier_remember_columns($pdo,(array)($preview['headers']??[]),(array)($preview['col_mapping']??[]));
        setting_set($pdo,'supplier_import_reservation_mode',$reservationMode);
        $pdo->prepare('INSERT INTO hsg_supplier_import_runs(filename,sheet_name,rows_detected,rows_updated,created_by_admin) VALUES(?,?,?,?,?)')->execute([
            substr((string)$preview['filename'],0,255),substr((string)$preview['sheet'],0,180),count($preview['items']),$updated+$created,(int)current_admin_id()
        ]);
        $runId=(int)$pdo->lastInsertId();
        audit_log($pdo,'supplier_import.apply','import',(string)$runId,['filename'=>$preview['filename'],'products_updated'=>$updated,'products_created'=>$created,'fields_changed'=>$changedFields,'reservation_mode'=>$reservationMode,'reserved_added_total'=>$reservedAddedTotal,'details'=>$details]);
        $pdo->commit();hsg_supplier_preview_delete($token);
        $msg = "$updated produkter blev opdateret";
        if($created > 0) $msg .= " og $created nye produkter blev oprettet i HSG Administration";
        $msg .= " med i alt $changedFields feltændringer.";
        if($reservedAddedTotal > 0) $msg .= " $reservedAddedTotal reserverede flasker er lagt oven i Kims tal.";
        flash('success',$msg);
        redirect('supplier_upload.php');
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();flash('error','Kunne ikke anvende ændringer: '.$e->getMessage());redirect('supplier_upload.php'.($token?'?preview='.$token:''));}
}

$brands=$pdo->query('SELECT b.id,b.name,b.parent_id,pb.name parent_name FROM lager_brands b LEFT JOIN lager_brands pb ON pb.id=b.parent_id WHERE b.active=1 ORDER BY COALESCE(pb.sort_order,b.sort_order),COALESCE(pb.name,b.name),b.parent_id IS NOT NULL,b.sort_order,b.name')->fetchAll(PDO::FETCH_ASSOC);
$products=$pdo->query('SELECT p.id,p.sku,p.name,p.cask_number,p.brand_id,b.name brand_name FROM lager_products p LEFT JOIN lager_brands b ON b.id=p.brand_id ORDER BY p.name')->fetchAll(PDO::FETCH_ASSOC);
$preview=null;
if($token!==''){
    try{
        $preview=hsg_supplier_preview_load($token);
        if((int)($preview['format']??0)!==2){$preview=null;$token='';flash('error',$oldPreviewMessage);}
    }catch(Throwable $e){flash('error',$e->getMessage());$token='';}
}
$runs=db_table_exists($pdo,'hsg_supplier_import_runs')?$pdo->query('SELECT * FROM hsg_supplier_import_runs ORDER BY id DESC LIMIT 12')->fetchAll(PDO::FETCH_ASSOC):[];
$missingCask=(int)$pdo->query("SELECT COUNT(*) FROM lager_products WHERE status<>'discontinued' AND (cask_number IS NULL OR cask_number='')")->fetchColumn();

function supfmt(mixed $v,string $field): string {if($v===null||$v==='')return '—';if(in_array($field,['wholesale_price','retail_price'],true))return number_format((float)$v,2,',','.').' kr.';if($field==='abv')return rtrim(rtrim(number_format((float)$v,2,',',''),'0'),',').' %';if($field==='bottle_size_cl')return rtrim(rtrim(number_format((float)$v,2,',',''),'0'),',').' cl';return (string)$v;}

/** Linjen "Fil: …" med Kims lagertal for varen (før reservationstillæg). */
function supplier_upload_file_line(array $item, array $mappedLocKeys, array $locationNames): string {
    $stockFile=(array)($item['stock_file']??[]);
    $mode=(string)($stockFile['mode']??'');
    $locations=(array)($stockFile['locations']??[]);
    if($mode==='locations'){
        $blank=array_map('strval',(array)($stockFile['blank']??[]));$invalid=(array)($stockFile['invalid']??[]);$parts=[];
        foreach($mappedLocKeys as $key){
            $name=$locationNames[$key]??$key;
            if(array_key_exists($key,$invalid))$parts[]=$name.' „'.(string)$invalid[$key].'“ (ugyldig)';
            elseif(array_key_exists($key,$locations))$parts[]=$name.' '.(int)$locations[$key].(in_array($key,$blank,true)?' (tom)':'');
            else $parts[]=$name.' (tom)';
        }
        if(isset($stockFile['total']))$parts[]='i alt '.(int)$stockFile['total'];
        return 'Fil: '.implode(' · ',$parts);
    }
    if($mode==='total'&&$locations){
        $key=(string)array_key_first($locations);
        return 'Fil: lagerantal i alt '.(int)($stockFile['total']??$locations[$key]).' → skrives på '.($locationNames[$key]??$key);
    }
    return '';
}

/** Linjen "Reserveret i HSG: …" for det matchede produkt. */
function supplier_upload_reserved_line(array $item, array $locationNames): string {
    $parts=[];
    foreach((array)($item['reserved']??[]) as $key=>$qty){if((int)$qty>0)$parts[]=($locationNames[(string)$key]??(string)$key).' '.(int)$qty;}
    return $parts?'Reserveret i HSG: '.implode(' · ',$parts):'';
}
page_header('Kims uploadfil');
?>
<div class="grid">
  <div class="card metric"><strong><?=$missingCask?></strong><span>Produkter mangler fadnummer</span></div>
  <div class="card metric"><strong><?=count($runs)?(int)$runs[0]['rows_updated']:0?></strong><span>Opdateret i seneste upload</span></div>
</div>

<div class="card">
  <h2>Upload Kims masterfil</h2>
  <p class="muted">Upload masterfil (Excel eller CSV). Filen opdaterer produktdata og lager i HSG Administration. Varenumre med et bogstav til sidst (fx 17-1028a) flettes ind i varenummeret uden bogstav (17-1028), og lagerantallet lægges sammen – men kun når navn og destilleri passer. Er det en anden whisky, står varen alene med en bemærkning. Kolonnevalg og reservationsvalg huskes fra seneste upload.</p>
  <form method="post" enctype="multipart/form-data"><?=csrf_field()?><input type="hidden" name="action" value="upload">
    <div class="split">
      <label>Excel/CSV<input type="file" name="file" accept=".xlsx,.csv" required></label>
      <label>Begræns til brand/leverandør (valgfri)<select name="brand_id"><option value="">Automatisk på tværs af alle produkter</option><?php foreach($brands as $b): $bDisplayName = !empty($b['parent_id']) ? $b['parent_name'].' › '.$b['name'] : $b['name']; ?><option value="<?=$b['id']?>"><?=h($bDisplayName)?></option><?php endforeach;?></select></label>
    </div>
    <button>Analysér fil og vis preview</button>
  </form>
</div>

<?php if($preview):
$detected=array_keys((array)$preview['mapping']);$detectedLabels=[];foreach($detected as $f)$detectedLabels[]=$fieldLabels[$f]??match($f){'sku'=>'SKU','name'=>'Produktnavn','brand_name'=>'Brand',default=>$f};
$mappedLocKeys=array_values(array_filter(array_map('strval',$detected),static fn($k)=>str_starts_with($k,'stock_loc_')));
$hasTotalColumn=in_array('stock_quantity',$detected,true);
$fileTotalSum=0;$locationSum=0;$reservedSum=0;
foreach((array)$preview['items'] as $it){
    $sf=(array)($it['stock_file']??[]);
    if(isset($sf['total']))$fileTotalSum+=(int)$sf['total'];
    if(($sf['mode']??'')==='locations')$locationSum+=(int)($sf['sum']??0);
    if((int)($it['match']['id']??0)>0)foreach((array)($it['reserved']??[]) as $rq)$reservedSum+=(int)$rq;
}
$reservationMode=(string)($preview['reservation_mode']??'add');
$colRejected=(array)($preview['col_rejected']??[]);
$firstLocationName=(string)($activeLocations[0]['name']??'første lokation');
?>
<div class="card">
  <div class="page-title"><div><h2>Preview: <?=h($preview['filename'])?></h2><p class="muted">Fundet i <strong><?=h($preview['sheet'])?></strong>, overskriftsrække <?=intval($preview['header_row'])?>. Genkendte felter: <?=h(implode(', ',$detectedLabels))?>.</p></div></div>
  <?php if($hasTotalColumn||$mappedLocKeys):?>
  <p><?php if($hasTotalColumn):?>Lager i filen: <?=$fileTotalSum?> stk. i alt<?php endif;?><?php if($hasTotalColumn&&$mappedLocKeys):?> · <?php endif;?><?php if($mappedLocKeys):?>Sum af lokationskolonner: <?=$locationSum?> stk.<?php endif;?><?php if($hasTotalColumn&&$mappedLocKeys&&$fileTotalSum!==$locationSum):?> <span class="badge amber">Afviger – se bemærkningerne på de enkelte varer</span><?php endif;?><br>Aktive reservationer i HSG på matchede varer: <?=$reservedSum?> stk.</p>
  <?php endif;?>
  <form method="post" class="inline"><?=csrf_field()?><input type="hidden" name="action" value="reservation_mode"><input type="hidden" name="preview_token" value="<?=h($token)?>">
    <label>Reservationer<select name="reservation_mode"><?php foreach($reservationModes as $modeKey=>$modeLabel):?><option value="<?=h($modeKey)?>" <?=$reservationMode===$modeKey?'selected':''?>><?=h($modeLabel)?></option><?php endforeach;?></select></label>
    <button class="button secondary">Genberegn preview</button>
  </form>
  <p class="muted" style="margin-top:6px">Hvis Kim tæller reserverede flasker som solgt, skal reservationerne lægges oven i. Ellers trækkes de fra to gange, og varen kan forsvinde fra Lagerstatus. Valget huskes til næste upload.<br><small>Et nyt valg bruges først, når du har trykket 'Genberegn preview'. Tallene beregnes igen med de aktuelle reservationer, når du trykker 'Anvend'.</small></p>
  <p><span class="badge green">90–100 %</span> vælges som udgangspunkt automatisk. <span class="badge">70–89 %</span> kræver din vurdering. Usikre/umatchede rækker ændrer intet.</p>
</div>

<details class="card" <?=isset($_GET['remap'])||count($detected)===0||$colRejected?'open':''?>>
  <summary style="cursor:pointer;font-weight:600;font-size:1.1rem;">⚙️ Kolonnemapping (Tilknytning af felter)</summary>
  <?php
  $headers=(array)($preview['headers']??[]);
  $colMapping=(array)($preview['col_mapping']??[]);
  $colRemembered=array_map('intval',(array)($preview['col_remembered']??[]));
  ?>
  <p class="muted" style="margin-top:8px;">Vælg hvilken kolonne fra filen der svarer til hvilken egenskab i HSG. Kolonner med samme overskrift som ved seneste upload får det valg, du brugte sidst – også "Ignorer kolonne". Kun nye overskrifter foreslås automatisk. Dit valg gemmes, når du genberegner eller anvender ændringerne.<?php if($colRemembered):?> <strong><?=count($colRemembered)?> af <?=count($headers)?> kolonner</strong> er hentet fra seneste upload.<?php endif;?></p>
  <p class="muted">Vælger du kolonner til lokationerne, skrives lageret kun på dem, og "Lagerantal i alt" bruges kun som kontroltal. Uden lokationskolonner skrives "Lagerantal i alt" på første lokation (<?=h($firstLocationName)?>).</p>
  <form method="post"><?=csrf_field()?><input type="hidden" name="action" value="remap"><input type="hidden" name="preview_token" value="<?=h($token)?>">
    <div class="table-wrap" style="max-height:380px;overflow-y:auto;margin-bottom:12px;">
      <table>
        <thead><tr><th>Fil-kolonne</th><th>Overskrift i dokument</th><th>Tilknyttet HSG-felt</th></tr></thead>
        <tbody>
          <?php
          foreach($headers as $ci=>$headerText):
            $curField=$colMapping[$ci]??'';
          ?>
          <tr>
            <td><strong>Kolonne #<?=((int)$ci+1)?></strong></td>
            <td><code><?=h($headerText!==''?$headerText:'(Tom overskrift)')?></code></td>
            <td>
              <select name="col_map[<?=(int)$ci?>]">
                <?php foreach($assignableFields as $fKey=>$fLabel): ?>
                  <option value="<?=h((string)$fKey)?>" <?=$curField===$fKey?'selected':''?>><?=h($fLabel)?></option>
                <?php endforeach; ?>
              </select>
              <?php if(in_array((int)$ci,$colRemembered,true)):?><small class="muted">Husket fra seneste upload</small><?php endif;?>
              <?php if(isset($colRejected[$ci])):?><br><small style="color:var(--amber)">⚠ Husket valg "<?=h($fieldLabels[(string)$colRejected[$ci]]??(string)$colRejected[$ci])?>" er ikke brugt: overskriften ligner et beløb.</small><?php endif;?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <button class="button secondary">Genberegn preview med tilpasset kolonnemapping</button>
  </form>
  <form method="post" class="inline" style="margin-top:8px"><?=csrf_field()?><input type="hidden" name="action" value="reguess"><input type="hidden" name="preview_token" value="<?=h($token)?>">
    <button class="button secondary">Gæt kolonner forfra</button>
  </form>
  <p class="muted"><small>"Gæt kolonner forfra" ser bort fra det huskede valg (lokationskolonnerne beholdes). Intet gemmes, før du genberegner eller anvender.</small></p>
</details>
<form method="post"><?=csrf_field()?><input type="hidden" name="action" value="apply"><input type="hidden" name="preview_token" value="<?=h($token)?>">
<div class="table-wrap"><table><thead><tr><th>Opdatér</th><th>Filrække</th><th>Leverandørens vare</th><th>Match i HSG</th><th>Foreslåede ændringer</th></tr></thead><tbody>
<?php foreach($preview['items'] as $i=>$item):$src=(array)$item['source'];$mid=(int)($item['match']['id']??0);$score=(int)($item['match']['score']??0);$changes=(array)$item['changes'];$fileLine=supplier_upload_file_line((array)$item,$mappedLocKeys,$locationNames);$reservedLine=supplier_upload_reserved_line((array)$item,$locationNames);?>
<tr>
  <td><input type="checkbox" name="apply_rows[]" value="<?=$i?>" <?=!empty($item['selected'])?'checked':''?> ></td>
  <td><?=h($item['row_label'] ?? ('#'.intval($item['row'])))?></td>
  <td><strong><?=h($src['name']??'(uden navn)')?></strong><br><small class="muted"><?php if(!empty($src['sku'])):?>SKU <?=h($src['sku'])?><?php if(!empty($item['merged_skus'])):?> + <?=h(implode(', ',(array)$item['merged_skus']))?><?php endif;?> · <?php endif;?><?php if(!empty($src['cask_number'])):?>Fad #<?=h($src['cask_number'])?><?php endif;?></small></td>
  <td>
    <?php if(!empty($item['is_create_new'])): ?>
      <span class="badge blue">Nyt produkt oprettes (Lager: <?=intval($item['total_stock']??0)?>)</span>
    <?php else: ?>
      <span class="badge <?=$score>=90?'green':($score>=70?'':'red')?>"><?=$score?> %</span> <small class="muted"><?=h($item['match']['reason']??'')?></small>
    <?php endif; ?>
    <?php foreach((array)($item['notes']??[]) as $note):?><br><small <?=str_starts_with((string)$note,'Bemærk')?'style="color:var(--amber);font-weight:600"':'class="muted"'?>><?=h((string)$note)?></small><?php endforeach;?>
    <select name="product_<?=$i?>" style="margin-top:6px">
      <?php if(!empty($item['is_create_new'])): ?>
        <option value="create_new" selected>✨ Opret som nyt produkt i HSG (Lager: <?=intval($item['total_stock']??0)?> stf)</option>
      <?php else: ?>
        <option value="">– Intet match –</option>
        <option value="create_new">✨ Opret som nyt produkt i HSG</option>
      <?php endif; ?>
      <?php foreach($products as $p):?>
        <option value="<?=$p['id']?>" <?=$mid===(int)$p['id']?'selected':''?>><?=h($p['sku'].' · '.$p['name'].(!empty($p['cask_number'])?' · #'.$p['cask_number']:''))?></option>
      <?php endforeach;?>
    </select>
  </td>
  <td>
    <?php if(!$changes):?><span class="muted">Ingen ændringer fundet</span><?php else:?><ul style="margin:0;padding-left:18px"><?php foreach($changes as $field=>$c):?><li><strong><?=h($fieldLabels[$field]??$field)?>:</strong> <?=h(supfmt($c['old'],(string)$field))?> → <strong><?=h(supfmt($c['new'],(string)$field))?></strong><?php if(str_starts_with((string)$field,'stock_loc_')&&(int)($c['reserved']??0)>0):?> (fil <?=intval($c['file']??0)?> + <?=intval($c['reserved'])?> reserveret)<?php endif;?></li><?php endforeach;?></ul><?php endif;?>
    <?php if($fileLine!==''):?><br><small class="muted"><?=h($fileLine)?></small><?php endif;?>
    <?php if($reservedLine!==''):?><br><small class="muted"><?=h($reservedLine)?></small><?php endif;?>
  </td>
</tr>
<?php endforeach;?></tbody></table></div>
<div class="card"><div class="actions"><button>Anvend valgte ændringer</button><a class="button secondary" href="supplier_upload.php">Annuller preview</a></div><p class="muted">Kun de markerede rækker opdateres. Tomme felter i Kims fil overskriver aldrig produktdata. Undtagelse: lager pr. lokation – har en række et tal i mindst én lokationskolonne, tæller tomme lokationsceller i samme række som 0. Lager og reservationer beregnes igen, når du trykker "Anvend".</p></div>
</form>
<?php endif;?>

<?php if($runs):?><div class="card"><h2>Seneste leverandøruploads</h2><div class="table-wrap"><table><thead><tr><th>Dato</th><th>Fil</th><th>Ark</th><th>Rækker fundet</th><th>Produkter opdateret</th></tr></thead><tbody><?php foreach($runs as $r):?><tr><td><?=h($r['created_at'])?></td><td><?=h($r['filename'])?></td><td><?=h($r['sheet_name']??'')?></td><td><?=intval($r['rows_detected'])?></td><td><?=intval($r['rows_updated'])?></td></tr><?php endforeach;?></tbody></table></div></div><?php endif;?>
<?php page_footer();
