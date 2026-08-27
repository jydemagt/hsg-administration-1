<?php
require __DIR__.'/auth.php';
require_module_enabled('catalog');require_capability('catalog.view');
require_once __DIR__.'/pdf_simple.php';require_once __DIR__.'/core/catalog_layout.php';

$price=(($_GET['price']??'retail')==='wholesale')?'wholesale':'retail';
$priceMeta=hsg_catalog_price_meta($price);$priceField=$priceMeta['field'];$priceLabel=$priceMeta['label'];

$brandRows=$pdo->query("SELECT b.id,b.name,b.description,b.logo_path,b.sort_order,pb.name parent_name FROM lager_brands b LEFT JOIN lager_brands pb ON pb.id=b.parent_id ORDER BY COALESCE(pb.sort_order,b.sort_order),COALESCE(pb.name,b.name),b.parent_id IS NOT NULL,b.sort_order,b.name")->fetchAll();
$brandMeta=[];foreach($brandRows as $b)$brandMeta[(string)$b['name']]=$b;

$rows=$pdo->query("SELECT p.*,b.name brand_name,b.description brand_description,b.logo_path brand_logo_path,b.sort_order brand_sort_order,pb.name parent_brand_name,pb.description parent_brand_description,pb.sort_order parent_sort_order,
 COALESCE(st.physical,0)-COALESCE(rs.reserved,0) available
 FROM lager_products p LEFT JOIN lager_brands b ON b.id=p.brand_id LEFT JOIN lager_brands pb ON pb.id=b.parent_id
 LEFT JOIN (SELECT product_id,SUM(quantity) physical FROM lager_stock GROUP BY product_id) st ON st.product_id=p.id
 LEFT JOIN (SELECT product_id,SUM(quantity) reserved FROM lager_reservations WHERE status='reserved' GROUP BY product_id) rs ON rs.product_id=p.id
 WHERE p.status='active' AND p.show_in_catalog=1 AND COALESCE(st.physical,0)-COALESCE(rs.reserved,0)>0
 ORDER BY COALESCE(pb.sort_order,b.sort_order,999),COALESCE(pb.name,b.name,'Uden brand'),b.sort_order,b.name,p.name")->fetchAll();

$families=[];
foreach($rows as $p){
    $brand=(string)($p['brand_name']?:'Uden brand');
    $parentBrand=$p['parent_brand_name']?(string)$p['parent_brand_name']:null;
    $family=hsg_catalog_family($brand,$parentBrand);
    $familySort=(int)($p['parent_sort_order']??$p['brand_sort_order']??999);
    if(!isset($families[$family]))$families[$family]=['sort'=>$familySort,'sections'=>[]];
    $families[$family]['sort']=min($families[$family]['sort'],$familySort);
    $families[$family]['sections'][$brand][]=$p;
}
uasort($families,static fn($a,$b)=>$a['sort']<=>$b['sort']);

function catalog_toc_page_count(array $families): int {
    if(!$families)return 1;$pages=1;$y=708;
    foreach($families as $family=>$f){
        $topGap=($y<690)?16:0;
        if($y-$topGap-18<52){$pages++;$y=748-$topGap;}$y-=$topGap+18;
        foreach($f['sections'] as $products){
            foreach($products as $_){
                if($y-14<52){$pages++;$y=748;}$y-=14;
            }
        }
    }
    return $pages;
}
$tocPages=catalog_toc_page_count($families);
$currentPage=2+$tocPages;$tocEntries=[];$pagePlan=[];
foreach($families as $family=>$f){
    $introPage=$currentPage++;$tocEntries[]=['type'=>'family','text'=>hsg_catalog_family_display($family),'page'=>$introPage];
    $familyMeta=$brandMeta[$family]??null;$familyDesc=hsg_catalog_get_family_desc($family,$f,$brandMeta);$familyLogo=(string)($familyMeta['logo_path']??'');
    $pagePlan[]=['type'=>'intro','family'=>$family,'display'=>hsg_catalog_family_display($family),'desc'=>$familyDesc,'logo'=>$familyLogo,'page'=>$introPage];
    foreach($f['sections'] as $section=>$products){
        foreach(array_chunk($products,2) as $chunk){
            $page=$currentPage++;foreach($chunk as $p){$tocEntries[]=['type'=>'product','text'=>(string)$p['name'],'page'=>$page];}
            $pagePlan[]=['type'=>'products','family'=>$family,'section'=>$section,'products'=>$chunk,'page'=>$page];
        }
    }
}

$pdf=new SimplePdf();$hsgLogo=hsg_catalog_hsg_logo_path();$newBadge=hsg_catalog_new_badge_path();

function pdf_image_fit(string $name,string $path,float $x,float $y,float $w,float $h): ?string {
    $info=@getimagesize($path);if(!$info||$info[0]<=0||$info[1]<=0)return null;
    $scale=min($w/$info[0],$h/$info[1]);$iw=$info[0]*$scale;$ih=$info[1]*$scale;$ix=$x+($w-$iw)/2;$iy=$y+($h-$ih)/2;
    return 'q '.$iw.' 0 0 '.$ih.' '.$ix.' '.$iy.' cm /'.$name.' Do Q';
}
function add_hsg_logo(SimplePdf $pdf,array &$ops,array &$images,?string $path): void {
    if(!$path)return;$images['HSG']=$path;$op=pdf_image_fit('HSG',$path,470,770,82,55);if($op)$ops[]=$op;
}
function add_page_no(SimplePdf $pdf,array &$ops,int $page): void {$ops[]=$pdf->textFont(548,22,8,(string)$page,'helvetica');}
function add_product_table(SimplePdf $pdf,array &$ops,array $rows,float $x,float $top,float $w=250): void {
    $labelW=145;$metrics=[];$totalH=0;
    foreach($rows as [$label,$value]){
        $valueLines=$pdf->wrap((string)$value,22);$labelLines=$pdf->wrap((string)$label,32);$lines=max(1,count($valueLines),count($labelLines));$rh=max(24,8+$lines*12);
        $metrics[]=['label'=>$labelLines,'value'=>$valueLines,'height'=>$rh];$totalH+=$rh;
    }
    if($totalH<=0)return;$bottom=$top-$totalH;$cursor=$top;
    // Draw all internal rules first and then one explicit outer frame. This
    // guarantees a continuous top, bottom, left and right border.
    $ops[]=$pdf->line($x+$labelW,$bottom,$x+$labelW,$top,.55);
    foreach($metrics as $idx=>$m){
        $rowBottom=$cursor-$m['height'];
        if($idx<count($metrics)-1)$ops[]=$pdf->line($x,$rowBottom,$x+$w,$rowBottom,.55);
        foreach(array_slice($m['label'],0,3) as $i=>$line)$ops[]=$pdf->textFont($x+7,$cursor-15-($i*11),8.6,$line,'helvetica-bold');
        foreach(array_slice($m['value'],0,3) as $i=>$line)$ops[]=$pdf->textFont($x+$labelW+7,$cursor-15-($i*11),9.2,$line,'helvetica');
        $cursor=$rowBottom;
    }
    // Redraw the full rectangle last, with a slightly heavier stroke, so the
    // lower/right edges can never disappear behind adjoining row rules.
    $ops[]='0.8 w';$ops[]=$pdf->rect($x,$bottom,$w,$totalH,false);
}
function add_product_slot(SimplePdf $pdf,array &$ops,array &$images,array $p,int $slot,string $priceField,string $priceLabel,?string $newBadge): void {
    $topSlot=$slot===0;$titleY=$topSlot?742:360;$imgX=$topSlot?328:48;$imgY=$topSlot?438:44;$imgW=214;$imgH=300;$tableX=$topSlot?42:300;$tableTop=$topSlot?706:322;
    $title=hsg_catalog_product_title($p);$titleLines=$pdf->wrap($title,44);foreach(array_slice($titleLines,0,2) as $ti=>$tl)$ops[]=$pdf->textFont($tableX,$titleY-($ti*15),13.3,$tl,'times');$tableTop-=max(0,count($titleLines)-1)*15;
    $rows=hsg_catalog_product_rows($p,$priceField,$priceLabel);add_product_table($pdf,$ops,$rows,$tableX,$tableTop,250);
    $path=hsg_catalog_image_path((string)($p['image_path']??''));
    if(($p['image_approval_status']??'')==='approved'&&$path&&is_file($path)&&(@getimagesize($path)[2]??0)===IMAGETYPE_JPEG){
        $name='P'.(int)$p['id'].'_'.$slot;$images[$name]=$path;$op=pdf_image_fit($name,$path,$imgX,$imgY,$imgW,$imgH);if($op)$ops[]=$op;
        if(!empty($p['is_new'])&&$newBadge){$b='NB'.(int)$p['id'].'_'.$slot;$images[$b]=$newBadge;$bo=pdf_image_fit($b,$newBadge,$imgX-6,$imgY+$imgH-54,58,58);if($bo)$ops[]=$bo;}
    }else{
        $ops[]=$pdf->setRgb(.95,.95,.95,true);$ops[]=$pdf->rect($imgX+30,$imgY+40,$imgW-60,$imgH-80,true);$ops[]=$pdf->setRgb(.45,.45,.45,true);$ops[]=$pdf->textFont($imgX+52,$imgY+($imgH/2),10,'Intet godkendt billede','helvetica');$ops[]=$pdf->setRgb(0,0,0,true);
    }
}

function pdf_text_centered(SimplePdf $pdf, float $y, float $size, string $text, string $font = 'helvetica', float $pageWidth = 595): string {
    $len = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    $factor = match(strtolower($font)) {
        'times-bold', 'helvetica-bold' => 0.56,
        'times' => 0.50,
        default => 0.52,
    };
    $w = $len * $size * $factor;
    $x = max(10.0, ($pageWidth - $w) / 2);
    return $pdf->textFont($x, $y, $size, $text, $font);
}

// Cover page
$ops=[];$images=[];
if($hsgLogo){$images['CoverLogo']=$hsgLogo;$op=pdf_image_fit('CoverLogo',$hsgLogo,180,535,235,150);if($op)$ops[]=$op;}
$ops[]=pdf_text_centered($pdf,418,34,'Whisky Katalog','helvetica');
$ops[]=pdf_text_centered($pdf,365,15,'Fra','helvetica');
$ops[]=pdf_text_centered($pdf,321,22,'HSG Whisky Aps','helvetica');
$ops[]=pdf_text_centered($pdf,139,9,'Opdateret','helvetica');
$ops[]=pdf_text_centered($pdf,115,10,date('d. m. Y'),'helvetica');
$pdf->addPage($ops,$images);

// TOC pages
$entries=$tocEntries;$entryIndex=0;
for($tocPage=0;$tocPage<$tocPages;$tocPage++){
    $pageNo=2+$tocPage;$ops=[];$images=[];add_hsg_logo($pdf,$ops,$images,$hsgLogo);$y=748;
    if($tocPage===0){$ops[]=$pdf->textFont(36,$y,17,'Indhold','helvetica-bold');$ops[]=$pdf->line(36,$y-3,90,$y-3,.8);$y-=40;}
    while($entryIndex<count($entries)){
        $e=$entries[$entryIndex];$isFamily=$e['type']==='family';
        $topGap=($isFamily && $y<690)?16:0;
        $step=$isFamily?18:14;
        if($y-$topGap-$step<52)break;
        $y-=$topGap;
        $size=$isFamily?12.5:8.8;$x=$isFamily?36:58;$font=$isFamily?'helvetica-bold':'helvetica';$text=(string)$e['text'];$page=(int)$e['page'];
        $ops[]=$pdf->textFont($x,$y,$size,$text,$font);
        $approx=min(430,$x+(function_exists('mb_strlen')?mb_strlen($text,'UTF-8'):strlen($text))*$size*.47+8);$ops[]=$pdf->dottedLine($approx,$y+2,539,.45);$ops[]=$pdf->textFont(543,$y,$size,(string)$page,$font);
        $pdf->addLink($pageNo,36.0,$y-3.0,558.0,$y+11.0,$page);
        $y-=$step;$entryIndex++;
    }
    add_page_no($pdf,$ops,$pageNo);$pdf->addPage($ops,$images);
}

// Brand intro and product pages
foreach($pagePlan as $plan){
    $ops=[];$images=[];add_hsg_logo($pdf,$ops,$images,$hsgLogo);$page=(int)$plan['page'];
    if($plan['type']==='intro'){
        $family=(string)$plan['family'];$ops[]=$pdf->textFont(36,775,18,(string)$plan['display'],'helvetica');
        $logo=hsg_catalog_logo_path($family,(string)($plan['logo']??''));
        if($logo){$images['Brand']=$logo;$op=pdf_image_fit('Brand',$logo,75,410,445,280);if($op)$ops[]=$op;}
        else{$ops[]=$pdf->textFont(82,555,34,(string)$plan['display'],'times-bold');}
        $desc=trim((string)$plan['desc']);$y=365;if($desc!=='')foreach(array_slice($pdf->wrap($desc,84),0,9) as $line){$ops[]=$pdf->textFont(36,$y,11,$line,'helvetica');$y-=18;}
    }else{
        $ops[]=$pdf->textFont(36,785,18,(string)$plan['section'],'helvetica');
        $products=$plan['products'];add_product_slot($pdf,$ops,$images,$products[0],0,$priceField,$priceLabel,$newBadge);
        if(isset($products[1])){$ops[]=$pdf->line(36,421,559,421,.55);add_product_slot($pdf,$ops,$images,$products[1],1,$priceField,$priceLabel,$newBadge);}
    }
    add_page_no($pdf,$ops,$page);$pdf->addPage($ops,$images);
}

if(!$rows){$pdf->addPage([$pdf->textFont(40,790,20,'HSG Whisky produktkatalog','helvetica-bold'),$pdf->textFont(40,755,11,'Ingen produkter med disponibelt lager er tilgængelige i kataloget.','helvetica')]);}
$pdf->output('HSG-Whisky-Katalog-'.($price==='retail'?'vejl-priser':'engrospriser').'-'.date('Y-m-d').'.pdf');
