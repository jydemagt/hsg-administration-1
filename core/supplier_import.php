<?php
declare(strict_types=1);

require_once __DIR__.'/product_enrichment.php';

function hsg_supplier_norm(string $s): string {
    $s=trim($s);
    $s=function_exists('mb_strtolower')?mb_strtolower($s,'UTF-8'):strtolower($s);
    $s=strtr($s,['æ'=>'ae','ø'=>'o','å'=>'a','é'=>'e','è'=>'e','ü'=>'u','ö'=>'o','ä'=>'a']);
    return preg_replace('/[^a-z0-9#%]+/','',$s)??'';
}

function hsg_supplier_col_index(string $ref): int {
    preg_match('/^[A-Z]+/i',$ref,$m);$letters=strtoupper($m[0]??'A');$n=0;
    for($i=0;$i<strlen($letters);$i++)$n=$n*26+(ord($letters[$i])-64);
    return max(0,$n-1);
}

function hsg_supplier_sheet_rows(string $xml,array $shared): array {
    $x=@simplexml_load_string($xml);if(!$x)return [];$rows=[];
    foreach($x->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]')?:[] as $row){
        $out=[];
        foreach($row->xpath('./*[local-name()="c"]')?:[] as $c){
            $a=$c->attributes();$ref=(string)($a['r']??'A1');$type=(string)($a['t']??'');$idx=hsg_supplier_col_index($ref);$val='';
            if($type==='inlineStr'){foreach($c->xpath('.//*[local-name()="t"]')?:[] as $p)$val.=(string)$p;}
            else{$v=$c->xpath('./*[local-name()="v"]');$raw=$v?(string)$v[0]:'';$val=($type==='s'&&isset($shared[(int)$raw]))?$shared[(int)$raw]:$raw;}
            $out[$idx]=trim($val);
        }
        if($out){$max=max(array_keys($out));$dense=[];for($i=0;$i<=$max;$i++)$dense[]=$out[$i]??'';$rows[]=$dense;}
    }
    return $rows;
}

function hsg_supplier_read_xlsx(string $path): array {
    if(!class_exists('ZipArchive'))throw new RuntimeException('PHP ZipArchive mangler på webhotellet.');
    if(!function_exists('simplexml_load_string'))throw new RuntimeException('PHP SimpleXML mangler på webhotellet.');
    $z=new ZipArchive();if($z->open($path)!==true)throw new RuntimeException('Kunne ikke åbne Excel-filen.');
    $shared=[];$ss=$z->getFromName('xl/sharedStrings.xml');
    if($ss!==false){$x=@simplexml_load_string($ss);foreach($x?->xpath('/*[local-name()="sst"]/*[local-name()="si"]')?:[] as $si){$t='';foreach($si->xpath('.//*[local-name()="t"]')?:[] as $p)$t.=(string)$p;$shared[]=$t;}}
    $sheets=[];
    for($i=1;$i<=50;$i++){
        $xml=$z->getFromName('xl/worksheets/sheet'.$i.'.xml');if($xml===false){if($i>10)break;continue;}
        $rows=hsg_supplier_sheet_rows($xml,$shared);if($rows)$sheets['Ark '.$i]=$rows;
    }
    $z->close();if(!$sheets)throw new RuntimeException('Ingen læsbare ark fundet i Excel-filen.');return $sheets;
}

function hsg_supplier_read_csv(string $path): array {
    $fh=fopen($path,'rb');if(!$fh)throw new RuntimeException('Kunne ikke åbne CSV-filen.');
    $first=fgets($fh);rewind($fh);$d=substr_count((string)$first,';')>substr_count((string)$first,',')?';':',';$rows=[];
    while(($r=fgetcsv($fh,0,$d))!==false)$rows[]=array_map(static fn($v)=>trim((string)$v),$r);fclose($fh);return ['CSV'=>$rows];
}

