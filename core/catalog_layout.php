<?php
declare(strict_types=1);

function hsg_catalog_normalize_name(string $name): string {
    $name=strtolower(trim(str_replace(["’","´","`"],"'",$name)));
    $name=preg_replace('/[^a-z0-9]+/u',' ',$name)??$name;
    return trim(preg_replace('/\s+/',' ',$name)??$name);
}

function hsg_catalog_family(string $brand): string {
    $n=hsg_catalog_normalize_name($brand);
    if(str_contains($n,'samhain')||str_contains($n,'dalgety')||str_contains($n,'bridget')) return "Lady of the Glen";
    if(str_contains($n,'jane street')) return "Woodrow's of Edinburgh";
    if(str_contains($n,'uncharted')) return 'Uncharted Whisky';
    return $brand!==''?$brand:'Uden brand';
}

function hsg_catalog_family_display(string $family): string {
    $n=hsg_catalog_normalize_name($family);
    if(str_contains($n,'uncharted')) return 'Uncharted Whisky Limited';
    return $family;
}

function hsg_catalog_seed_logo_name(string $family): ?string {
    $n=hsg_catalog_normalize_name($family);
    return match(true){
        str_contains($n,'woodrow')=>'woodrows.jpg',
        str_contains($n,'fragrant')=>'fragrant-drops.jpg',
        str_contains($n,'edinburgh whisky')=>'edinburgh-whisky.jpg',
        str_contains($n,'lady of the glen')=>'lady-of-the-glen.jpg',
        str_contains($n,'uncharted')=>'uncharted-whisky.jpg',
        default=>null,
    };
}

function hsg_catalog_seed_asset_path(string $name): string {return __DIR__.'/../seed/catalog-layout/'.$name;}
function hsg_catalog_seed_asset_url(string $name): string {return 'seed/catalog-layout/'.rawurlencode($name);}

function hsg_catalog_logo_path(string $family,?string $dbLogo=null): ?string {
    if($dbLogo){$p=__DIR__.'/../'.ltrim($dbLogo,'/');if(is_file($p))return $p;}
    $seed=hsg_catalog_seed_logo_name($family);if($seed){$p=hsg_catalog_seed_asset_path($seed);if(is_file($p))return $p;}
    return null;
}

function hsg_catalog_logo_url(string $family,?string $dbLogo=null): ?string {
    if($dbLogo){$p=__DIR__.'/../'.ltrim($dbLogo,'/');if(is_file($p))return ltrim($dbLogo,'/');}
    $seed=hsg_catalog_seed_logo_name($family);return $seed?hsg_catalog_seed_asset_url($seed):null;
}

function hsg_catalog_hsg_logo_path(): ?string {$p=hsg_catalog_seed_asset_path('hsg-logo.jpg');return is_file($p)?$p:null;}
function hsg_catalog_hsg_logo_url(): string {return hsg_catalog_seed_asset_url('hsg-logo.jpg');}
function hsg_catalog_new_badge_path(): ?string {$p=hsg_catalog_seed_asset_path('nyhed.jpg');return is_file($p)?$p:null;}


/**
 * Prepare a bottle image specifically for catalogue presentation.
 * The original product image is never changed. A tightly cropped JPEG is
 * cached under uploads/catalog-crops so both web preview and PDF can reuse it.
 */
