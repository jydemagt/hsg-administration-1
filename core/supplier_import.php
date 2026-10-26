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
    while(($r=fgetcsv($fh,0,$d,'"','\\'))!==false)$rows[]=array_map(static fn($v)=>trim((string)$v),$r);fclose($fh);return ['CSV'=>$rows];
}

function hsg_supplier_aliases(): array {
    return [
      'sku'=>['sku','varenummer','varenr','varenr.','itemno','itemnumber','productcode','productid','artikelnummer','artnr','stockcode','code','nummer'],
      'name'=>['navn','produkt','produktnavn','product','productname','item','itemname','description','beskrivelse','varetekst','title','whisky','variant'],
      'cask_number'=>['fadnummer','fadnr','fadno','casknumber','caskno','casknr','cask#','caskid','barrelnumber','barrelno','barrelnr'],
      'cask_type'=>['fadtype','casktype','barreltype','caskfinish','finish','finishtype','maturation','maturationtype','woodtype','cask','barrel'],
      'wholesale_price'=>['engrospris','engrospriseksklmoms','wholesale','wholesaleprice','tradeprice','dealerprice','netprice','costprice','buyingprice','purchaseprice','indkobspris','indkoebspris','engros'],
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
            if($n==='')continue; // "?", "-" o.l. må ikke tælle som match på alle felter
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

function hsg_supplier_is_stock_field(string $field): bool {return $field==='stock_quantity'||str_starts_with($field,'stock_loc_');}

/** Har kolonnevalget mindst én kolonne valgt som "Lagerantal for <lokation>"? (tilstand L) */
function hsg_supplier_map_has_locations(array $map): bool {
    foreach(array_keys($map) as $k)if(str_starts_with((string)$k,'stock_loc_'))return true;
    return false;
}

/** Rå celletekst afkortet til $max tegn + "…" (tegn, ikke bytes – æ, ø og å klippes ikke over). Ugyldig UTF-8 returneres uændret. */
function hsg_supplier_short_text(string $s, int $max = 40): string {
    if(preg_match('/^.{0,'.max(0,$max).'}/us',$s,$m)!==1)return $s;
    return strlen($m[0])<strlen($s)?$m[0].'…':$s;
}

/**
 * Én lagercelle (lokationscelle eller "Lagerantal i alt"): ['state'=>'blank'|'number'|'invalid', 'value'=>?int, 'text'=>string].
 * - Mellemrum (også hårde mellemrum U+00A0/U+202F) i begyndelsen og slutningen fjernes. Er der intet tilbage, er cellen tom.
 * - Et tal er valgfrit minus, 1–5 cifre (0–9, også foranstillede nuller) og højst én decimal-separator (. eller ,) med
 *   cifre efter – men ikke præcis tre cifre efter separatoren. Værdien afrundes én gang (,5 væk fra 0), og negative
 *   tal bliver 0. Et tal har også 'negative'=>bool: sand, når den afrundede værdi er under 0 ("-1", "-0,5", men ikke
 *   "-0" eller "-0,4").
 * - Alt andet er ugyldigt ("3 + 2", "53 stk.", "?", "1.234", "000041", EAN …), og text er den afkortede tekst.
 *   Ugyldig UTF-8 er altid ugyldig (aldrig tom), og teksten afkortes da ikke.
 */
function hsg_supplier_stock_cell(mixed $v): array {
    $t=preg_replace('/^[\s\x{00A0}\x{202F}]+|[\s\x{00A0}\x{202F}]+$/u','',(string)$v);
    if($t===null)return ['state'=>'invalid','value'=>null,'text'=>trim((string)$v)];
    if($t==='')return ['state'=>'blank','value'=>null,'text'=>''];
    if(preg_match('/^-?[0-9]{1,5}(?:[.,]([0-9]+))?$/',$t,$m)===1&&strlen($m[1]??'')!==3){
        // Højst én separator, så den er altid decimaltegnet (også "40,999999999999993").
        $n=(int)round((float)str_replace(',','.',$t));
        return ['state'=>'number','value'=>max(0,$n),'text'=>$t,'negative'=>$n<0];
    }
    return ['state'=>'invalid','value'=>null,'text'=>hsg_supplier_short_text($t)];
}

/**
 * Kims lokationstal for én række (tilstand L). Hver celle vurderes med hsg_supplier_stock_cell: tal (også 0) bruges,
 * tomme celler tæller som 0, hvis mindst én anden valgt lokationscelle i rækken har et tal – ellers bidrager rækken
 * ikke med lager. Ugyldig tekst ændrer ikke lokationen. Et negativt tal tæller som 0 og står også i 'negative'.
 * Returnerer ['values'=>[stock_loc_x=>int], 'blank'=>[stock_loc_x, …], 'invalid'=>[stock_loc_x=>afkortet tekst],
 * 'negative'=>[stock_loc_x=>afkortet tekst]].
 */
function hsg_supplier_location_cells(array $row, array $map): array {
    $numbers=[];$blank=[];$invalid=[];$negative=[];
    foreach($map as $fieldKey=>$colIdx){
        $fieldKey=(string)$fieldKey;
        if(!str_starts_with($fieldKey,'stock_loc_'))continue;
        $cell=hsg_supplier_stock_cell(trim((string)($row[$colIdx]??'')));
        if($cell['state']==='blank')$blank[]=$fieldKey;
        elseif($cell['state']==='invalid')$invalid[$fieldKey]=$cell['text'];
        else{$numbers[$fieldKey]=(int)$cell['value'];if(!empty($cell['negative']))$negative[$fieldKey]=hsg_supplier_short_text($cell['text']);}
    }
    $values=[];
    if($numbers){
        foreach(array_keys($map) as $fieldKey){
            $fieldKey=(string)$fieldKey;
            if(isset($numbers[$fieldKey]))$values[$fieldKey]=$numbers[$fieldKey];
            elseif(in_array($fieldKey,$blank,true))$values[$fieldKey]=0;
        }
    }
    return ['values'=>$values,'blank'=>$blank,'invalid'=>$invalid,'negative'=>$negative];
}

/**
 * Feltværdier fra én filrække.
 * - Tilstand L (lokationskolonner valgt): stock_loc_* efter reglen for tomme celler og Kims total som stock_file_total
 *   (kun kontroltal). Aldrig stock_quantity.
 * - Tilstand T (kun "Lagerantal i alt"): med $fallbackLocationId skrives totalen som stock_loc_<første lokation> og
 *   stock_file_total. Uden fallback-id som stock_quantity (som før).
 * "Lagerantal i alt" følger samme celleregel som lokationscellerne (hsg_supplier_stock_cell). Er cellen ugyldig, skrives
 * intet lager og intet kontroltal; den afkortede tekst lægges i den interne nøgle stock_file_total_invalid, som
 * hsg_supplier_merge_raw_items fjerner fra source igen. Er tallet negativt, skrives 0, og den afkortede tekst lægges på
 * samme måde i den interne nøgle stock_file_total_negative.
 */
function hsg_supplier_source_fields(array $row,array $map,?int $fallbackLocationId=null): array {
    $name=hsg_supplier_row_value($row,$map,'name');$fields=[];
    foreach(['sku','name','distillery','age_text','brand_name','category','country','cask_type','cask_number','bottle_count'] as $f){$v=hsg_supplier_row_value($row,$map,$f);if($v!=='')$fields[$f]=$v;}
    foreach(['wholesale_price','retail_price','abv','bottle_size_cl'] as $f){$v=hsg_supplier_row_value($row,$map,$f);$n=hsg_supplier_parse_decimal($v);if($n!==null)$fields[$f]=$n;}
    $vy=hsg_supplier_row_value($row,$map,'vintage_year');if(preg_match('/\b(19|20)\d{2}\b/',$vy,$m))$fields['vintage_year']=(int)$m[0];
    $total=null;
    if(isset($map['stock_quantity'])){
        $cell=hsg_supplier_stock_cell(hsg_supplier_row_value($row,$map,'stock_quantity'));
        if($cell['state']==='number'){$total=(int)$cell['value'];if(!empty($cell['negative']))$fields['stock_file_total_negative']=hsg_supplier_short_text($cell['text']);}
        elseif($cell['state']==='invalid')$fields['stock_file_total_invalid']=$cell['text'];
    }
    if(hsg_supplier_map_has_locations($map)){
        foreach(hsg_supplier_location_cells($row,$map)['values'] as $fieldKey=>$qty)$fields[$fieldKey]=$qty;
        if($total!==null)$fields['stock_file_total']=$total;
    }elseif($total!==null){
        if($fallbackLocationId!==null&&$fallbackLocationId>0){$fields['stock_loc_'.$fallbackLocationId]=$total;$fields['stock_file_total']=$total;}
        else $fields['stock_quantity']=$total;
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
    // Et fadnummer uden ciffer (fx STRENGTH fra "Cask Strength") er ikke et fadnummer og giver ikke et match.
    if($cask!==''&&$pcask!==''&&$cask===$pcask&&preg_match('/\d/',$cask)===1){$score=98;$reasons[]='fadnummer er identisk';}
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

function hsg_supplier_find_match(array $src,array $products,?int $brandId=null,?PDO $pdo=null): array {
    $sku=trim((string)($src['sku']??''));
    if($pdo && $sku!=='' && db_table_exists($pdo,'hsg_supplier_sku_aliases')){
        $stA=$pdo->prepare('SELECT product_id FROM hsg_supplier_sku_aliases WHERE supplier_sku=? ORDER BY id DESC LIMIT 1');
        $stA->execute([$sku]);
        $aliasPid=(int)($stA->fetchColumn()?:0);
        if($aliasPid>0){
            foreach($products as $p){
                if((int)$p['id']===$aliasPid){
                    return ['id'=>$aliasPid,'score'=>100,'reason'=>'Automatisk fletning ud fra tidligere gemt match'];
                }
            }
        }
    }
    $best=['id'=>0,'score'=>0,'reason'=>'Intet sikkert match'];$second=0;
    foreach($products as $p){if($brandId && (int)($p['brand_id']??0)!==$brandId)continue;$m=hsg_supplier_match_score($src,$p);if($m['score']>$best['score']){$second=$best['score'];$best=['id'=>(int)$p['id'],'score'=>$m['score'],'reason'=>$m['reason']];}elseif($m['score']>$second)$second=$m['score'];}
    if($best['score']<70)return ['id'=>0,'score'=>$best['score'],'reason'=>'For usikkert: '.$best['reason']];
    if($best['score']-$second<5 && $best['score']<96)return ['id'=>0,'score'=>$best['score'],'reason'=>'Flere produkter ligner hinanden for meget'];
    return $best;
}

/**
 * Nøgle pr. kolonne, som kolonnevalget huskes under: den normaliserede overskrift.
 * Gentages en overskrift i samme fil, får anden forekomst "|2", tredje "|3" osv.
 */
function hsg_supplier_header_keys(array $headers): array {
    $keys=[];$seen=[];
    foreach($headers as $ci=>$cell){
        $n=hsg_supplier_norm((string)$cell);
        if($n===''){$keys[(int)$ci]='';continue;}
        $seen[$n]=($seen[$n]??0)+1;
        $keys[(int)$ci]=$seen[$n]>1?$n.'|'.$seen[$n]:$n;
    }
    return $keys;
}

function hsg_supplier_saved_columns(PDO $pdo): array {
    $saved=json_decode(trim((string)setting_get($pdo,'supplier_import_header_map','')),true);
    return is_array($saved)?$saved:[];
}

/** Gemmer kolonnevalget fra seneste upload (overskrift → HSG-felt, '' = ignoreret) i hsg_settings. */
function hsg_supplier_remember_columns(PDO $pdo, array $headers, array $colMap): void {
    $map=[];
    foreach(hsg_supplier_header_keys($headers) as $ci=>$key){
        if($key===''||!array_key_exists($ci,$colMap))continue;
        $map[$key]=trim((string)$colMap[$ci]);
    }
    if($map)setting_set($pdo,'supplier_import_header_map',json_encode($map,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_FORCE_OBJECT));
}

/**
 * K4: Ligner overskriften et beløb (værdi, pris, kostpris, moms, kr., DKK …)? Så må kolonnen aldrig bruges som lagerantal.
 */
function hsg_supplier_header_is_money(string $header): bool {
    $n=hsg_supplier_norm($header);
    if($n!==''){
        foreach(['vaerdi','value','pris','price','kost','cost','belob','amount','moms'] as $word)if(str_contains($n,$word))return true;
    }
    return preg_match('/(?<![\p{L}\p{N}])(?:kr|dkk|eur|usd)(?![\p{L}\p{N}])/iu',$header)===1;
}

/** K2/K4: Må et gæt (eller et husket/manuelt valg) knytte feltet til denne overskrift? */
function hsg_supplier_guess_allowed(string $field, string $header): bool {
    if(hsg_supplier_is_stock_field($field)&&hsg_supplier_header_is_money($header))return false;
    $n=hsg_supplier_norm($header);
    $contains=static function(array $words) use ($n): bool {foreach($words as $w)if(str_contains($n,$w))return true;return false;};
    if($field==='retail_price'&&$contains(['engros','wholesale','netto','dealer','trade','indkob','kost','cost']))return false;
    if($field==='wholesale_price'&&$contains(['udsalg','vejl','retail','rrp','inklmoms']))return false;
    return true;
}

/**
 * Huskede valg, der ikke bruges, fordi en beløbskolonne var knyttet til lager (K4): [kolonneindeks => afvist felt].
 */
function hsg_supplier_rejected_saved_columns(array $headers, array $savedHeaderMap): array {
    $rejected=[];
    foreach(hsg_supplier_header_keys($headers) as $ci=>$key){
        if($key===''||!array_key_exists($key,$savedHeaderMap))continue;
        $savedField=trim((string)$savedHeaderMap[$key]);
        if($savedField!==''&&hsg_supplier_is_stock_field($savedField)&&hsg_supplier_header_is_money((string)$headers[$ci]))$rejected[(int)$ci]=$savedField;
    }
    return $rejected;
}

/**
 * Foreslår HSG-felt pr. kolonne (K1–K5):
 * 1. Husket valg fra seneste upload bruges først – også "Ignorer kolonne". En beløbskolonne, der var husket som lager, afvises.
 *    Felter, der bevidst stod uden kolonne sidst, gættes ikke igen.
 * 2. Præcise alias-match for de øvrige kolonner fra venstre mod højre (første kolonne pr. felt vinder).
 * 3. Delvise match for de kolonner, der stadig er tomme – kun til felter, der ikke er brugt. To trin: først hvor
 *    overskriften indeholder et alias, derefter hvor aliaset indeholder overskriften.
 * Lokationskolonner gættes aldrig automatisk.
 */
function hsg_supplier_suggest_columns(array $headers, array $savedHeaderMap = [], ?array $allowedFields = null): array {
    $aliases=hsg_supplier_aliases();$reverse=[];
    foreach($aliases as $field=>$names)foreach($names as $n)$reverse[hsg_supplier_norm($n)]=$field;
    $isAllowed=static fn(string $f): bool => $allowedFields!==null ? in_array($f,$allowedFields,true) : (isset($aliases[$f])||str_starts_with($f,'stock_loc_'));
    $colMap=[];$usedFields=[];
    foreach(hsg_supplier_header_keys($headers) as $ci=>$key){
        if($key===''||!array_key_exists($key,$savedHeaderMap))continue;
        $savedField=trim((string)$savedHeaderMap[$key]);
        if($savedField===''){$colMap[$ci]='';continue;}
        if(hsg_supplier_is_stock_field($savedField)&&hsg_supplier_header_is_money((string)$headers[$ci])){$colMap[$ci]='';continue;}
        if($isAllowed($savedField)&&!in_array($savedField,$usedFields,true)){$colMap[$ci]=$savedField;$usedFields[]=$savedField;}
    }
    $mappedLast=array_filter(array_map(static fn($f)=>trim((string)$f),$savedHeaderMap),static fn($f)=>$f!=='');
    $blocked=$savedHeaderMap?array_diff(array_keys($aliases),$mappedLast):[];
    $available=static function(string $f) use (&$usedFields,$blocked): bool {return !in_array($f,$usedFields,true) && !in_array($f,$blocked,true);};

    // Gennemløb 1: præcise match.
    $pending=[];
    foreach($headers as $ci=>$cell){
        $ci=(int)$ci;
        if(array_key_exists($ci,$colMap))continue;
        $cell=trim((string)$cell);if($cell===''){$colMap[$ci]='';continue;}
        $n=hsg_supplier_norm($cell);
        $exact=$reverse[$n]??null;
        if($exact!==null&&$available($exact)&&hsg_supplier_guess_allowed($exact,$cell)){$colMap[$ci]=$exact;$usedFields[]=$exact;continue;}
        $pending[$ci]=[$cell,$n];
    }
    // Gennemløb 2: delvise match i to trin, begge fra venstre mod højre.
    //   2a: overskriften indeholder et alias ("Retail Price (inc VAT)" → udsalgspris).
    //   2b: aliaset indeholder overskriften ("Price" → første ledige prisfelt) – først når 2a har taget sine felter.
    // Produktnavn kræver et alias på mindst 8 tegn (whisky, navn, item … kun præcist).
    foreach([true,false] as $headerContainsAlias){
        foreach($pending as $ci=>[$cell,$n]){
            if(isset($colMap[$ci])||$n==='')continue;
            foreach($aliases as $field=>$names){
                if(!$available($field)||!hsg_supplier_guess_allowed($field,$cell))continue;
                $minLength=$field==='name'?8:4;
                foreach($names as $alias){
                    $an=hsg_supplier_norm($alias);
                    if(strlen($an)>=$minLength && ($headerContainsAlias?str_contains($n,$an):str_contains($an,$n))){$colMap[$ci]=$field;$usedFields[]=$field;break 2;}
                }
            }
        }
    }
    foreach(array_keys($pending) as $ci)if(!isset($colMap[$ci]))$colMap[$ci]='';
    ksort($colMap);
    return $colMap;
}

/**
 * K6 – "Gæt kolonner forfra": gætter uden husket valg og lægger bagefter de huskede lokationskolonner
 * (stock_loc_*) ind igen på kolonner, der står tomme efter gættet. Gemmer intet.
 */
function hsg_supplier_reguess_columns(array $headers, array $savedHeaderMap, ?array $allowedFields = null): array {
    $colMap=hsg_supplier_suggest_columns($headers,[],$allowedFields);
    $aliases=hsg_supplier_aliases();
    $isAllowed=static fn(string $f): bool => $allowedFields!==null ? in_array($f,$allowedFields,true) : (isset($aliases[$f])||str_starts_with($f,'stock_loc_'));
    $usedFields=array_values(array_filter($colMap,static fn($f)=>$f!==''));
    foreach(hsg_supplier_header_keys($headers) as $ci=>$key){
        if($key===''||!array_key_exists($key,$savedHeaderMap))continue;
        $savedField=trim((string)$savedHeaderMap[$key]);
        if(!str_starts_with($savedField,'stock_loc_')||!$isAllowed($savedField)||in_array($savedField,$usedFields,true))continue;
        if(($colMap[$ci]??'')!==''||hsg_supplier_header_is_money((string)$headers[$ci]))continue;
        $colMap[$ci]=$savedField;$usedFields[]=$savedField;
    }
    ksort($colMap);
    return $colMap;
}

/**
 * Varenummer uden bogstav-endelse: "17-1028a" → "17-1028". Kun ét bogstav direkte efter et tal
 * tæller som variant. Andre varenumre returneres uændret (med store bogstaver).
 */
function hsg_supplier_base_sku(string $sku): string {
    $s=strtoupper(trim($sku));if($s==='')return '';
    if(preg_match('/^(.*\d)[A-Z]$/',$s,$m)&&strlen($m[1])>=3)return $m[1];
    return $s;
}

/**
 * Lægger en ekstra filrække oven i en primær række: lagerfelter summeres, øvrige felter udfylder kun tomme felter.
 * Den interne nøgle stock_file_total_invalid er ikke et lagerfelt: den første ikke-tomme værdi bevares (den primære
 * række først), og hsg_supplier_merge_raw_items gør så hele gruppens total ugyldig. Det samme gælder
 * stock_file_total_negative (første negative tekst vinder).
 */
function hsg_supplier_merge_source(array $primary, array $extra): array {
    foreach($extra as $k=>$v){
        if($k==='stock_quantity'||$k==='stock_file_total'||str_starts_with($k,'stock_loc_')){$primary[$k]=(int)($primary[$k]??0)+(int)$v;continue;}
        if((!isset($primary[$k])||trim((string)$primary[$k])==='')&&$v!==null&&trim((string)$v)!=='')$primary[$k]=$v;
    }
    return $primary;
}

/**
 * Er to rækker (eller en række og et HSG-produkt) den samme vare? Bruges før bogstav-varenumre og enheder,
 * der matcher samme HSG-produkt, flettes. Nøgler: name, distillery, cask_number, vintage_year, age_text.
 * Stopregler: forskelligt fadnummer, kendt destilleri, årgang eller alder afviser altid. Et fadnummer uden ciffer
 * tæller som tomt.
 * Navneregler: samme navn, næsten samme navn (≥ 90 %), lignende navn (≥ 75 %) og samme kendte destilleri,
 * eller navn mangler på den ene side og samme kendte destilleri. Ellers afvist.
 * Returnerer ['ok'=>bool, 'reason'=>string].
 */
function hsg_supplier_variant_compatible(array $a, array $b): array {
    // Et fadnummer uden ciffer (fx FINISH fra "Cask Finish", STRENGTH fra "Cask Strength") tæller som tomt.
    $caskOf=static function(array $x): string {
        $c=strtoupper(preg_replace('/[#\s]+/u','',trim((string)($x['cask_number']??'')))??'');
        return preg_match('/\d/',$c)===1?$c:'';
    };
    $caskA=$caskOf($a);$caskB=$caskOf($b);
    if($caskA!==''&&$caskB!==''&&$caskA!==$caskB)return ['ok'=>false,'reason'=>'forskelligt fadnummer (#'.$caskA.' / #'.$caskB.')'];

    $unknown=['','na','ukendt','unknown','diverse'];
    $distRawA=trim((string)($a['distillery']??''));$distRawB=trim((string)($b['distillery']??''));
    $distA=hsg_supplier_norm($distRawA);$distB=hsg_supplier_norm($distRawB);
    $knownA=!in_array($distA,$unknown,true);$knownB=!in_array($distB,$unknown,true);
    if($knownA&&$knownB&&$distA!==$distB)return ['ok'=>false,'reason'=>'forskelligt destilleri ('.$distRawA.' / '.$distRawB.')'];
    $sameDistillery=$knownA&&$knownB&&$distA===$distB;

    $yearA=(int)trim((string)($a['vintage_year']??''));$yearB=(int)trim((string)($b['vintage_year']??''));
    if($yearA>0&&$yearB>0&&$yearA!==$yearB)return ['ok'=>false,'reason'=>'forskellig årgang ('.$yearA.' / '.$yearB.')'];

    $ageRawA=trim((string)($a['age_text']??''));$ageRawB=trim((string)($b['age_text']??''));
    if(preg_match('/\d+/',$ageRawA,$ma)&&preg_match('/\d+/',$ageRawB,$mb)&&(int)$ma[0]!==(int)$mb[0])return ['ok'=>false,'reason'=>'forskellig alder ('.$ageRawA.' / '.$ageRawB.')'];

    $nameA=trim((string)($a['name']??''));$nameB=trim((string)($b['name']??''));
    $keyA=$nameA!==''?hsg_supplier_name_key($nameA):'';$keyB=$nameB!==''?hsg_supplier_name_key($nameB):'';
    if($keyA!==''&&$keyB!==''){
        if($keyA===$keyB)return ['ok'=>true,'reason'=>'samme navn'];
        $pct=0.0;similar_text($keyA,$keyB,$pct);
        if($pct>=90)return ['ok'=>true,'reason'=>'næsten samme navn'];
        if($pct>=75&&$sameDistillery)return ['ok'=>true,'reason'=>'samme destilleri og lignende navn'];
        return ['ok'=>false,'reason'=>'navnene er forskellige („'.$nameA.'“ / „'.$nameB.'“)'];
    }
    if(($keyA==='')!==($keyB==='')&&$sameDistillery)return ['ok'=>true,'reason'=>'samme destilleri'];
    return ['ok'=>false,'reason'=>'for få oplysninger til at afgøre, om det er samme vare'];
}

/**
 * Er Kims lager i alt for varen entydigt 0? (spec 2026-10-26-kims-upload-kun-lagerantal, 2.2). Bruger items[].stock_file.
 * - Tilstand L: mindst én lokation har et tal, ingen ugyldige lokationsceller, ingen ugyldig total, sum 0 og total
 *   enten tom eller 0 (en afvigende total er ikke entydig).
 * - Tilstand T: første lokation har tallet, total 0 og ingen ugyldig total.
 * - Ingen lagerkolonner, manglende eller ukendt tilstand (fx ældre previews): aldrig.
 */
function hsg_supplier_stock_file_is_zero(array $stockFile): bool {
    $mode = (string)($stockFile['mode'] ?? '');
    $locations = (array)($stockFile['locations'] ?? []);
    $total = $stockFile['total'] ?? null;
    if(!$locations || ($stockFile['total_invalid'] ?? null) !== null) return false;
    foreach($locations as $qty) if((int)$qty !== 0) return false;
    if($mode === 'locations') {
        if((array)($stockFile['invalid'] ?? [])) return false;
        if((int)($stockFile['sum'] ?? 0) !== 0) return false;
        return $total === null || (int)$total === 0;
    }
    if($mode === 'total') return $total !== null && (int)$total === 0;
    return false;
}

/**
 * Samler filrækkerne til varer og finder match i HSG.
 * $options: fallback_location_id (?int, første aktive lokation – bruges når kun "Lagerantal i alt" er valgt),
 *           location_names ([id => navn], til bemærkninger), add_reserved (bool, læg aktive reservationer oven i) og
 *           stock_only (bool, "Kun lagerantal": kun lager ændres, og varer uden match springes over).
 * Produkterne kan have stock_loc_<id> (fysisk lager), reserved_loc_<id> (aktive reservationer) og active_reservations
 * (de aktive reservationer enkeltvis). Er Kims lager i alt entydigt 0, foreslås de aktive reservationer på lokationer med
 * et tal fra filen arkiveret (items[].archive). På et sikkert match er de forvalgt, og changes beregnes, som om de er
 * arkiveret. items[].stock_file har også negative ([stock_loc_x => tekst]) og total_negative (?string) for negative tal
 * i filen. Så er arkiveringen aldrig forvalgt.
 */
function hsg_supplier_merge_raw_items(array $rawRows, array $fieldMap, array $products, ?int $brandId = null, int $start = 1, ?PDO $pdo = null, array $options = []): array {
    $fallbackLid = (int)($options['fallback_location_id'] ?? 0) > 0 ? (int)$options['fallback_location_id'] : null;
    $locationNames = (array)($options['location_names'] ?? []);
    $addReserved = !empty($options['add_reserved']);
    $stockOnly = !empty($options['stock_only']);
    $locKeys = [];
    foreach(array_keys($fieldMap) as $f) if(str_starts_with((string)$f, 'stock_loc_')) $locKeys[] = (string)$f;
    $stockMode = $locKeys ? 'locations' : (array_key_exists('stock_quantity', $fieldMap) ? 'total' : 'none');
    $locLabel = static function(string $key) use ($locationNames): string {
        $id = (int)substr($key, 10);
        $name = trim((string)($locationNames[$id] ?? ''));
        return $name !== '' ? $name : 'lokation #'.$id;
    };

    $byId = [];$bySku = [];
    foreach($products as $p) {
        $byId[(int)$p['id']] = $p;
        $k = strtoupper(trim((string)($p['sku'] ?? '')));
        if($k !== '') $bySku[$k][] = (int)$p['id'];
    }

    $noCells = ['values' => [], 'blank' => [], 'invalid' => [], 'negative' => []];
    $parsedRows = [];
    foreach($rawRows as $i => $row) {
        $row = (array)$row;
        $src = hsg_supplier_source_fields($row, $fieldMap, $fallbackLid);
        if(!$src || (empty($src['name']) && empty($src['sku']) && empty($src['cask_number']))) continue;
        $sku = trim((string)($src['sku'] ?? ''));
        $parsedRows[] = [
            'original_row' => $start + 1 + $i,
            'src' => $src,
            'sku' => $sku,
            'sku_key' => strtoupper($sku),
            'base_sku' => hsg_supplier_base_sku($sku),
            'cells' => $stockMode === 'locations' ? hsg_supplier_location_cells($row, $fieldMap) : $noCells,
        ];
    }
    $fileSkus = [];
    foreach($parsedRows as $pRow) if($pRow['sku_key'] !== '' && !isset($fileSkus[$pRow['sku_key']])) $fileSkus[$pRow['sku_key']] = $pRow;

    // 1) Rækker med samme varenummer samles. En bogstav-variant (17-1028a) samles under varenummeret uden bogstav
    //    (17-1028), hvis det findes i filen eller i HSG – men kun hvis det er samme vare (navn, fad, destilleri, årgang, alder).
    //    Ellers står varianten alene, og dens match må aldrig pege på HSG-produktet med basens varenummer.
    //    Basens ejer (gemt alias for basen, ellers produktet med basens SKU) findes uden brand-filter og bruges kun til at
    //    udelukke og til kompatibilitetstjekket. Fletning ind i basen kræver stadig et match med brand-filteret.
    $baseMatches = [];$baseOwners = [];$units = [];
    $ownerOf = static function(string $base) use (&$baseOwners, &$baseMatches, $products, $brandId, $pdo): int {
        if(!array_key_exists($base, $baseOwners)) {
            // Uden brand-filter er ejeren det samme som matchet, så et allerede beregnet match genbruges.
            $baseOwners[$base] = (!$brandId && array_key_exists($base, $baseMatches)) ? $baseMatches[$base] : hsg_supplier_find_match(['sku' => $base], $products, null, $pdo);
        }
        return (int)($baseOwners[$base]['id'] ?? 0);
    };
    foreach($parsedRows as $pRow) {
        $key = $pRow['sku_key'];$split = null;$mergeReason = null;
        if($key !== '' && $pRow['base_sku'] !== $key) {
            $base = $pRow['base_sku'];
            if(isset($fileSkus[$base])) {
                // F1: basen står i filen.
                $cmp = hsg_supplier_variant_compatible($pRow['src'], $fileSkus[$base]['src']);
                if($cmp['ok']) { $key = $base; $mergeReason = $cmp['reason']; }
                else {
                    $ownerPid = $ownerOf($base);
                    $split = ['base' => $fileSkus[$base]['sku'], 'reason' => $cmp['reason'], 'exclude' => array_values(array_unique(array_merge($bySku[$base] ?? [], $ownerPid > 0 ? [$ownerPid] : [])))];
                }
            } else {
                // F2: basen findes kun i HSG.
                if(!array_key_exists($base, $baseMatches)) $baseMatches[$base] = hsg_supplier_find_match(['sku' => $base], $products, $brandId, $pdo);
                $matchPid = (int)($baseMatches[$base]['id'] ?? 0);
                $ownerPid = $ownerOf($base);
                $targetPid = $matchPid > 0 ? $matchPid : $ownerPid;
                if($targetPid > 0) {
                    $cmp = hsg_supplier_variant_compatible($pRow['src'], $byId[$targetPid] ?? []);
                    if(!$cmp['ok']) $split = ['base' => (string)($byId[$targetPid]['sku'] ?? $base), 'reason' => $cmp['reason'], 'exclude' => array_values(array_unique(array_merge($bySku[$base] ?? [], array_values(array_filter([$matchPid, $ownerPid], static fn(int $id): bool => $id > 0)))))];
                    elseif($matchPid > 0) { $key = $base; $mergeReason = $cmp['reason']; }
                    // Kompatibel, men kun ejeren fandtes (uden for brand-filteret): ingen fletning og intet split.
                }
            }
        }
        $unitKey = $key !== '' ? 'sku_'.$key : 'row_'.$pRow['original_row'];
        $units[$unitKey]['base'] = $key;
        $units[$unitKey]['rows'][] = $pRow;
        if($mergeReason !== null && !isset($units[$unitKey]['merge_reasons'][$pRow['sku_key']])) $units[$unitKey]['merge_reasons'][$pRow['sku_key']] = $mergeReason;
        if($split !== null && !isset($units[$unitKey]['split'])) $units[$unitKey]['split'] = $split;
    }

    $merged = [];
    foreach($units as $u) {
        $rows = $u['rows'];$base = (string)$u['base'];
        $primaryIdx = 0;
        foreach($rows as $ri => $r) if($r['sku_key'] === $base) { $primaryIdx = $ri; break; }
        $src = $rows[$primaryIdx]['src'];
        $variants = [];
        foreach($rows as $ri => $r) {
            if($ri !== $primaryIdx) $src = hsg_supplier_merge_source($src, $r['src']);
            if($r['sku_key'] !== $base && !isset($variants[$r['sku_key']])) $variants[$r['sku_key']] = $r['sku'];
        }

        $notes = [];$flags = [];$candidates = $products;
        if(!empty($u['split'])) {
            $sp = $u['split'];
            $flags[] = 'variant_split';
            $notes[] = 'Bemærk: '.$rows[0]['sku'].' er ikke flettet med '.$sp['base'].': '.$sp['reason'].'. Varen behandles som sit eget varenummer og er ikke valgt på forhånd.';
            $exclude = array_flip(array_map('intval', (array)$sp['exclude']));
            $candidates = array_values(array_filter($products, static fn($p) => !isset($exclude[(int)$p['id']])));
        }

        if($base !== '' && !isset($fileSkus[$base])) {
            // Kun bogstav-varianten står i filen, men varenummeret uden bogstav findes i HSG (og det er samme vare).
            $match = $baseMatches[$base];
            $match['reason'] = 'Varenummer '.$base.' findes i HSG – bogstav-varianten flettes ind';
            $src['sku'] = (string)($byId[(int)$match['id']]['sku'] ?? $base);
        } else {
            $match = hsg_supplier_find_match($src, $candidates, $brandId, $pdo);
        }
        if((int)$match['id'] === 0) {
            // Undgå dubletter: findes en bogstav-variant allerede som produkt i HSG, bruges det.
            foreach($variants as $v) {
                $vm = hsg_supplier_find_match(['sku' => $v], $products, $brandId, $pdo);
                if((int)$vm['id'] > 0) { $match = ['id' => (int)$vm['id'], 'score' => 95, 'reason' => 'Matchet via bogstav-varianten '.$v.', som allerede findes i HSG']; break; }
            }
        }

        $byReason = [];
        foreach($variants as $vKey => $v) $byReason[(string)($u['merge_reasons'][$vKey] ?? 'samme vare')][] = $v;
        foreach($byReason as $reason => $vs) $notes[] = 'Lagerantal fra '.implode(', ', $vs).' er lagt sammen med '.($src['sku'] ?? $base).' ('.$reason.').';
        foreach($variants as $v) {
            foreach($bySku[strtoupper($v)] ?? [] as $vpid) {
                if($vpid === (int)$match['id'] || ($byId[$vpid]['status'] ?? '') === 'discontinued') continue;
                $notes[] = 'Bemærk: '.$v.' findes også som selvstændigt produkt i HSG (lager '.(int)($byId[$vpid]['stock_quantity'] ?? 0).' stk.). Det ændres ikke – sæt det som udgået, så lageret ikke tælles dobbelt.';
            }
        }
        $merged[] = ['rows' => array_column($rows, 'original_row'), 'cells' => array_column($rows, 'cells'), 'source' => $src, 'match' => $match, 'merged_skus' => array_values($variants), 'notes' => $notes, 'flags' => $flags];
    }

    // 2) Enheder, der matcher samme HSG-produkt, flettes (lagerantal lægges sammen) – men kun hvis de er samme vare.
    //    Ellers beholder den bedste enhed matchet, og de andre skal vælges manuelt.
    $grouped = [];
    foreach($merged as $mi => $m) {
        $pid = (int)$m['match']['id'];
        if($pid <= 0) { $grouped['unit_'.$mi] = $m; continue; }
        $groupKey = 'pid_'.$pid;
        if(!isset($grouped[$groupKey])) { $grouped[$groupKey] = $m; continue; }
        $g = $grouped[$groupKey];
        $cmp = hsg_supplier_variant_compatible($g['source'], $m['source']);
        if($cmp['ok']) {
            $g['rows'] = array_merge($g['rows'], $m['rows']);
            $g['cells'] = array_merge($g['cells'], $m['cells']);
            $g['source'] = hsg_supplier_merge_source($g['source'], $m['source']);
            $g['merged_skus'] = array_values(array_unique(array_merge($g['merged_skus'], $m['merged_skus'])));
            $g['notes'] = array_values(array_unique(array_merge($g['notes'], $m['notes'])));
            $g['flags'] = array_values(array_unique(array_merge($g['flags'], $m['flags'])));
            if($m['match']['score'] > $g['match']['score']) $g['match'] = $m['match'];
            $grouped[$groupKey] = $g;
            continue;
        }
        $productSku = strtoupper(trim((string)($byId[$pid]['sku'] ?? '')));
        $skuIs = static fn(array $unit): bool => $productSku !== '' && strtoupper(trim((string)($unit['source']['sku'] ?? ''))) === $productSku;
        $gScore = (int)$g['match']['score'];$mScore = (int)$m['match']['score'];
        $newWins = $mScore > $gScore || ($mScore === $gScore && $skuIs($m) && !$skuIs($g));
        [$winner, $loser] = $newWins ? [$m, $g] : [$g, $m];
        $winnerLabel = trim((string)($winner['source']['sku'] ?? ''));
        if($winnerLabel === '') $winnerLabel = trim((string)($winner['source']['name'] ?? ''));
        if($winnerLabel === '') $winnerLabel = 'filrække #'.(int)$winner['rows'][0];
        $loser['match'] = ['id' => 0, 'score' => 0, 'reason' => 'Matcher samme HSG-produkt som '.$winnerLabel.' – vælg manuelt'];
        $loser['notes'][] = 'Bemærk: Matcher samme HSG-produkt som '.$winnerLabel.', men er en anden vare: '.$cmp['reason'].'. Vælg produkt manuelt eller opret nyt.';
        $loser['flags'][] = 'match_conflict';
        $grouped[$groupKey] = $winner;
        $grouped['conflict_'.$pid.'_'.$mi] = $loser;
    }

    $items = [];
    foreach($grouped as $g) {
        $src = $g['source'];
        $match = $g['match'];
        $pid = (int)$match['id'];
        $product = $pid > 0 ? ($byId[$pid] ?? null) : null;
        $notes = $g['notes'];
        $flags = $g['flags'];

        // Kims lagertal (før reservationstillæg). Ugyldig tekst i en lokationscelle ændrer aldrig lokationen.
        // Negative tal (spec 2026-10-26-kims-upload-opfoelgning-review, punkt 1) tæller som 0; første tekst pr. lokation vinder.
        $invalid = [];$numeric = [];$negative = [];
        foreach($g['cells'] as $c) {
            foreach((array)$c['invalid'] as $k => $raw) if(!isset($invalid[$k])) $invalid[$k] = (string)$raw;
            foreach((array)($c['negative'] ?? []) as $k => $raw) if(!isset($negative[$k])) $negative[$k] = (string)$raw;
            foreach((array)$c['values'] as $k => $v) if(!in_array($k, (array)$c['blank'], true)) $numeric[$k] = true;
        }
        foreach(array_keys($invalid) as $k) unset($src[$k], $negative[$k]); // En ugyldig lokation bruges slet ikke.
        // Ugyldig "Lagerantal i alt" i blot én af de flettede rækker gør gruppens total ugyldig: ingen delsum skrives eller
        // bruges som kontroltal. I tilstand T kommer stock_loc_* kun fra totalen og fjernes også. Nøglerne når aldrig changes.
        $totalInvalid = isset($src['stock_file_total_invalid']) ? (string)$src['stock_file_total_invalid'] : null;
        $totalNegative = isset($src['stock_file_total_negative']) ? (string)$src['stock_file_total_negative'] : null;
        unset($src['stock_file_total_invalid'], $src['stock_file_total_negative']);
        if($totalInvalid !== null) {
            $totalNegative = null; // Totalen bruges slet ikke.
            unset($src['stock_file_total'], $src['stock_quantity']);
            if($stockMode === 'total') foreach(array_keys($src) as $k) if(str_starts_with((string)$k, 'stock_loc_')) unset($src[$k]);
        }
        $stockFile = ['mode' => $stockMode, 'locations' => [], 'blank' => [], 'invalid' => $invalid, 'total' => isset($src['stock_file_total']) ? (int)$src['stock_file_total'] : null, 'total_invalid' => $totalInvalid, 'sum' => 0, 'negative' => $negative, 'total_negative' => $totalNegative];
        if($stockMode === 'locations') {
            foreach($locKeys as $k) {
                if(array_key_exists($k, $src)) $stockFile['locations'][$k] = (int)$src[$k];
                if(!isset($numeric[$k]) && !isset($invalid[$k])) $stockFile['blank'][] = $k;
            }
        } elseif($stockMode === 'total') {
            foreach($src as $k => $v) if(str_starts_with((string)$k, 'stock_loc_')) $stockFile['locations'][$k] = (int)$v;
            if($stockFile['total'] === null && isset($src['stock_quantity'])) $stockFile['total'] = (int)$src['stock_quantity'];
        }
        $stockFile['sum'] = $stockFile['locations'] ? array_sum($stockFile['locations']) : (int)($stockFile['total'] ?? 0);
        if($stockMode === 'none') $stockFile['sum'] = 0;

        $reserved = [];
        if($product) {
            foreach($product as $k => $v) {
                if(str_starts_with((string)$k, 'reserved_loc_') && (int)$v > 0) $reserved['stock_loc_'.substr((string)$k, 13)] = (int)$v;
            }
        }
        $mismatch = $stockMode === 'locations' && $stockFile['total'] !== null && $stockFile['locations'] && $stockFile['sum'] !== $stockFile['total'];

        // Arkivering (spec 2026-10-26, 2.2–2.3 og 2.6): kun når Kims lager i alt entydigt er 0, og kun reservationer på
        // lokationer, der får et tal fra filen. Forvalgt på et sikkert match (≥ 90 % uden flag) – men aldrig, når filen har
        // en negativ værdi for varen (opfølgning på review, punkt 1 (a)): så skal admin selv afkrydse.
        $hasNegative = $negative || $totalNegative !== null;
        $archive = null;
        $archivedDefault = [];
        if($product && hsg_supplier_stock_file_is_zero($stockFile)) {
            $active = array_values((array)($product['active_reservations'] ?? []));
            usort($active, static fn($a, $b) => [(string)($a['created_at'] ?? ''), (int)($a['id'] ?? 0)] <=> [(string)($b['created_at'] ?? ''), (int)($b['id'] ?? 0)]);
            $candidates = [];
            $outside = [];
            foreach($active as $r) {
                $key = 'stock_loc_'.(int)($r['location_id'] ?? 0);
                if(!array_key_exists($key, $stockFile['locations'])) { $outside[$key] = ($outside[$key] ?? 0) + 1; continue; }
                $candidates[] = [
                    'id' => (int)($r['id'] ?? 0),
                    'location' => $key,
                    'quantity' => (int)($r['quantity'] ?? 0),
                    'customer_name' => (string)($r['customer_name'] ?? ''),
                    'reference' => (string)($r['reference'] ?? ''),
                    'created_at' => (string)($r['created_at'] ?? ''),
                ];
            }
            foreach($outside as $key => $n) $notes[] = 'Bemærk: '.$n.' '.($n === 1 ? 'reservation' : 'reservationer').' på '.$locLabel($key).' arkiveres ikke, fordi lokationen ikke får et tal fra filen.';
            if($candidates) {
                $safeMatch = $pid > 0 && (int)$match['score'] >= 90 && !$flags && !$mismatch && !$hasNegative;
                $archive = ['default' => $safeMatch, 'quantity' => array_sum(array_column($candidates, 'quantity')), 'reservations' => $candidates];
                if($safeMatch) foreach($candidates as $c) $archivedDefault[$c['location']] = ($archivedDefault[$c['location']] ?? 0) + $c['quantity'];
            }
        }
        // Reserveret pr. lokation efter den forvalgte arkivering (bruges til tillægget, advarslen og T-bemærkningen).
        $reservedAfter = $reserved;
        foreach($archivedDefault as $k => $q) if(isset($reservedAfter[$k])) $reservedAfter[$k] = max(0, $reservedAfter[$k] - $q);

        if($stockMode === 'locations') {
            if(!$stockFile['locations']) $notes[] = 'Bemærk: Ingen tal i lokationskolonnerne – lageret ændres ikke.';
            foreach($invalid as $k => $raw) $notes[] = 'Bemærk: Ugyldig værdi „'.$raw.'“ for '.$locLabel($k).' – lokationen ændres ikke.';
            if($totalInvalid !== null) $notes[] = 'Bemærk: Ugyldig værdi „'.$totalInvalid.'“ for lagerantal i alt – bruges ikke som kontroltal.';
            if($mismatch) {
                $notes[] = 'Bemærk: Summen af lokationskolonnerne ('.$stockFile['sum'].') passer ikke med lagerantal i alt ('.$stockFile['total'].'). Tjek kolonnevalget og rækken i Kims fil. Rækken er ikke valgt på forhånd.';
                $flags[] = 'stock_mismatch';
            }
        } elseif($stockMode === 'total' && $totalInvalid !== null) {
            $notes[] = 'Bemærk: Ugyldig værdi „'.$totalInvalid.'“ for lagerantal i alt – lageret ændres ikke.';
        } elseif($stockMode === 'total' && $product && $fallbackLid !== null && isset($src['stock_loc_'.$fallbackLid])) {
            $fallbackKey = 'stock_loc_'.$fallbackLid;
            $other = 0;
            foreach($product as $k => $v) if(str_starts_with((string)$k, 'stock_loc_') && $k !== $fallbackKey) $other += (int)$v;
            if($other > 0) {
                $written = (int)$src[$fallbackKey] + ($addReserved ? (int)($reservedAfter[$fallbackKey] ?? 0) : 0);
                $notes[] = 'Bemærk: Lagerantal i alt ('.(int)$src[$fallbackKey].') skrives på '.$locLabel($fallbackKey).'. Produktet har også '.$other.' stk. på andre lokationer, så lageret i alt bliver '.($written + $other).'.';
            }
        }
        // Negative tal skrives som 0, men admin skal kunne se dem (opfølgning på review, punkt 1).
        foreach($negative as $k => $raw) $notes[] = 'Bemærk: Negativ værdi „'.$raw.'“ for '.$locLabel($k).' behandles som 0.';
        if($totalNegative !== null) $notes[] = 'Bemærk: Negativ værdi „'.$totalNegative.'“ for lagerantal i alt behandles som 0.';
        if($archive !== null && $hasNegative) $notes[] = 'Bemærk: Arkiveringen er ikke valgt på forhånd, fordi Kims fil har en negativ værdi for varen.';
        if($product && !$addReserved) {
            foreach($stockFile['locations'] as $k => $fileQty) {
                $r = (int)($reservedAfter[$k] ?? 0);
                if($r > 0 && $fileQty < $r) $notes[] = 'Bemærk: '.$locLabel($k).' får '.$fileQty.' fysisk efter import, men '.$r.' er reserveret. Disponibelt bliver −'.($r - $fileQty).', varen kan forsvinde fra Lagerstatus, og reservationen kan ikke markeres som solgt.';
            }
        }

        $changes = [];
        if($product) {
            $productForChanges = $product;
            foreach($archivedDefault as $k => $q) {
                $rk = 'reserved_loc_'.substr($k, 10);
                $productForChanges[$rk] = max(0, (int)($product[$rk] ?? 0) - $q);
            }
            $changes = hsg_supplier_changes_for_product($src, $productForChanges, ['add_reserved' => $addReserved, 'stock_only' => $stockOnly]);
            foreach($archivedDefault as $k => $q) if($q > 0 && isset($changes[$k])) $changes[$k]['archived'] = $q;
        }
        sort($g['rows']);
        $rowLabel = count($g['rows']) > 1 ? '#' . implode(', #', $g['rows']) . ' (Flettet)' : '#' . $g['rows'][0];

        $totalStock = (int)($src['stock_quantity'] ?? 0);
        foreach ($src as $k => $v) {
            if (str_starts_with((string)$k, 'stock_loc_')) {
                $totalStock += (int)$v;
            }
        }
        // "Kun lagerantal" opretter aldrig nye produkter: en vare uden match springes over.
        $skipped = $stockOnly && $pid === 0;
        if($skipped) $notes[] = 'Bemærk: Springes over – varen findes ikke i HSG, og Kun lagerantal opretter ikke nye produkter. Vælg et HSG-produkt manuelt, hvis varen findes under et andet varenummer.';
        $isCreateNew = (!$stockOnly && $pid === 0 && $totalStock > 0);
        $flags = array_values(array_unique($flags));

        $items[] = [
            'row' => $g['rows'][0],
            'row_label' => $rowLabel,
            'source' => $src,
            'match' => $match,
            'changes' => $changes,
            'total_stock' => $totalStock,
            'is_create_new' => $isCreateNew,
            'merged_skus' => $g['merged_skus'],
            'notes' => array_values(array_unique($notes)),
            'flags' => $flags,
            'stock_file' => $stockFile,
            'reserved' => $reserved,
            'skipped' => $skipped,
            'archive' => $archive,
            'selected' => !$flags && (($pid > 0 && $match['score'] >= 90 && (count($changes) > 0 || !empty($archive['default']))) || $isCreateNew)
        ];
    }
    return $items;
}

/** Aktive lokationer i rækkefølgen sort_order, id: [id => navn]. Den første bruges, når kun "Lagerantal i alt" er valgt. */
function hsg_supplier_locations(PDO $pdo): array {
    $out=[];
    foreach($pdo->query('SELECT id,name FROM lager_locations WHERE active=1 ORDER BY sort_order ASC, id ASC')->fetchAll(PDO::FETCH_ASSOC) as $r)$out[(int)$r['id']]=(string)$r['name'];
    return $out;
}

/**
 * HSG-produkter med fysisk lager (stock_loc_<id>) og aktive reservationer (reserved_loc_<id>) pr. aktiv lokation samt
 * de aktive reservationer enkeltvis (active_reservations: [{id, location_id, quantity, customer_name, reference,
 * created_at}], sorteret efter oprettelse og id).
 */
function hsg_supplier_products_with_stock(PDO $pdo, array $locationIds): array {
    $products=$pdo->query('SELECT p.*,b.name brand_name, COALESCE((SELECT SUM(s.quantity) FROM lager_stock s JOIN lager_locations l ON l.id=s.location_id WHERE s.product_id=p.id AND l.active=1), 0) stock_quantity FROM lager_products p LEFT JOIN lager_brands b ON b.id=p.brand_id ORDER BY p.name')->fetchAll(PDO::FETCH_ASSOC);
    $stock=[];
    foreach($pdo->query('SELECT product_id,location_id,quantity FROM lager_stock')->fetchAll(PDO::FETCH_ASSOC) as $r)$stock[(int)$r['product_id']][(int)$r['location_id']]=(int)$r['quantity'];
    $reserved=[];
    foreach($pdo->query("SELECT product_id,location_id,SUM(quantity) qty FROM lager_reservations WHERE status='reserved' GROUP BY product_id,location_id")->fetchAll(PDO::FETCH_ASSOC) as $r)$reserved[(int)$r['product_id']][(int)$r['location_id']]=(int)$r['qty'];
    $active=[];
    foreach($pdo->query("SELECT id,product_id,location_id,quantity,customer_name,reference,created_at FROM lager_reservations WHERE status='reserved' ORDER BY created_at,id")->fetchAll(PDO::FETCH_ASSOC) as $r){
        $active[(int)$r['product_id']][]=['id'=>(int)$r['id'],'location_id'=>(int)$r['location_id'],'quantity'=>(int)$r['quantity'],'customer_name'=>(string)($r['customer_name']??''),'reference'=>(string)($r['reference']??''),'created_at'=>(string)$r['created_at']];
    }
    foreach($products as &$p){
        $pid=(int)$p['id'];
        foreach($locationIds as $lid){
            $lid=(int)$lid;
            $p['stock_loc_'.$lid]=(int)($stock[$pid][$lid]??0);
            $p['reserved_loc_'.$lid]=(int)($reserved[$pid][$lid]??0);
        }
        $p['active_reservations']=$active[$pid]??[];
    }
    unset($p);
    return $products;
}

/** Gemt reservationsvalg: 'add' (Kims tal er efter reservationer – læg dem oven i, standard) eller 'none'. */
function hsg_supplier_reservation_mode(PDO $pdo): string {
    $mode=trim((string)setting_get($pdo,'supplier_import_reservation_mode','add'));
    return in_array($mode,['add','none'],true)?$mode:'add';
}

/** Gemt valg for, hvad filen opdaterer: 'full' (produktdata og lager, standard) eller 'stock_only' (kun lagerantal). */
function hsg_supplier_import_scope(PDO $pdo): string {
    $scope=trim((string)setting_get($pdo,'supplier_import_scope','full'));
    return $scope==='stock_only'?'stock_only':'full';
}

function hsg_supplier_allowed_fields(array $locationIds): array {
    $allowed=array_keys(hsg_supplier_aliases());
    foreach($locationIds as $lid)$allowed[]='stock_loc_'.(int)$lid;
    return $allowed;
}

function hsg_supplier_merge_options(array $locations, string $reservationMode, string $importScope = 'full'): array {
    return [
        'fallback_location_id'=>$locations?(int)array_key_first($locations):null,
        'location_names'=>$locations,
        'add_reserved'=>$reservationMode==='add',
        'stock_only'=>$importScope==='stock_only',
    ];
}

/** $importScope: 'full' eller 'stock_only'. null (eller en ukendt værdi) ⇒ det gemte valg. */
function hsg_supplier_prepare_preview(PDO $pdo,array $sheets,string $filename,?int $brandId=null,?string $importScope=null): array {
    $bestSheet=null;$bestHeader=null;
    foreach($sheets as $sheet=>$rows){try{$h=hsg_supplier_detect_header($rows);if($bestHeader===null||$h['score']>$bestHeader['score']){$bestSheet=$sheet;$bestHeader=$h;}}catch(Throwable $e){}}
    if($bestHeader===null||$bestSheet===null)throw new RuntimeException('Kunne ikke finde en tabel med produkter/priser i nogen af arkene.');
    $locations=hsg_supplier_locations($pdo);
    $products=hsg_supplier_products_with_stock($pdo,array_keys($locations));
    $rows=$sheets[$bestSheet];$start=$bestHeader['row']+1;
    $headers=(array)$bestHeader['headers'];
    $savedColMap=hsg_supplier_saved_columns($pdo);
    $colMap=hsg_supplier_suggest_columns($headers,$savedColMap,hsg_supplier_allowed_fields(array_keys($locations)));
    $rejected=hsg_supplier_rejected_saved_columns($headers,$savedColMap);
    $remembered=[];
    foreach(hsg_supplier_header_keys($headers) as $ci=>$key){
        if($key!==''&&array_key_exists($key,$savedColMap)&&trim((string)$savedColMap[$key])===($colMap[$ci]??null))$remembered[]=$ci;
    }
    $fieldMap=[];
    foreach($colMap as $ci=>$field){if($field!==''){$fieldMap[$field]=(int)$ci;}}
    $rawRows=array_values(array_slice($rows,$start,null,true));
    $reservationMode=hsg_supplier_reservation_mode($pdo);
    if(!in_array($importScope,['full','stock_only'],true))$importScope=hsg_supplier_import_scope($pdo);
    $items=hsg_supplier_merge_raw_items($rawRows, $fieldMap, $products, $brandId, $start, $pdo, hsg_supplier_merge_options($locations,$reservationMode,$importScope));

    return [
        'format'=>2,
        'filename'=>$filename,
        'sheet'=>$bestSheet,
        'header_row'=>$bestHeader['row']+1,
        'headers'=>$headers,
        'mapping'=>$fieldMap,
        'col_mapping'=>$colMap,
        'col_remembered'=>$remembered,
        'col_rejected'=>$rejected,
        'reservation_mode'=>$reservationMode,
        'import_scope'=>$importScope,
        'raw_rows'=>$rawRows,
        'items'=>$items,
        'created_at'=>date('c'),
        'brand_id'=>$brandId
    ];
}

/**
 * Genberegner previewet med et (manuelt) kolonnevalg og evt. et nyt reservationsvalg eller et nyt valg af, hvad filen
 * opdaterer. En beløbskolonne valgt som lager sættes til "Ignorer" (K4) og står i col_rejected_manual, så siden kan vise
 * en advarsel. $importScope null ⇒ previewets import_scope, ellers det gemte valg.
 */
function hsg_supplier_recalculate_preview(PDO $pdo, array $preview, array $customColMap, ?string $reservationMode = null, ?string $importScope = null): array {
    $headers = (array)($preview['headers'] ?? []);
    $colMap = [];
    $fieldMap = [];
    $manualRejected = [];
    foreach($customColMap as $ci => $field) {
        $ci = (int)$ci;
        $field = trim((string)$field);
        if($field !== '' && hsg_supplier_is_stock_field($field) && hsg_supplier_header_is_money((string)($headers[$ci] ?? ''))) {
            $manualRejected[$ci] = $field;
            $field = '';
        }
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
    if($reservationMode === null || !in_array($reservationMode, ['add', 'none'], true)) {
        $reservationMode = in_array((string)($preview['reservation_mode'] ?? ''), ['add', 'none'], true) ? (string)$preview['reservation_mode'] : hsg_supplier_reservation_mode($pdo);
    }
    if($importScope === null || !in_array($importScope, ['full', 'stock_only'], true)) {
        $importScope = in_array((string)($preview['import_scope'] ?? ''), ['full', 'stock_only'], true) ? (string)$preview['import_scope'] : hsg_supplier_import_scope($pdo);
    }
    $locations = hsg_supplier_locations($pdo);
    $products = hsg_supplier_products_with_stock($pdo, array_keys($locations));

    $brandId = !empty($preview['brand_id']) ? (int)$preview['brand_id'] : null;
    $rawRows = (array)($preview['raw_rows'] ?? []);
    $start = (int)($preview['header_row'] ?? 1);
    $items = hsg_supplier_merge_raw_items($rawRows, $fieldMap, $products, $brandId, $start, $pdo, hsg_supplier_merge_options($locations, $reservationMode, $importScope));

    $preview['format'] = 2;
    $preview['reservation_mode'] = $reservationMode;
    $preview['import_scope'] = $importScope;
    $preview['mapping'] = $fieldMap;
    $preview['col_mapping'] = $colMap;
    $preview['col_remembered'] = [];
    $preview['col_rejected'] = [];
    $preview['col_rejected_manual'] = $manualRejected;
    $preview['items'] = $items;
    return $preview;
}

function hsg_supplier_preview_dir(): string {$d=__DIR__.'/../storage/import-previews';if(!is_dir($d)&&!@mkdir($d,0770,true)&&!is_dir($d))throw new RuntimeException('Kunne ikke oprette preview-mappe.');return $d;}
/** Et preview-token er 40 små hex-tegn (bin2hex(random_bytes(20))). Alt andet afvises, før der bygges en filsti. */
function hsg_supplier_preview_token_valid(string $token): bool {return preg_match('/^[a-f0-9]{40}\z/',$token)===1;}
function hsg_supplier_preview_save(array $preview, ?string $existingToken = null): string {
    $token = ($existingToken !== null && hsg_supplier_preview_token_valid($existingToken)) ? $existingToken : bin2hex(random_bytes(20));
    $path = hsg_supplier_preview_dir().'/'.$token.'.json';
    file_put_contents($path, json_encode($preview, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR), LOCK_EX);
    return $token;
}
function hsg_supplier_preview_load(string $token): array {if(!hsg_supplier_preview_token_valid($token))throw new RuntimeException('Ugyldigt preview-token.');$path=hsg_supplier_preview_dir().'/'.$token.'.json';if(!is_file($path))throw new RuntimeException('Previewet er udløbet eller findes ikke.');$data=json_decode((string)file_get_contents($path),true,512,JSON_THROW_ON_ERROR);return is_array($data)?$data:[];}
function hsg_supplier_preview_delete(string $token): void {if(hsg_supplier_preview_token_valid($token))@unlink(hsg_supplier_preview_dir().'/'.$token.'.json');}

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

/**
 * Ændringer for et eksisterende produkt. Lager pr. lokation: new = Kims tal (+ aktive reservationer på lokationen,
 * hvis $options['add_reserved']). Indgangen har også 'file' (Kims tal) og 'reserved' (tillægget, kun når > 0).
 * stock_file_total (Kims kontroltal) er aldrig en ændring. $options['stock_only'] ("Kun lagerantal"): kun stock_loc_*
 * og stock_quantity.
 */
function hsg_supplier_changes_for_product(array $src,array $product,array $options=[]): array {
    $addReserved=!empty($options['add_reserved']);
    $stockOnly=!empty($options['stock_only']);
    $changes=[];
    $updateFields = hsg_supplier_update_fields();
    foreach($src as $k=>$v){
        if(str_starts_with((string)$k,'stock_loc_') && !in_array($k,$updateFields,true)){
            $updateFields[]=$k;
        }
    }
    foreach($updateFields as $field){
        if(!array_key_exists($field,$src))continue;$new=$src[$field];if($new===null||trim((string)$new)==='')continue;$old=$product[$field]??null;
        $isStockLoc = str_starts_with($field,'stock_loc_');
        $isStockField = ($field === 'stock_quantity' || $isStockLoc);
        if($stockOnly && !$isStockField) continue;
        if(!$isStockField && $old !== null && trim((string)$old) !== '') {
            continue;
        }
        $fileQty=0;$reservedQty=0;
        if($isStockLoc){
            $fileQty=(int)$new;
            $reservedQty=$addReserved?max(0,(int)($product['reserved_loc_'.substr($field,10)]??0)):0;
            $new=$fileQty+$reservedQty;
        }
        $same=in_array($field,['wholesale_price','retail_price','abv','bottle_size_cl'],true)
            ?($old!==null&&trim((string)$old)!==''&&abs((float)$old-(float)$new)<0.001)
            :($isStockField
                ?($old!==null&&trim((string)$old)!==''&&(int)$old===(int)$new)
                :hsg_supplier_norm((string)$old)===hsg_supplier_norm((string)$new));
        if($same)continue;
        $change=['old'=>$old,'new'=>$new];
        if($isStockLoc){$change['file']=$fileQty;if($reservedQty>0)$change['reserved']=$reservedQty;}
        $changes[$field]=$change;
    }
    return $changes;
}

/**
 * Opdaterer et eksisterende produkt. Lager og aktive reservationer læses igen her (inde i transaktionen), så det
 * skrevne tal bygger på de aktuelle reservationer. $options['add_reserved']: læg reservationerne oven i Kims tal.
 * $options['stock_only']: kun lager ændres ("Kun lagerantal"), aldrig produktfelterne.
 * $options['archived_by_location'] ([lokations-id ⇒ stk.]): reservationer, der netop er arkiveret i samme "Anvend".
 * Bruges kun til lagerbevægelsens reference og til nøglen 'archived' i ændringen – de arkiverede reservationer tæller
 * allerede ikke med, når reservationerne læses igen.
 * "Lagerantal i alt" skrives kun på første lokation, hvis kilden ikke har nogen stock_loc_*-nøgler.
 */
function hsg_supplier_apply_product(PDO $pdo,int $productId,array $src,array $options=[]): array {
    $st=$pdo->prepare('SELECT p.*, COALESCE((SELECT SUM(s.quantity) FROM lager_stock s JOIN lager_locations l ON l.id=s.location_id WHERE s.product_id=p.id AND l.active=1), 0) stock_quantity FROM lager_products p WHERE p.id=?');
    $st->execute([$productId]);$product=$st->fetch(PDO::FETCH_ASSOC);
    if(!$product)throw new RuntimeException('Det valgte HSG-produkt findes ikke.');
    $locs=$pdo->query('SELECT id FROM lager_locations WHERE active=1 ORDER BY sort_order ASC, id ASC')->fetchAll(PDO::FETCH_COLUMN);
    $stLoc=$pdo->prepare('SELECT quantity FROM lager_stock WHERE product_id=? AND location_id=?');
    $stRes=$pdo->prepare("SELECT location_id, COALESCE(SUM(quantity),0) qty FROM lager_reservations WHERE product_id=? AND status='reserved' GROUP BY location_id");
    $stRes->execute([$productId]);
    $reservedByLoc=[];
    foreach($stRes->fetchAll(PDO::FETCH_ASSOC) as $r)$reservedByLoc[(int)$r['location_id']]=(int)$r['qty'];
    foreach($locs as $lid){
        $stLoc->execute([$productId,(int)$lid]);
        $product['stock_loc_'.$lid] = (int)($stLoc->fetchColumn()?:0);
        $product['reserved_loc_'.$lid] = (int)($reservedByLoc[(int)$lid]??0);
    }
    $archivedByLoc=[];
    foreach((array)($options['archived_by_location']??[]) as $lid=>$qty){if((int)$lid>0&&(int)$qty>0)$archivedByLoc[(int)$lid]=(int)$qty;}
    $changes=hsg_supplier_changes_for_product($src,$product,['add_reserved'=>!empty($options['add_reserved']),'stock_only'=>!empty($options['stock_only'])]);if(!$changes)return [];
    $sets=[];$params=[];
    foreach($changes as $field=>$change){
        if($field==='stock_quantity' || str_starts_with($field,'stock_loc_')) continue;
        $sets[]="$field=?";$params[]=$change['new'];
    }
    if($sets){
        $params[]=$productId;
        $pdo->prepare('UPDATE lager_products SET '.implode(',',$sets).' WHERE id=?')->execute($params);
    }
    // Lager pr. lokation (reference viser reservationstillægget og de arkiverede stk., når de er > 0)
    foreach($changes as $field=>$change){
        if(str_starts_with($field,'stock_loc_')){
            $locationId=(int)substr($field,10);
            if($locationId>0){
                $newQty=(int)$change['new'];
                $reservedQty=(int)($change['reserved']??0);
                $archivedQty=(int)($archivedByLoc[$locationId]??0);
                if($archivedQty>0)$changes[$field]['archived']=$archivedQty;
                $refParts=[];
                if($reservedQty>0)$refParts[]='+'.$reservedQty.' reserveret';
                if($archivedQty>0)$refParts[]=$archivedQty.' arkiveret';
                $reference='Kims masterfil opdatering'.($refParts?' ('.implode(', ',$refParts).')':'');
                $stLocForUpdate=$pdo->prepare('SELECT quantity FROM lager_stock WHERE product_id=? AND location_id=? FOR UPDATE');
                $stLocForUpdate->execute([$productId,$locationId]);
                $oldQty=(int)($stLocForUpdate->fetchColumn()?:0);
                $changeQty=$newQty-$oldQty;
                $pdo->prepare('INSERT INTO lager_stock(product_id,location_id,quantity) VALUES(?,?,?) ON DUPLICATE KEY UPDATE quantity=VALUES(quantity)')->execute([$productId,$locationId,$newQty]);
                $pdo->prepare('INSERT INTO lager_stock_movements(product_id,location_id,change_qty,balance_after,movement_type,reference,created_by,created_by_admin) VALUES(?,?,?,?,?,?,?,?)')
                    ->execute([$productId,$locationId,$changeQty,$newQty,'import',$reference,null,current_admin_id()]);
            }
        }
    }
    // Ældre kilde med kun stock_quantity: skrives på første lokation – men kun når kilden ikke har nogen stock_loc_*-nøgler.
    $srcHasLocations=false;
    foreach(array_keys($src) as $sk){
        if(str_starts_with((string)$sk,'stock_loc_')){$srcHasLocations=true;break;}
    }
    if(isset($changes['stock_quantity']) && !$srcHasLocations){
        $newQty=(int)$changes['stock_quantity']['new'];
        $locationId=(int)($locs[0]??0);
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
    hsg_sync_product_stock_status($pdo,$productId);
    return $changes;
}

/**
 * Arkiverer reservationer, fordi Kims lager i alt for varen er 0 (spec 2026-10-26-kims-upload-kun-lagerantal, 2.4 og 2.7).
 * Kun id'er, der stadig har status 'reserved', hører til $productId og ligger på en af $locationIds, arkiveres.
 * Ugyldige, inaktive og fremmede id'er springes over uden fejl. Rækkerne låses (FOR UPDATE), og kun status ændres:
 * ingen lagerændring, ingen lagerbevægelse og ingen audit (siden logger). Kaldes inde i "Anvend"s transaktion.
 * Kender kolonnen ikke status 'archived' (migrationen er ikke kørt), kastes en RuntimeException, så intet gemmes som ''.
 * Returnerer de arkiverede rækker: [{id, product_id, location_id, quantity, customer_name, reference}].
 */
function hsg_supplier_archive_reservations(PDO $pdo, int $productId, array $reservationIds, array $locationIds): array {
    $positive=static fn(array $values): array => array_values(array_unique(array_filter(array_map('intval',$values),static fn(int $v): bool => $v>0)));
    $ids=$positive($reservationIds);$lids=$positive($locationIds);
    if($productId<=0||!$ids||!$lids)return [];
    $stType=$pdo->prepare('SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
    $stType->execute(['lager_reservations','status']);
    if(!str_contains(strtolower((string)$stType->fetchColumn()),"'archived'"))throw new RuntimeException('Databasen kan ikke gemme status „Arkiveret“ endnu. Genindlæs siden, så opdateringen kan køre, og prøv igen.');
    $sel=$pdo->prepare('SELECT id,product_id,location_id,quantity,customer_name,reference FROM lager_reservations WHERE id IN ('.implode(',',array_fill(0,count($ids),'?')).') AND product_id=? AND location_id IN ('.implode(',',array_fill(0,count($lids),'?')).") AND status='reserved' ORDER BY created_at,id FOR UPDATE");
    $sel->execute(array_merge($ids,[$productId],$lids));
    $upd=$pdo->prepare("UPDATE lager_reservations SET status='archived' WHERE id=? AND status='reserved'");
    $archived=[];
    foreach($sel->fetchAll(PDO::FETCH_ASSOC) as $r){
        $upd->execute([(int)$r['id']]);
        if($upd->rowCount()!==1)continue;
        $archived[]=[
            'id'=>(int)$r['id'],
            'product_id'=>(int)$r['product_id'],
            'location_id'=>(int)$r['location_id'],
            'quantity'=>(int)$r['quantity'],
            'customer_name'=>$r['customer_name']===null?null:(string)$r['customer_name'],
            'reference'=>$r['reference']===null?null:(string)$r['reference'],
        ];
    }
    return $archived;
}

function hsg_supplier_create_product(PDO $pdo, array $src): int {
    $sku = trim((string)($src['sku'] ?? ''));
    if ($sku === '') {
        $caskStr = preg_replace('/[^0-9A-Z]/i', '', (string)($src['cask_number'] ?? ''));
        $sku = 'KIM-' . ($caskStr !== '' ? $caskStr : date('ymd') . '-' . substr(bin2hex(random_bytes(3)), 0, 5));
    }

    $name = trim((string)($src['name'] ?? ''));
    if ($name === '') {
        $name = 'Nyt produkt ' . (!empty($src['cask_number']) ? 'Fad #' . $src['cask_number'] : $sku);
    }

    $brandId = null;
    $brandName = trim((string)($src['brand_name'] ?? ''));
    if ($brandName !== '') {
        $stB = $pdo->prepare('SELECT id FROM lager_brands WHERE name=? LIMIT 1');
        $stB->execute([$brandName]);
        $brandId = (int)($stB->fetchColumn() ?: 0) ?: null;
        if (!$brandId) {
            $pdo->prepare('INSERT IGNORE INTO lager_brands (name, active, sort_order) VALUES (?, 1, 100)')->execute([$brandName]);
            $brandId = (int)$pdo->lastInsertId() ?: null;
        }
    }

    $wholesale = isset($src['wholesale_price']) ? (float)$src['wholesale_price'] : null;
    $retail = isset($src['retail_price']) ? (float)$src['retail_price'] : null;
    $abv = isset($src['abv']) ? (float)$src['abv'] : null;
    $cl = isset($src['bottle_size_cl']) ? (float)$src['bottle_size_cl'] : 70.0;
    $vintage = isset($src['vintage_year']) ? (int)$src['vintage_year'] : null;

    $stIns = $pdo->prepare('INSERT INTO lager_products
        (sku, name, call_name, brand_id, category, distillery, country, age_text, vintage_year, abv, bottle_size_cl, cask_type, cask_number, bottle_count, wholesale_price, retail_price, is_new, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "active")');

    $stIns->execute([
        $sku,
        $name,
        $src['call_name'] ?? null,
        $brandId,
        $src['category'] ?? null,
        $src['distillery'] ?? null,
        $src['country'] ?? null,
        $src['age_text'] ?? null,
        $vintage,
        $abv,
        $cl,
        $src['cask_type'] ?? null,
        $src['cask_number'] ?? null,
        $src['bottle_count'] ?? null,
        $wholesale,
        $retail,
        1
    ]);

    $newPid = (int)$pdo->lastInsertId();

    // Insert stock quantities for mapped locations
    $locs = $pdo->query('SELECT id FROM lager_locations WHERE active=1 ORDER BY sort_order, id')->fetchAll(PDO::FETCH_COLUMN);

    foreach ($locs as $lid) {
        $locKey = 'stock_loc_' . $lid;
        if (isset($src[$locKey])) {
            $qty = max(0, (int)$src[$locKey]);
            if ($qty > 0) {
                $pdo->prepare('INSERT INTO lager_stock (product_id, location_id, quantity) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE quantity=VALUES(quantity)')
                    ->execute([$newPid, $lid, $qty]);
                $pdo->prepare('INSERT INTO lager_stock_movements (product_id, location_id, change_qty, balance_after, movement_type, reference, created_by_admin) VALUES (?, ?, ?, ?, "import", "Oprettet via Kims uploadfil", ?)')
                    ->execute([$newPid, $lid, $qty, $qty, current_admin_id()]);
            }
        }
    }

    // Ældre kilde med kun stock_quantity: skrives på første lokation – men kun når kilden ikke har nogen stock_loc_*-nøgler.
    $srcHasLocations = false;
    foreach (array_keys($src) as $sk) {
        if (str_starts_with((string)$sk, 'stock_loc_')) { $srcHasLocations = true; break; }
    }
    if (!$srcHasLocations && isset($src['stock_quantity'])) {
        $genQty = max(0, (int)$src['stock_quantity']);
        if ($genQty > 0 && !empty($locs[0])) {
            $firstLid = (int)$locs[0];
            $pdo->prepare('INSERT INTO lager_stock (product_id, location_id, quantity) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE quantity=VALUES(quantity)')
                ->execute([$newPid, $firstLid, $genQty]);
            $pdo->prepare('INSERT INTO lager_stock_movements (product_id, location_id, change_qty, balance_after, movement_type, reference, created_by_admin) VALUES (?, ?, ?, ?, "import", "Oprettet via Kims uploadfil", ?)')
                ->execute([$newPid, $firstLid, $genQty, $genQty, current_admin_id()]);
        }
    }

    hsg_sync_product_stock_status($pdo, $newPid);
    return $newPid;
}


function hsg_supplier_remember_alias(PDO $pdo, string $supplierSku, int $productId, ?string $supplierName = null): void {
    $sku = trim($supplierSku);
    if ($sku === '' || $productId <= 0 || !db_table_exists($pdo, 'hsg_supplier_sku_aliases')) return;
    $normName = $supplierName ? hsg_supplier_norm($supplierName) : null;
    // Unik nøgle er (supplier_sku, product_id) – fjern ældre kobling til et andet produkt, så nyeste valg gælder.
    $pdo->prepare('DELETE FROM hsg_supplier_sku_aliases WHERE supplier_sku=? AND product_id<>?')->execute([$sku, $productId]);
    $st = $pdo->prepare('INSERT INTO hsg_supplier_sku_aliases (supplier_sku, supplier_name_norm, product_id) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE product_id=VALUES(product_id), supplier_name_norm=VALUES(supplier_name_norm)');
    $st->execute([$sku, $normName, $productId]);
}