function hsg_supplier_aliases(): array {
    return [
      'sku'=>['sku','varenummer','varenr','varenr.','itemno','itemnumber','productcode','productid','artikelnummer','artnr','stockcode','code','nummer'],
      'name'=>['navn','produkt','produktnavn','product','productname','item','itemname','description','beskrivelse','varetekst','title','whisky'],
      'cask_number'=>['fadnummer','fadnr','fadno','casknumber','caskno','casknr','cask#','caskid','barrelnumber','barrelno','barrelnr'],
      'cask_type'=>['fadtype','casktype','barreltype','caskfinish','finish','finishtype','maturation','maturationtype','woodtype','cask','barrel'],
      'wholesale_price'=>['engrospris','engrospriseksklmoms','wholesale','wholesaleprice','tradeprice','dealerprice','netprice','costprice','buyingprice','purchaseprice','indkobspris','indkoebspris'],
      'retail_price'=>['udsalgspris','udsalgsprisinklmoms','retailprice','retail','rrp','srp','salgspris','recommendedretailprice','vejledendeudsalgspris'],
      'abv'=>['abv','alc','alc%','alcohol','alcohol%','strength','vol','vol%'],
      'distillery'=>['destilleri','distillery','producer','producent'],
      'age_text'=>['alder','age','ageyears','yearsold','yo'],
      'vintage_year'=>['argang','aargang','vintage','vintageyear','distilled','distillationyear','destilleret'],
      'brand_name'=>['brand','maerke','mærke','bottler','independentbottler','aftapper','supplier','leverandor','leverandør'],
      'category'=>['kategori','category','type','spiritstype','producttype'],
      'country'=>['land','country','origin','oprindelse'],
      'bottle_size_cl'=>['flaskestorrelse','flaskestørrelse','flaskestorrelsecl','size','bottlesize','cl','volume','volumeml'],
      'bottle_count'=>['antalflasker','bottlecount','outturn','yield','numberofbottles'],
      'stock_quantity'=>['lagerantal','antalpaalager','lagerbeholdning','stockquantity','stockqty','quantity','qty','beholdning','lager','antal'],
    ];
}

function hsg_supplier_detect_header(array $rows): array {
    $aliases=hsg_supplier_aliases();$reverse=[];
    foreach($aliases as $field=>$names)foreach($names as $n)$reverse[hsg_supplier_norm($n)]=$field;
    $best=['row'=>-1,'score'=>-1,'map'=>[],'headers'=>[]];
    foreach(array_slice($rows,0,25,true) as $ri=>$row){$map=[];$score=0;$headers=[];
        foreach($row as $ci=>$cell){$cell=trim((string)$cell);if($cell==='')continue;$headers[$ci]=$cell;$n=hsg_supplier_norm($cell);
            if(isset($reverse[$n])){$field=$reverse[$n];if(!isset($map[$field])){$map[$field]=$ci;$score+=in_array($field,['name','sku','cask_number','wholesale_price','retail_price'],true)?3:1;}}
            else{
                foreach($aliases as $field=>$names){foreach($names as $alias){$an=hsg_supplier_norm($alias);if(strlen($an)>=5 && (str_contains($n,$an)||str_contains($an,$n))){if(!isset($map[$field])){$map[$field]=$ci;$score++;}break;}}}
            }
        }
        if($score>$best['score'])$best=['row'=>(int)$ri,'score'=>$score,'map'=>$map,'headers'=>$headers];
    }
    if($best['score']<3 || (!isset($best['map']['name'])&&!isset($best['map']['sku'])&&!isset($best['map']['cask_number'])))throw new RuntimeException('Kunne ikke genkende en produkttabel i filen.');
    return $best;
}

function hsg_supplier_parse_decimal(mixed $v): ?float {
    $s=trim(strip_tags((string)$v));if($s==='')return null;
    $s=preg_replace('/[^0-9,\.\-]/u','',$s)??'';if($s==='')return null;
    if(str_contains($s,',')&&str_contains($s,'.')){
        if(strrpos($s,',')>strrpos($s,'.')){$s=str_replace('.','',$s);$s=str_replace(',','.',$s);}else{$s=str_replace(',','',$s);}
    }elseif(str_contains($s,',')){$parts=explode(',',$s);$s=count($parts)===2&&strlen(end($parts))<=2?str_replace(',','.',$s):str_replace(',','',$s);}
    if(!is_numeric($s))return null;return round((float)$s,2);
}

function hsg_supplier_row_value(array $row,array $map,string $field): string {return isset($map[$field])?trim((string)($row[$map[$field]]??'')):'';}

