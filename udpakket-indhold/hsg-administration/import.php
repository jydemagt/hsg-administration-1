<?php
require __DIR__.'/auth.php';require_module_enabled('import_export');require_capability('imports.manage');require_once __DIR__.'/core/product_enrichment.php';
function column_index(string $ref): int {preg_match('/^[A-Z]+/i',$ref,$m);$letters=strtoupper($m[0]??'A');$n=0;for($i=0;$i<strlen($letters);$i++)$n=$n*26+(ord($letters[$i])-64);return $n-1;}
function read_xlsx_rows(string $path): array {if(!class_exists('ZipArchive'))throw new RuntimeException('PHP ZipArchive mangler. Brug CSV eller få ZipArchive aktiveret.');if(!function_exists('simplexml_load_string'))throw new RuntimeException('PHP SimpleXML mangler.');$z=new ZipArchive();if($z->open($path)!==true)throw new RuntimeException('Kunne ikke åbne XLSX.');$shared=[];$ss=$z->getFromName('xl/sharedStrings.xml');if($ss!==false){$x=simplexml_load_string($ss);foreach($x?->xpath('/*[local-name()="sst"]/*[local-name()="si"]')?:[] as $si){$t='';foreach($si->xpath('.//*[local-name()="t"]')?:[] as $p)$t.=(string)$p;$shared[]=$t;}}$sheet=$z->getFromName('xl/worksheets/sheet1.xml');$z->close();if($sheet===false)throw new RuntimeException('Første Excel-ark mangler.');$x=simplexml_load_string($sheet);$rows=[];foreach($x?->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]')?:[] as $row){$out=[];foreach($row->xpath('./*[local-name()="c"]')?:[] as $c){$a=$c->attributes();$ref=(string)($a['r']??'A1');$type=(string)($a['t']??'');$idx=column_index($ref);$val='';if($type==='inlineStr'){foreach($c->xpath('.//*[local-name()="t"]')?:[] as $p)$val.=(string)$p;}else{$v=$c->xpath('./*[local-name()="v"]');$raw=$v?(string)$v[0]:'';$val=($type==='s'&&isset($shared[(int)$raw]))?$shared[(int)$raw]:$raw;}$out[$idx]=trim($val);}if($out){$max=max(array_keys($out));$dense=[];for($i=0;$i<=$max;$i++)$dense[]=$out[$i]??'';$rows[]=$dense;}}return $rows;}
function read_csv_rows(string $path): array {$fh=fopen($path,'rb');if(!$fh)throw new RuntimeException('Kunne ikke åbne CSV.');$first=fgets($fh);rewind($fh);$d=substr_count((string)$first,';')>substr_count((string)$first,',')?';':',';$rows=[];while(($r=fgetcsv($fh,0,$d))!==false)$rows[]=array_map(fn($v)=>trim((string)$v),$r);fclose($fh);return $rows;}
function nh(string $s): string {$s=trim($s);$s=function_exists('mb_strtolower')?mb_strtolower($s,'UTF-8'):strtolower($s);$s=strtr($s,['æ'=>'ae','ø'=>'o','å'=>'a']);return preg_replace('/[^a-z0-9]+/','',$s)??'';}
function pick(array $map,array $names): ?int {foreach($names as $n)if(isset($map[$n]))return $map[$n];return null;}
function qint(mixed $v,int $row,string $col): int {$s=trim((string)$v);if($s==='')return 0;$s=str_replace(',','.',$s);if(!is_numeric($s)||(float)$s!=(int)(float)$s)throw new RuntimeException("Række $row: $col skal være et helt antal.");$n=(int)$s;if($n<0)throw new RuntimeException("Række $row: $col kan ikke være negativ.");return $n;}
function stock_qint(mixed $v,int $row,string $col): int {$s=trim((string)$v);if($s==='')return 0;$s=str_replace(',','.',$s);if(!is_numeric($s)||(float)$s!=(int)(float)$s)throw new RuntimeException("Række $row: $col skal være et helt antal.");return (int)$s;}
function existing_product_id(PDO $pdo,string $sku): int {$st=$pdo->prepare('SELECT id FROM lager_products WHERE sku=?');$st->execute([$sku]);return (int)($st->fetchColumn()?:0);}
function boolv(mixed $v,bool $default=false): int {$s=nh((string)$v);if($s==='')return $default?1:0;return in_array($s,['1','ja','yes','true','x'],true)?1:0;}
function loc_id(PDO $pdo,string $name): int {$name=canonical_location_name($name);$st=$pdo->prepare('SELECT id FROM lager_locations WHERE name=?');$st->execute([$name]);$id=$st->fetchColumn();if(!$id){$pdo->prepare('INSERT INTO lager_locations(name,active) VALUES(?,1)')->execute([$name]);$id=$pdo->lastInsertId();}return (int)$id;}
function brand_id(PDO $pdo,string $name): ?int {if(trim($name)==='')return null;$st=$pdo->prepare('SELECT id FROM lager_brands WHERE name=?');$st->execute([$name]);$id=$st->fetchColumn();if(!$id){$pdo->prepare('INSERT INTO lager_brands(name,active) VALUES(?,1)')->execute([$name]);$id=$pdo->lastInsertId();}return (int)$id;}
function product_id(PDO $pdo,string $sku,string $name,array $f): int {$st=$pdo->prepare('SELECT id FROM lager_products WHERE sku=?');$st->execute([$sku]);$id=$st->fetchColumn();$vals=[$name,$f['brand_id'],$f['category'],$f['distillery'],$f['country'],$f['age_text'],$f['vintage_year'],$f['abv'],$f['bottle_size_cl'],$f['cask_type'],$f['cask_number'],$f['wholesale'],$f['retail'],$f['is_new'],$f['catalog']];if($id){$vals[]=(int)$id;$pdo->prepare('UPDATE lager_products SET name=?,brand_id=?,category=?,distillery=?,country=?,age_text=?,vintage_year=?,abv=?,bottle_size_cl=?,cask_type=?,cask_number=?,wholesale_price=?,retail_price=?,is_new=?,show_in_catalog=? WHERE id=?')->execute($vals);return (int)$id;}$pdo->prepare("INSERT INTO lager_products(sku,name,brand_id,category,distillery,country,age_text,vintage_year,abv,bottle_size_cl,cask_type,cask_number,wholesale_price,retail_price,is_new,show_in_catalog,status) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'active')")->execute([$sku,...$vals]);return (int)$pdo->lastInsertId();}
function set_qty(PDO $pdo,int $pid,int $lid,int $qty,string $mode,string $ref,int $adminId): void {$st=$pdo->prepare('SELECT quantity FROM lager_stock WHERE product_id=? AND location_id=? FOR UPDATE');$st->execute([$pid,$lid]);$old=(int)($st->fetchColumn()?:0);$new=$mode==='add'?$old+$qty:$qty;if($new<0)throw new RuntimeException('Importen ville give negativt fysisk lager.');$pdo->prepare('INSERT INTO lager_stock(product_id,location_id,quantity) VALUES(?,?,?) ON DUPLICATE KEY UPDATE quantity=VALUES(quantity)')->execute([$pid,$lid,$new]);$pdo->prepare("INSERT INTO lager_stock_movements(product_id,location_id,change_qty,balance_after,movement_type,reference,created_by,created_by_admin) VALUES(?,?,?,?, 'import', ?,NULL,?)")->execute([$pid,$lid,$new-$old,$new,$ref,$adminId]);}
if($_SERVER['REQUEST_METHOD']==='POST'&&isset($_FILES['file'])){try{if($_FILES['file']['error']!==UPLOAD_ERR_OK)throw new RuntimeException('Upload fejlede.');$ext=strtolower(pathinfo($_FILES['file']['name'],PATHINFO_EXTENSION));if(!in_array($ext,['xlsx','csv'],true))throw new RuntimeException('Brug .xlsx eller .csv.');$rows=$ext==='xlsx'?read_xlsx_rows($_FILES['file']['tmp_name']):read_csv_rows($_FILES['file']['tmp_name']);if(count($rows)<2)throw new RuntimeException('Filen indeholder ingen datarækker.');$heads=array_map('trim',$rows[0]);$map=[];foreach($heads as $i=>$h)$map[nh($h)]=$i;$cSku=pick($map,['nummer','sku','varenummer','varenr']);$cName=pick($map,['navn','produktnavn','produkt']);if($cSku===null||$cName===null)throw new RuntimeException('Kolonnerne Nummer/SKU og Navn mangler.');$cBrand=pick($map,['brand']);$cCat=pick($map,['kategori']);$cDist=pick($map,['destilleri']);$cCountry=pick($map,['land','country']);$cAbv=pick($map,['alc','alc','abv','alc']);$cAge=pick($map,['alder']);$cVintage=pick($map,['argang','destilleret','vintage','vintageyear']);$cSize=pick($map,['flaskestorrelsecl','flaskestorrelse','cl']);$cCask=pick($map,['fadtype']);$cCaskNo=pick($map,['fadnummer','fadnr','casknumber','caskno','casknr']);$cW=pick($map,['engrospris','engrospriseksklmoms']);$cR=pick($map,['udsalgspris','udsalgsprisinklmoms']);$cNew=pick($map,['nyhed']);$cCatalog=pick($map,['visikatalog']);$derived=['antalialt','reserveret','disponibelt','restlager'];$locCols=[];$legacyRes=[];foreach($heads as $i=>$head){$n=nh($head);if(str_starts_with($n,'lokation')&&str_contains($head,':')){$name=trim(substr($head,strpos($head,':')+1));if($name!=='')$locCols[$i]=canonical_location_name($name);}elseif($n==='hovedlager'||(str_starts_with($n,'lager')&&!in_array($n,['lagerantal','lagerlokation'],true))){$locCols[$i]=canonical_location_name($head);}elseif(str_starts_with($n,'res')&&!in_array($n,$derived,true)){$legacyRes[$i]=trim((string)preg_replace('/^res(?:erveret)?[\s\.,-]*/iu','',$head))?:$head;}}
 if(!$locCols)throw new RuntimeException('Ingen lagerlokationskolonner fundet. Brug fx "Lokation: Hovedlager" eller "Lager Gert".');
 $mode=($_POST['mode']??'set')==='add'?'add':'set';
 $pdo->beginTransaction();
 $count=0;$skippedNegative=0;$adminId=(int)current_admin_id();$ref='Excel: '.$_FILES['file']['name'];
 foreach(array_slice($rows,1) as $ln=>$r){
   $rowNo=$ln+2;$sku=trim((string)($r[$cSku]??''));$name=trim((string)($r[$cName]??''));
   if($sku===''&&$name==='')continue;
   if($sku===''||$name==='')throw new RuntimeException("Række $rowNo: Nummer og navn kræves.");

   // Læs lagercellerne før produktet oprettes. En ny vare med negativ lagercelle
   // skal springes over i stedet for at blive oprettet og derefter fejle.
   $stockValues=[];$hasNegativeInput=false;
   foreach($locCols as $i=>$lnm){$qty=stock_qint($r[$i]??'',$rowNo,$lnm);$stockValues[$i]=$qty;if($qty<0)$hasNegativeInput=true;}
   $existingId=existing_product_id($pdo,$sku);
   if(!$existingId && $hasNegativeInput){$skippedNegative++;continue;}
   if($mode==='set' && $hasNegativeInput){$skippedNegative++;continue;}
   if($mode==='add' && $existingId){
      $wouldGoNegative=false;
      foreach($locCols as $i=>$lnm){
        $locName=canonical_location_name($lnm);
        $ls=$pdo->prepare('SELECT l.id,COALESCE(s.quantity,0) quantity FROM lager_locations l LEFT JOIN lager_stock s ON s.location_id=l.id AND s.product_id=? WHERE l.name=? LIMIT 1');
        $ls->execute([$existingId,$locName]);$lr=$ls->fetch();$oldQty=$lr?(int)$lr['quantity']:0;
        if($oldQty+(int)$stockValues[$i]<0){$wouldGoNegative=true;break;}
      }
      if($wouldGoNegative){$skippedNegative++;continue;}
   }

   $parsed=hsg_product_parse_text($name);$pf=$parsed['fields']??[];
   $f=[
     'brand_id'=>$cBrand!==null?brand_id($pdo,(string)($r[$cBrand]??'')):null,
     'category'=>$cCat!==null&&trim((string)($r[$cCat]??''))!==''?(string)$r[$cCat]:($pf['category']??''),
     'distillery'=>$cDist!==null&&trim((string)($r[$cDist]??''))!==''?(string)$r[$cDist]:($pf['distillery']??''),
     'country'=>$cCountry!==null&&trim((string)($r[$cCountry]??''))!==''?(string)$r[$cCountry]:($pf['country']??''),
     'abv'=>$cAbv!==null&&trim((string)($r[$cAbv]??''))!==''?parse_decimal($r[$cAbv]):($pf['abv']??null),
     'age_text'=>$cAge!==null&&trim((string)($r[$cAge]??''))!==''?(string)$r[$cAge]:($pf['age_text']??''),
     'vintage_year'=>$cVintage!==null&&trim((string)($r[$cVintage]??''))!==''?(int)$r[$cVintage]:($pf['vintage_year']??null),
     'bottle_size_cl'=>$cSize!==null&&trim((string)($r[$cSize]??''))!==''?parse_decimal($r[$cSize]):($pf['bottle_size_cl']??70),
     'cask_type'=>$cCask!==null&&trim((string)($r[$cCask]??''))!==''?(string)$r[$cCask]:($pf['cask_type']??''),
     'cask_number'=>$cCaskNo!==null&&trim((string)($r[$cCaskNo]??''))!==''?trim((string)$r[$cCaskNo]," #\t\n\r\0\x0B"):($pf['cask_number']??''),
     'wholesale'=>$cW!==null?parse_decimal($r[$cW]??''):null,
     'retail'=>$cR!==null?parse_decimal($r[$cR]??''):null,
     'is_new'=>$cNew!==null?boolv($r[$cNew]??''):0,
     'catalog'=>$cCatalog!==null?boolv($r[$cCatalog]??'',true):1
   ];
   $pid=product_id($pdo,$sku,$name,$f);
   foreach($locCols as $i=>$lnm)set_qty($pdo,$pid,loc_id($pdo,$lnm),(int)$stockValues[$i],$mode,$ref,$adminId);
   foreach($legacyRes as $i=>$label){$q=qint($r[$i]??'',$rowNo,'Reservation '.$label);if($q>0){foreach($locCols as $li=>$lnm){$lid=loc_id($pdo,$lnm);$avail=available_for($pdo,$pid,$lid);if($avail<=0)continue;$take=min($q,$avail);$pdo->prepare("INSERT INTO lager_reservations(product_id,location_id,quantity,customer_name,reference,status,created_by,created_by_admin) VALUES(?,?,?,?,?,'reserved',NULL,?)")->execute([$pid,$lid,$take,$label,'[Excel] '.$label,$adminId]);$q-=$take;if($q<=0)break;}}}
   $count++;
 }
 $pdo->commit();
 audit_log($pdo,'inventory.import','import',null,['filename'=>$_FILES['file']['name'],'products'=>$count,'skipped_negative'=>$skippedNegative,'mode'=>$mode]);
 $msg="$count produkter blev importeret.".($skippedNegative>0?" $skippedNegative række(r) med negativt lager blev sprunget over.":'')." Nye lokationskolonner blev automatisk oprettet som lokationer.";
 flash('success',$msg);redirect('import.php');
}catch(Throwable $e){if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();flash('error',$e->getMessage());}}
page_header('Excel import / eksport');
?>
<div class="split"><div class="card"><h2>Download arbejdsfil</h2><p>Excel-filen indeholder alle produkter og én kolonne pr. lagerlokation. Nye lokationer oprettet på siden kommer automatisk med.</p><p>Du kan også tilføje en ny kolonne som <strong>Lokation: Lager Odense</strong>. Ved næste import opretter systemet lokationen automatisk.</p><a class="button" href="export.php">Download Excel (.xlsx)</a></div><div class="card"><h2>Upload lagerfil</h2><form method="post" enctype="multipart/form-data"><?=csrf_field()?><label>Excel eller CSV<input type="file" name="file" accept=".xlsx,.csv" required></label><label>Importmetode<select name="mode"><option value="set">Overskriv lagerantal med filens tal</option><option value="add">Læg filens antal oven i eksisterende lager</option></select></label><button>Importér</button></form></div></div>
<div class="card"><h2>Kolonner</h2><p class="muted">Understøttet: Nummer/SKU, Navn, Brand, Kategori, Destilleri, Land, Alc. %, Alder, Årgang, Flaskestørrelse, Fadtype, Fadnummer, Engrospris, Udsalgspris, Nyhed, Vis i katalog. Hvis disse produktfelter mangler, udfyldes sikre værdier automatisk ud fra Navn/vareteksten samt dynamiske lokationskolonner. Din oprindelige HSG-fil med <strong>Lager Gert</strong> og <strong>Hovedlager</strong> kan fortsat importeres. Rækker, der ville oprette eller sætte en lokation til negativt fysisk lager, springes over og tælles i importresultatet.</p></div>
<?php page_footer();