function hsg_catalog_prepare_image(?string $relativePath): array {
    $relativePath=trim((string)$relativePath);
    if($relativePath==='') return ['path'=>null,'web'=>null,'cropped'=>false];
    $source=__DIR__.'/../'.ltrim($relativePath,'/');
    if(!is_file($source)) return ['path'=>null,'web'=>null,'cropped'=>false];
    $fallback=['path'=>$source,'web'=>ltrim($relativePath,'/'),'cropped'=>false];
    if(!function_exists('imagecreatefromstring')||!function_exists('imagejpeg')) return $fallback;

    $cacheDir=__DIR__.'/../uploads/catalog-crops';
    if(!is_dir($cacheDir) && !@mkdir($cacheDir,0775,true) && !is_dir($cacheDir)) return $fallback;
    $fingerprint=hash('sha256',$relativePath.'|'.(string)@filesize($source).'|'.(string)@filemtime($source));
    $filename='catalog-'.substr($fingerprint,0,24).'.jpg';
    $target=$cacheDir.'/'.$filename;
    $web='uploads/catalog-crops/'.$filename;
    if(is_file($target) && @filesize($target)>1000) return ['path'=>$target,'web'=>$web,'cropped'=>true];

    $bytes=@file_get_contents($source);if($bytes===false||$bytes==='')return $fallback;
    $src=@imagecreatefromstring($bytes);if(!$src)return $fallback;
    $w=imagesx($src);$h=imagesy($src);if($w<40||$h<40){imagedestroy($src);return $fallback;}

    // Use the four corners as the background reference. Catalogue bottle
    // photography is normally on white/off-white, but this also tolerates a
    // slightly tinted background.
    $corners=[[0,0],[$w-1,0],[0,$h-1],[$w-1,$h-1]];$br=$bg=$bb=0;
    foreach($corners as [$cx,$cy]){$rgb=imagecolorat($src,$cx,$cy);$br+=($rgb>>16)&255;$bg+=($rgb>>8)&255;$bb+=$rgb&255;}
    $br/=4;$bg/=4;$bb/=4;
    $nearWhite=$br>225&&$bg>225&&$bb>225;$threshold=$nearWhite?34:58;
    $minX=$w;$minY=$h;$maxX=-1;$maxY=-1;$step=max(1,(int)floor(max($w,$h)/1100));
    for($y=0;$y<$h;$y+=$step){
        for($x=0;$x<$w;$x+=$step){
            $rgb=imagecolorat($src,$x,$y);$r=($rgb>>16)&255;$g=($rgb>>8)&255;$b=$rgb&255;
            $alpha=($rgb>>24)&0x7F;if($alpha>110)continue;
            $dist=abs($r-$br)+abs($g-$bg)+abs($b-$bb);
            // Dark/coloured bottle pixels are foreground. On white backgrounds,
            // very light pixels are background unless colour distance is clear.
            $foreground=$dist>$threshold;
            if($nearWhite && max($r,$g,$b)<238)$foreground=true;
            if($foreground){$minX=min($minX,$x);$minY=min($minY,$y);$maxX=max($maxX,$x);$maxY=max($maxY,$y);}
        }
    }
    if($maxX<$minX||$maxY<$minY){imagedestroy($src);return $fallback;}

    // Expand a little so bottle edges/corks are never clipped, but avoid the
    // large white margins from the source catalogue image.
    $boxW=$maxX-$minX+1;$boxH=$maxY-$minY+1;
    if($boxW<$w*.04||$boxH<$h*.12){imagedestroy($src);return $fallback;}
    $padX=max(2,(int)round($boxW*.025));$padY=max(2,(int)round($boxH*.018));
    $minX=max(0,$minX-$padX);$maxX=min($w-1,$maxX+$padX);$minY=max(0,$minY-$padY);$maxY=min($h-1,$maxY+$padY);
    $cropW=$maxX-$minX+1;$cropH=$maxY-$minY+1;

    // Keep enough resolution for PDF while avoiding oversized cached files.
    $maxOutW=1200;$maxOutH=1800;$scale=min(1.0,$maxOutW/$cropW,$maxOutH/$cropH);
    $outW=max(1,(int)round($cropW*$scale));$outH=max(1,(int)round($cropH*$scale));
    $canvas=imagecreatetruecolor($outW,$outH);$white=imagecolorallocate($canvas,255,255,255);imagefill($canvas,0,0,$white);
    imagecopyresampled($canvas,$src,0,0,$minX,$minY,$outW,$outH,$cropW,$cropH);
    $saved=@imagejpeg($canvas,$target,92);imagedestroy($canvas);imagedestroy($src);
    if(!$saved||!is_file($target))return $fallback;
    return ['path'=>$target,'web'=>$web,'cropped'=>true];
}
function hsg_catalog_image_path(?string $relativePath): ?string {return hsg_catalog_prepare_image($relativePath)['path'];}
function hsg_catalog_image_url(?string $relativePath): ?string {return hsg_catalog_prepare_image($relativePath)['web'];}