function hsg_supplier_source_fields(array $row,array $map): array {
    $name=hsg_supplier_row_value($row,$map,'name');$fields=[];
    foreach(['sku','name','distillery','age_text','brand_name','category','country','cask_type','cask_number','bottle_count'] as $f){$v=hsg_supplier_row_value($row,$map,$f);if($v!=='')$fields[$f]=$v;}
    foreach(['wholesale_price','retail_price','abv','bottle_size_cl'] as $f){$v=hsg_supplier_row_value($row,$map,$f);$n=hsg_supplier_parse_decimal($v);if($n!==null)$fields[$f]=$n;}
    $vy=hsg_supplier_row_value($row,$map,'vintage_year');if(preg_match('/\b(19|20)\d{2}\b/',$vy,$m))$fields['vintage_year']=(int)$m[0];
    $sq=hsg_supplier_row_value($row,$map,'stock_quantity');
    if($sq!==''){
        $sqNum=hsg_supplier_parse_decimal($sq);
        if($sqNum!==null)$fields['stock_quantity']=(int)max(0,round($sqNum));
    }
    foreach($map as $fieldKey=>$colIdx){
        if(str_starts_with($fieldKey,'stock_loc_')){
            $sv=hsg_supplier_row_value($row,$map,$fieldKey);
            if($sv!==''){
                $svNum=hsg_supplier_parse_decimal($sv);
                if($svNum!==null)$fields[$fieldKey]=(int)max(0,round($svNum));
            }
        }
    }
    if($name!==''){
        $parsed=hsg_product_parse_text($name);foreach((array)($parsed['fields']??[]) as $f=>$v)if(!isset($fields[$f])&&$v!==null&&trim((string)$v)!=='')$fields[$f]=$v;
        if(empty($fields['cask_number'])){$c=hsg_extract_cask_number($name);if($c)$fields['cask_number']=$c;}
    }
    if(!empty($fields['cask_number']))$fields['cask_number']=strtoupper(trim((string)$fields['cask_number']," #\t\n\r\0\x0B"));
    return $fields;
}

function hsg_supplier_name_key(string $s): string {
    $s=hsg_supplier_norm($s);return preg_replace('/(?:singlemalt|scotchwhisky|whisky|whiskey|bottle|700ml|70cl)/','',$s)??$s;
}

function hsg_supplier_match_score(array $src,array $p): array {
    $reasons=[];$score=0;
    $sku=trim((string)($src['sku']??''));if($sku!==''&&strcasecmp($sku,(string)$p['sku'])===0)return ['score'=>100,'reason'=>'SKU er identisk'];
    $cask=strtoupper(trim((string)($src['cask_number']??'')));$pcask=strtoupper(trim((string)($p['cask_number']??'')));
    if($cask!==''&&$pcask!==''&&$cask===$pcask){$score=98;$reasons[]='fadnummer er identisk';}
    $name=trim((string)($src['name']??''));if($name!==''){
        $a=hsg_supplier_name_key($name);$b=hsg_supplier_name_key((string)$p['name']);
        if($a!==''&&$a===$b){$score=max($score,96);$reasons[]='produktnavn er identisk';}
        elseif($a!==''&&$b!==''){$pct=0;similar_text($a,$b,$pct);if($pct>=90){$score=max($score,(int)round(82+($pct-90)*1.2));$reasons[]='meget stærkt navnematch';}elseif($pct>=78){$score=max($score,(int)round(60+($pct-78)*1.5));$reasons[]='muligt navnematch';}}
    }
    foreach([['distillery',5],['vintage_year',5],['abv',4],['age_text',3]] as [$f,$bonus]){
        $sv=trim((string)($src[$f]??''));$pv=trim((string)($p[$f]??''));if($sv===''||$pv==='')continue;
        $same=$f==='abv'?abs((float)$sv-(float)$pv)<0.11:hsg_supplier_norm($sv)===hsg_supplier_norm($pv);
        if($same){$score=min(99,$score+$bonus);$reasons[]=$f.' matcher';}
    }
    return ['score'=>min(99,$score),'reason'=>implode(', ',$reasons)?:'svagt match'];
}

function hsg_supplier_find_match(array $src,array $products,?int $brandId=null): array {
    $best=['id'=>0,'score'=>0,'reason'=>'Intet sikkert match'];$second=0;
    foreach($products as $p){if($brandId && (int)($p['brand_id']??0)!==$brandId)continue;$m=hsg_supplier_match_score($src,$p);if($m['score']>$best['score']){$second=$best['score'];$best=['id'=>(int)$p['id'],'score'=>$m['score'],'reason'=>$m['reason']];}elseif($m['score']>$second)$second=$m['score'];}
    if($best['score']<70)return ['id'=>0,'score'=>$best['score'],'reason'=>'For usikkert: '.$best['reason']];
    if($best['score']-$second<5 && $best['score']<96)return ['id'=>0,'score'=>$best['score'],'reason'=>'Flere produkter ligner hinanden for meget'];
    return $best;
}

function hsg_supplier_suggest_columns(array $headers, array $savedColMap = []): array {
    $aliases=hsg_supplier_aliases();$reverse=[];
    foreach($aliases as $field=>$names)foreach($names as $n)$reverse[hsg_supplier_norm($n)]=$field;
    $colMap=[];$usedFields=[];
    foreach($headers as $ci=>$cell){
        $ci=(int)$ci;
        $cell=trim((string)$cell);if($cell===''){$colMap[$ci]='';continue;}
        $savedField=isset($savedColMap[$ci])?trim((string)$savedColMap[$ci]):'';
        if($savedField!=='' && isset($aliases[$savedField]) && !in_array($savedField,$usedFields,true)){
            $usedFields[]=$savedField;
            $colMap[$ci]=$savedField;
            continue;
        }
        $n=hsg_supplier_norm($cell);$matched=null;
        if(isset($reverse[$n])){$matched=$reverse[$n];}
        else{
            foreach($aliases as $field=>$names){
                foreach($names as $alias){
                    $an=hsg_supplier_norm($alias);
                    if(strlen($an)>=4 && (str_contains($n,$an)||str_contains($an,$n))){$matched=$field;break 2;}
                }
            }
        }
        if($matched && !in_array($matched,$usedFields,true)){
            $usedFields[]=$matched;
            $colMap[$ci]=$matched;
        } else {
            $colMap[$ci]='';
        }
    }
    return $colMap;
}

function hsg_supplier_base_sku(string $sku): string {
    $s=strtoupper(trim($sku));if($s==='')return '';
    if(preg_match('/^([A-Z0-9\-_]+?)[_\-\s]*[A-Z]$/i',$s,$m)&&strlen($m[1])>=3)return $m[1];
    return $s;
}

function hsg_supplier_merge_raw_items(array $rawRows, array $fieldMap, array $products, ?int $brandId = null, int $start = 1): array {
    $byId = [];
    foreach($products as $p) $byId[(int)$p['id']] = $p;

    $parsedRows = [];
    foreach($rawRows as $i => $row) {
        $src = hsg_supplier_source_fields((array)$row, $fieldMap);
        if(!$src || (empty($src['name']) && empty($src['sku']) && empty($src['cask_number']))) continue;
        $sku = trim((string)($src['sku'] ?? ''));
        $baseSku = hsg_supplier_base_sku($sku);
        $parsedRows[] = [
            'original_row' => $start + 1 + $i,
            'src' => $src,
            'sku' => $sku,
            'base_sku' => $baseSku
        ];
    }

    $grouped = [];
    foreach($parsedRows as $pRow) {
        $src = $pRow['src'];
        $match = hsg_supplier_find_match($src, $products, $brandId);
        $pid = (int)$match['id'];

        $groupKey = $pid > 0 ? "pid_{$pid}" : ($pRow['base_sku'] !== '' ? "sku_{$pRow['base_sku']}" : "row_{$pRow['original_row']}");

        if (!isset($grouped[$groupKey])) {
            $grouped[$groupKey] = [
                'rows' => [$pRow['original_row']],
                'source' => $src,
                'match' => $match,
            ];
        } else {
            $grouped[$groupKey]['rows'][] = $pRow['original_row'];
            $existingSrc = $grouped[$groupKey]['source'];

            if (isset($src['stock_quantity'])) {
                $existingSrc['stock_quantity'] = (int)($existingSrc['stock_quantity'] ?? 0) + (int)$src['stock_quantity'];
            }

            foreach ($src as $k => $v) {
                if ($k === 'stock_quantity') continue;
                if (str_starts_with($k, 'stock_loc_')) {
                    $existingSrc[$k] = (int)($existingSrc[$k] ?? 0) + (int)$v;
                    continue;
                }
                if ($v !== null && trim((string)$v) !== '') {
                    $existingSrc[$k] = $v;
                }
            }

            $newMatch = hsg_supplier_find_match($existingSrc, $products, $brandId);
            if ($newMatch['score'] > $grouped[$groupKey]['match']['score']) {
                $grouped[$groupKey]['match'] = $newMatch;
            }
            $grouped[$groupKey]['source'] = $existingSrc;
        }
    }

    $items = [];
    foreach($grouped as $g) {
        $src = $g['source'];
        $match = $g['match'];
        $pid = (int)$match['id'];
        $changes = [];
        if ($pid && isset($byId[$pid])) {
            $changes = hsg_supplier_changes_for_product($src, $byId[$pid]);
        }
        $rowLabel = count($g['rows']) > 1 ? '#' . implode(', #', $g['rows']) . ' (Flettet)' : '#' . $g['rows'][0];
        $items[] = [
            'row' => $g['rows'][0],
            'row_label' => $rowLabel,
            'source' => $src,
            'match' => $match,
            'changes' => $changes,
            'selected' => $pid > 0 && $match['score'] >= 90 && count($changes) > 0
        ];
    }
    return $items;
}