function hsg_catalog_seed_product_map(): array {
    static $map=null;if($map!==null)return $map;$map=[];
    $file=__DIR__.'/../seed/catalog-product-data.json';if(!is_file($file))return $map;
    $rows=json_decode((string)file_get_contents($file),true);if(!is_array($rows))return $map;
    foreach($rows as $row){foreach((array)($row['skus']??[]) as $sku){$sku=trim((string)$sku);if($sku!=='')$map[$sku]=$row;}}
    return $map;
}
function hsg_catalog_product_effective(array $p): array {
    $seed=hsg_catalog_seed_product_map()[trim((string)($p['sku']??''))]??[];
    foreach(['distillery','age_text','cask_type','bottle_count','retail_price'] as $field){
        $cur=$p[$field]??null;if(($cur===null||trim((string)$cur)==='') && array_key_exists($field,$seed) && $seed[$field]!==null && $seed[$field]!=='')$p[$field]=$seed[$field];
    }
    if(($p['abv']??null)===null && isset($seed['abv']) && $seed['abv']!==null)$p['abv']=$seed['abv'];
    $p['_catalog_seed_title']=trim((string)($seed['title']??''));return $p;
}
function hsg_catalog_product_title(array $p): string {
    $p=hsg_catalog_product_effective($p);$seed=trim((string)($p['_catalog_seed_title']??''));$title=$seed!==''?$seed:(string)($p['name']??'');
    $title=preg_replace('/\s+([,;%])/u','$1',$title)??$title;
    $title=preg_replace('/([,(])\s+/u','$1',$title)??$title;
    $title=preg_replace('/\s+([)])/u','$1',$title)??$title;
    $title=preg_replace('/(?<=\d),\s+(?=\d)/u',',',$title)??$title;
    return trim(preg_replace('/\s+/u',' ',$title)??$title);
}

function hsg_catalog_price_meta(string $price): array {
    if($price==='retail') return ['field'=>'retail_price','label'=>'Vejl. pris (inkl. moms)','short'=>'Vejl. pris'];
    return ['field'=>'wholesale_price','label'=>'Engros pris (ekskl. moms)','short'=>'Engros pris'];
}

function hsg_catalog_abv($v): string {
    if($v===null||$v==='')return 'N/A';
    return rtrim(rtrim(number_format((float)$v,2,',',''),'0'),',').' %';
}

function hsg_catalog_product_rows(array $p,string $priceField,string $priceLabel): array {
    $p=hsg_catalog_product_effective($p);
    $rows=[
        ['Destilleri',trim((string)($p['distillery']??''))?:'N/A'],
        ['Alc. %',hsg_catalog_abv($p['abv']??null)],
        ['Alder',trim((string)($p['age_text']??''))?:'N/A'],
    ];
    if(trim((string)($p['cask_number']??''))!=='')$rows[]=['Fadnummer','#'.ltrim(trim((string)$p['cask_number']),'#')];
    $rows[]=['Fadtype',trim((string)($p['cask_type']??''))?:'N/A'];
    $rows[]=['Antal flasker',($p['bottle_count']??null)!==null&&$p['bottle_count']!==''?(string)$p['bottle_count'].' stk.':'N/A'];
    $rows[]=[$priceLabel,money_dkk($p[$priceField]??null)];
    return $rows;
}