function hsg_supplier_prepare_preview(PDO $pdo,array $sheets,string $filename,?int $brandId=null): array {
    $bestSheet=null;$bestHeader=null;
    foreach($sheets as $sheet=>$rows){try{$h=hsg_supplier_detect_header($rows);if($bestHeader===null||$h['score']>$bestHeader['score']){$bestSheet=$sheet;$bestHeader=$h;}}catch(Throwable $e){}}
    if($bestHeader===null||$bestSheet===null)throw new RuntimeException('Kunne ikke finde en tabel med produkter/priser i nogen af arkene.');
    $products=$pdo->query('SELECT p.*,b.name brand_name, COALESCE((SELECT SUM(s.quantity) FROM lager_stock s JOIN lager_locations l ON l.id=s.location_id WHERE s.product_id=p.id AND l.active=1), 0) stock_quantity FROM lager_products p LEFT JOIN lager_brands b ON b.id=p.brand_id ORDER BY p.name')->fetchAll(PDO::FETCH_ASSOC);
    $locs=$pdo->query('SELECT id FROM lager_locations WHERE active=1')->fetchAll(PDO::FETCH_COLUMN);
    $stLoc=$pdo->prepare('SELECT quantity FROM lager_stock WHERE product_id=? AND location_id=?');
    foreach($products as &$p){
        foreach($locs as $lid){
            $stLoc->execute([(int)$p['id'],(int)$lid]);
            $p['stock_loc_'.$lid] = (int)($stLoc->fetchColumn()?:0);
        }
    }
    unset($p);
    $rows=$sheets[$bestSheet];$start=$bestHeader['row']+1;
    $headers=(array)$bestHeader['headers'];
    $savedRaw=trim((string)setting_get($pdo,'supplier_import_last_col_map',''));
    $savedColMap=json_decode($savedRaw,true);
    if(!is_array($savedColMap))$savedColMap=[];
    $colMap=hsg_supplier_suggest_columns($headers,$savedColMap);
    $fieldMap=[];
    foreach($colMap as $ci=>$field){if($field!==''){$fieldMap[$field]=(int)$ci;}}
    $rawRows=array_values(array_slice($rows,$start,null,true));
    $items=hsg_supplier_merge_raw_items($rawRows, $fieldMap, $products, $brandId, $start);

    return [
        'filename'=>$filename,
        'sheet'=>$bestSheet,
        'header_row'=>$bestHeader['row']+1,
        'headers'=>$headers,
        'mapping'=>$fieldMap,
        'col_mapping'=>$colMap,
        'raw_rows'=>$rawRows,
        'items'=>$items,
        'created_at'=>date('c'),
        'brand_id'=>$brandId
    ];
}

function hsg_supplier_recalculate_preview(PDO $pdo, array $preview, array $customColMap): array {
    $headers = (array)($preview['headers'] ?? []);
    $colMap = [];
    $fieldMap = [];
    foreach($customColMap as $ci => $field) {
        $ci = (int)$ci;
        $field = trim((string)$field);
        $colMap[$ci] = $field;
        if($field !== '') {
            $fieldMap[$field] = $ci;
        }
    }
    foreach(array_keys($headers) as $ci) {
        $ci = (int)$ci;
        if(!array_key_exists($ci, $colMap)) {
            $colMap[$ci] = '';
        }
    }
    ksort($colMap);
    $products = $pdo->query('SELECT p.*,b.name brand_name, COALESCE((SELECT SUM(s.quantity) FROM lager_stock s JOIN lager_locations l ON l.id=s.location_id WHERE s.product_id=p.id AND l.active=1), 0) stock_quantity FROM lager_products p LEFT JOIN lager_brands b ON b.id=p.brand_id ORDER BY p.name')->fetchAll(PDO::FETCH_ASSOC);
    $locs=$pdo->query('SELECT id FROM lager_locations WHERE active=1')->fetchAll(PDO::FETCH_COLUMN);
    $stLoc=$pdo->prepare('SELECT quantity FROM lager_stock WHERE product_id=? AND location_id=?');
    foreach($products as &$p){
        foreach($locs as $lid){
            $stLoc->execute([(int)$p['id'],(int)$lid]);
            $p['stock_loc_'.$lid] = (int)($stLoc->fetchColumn()?:0);
        }
    }
    unset($p);

    $brandId = !empty($preview['brand_id']) ? (int)$preview['brand_id'] : null;
    $rawRows = (array)($preview['raw_rows'] ?? []);
    $start = (int)($preview['header_row'] ?? 1);
    $items = hsg_supplier_merge_raw_items($rawRows, $fieldMap, $products, $brandId, $start);

    $preview['mapping'] = $fieldMap;
    $preview['col_mapping'] = $colMap;
    $preview['items'] = $items;
    return $preview;
}

function hsg_supplier_preview_dir(): string {$d=__DIR__.'/../storage/import-previews';if(!is_dir($d)&&!@mkdir($d,0770,true)&&!is_dir($d))throw new RuntimeException('Kunne ikke oprette preview-mappe.');return $d;}
function hsg_supplier_preview_save(array $preview, ?string $existingToken = null): string {
    $token = ($existingToken !== null && preg_match('/^[a-f0-9]{40}$/', $existingToken)) ? $existingToken : bin2hex(random_bytes(20));
    $path = hsg_supplier_preview_dir().'/'.$token.'.json';
    file_put_contents($path, json_encode($preview, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR), LOCK_EX);
    return $token;
}
function hsg_supplier_preview_load(string $token): array {if(!preg_match('/^[a-f0-9]{40}$/',$token))throw new RuntimeException('Ugyldigt preview-token.');$path=hsg_supplier_preview_dir().'/'.$token.'.json';if(!is_file($path))throw new RuntimeException('Previewet er udløbet eller findes ikke.');$data=json_decode((string)file_get_contents($path),true,512,JSON_THROW_ON_ERROR);return is_array($data)?$data:[];}
function hsg_supplier_preview_delete(string $token): void {if(preg_match('/^[a-f0-9]{40}$/',$token))@unlink(hsg_supplier_preview_dir().'/'.$token.'.json');}

function hsg_supplier_update_fields(): array {
    $fields = ['cask_number','cask_type','wholesale_price','retail_price','abv','distillery','age_text','vintage_year','category','country','bottle_size_cl','bottle_count','stock_quantity'];
    if(isset($GLOBALS['pdo']) && ($GLOBALS['pdo'] instanceof PDO)){
        $locs = $GLOBALS['pdo']->query('SELECT id FROM lager_locations WHERE active=1')->fetchAll(PDO::FETCH_COLUMN);
        foreach($locs as $lid) {
            $fields[] = 'stock_loc_'.$lid;
        }
    }
    return $fields;
}

function hsg_supplier_changes_for_product(array $src,array $product): array {
    $changes=[];
    $updateFields = hsg_supplier_update_fields();
    foreach($src as $k=>$v){
        if(str_starts_with($k,'stock_loc_') && !in_array($k,$updateFields,true)){
            $updateFields[]=$k;
        }
    }
    foreach($updateFields as $field){
        if(!array_key_exists($field,$src))continue;$new=$src[$field];if($new===null||trim((string)$new)==='')continue;$old=$product[$field]??null;
        $isStockLoc = str_starts_with($field,'stock_loc_');
        $same=in_array($field,['wholesale_price','retail_price','abv','bottle_size_cl'],true)
            ?($old!==null&&trim((string)$old)!==''&&abs((float)$old-(float)$new)<0.001)
            :($field==='stock_quantity' || $isStockLoc
                ?($old!==null&&trim((string)$old)!==''&&(int)$old===(int)$new)
                :hsg_supplier_norm((string)$old)===hsg_supplier_norm((string)$new));
        if(!$same)$changes[$field]=['old'=>$old,'new'=>$new];
    }
    return $changes;
}

function hsg_supplier_apply_product(PDO $pdo,int $productId,array $src): array {
    $st=$pdo->prepare('SELECT p.*, COALESCE((SELECT SUM(s.quantity) FROM lager_stock s JOIN lager_locations l ON l.id=s.location_id WHERE s.product_id=p.id AND l.active=1), 0) stock_quantity FROM lager_products p WHERE p.id=?');
    $st->execute([$productId]);$product=$st->fetch(PDO::FETCH_ASSOC);
    if(!$product)throw new RuntimeException('Det valgte HSG-produkt findes ikke.');
    $locs=$pdo->query('SELECT id FROM lager_locations WHERE active=1')->fetchAll(PDO::FETCH_COLUMN);
    $stLoc=$pdo->prepare('SELECT quantity FROM lager_stock WHERE product_id=? AND location_id=?');
    foreach($locs as $lid){
        $stLoc->execute([$productId,(int)$lid]);
        $product['stock_loc_'.$lid] = (int)($stLoc->fetchColumn()?:0);
    }
    $changes=hsg_supplier_changes_for_product($src,$product);if(!$changes)return [];
    $sets=[];$params=[];
    foreach($changes as $field=>$change){
        if($field==='stock_quantity' || str_starts_with($field,'stock_loc_')) continue;
        $sets[]="$field=?";$params[]=$change['new'];
    }
    if($sets){
        $params[]=$productId;
        $pdo->prepare('UPDATE lager_products SET '.implode(',',$sets).' WHERE id=?')->execute($params);
    }
    // Per-location stock updates
    foreach($changes as $field=>$change){
        if(str_starts_with($field,'stock_loc_')){
            $locationId=(int)substr($field,10);
            if($locationId>0){
                $newQty=(int)$change['new'];
                $stLocForUpdate=$pdo->prepare('SELECT quantity FROM lager_stock WHERE product_id=? AND location_id=? FOR UPDATE');
                $stLocForUpdate->execute([$productId,$locationId]);
                $oldQty=(int)($stLocForUpdate->fetchColumn()?:0);
                $changeQty=$newQty-$oldQty;
                $pdo->prepare('INSERT INTO lager_stock(product_id,location_id,quantity) VALUES(?,?,?) ON DUPLICATE KEY UPDATE quantity=VALUES(quantity)')->execute([$productId,$locationId,$newQty]);
                $pdo->prepare('INSERT INTO lager_stock_movements(product_id,location_id,change_qty,balance_after,movement_type,reference,created_by,created_by_admin) VALUES(?,?,?,?,?,?,?,?)')
                    ->execute([$productId,$locationId,$changeQty,$newQty,'import','Kims masterfil opdatering',null,current_admin_id()]);
            }
        }
    }
    // Default stock_quantity field (if generic stock_quantity mapped and no location-specific mapped)
    if(isset($changes['stock_quantity'])){
        $hasLocMapped = false;
        foreach(array_keys($changes) as $ck){
            if(str_starts_with($ck,'stock_loc_')){$hasLocMapped=true;break;}
        }
        if(!$hasLocMapped){
            $newQty=(int)$changes['stock_quantity']['new'];
            $locSt=$pdo->query('SELECT id FROM lager_locations WHERE active=1 ORDER BY sort_order ASC, id ASC LIMIT 1');
            $locationId=(int)($locSt->fetchColumn()?:0);
            if($locationId>0){
                $stLocForUpdate=$pdo->prepare('SELECT quantity FROM lager_stock WHERE product_id=? AND location_id=? FOR UPDATE');
                $stLocForUpdate->execute([$productId,$locationId]);
                $oldQty=(int)($stLocForUpdate->fetchColumn()?:0);
                $changeQty=$newQty-$oldQty;
                $pdo->prepare('INSERT INTO lager_stock(product_id,location_id,quantity) VALUES(?,?,?) ON DUPLICATE KEY UPDATE quantity=VALUES(quantity)')->execute([$productId,$locationId,$newQty]);
                $pdo->prepare('INSERT INTO lager_stock_movements(product_id,location_id,change_qty,balance_after,movement_type,reference,created_by,created_by_admin) VALUES(?,?,?,?,?,?,?,?)')
                    ->execute([$productId,$locationId,$changeQty,$newQty,'import','Kims masterfil opdatering',null,current_admin_id()]);
            }
        }
    }
    hsg_sync_product_stock_status($pdo,$productId);
    return $changes;
}
