<?php
declare(strict_types=1);
require_once __DIR__.'/core/ai.php';

function image_dir(): string { return __DIR__.'/uploads/products'; }
function image_web_path(string $filename): string { return 'uploads/products/'.$filename; }
function ensure_image_dir(): void {
    $dir=image_dir(); if(!is_dir($dir) && !mkdir($dir,0775,true) && !is_dir($dir)) throw new RuntimeException('Kunne ikke oprette billedmappen uploads/products.');
}
function image_is_present(?string $path): bool { return $path!==null && $path!=='' && is_file(__DIR__.'/'.$path); }

function host_is_public(string $host): bool {
    if($host==='' || in_array(strtolower($host),['localhost','localhost.localdomain'],true)) return false;
    $ips=gethostbynamel($host)?:[];
    if(!$ips && filter_var($host,FILTER_VALIDATE_IP)) $ips=[$host];
    if(!$ips) return false;
    foreach($ips as $ip){ if(!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)) return false; }
    return true;
}
function validate_remote_url(string $url): string {
    $url=trim(html_entity_decode($url,ENT_QUOTES|ENT_HTML5,'UTF-8'));
    if(!preg_match('~^https?://~i',$url)) throw new RuntimeException('URL skal starte med http:// eller https://.');
    $p=parse_url($url);$host=(string)($p['host']??''); if(!host_is_public($host)) throw new RuntimeException('URL peger ikke på en offentlig internetadresse.');
    return $url;
}
function absolute_url(string $base,string $relative): string {
    $relative=trim($relative); if($relative==='') return '';
    if(preg_match('~^https?://~i',$relative)) return $relative;
    if(str_starts_with($relative,'//')) return (parse_url($base,PHP_URL_SCHEME)?:'https').':'.$relative;
    $bp=parse_url($base);$scheme=$bp['scheme']??'https';$host=$bp['host']??'';$port=isset($bp['port'])?':'.$bp['port']:'';
    if(str_starts_with($relative,'/')) return $scheme.'://'.$host.$port.$relative;
    $path=$bp['path']??'/';$dir=preg_replace('~/[^/]*$~','/',$path)?:'/';
    return $scheme.'://'.$host.$port.$dir.$relative;
}
function http_fetch(string $url,int $maxBytes=8388608,array $accept=['text/html','image/']): array {
    $url=validate_remote_url($url);$current=$url;
    for($redirect=0;$redirect<5;$redirect++){
        if(function_exists('curl_init')){
            $ch=curl_init($current);$data='';$headers=[];
            curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>false,CURLOPT_HEADER=>false,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>18,CURLOPT_USERAGENT=>'Mozilla/5.0 HSG-Administration/1.2',CURLOPT_ENCODING=>'']);
            curl_setopt($ch,CURLOPT_HEADERFUNCTION,function($ch,$line)use(&$headers){$len=strlen($line);$p=strpos($line,':');if($p!==false)$headers[strtolower(trim(substr($line,0,$p)))]=trim(substr($line,$p+1));return $len;});
            curl_setopt($ch,CURLOPT_WRITEFUNCTION,function($ch,$chunk)use(&$data,$maxBytes){if(strlen($data)+strlen($chunk)>$maxBytes)return 0;$data.=$chunk;return strlen($chunk);});
            $ok=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$type=(string)curl_getinfo($ch,CURLINFO_CONTENT_TYPE);$err=curl_error($ch);curl_close($ch);
            if($ok===false && $data==='') throw new RuntimeException('Kunne ikke hente URL: '.$err);
        } else {
            $ctx=stream_context_create(['http'=>['timeout'=>15,'user_agent'=>'Mozilla/5.0 HSG-Administration/1.2','follow_location'=>0,'ignore_errors'=>true]]);
            $data=@file_get_contents($current,false,$ctx,0,$maxBytes);if($data===false)throw new RuntimeException('Kunne ikke hente URL fra webhotellet.');$headers=[];$code=200;$type='';
            foreach($http_response_header??[] as $line){if(preg_match('~^HTTP/\S+\s+(\d+)~',$line,$m))$code=(int)$m[1];elseif(str_contains($line,':')){[$k,$v]=explode(':',$line,2);$headers[strtolower(trim($k))]=trim($v);}}
            $type=$headers['content-type']??'';
        }
        if(in_array($code,[301,302,303,307,308],true) && !empty($headers['location'])){$current=validate_remote_url(absolute_url($current,$headers['location']));continue;}
        if($code<200||$code>=400)throw new RuntimeException('Webserveren svarede med HTTP '.$code.'.');
        $allowed=false;foreach($accept as $a){if(str_starts_with(strtolower($type),strtolower($a)))$allowed=true;}
        if(!$allowed && $type!=='') throw new RuntimeException('URL har en uventet filtype: '.$type);
        return ['body'=>$data,'content_type'=>$type,'url'=>$current,'headers'=>$headers];
    }
    throw new RuntimeException('For mange viderestillinger.');
}
function hsg_normalize_search_text(string $value): string {
    $v=html_entity_decode($value,ENT_QUOTES|ENT_HTML5,'UTF-8');
    $v=strtolower($v);
    if(function_exists('iconv')){$t=@iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$v);if($t!==false)$v=$t;}
    $v=preg_replace('/[^a-z0-9%.,]+/',' ',$v)??$v;
    return trim(preg_replace('/\s+/',' ',$v)??$v);
}
function hsg_product_search_profile(array $product): array {
    $name=trim((string)($product['name']??''));
    $brand=trim((string)($product['brand_name']??''));
    $dist=trim((string)($product['distillery']??''));
    $age=trim((string)($product['age_text']??''));
    $vintage=(int)($product['vintage_year']??0);
    $abv=$product['abv']??null;
    $sku=trim((string)($product['sku']??''));
    $caskNumber=strtoupper(trim((string)($product['cask_number']??'')));
    $tokens=[];
    foreach([$name,$brand,$dist] as $value){foreach(product_search_tokens($value) as $t)$tokens[$t]=true;}
    $abvVariants=[];
    if($abv!==null && $abv!==''){
        $f=(float)$abv;$abvVariants=[rtrim(rtrim(number_format($f,2,'.',''),'0'),'.').'%',rtrim(rtrim(number_format($f,2,',',''),'0'),',').'%'];
    }
    $ageNum=null;if(preg_match('/(\d{1,3})/u',$age,$m))$ageNum=(int)$m[1];
    return [
        'name'=>$name,'name_norm'=>hsg_normalize_search_text($name),'brand'=>$brand,'brand_norm'=>hsg_normalize_search_text($brand),
        'distillery'=>$dist,'distillery_norm'=>hsg_normalize_search_text($dist),'age'=>$age,'age_num'=>$ageNum,'vintage'=>$vintage,
        'abv'=>$abv!==null&&$abv!==''?(float)$abv:null,'abv_variants'=>$abvVariants,'sku'=>$sku,'cask_number'=>$caskNumber,'tokens'=>array_keys($tokens)
    ];
}
function hsg_srcset_best(string $srcset): string {
    $best='';$bestW=-1;
    foreach(explode(',',$srcset) as $part){$part=trim($part);if($part==='')continue;$bits=preg_split('/\s+/',$part)?:[];$url=$bits[0]??'';$w=0;if(isset($bits[1])&&preg_match('/(\d+)(?:w|x)$/',$bits[1],$m))$w=(int)$m[1];if($url!==''&&$w>=$bestW){$best=$url;$bestW=$w;}}
    return $best;
}
function hsg_add_image_candidate(array &$out,string $pageUrl,string $rawUrl,int $baseScore,string $source,string $label='',int $width=0,int $height=0): void {
    $rawUrl=trim($rawUrl);if($rawUrl===''||str_starts_with($rawUrl,'data:'))return;
    $url=absolute_url($pageUrl,$rawUrl);if(!preg_match('~^https?://~i',$url))return;
    $hay=strtolower(rawurldecode($url).' '.$label);$score=$baseScore;
    if(str_contains($hay,'product'))$score+=7;if(str_contains($hay,'bottle'))$score+=8;if(str_contains($hay,'packshot'))$score+=10;
    if(str_contains($hay,'large')||str_contains($hay,'zoom')||str_contains($hay,'original'))$score+=4;
    foreach(['logo','icon','avatar','banner','hero','favicon','payment','badge','placeholder','sprite'] as $bad)if(str_contains($hay,$bad))$score-=18;
    if($width>=500||$height>=500)$score+=5;if($width>0&&$height>0&&$height>$width)$score+=3;
    if(preg_match('~\.(jpe?g|png|webp)(?:[?#]|$)~i',$url))$score+=3;
    $key=$url;if(!isset($out[$key])||$score>$out[$key]['score'])$out[$key]=['url'=>$url,'score'=>max(0,min(100,$score)),'source'=>$source,'label'=>trim($label)];
}
function html_product_image_candidates(string $html,string $pageUrl,array $product=[]): array {
    libxml_use_internal_errors(true);$dom=new DOMDocument();if(!@$dom->loadHTML($html))return [];$xp=new DOMXPath($dom);$out=[];$profile=hsg_product_search_profile($product);
    // Product JSON-LD is the strongest non-AI signal on modern ecommerce sites.
    foreach($xp->query('//script[@type="application/ld+json"]')?:[] as $n){
        $json=json_decode((string)$n->textContent,true);$stack=is_array($json)?[$json]:[];
        while($stack){$v=array_pop($stack);if(!is_array($v))continue;$type=$v['@type']??'';$types=is_array($type)?$type:[$type];$isProduct=false;foreach($types as $t)if(strtolower((string)$t)==='product')$isProduct=true;
            if($isProduct){$label=trim((string)($v['name']??''));$images=$v['image']??[];if(is_string($images))$images=[$images];if(is_array($images))foreach($images as $im){if(is_string($im))hsg_add_image_candidate($out,$pageUrl,$im,82,'JSON-LD Product',$label);elseif(is_array($im)){foreach(['url','contentUrl'] as $k)if(!empty($im[$k]))hsg_add_image_candidate($out,$pageUrl,(string)$im[$k],82,'JSON-LD Product',$label);}}}
            foreach($v as $x)if(is_array($x))$stack[]=$x;
        }
    }
    foreach([['//meta[@property="og:image"]/@content',72,'og:image'],['//meta[@property="og:image:secure_url"]/@content',74,'og:image'],['//meta[@name="twitter:image"]/@content',62,'twitter:image']] as [$q,$score,$source])foreach($xp->query($q)?:[] as $n)hsg_add_image_candidate($out,$pageUrl,(string)$n->nodeValue,$score,$source);
    // WooCommerce, Shopify and generic product galleries often expose a large-image attribute.
    foreach($xp->query('//img')?:[] as $img){
        $label=trim($img->getAttribute('alt').' '.$img->getAttribute('title').' '.$img->getAttribute('class'));
        $width=(int)$img->getAttribute('width');$height=(int)$img->getAttribute('height');$cls=strtolower($img->getAttribute('class'));$base=(str_contains($cls,'wp-post-image')||str_contains($cls,'woocommerce')||str_contains($cls,'product')||str_contains($cls,'gallery'))?58:35;
        foreach(['data-large_image','data-zoom-image','data-zoom','data-src','data-lazy-src','data-original','src'] as $a){$v=$img->getAttribute($a);if($v!=='')hsg_add_image_candidate($out,$pageUrl,$v,$base,$a,$label,$width,$height);}
        foreach(['srcset','data-srcset'] as $a){$v=$img->getAttribute($a);if($v!==''){$best=hsg_srcset_best($v);if($best!=='')hsg_add_image_candidate($out,$pageUrl,$best,$base+5,$a,$label,$width,$height);}}
    }
    // Product-name terms in image alt/URL improve ranking, but never enough to rescue logos/banners.
    foreach($out as &$c){$hay=hsg_normalize_search_text($c['url'].' '.$c['label']);$hits=0;foreach($profile['tokens'] as $t){$t=(string)$t;if($t!==''&&str_contains($hay,$t))$hits++;}$c['score']=max(0,min(100,$c['score']+min(15,$hits*3)));}unset($c);
    usort($out,static fn($a,$b)=>($b['score']??0)<=>($a['score']??0));return array_slice(array_values($out),0,12);
}
function html_meta_image(string $html,string $pageUrl): ?string {
    $c=html_product_image_candidates($html,$pageUrl,[]);return $c[0]['url']??null;
}
function hsg_page_match_score(string $html,string $pageUrl,array $product): array {
    libxml_use_internal_errors(true);$dom=new DOMDocument();if(!@$dom->loadHTML($html))return ['score'=>0,'reason'=>'Siden kunne ikke analyseres.'];$xp=new DOMXPath($dom);$profile=hsg_product_search_profile($product);
    $parts=[];foreach(['//title','//h1','//meta[@property="og:title"]/@content','//meta[@name="twitter:title"]/@content'] as $q)foreach($xp->query($q)?:[] as $n)$parts[]=(string)($n->nodeValue??$n->textContent);
    foreach($xp->query('//script[@type="application/ld+json"]')?:[] as $n){$j=json_decode((string)$n->textContent,true);$stack=is_array($j)?[$j]:[];while($stack){$v=array_pop($stack);if(!is_array($v))continue;if(isset($v['name'])&&is_string($v['name']))$parts[]=$v['name'];if(isset($v['sku'])&&is_string($v['sku']))$parts[]=$v['sku'];foreach($v as $x)if(is_array($x))$stack[]=$x;}}
    $body=trim((string)$dom->textContent);if(strlen($body)>120000)$body=substr($body,0,120000);$hay=hsg_normalize_search_text(implode(' ',array_merge($parts,[$pageUrl,$body])));$headline=hsg_normalize_search_text(implode(' ',array_merge($parts,[$pageUrl])));
    $score=0;$reasons=[];
    if($profile['name_norm']!==''&&str_contains($hay,$profile['name_norm'])){$score+=35;$reasons[]='produktnavn matcher';}
    $tokenHits=0;foreach($profile['tokens'] as $t){$t=(string)$t;if($t!==''&&str_contains($hay,$t))$tokenHits++;}if($profile['tokens']){$coverage=$tokenHits/count($profile['tokens']);$score+=(int)round(min(28,28*$coverage));if($coverage>=0.7)$reasons[]='de fleste navneord matcher';}
    if($profile['brand_norm']!==''&&str_contains($hay,$profile['brand_norm'])){$score+=8;$reasons[]='brand matcher';}
    if($profile['distillery_norm']!==''&&str_contains($hay,$profile['distillery_norm'])){$score+=14;$reasons[]='destilleri matcher';}
    if($profile['vintage']>0&&preg_match('/\b'.preg_quote((string)$profile['vintage'],'/').'\b/',$hay)){$score+=10;$reasons[]='årgang matcher';}
    if($profile['age_num']!==null&&preg_match('/\b'.(int)$profile['age_num'].'\s*(?:year|years|yr|yrs|aar|ar)\b/u',$hay)){$score+=8;$reasons[]='alder matcher';}
    if($profile['abv']!==null){$abvHit=false;foreach($profile['abv_variants'] as $a)if(str_contains(str_replace(' ','',$hay),str_replace(' ','',strtolower($a)))){$abvHit=true;break;}if(!$abvHit){$num=rtrim(rtrim(number_format((float)$profile['abv'],2,'.',''),'0'),'.');$abvHit=(bool)preg_match('/\b'.preg_quote($num,'/').'\s*%/',$hay);}if($abvHit){$score+=12;$reasons[]='ABV matcher';}}
    if($profile['sku']!==''&&str_contains($hay,hsg_normalize_search_text($profile['sku']))){$score+=5;$reasons[]='SKU matcher';}
    if($profile['cask_number']!==''){ $cn=hsg_normalize_search_text($profile['cask_number']); if($cn!==''&&str_contains($hay,$cn)){$score+=22;$reasons[]='fadnummer matcher';} elseif(preg_match('/(?:cask|fad|barrel)[^a-z0-9]{0,8}#?\s*([a-z0-9.\-\/]+)/i',$hay,$cm) && hsg_normalize_search_text((string)$cm[1])!==$cn){$score-=35;$reasons[]='andet fadnummer på siden';}}
    $criticalHits=0;if($profile['distillery_norm']!==''&&str_contains($hay,$profile['distillery_norm']))$criticalHits++;if($profile['vintage']>0&&preg_match('/\b'.preg_quote((string)$profile['vintage'],'/').'\b/',$hay))$criticalHits++;if($profile['age_num']!==null&&preg_match('/\b'.(int)$profile['age_num'].'\s*(?:year|years|yr|yrs|aar|ar)\b/u',$hay))$criticalHits++;if($profile['abv']!==null){$numDot=rtrim(rtrim(number_format((float)$profile['abv'],2,'.',''),'0'),'.');$numComma=str_replace('.',',',$numDot);if(preg_match('/\b'.preg_quote($numDot,'/').'\s*%/',$hay)||preg_match('/\b'.preg_quote($numComma,'/').'\s*%/',$hay))$criticalHits++;}if($criticalHits>=3){$score+=12;$reasons[]='flere unikke flaske-data matcher samtidigt';}
    $path=strtolower((string)parse_url($pageUrl,PHP_URL_PATH));if(str_contains($path,'product')||str_contains($path,'shop'))$score+=4;if(str_contains($path,'category')||str_contains($path,'collections'))$score-=3;if(str_contains($path,'blog')||str_contains($path,'news'))$score-=8;
    // A page whose headline contains almost none of the product terms is unlikely to be the exact bottle.
    $headlineHits=0;foreach($profile['tokens'] as $t){$t=(string)$t;if($t!==''&&str_contains($headline,$t))$headlineHits++;}if(count($profile['tokens'])>=2&&$headlineHits===0)$score-=18;
    return ['score'=>max(0,min(100,$score)),'reason'=>$reasons?implode(', ',$reasons):'svagt tekstmatch'];
}
function hsg_build_search_queries(array $product,?string $host=null): array {
    $p=hsg_product_search_profile($product);$queries=[];$site=$host?' site:'.$host:'';
    if($p['name']!=='')$queries[]='"'.$p['name'].'"'.$site;
    $facts=array_filter([$p['distillery'],$p['vintage']?:'', $p['age'], $p['abv']!==null?rtrim(rtrim(number_format((float)$p['abv'],2,'.',''),'0'),'.').'%':'', $p['cask_number']!==''?'#'.$p['cask_number']:'', $p['brand']]);
    if($facts)$queries[]=implode(' ',$facts).$site;
    $clean=preg_replace('/[()–—-]+/u',' ',$p['name'])??$p['name'];$clean=trim(preg_replace('/\s+/',' ',$clean)??$clean);if($clean!==''&&$clean!==$p['name'])$queries[]='"'.$clean.'"'.$site;
    if($p['distillery']!==''&&$p['brand']!=='')$queries[]='"'.$p['distillery'].'" "'.$p['brand'].'" '.($p['age']?:'').' '.($p['vintage']?:'').$site;
    return array_values(array_unique(array_filter(array_map('trim',$queries))));
}
function crop_and_store_image(string $bytes,string $sku): string {
    if(!function_exists('imagecreatefromstring')) throw new RuntimeException('PHP GD mangler. Få GD aktiveret på webhotellet for automatisk billedbehandling.');
    $src=@imagecreatefromstring($bytes);if(!$src)throw new RuntimeException('Det hentede billede kunne ikke læses.');$w=imagesx($src);$h=imagesy($src);if($w<80||$h<80){imagedestroy($src);throw new RuntimeException('Billedet er for lille.');}
    $corners=[[0,0],[$w-1,0],[0,$h-1],[$w-1,$h-1]];$br=$bg=$bb=0;foreach($corners as [$x,$y]){$rgb=imagecolorat($src,$x,$y);$br+=($rgb>>16)&255;$bg+=($rgb>>8)&255;$bb+=$rgb&255;}$br/=4;$bg/=4;$bb/=4;
    $minX=$w;$minY=$h;$maxX=0;$maxY=0;$step=max(1,(int)floor(max($w,$h)/900));
    for($y=0;$y<$h;$y+=$step){for($x=0;$x<$w;$x+=$step){$rgb=imagecolorat($src,$x,$y);$r=($rgb>>16)&255;$g=($rgb>>8)&255;$b=$rgb&255;$dist=abs($r-$br)+abs($g-$bg)+abs($b-$bb);if($dist>65){$minX=min($minX,$x);$minY=min($minY,$y);$maxX=max($maxX,$x);$maxY=max($maxY,$y);}}}
    if($minX>$maxX||$minY>$maxY){$minX=0;$minY=0;$maxX=$w-1;$maxY=$h-1;}
    $pad=(int)(max($maxX-$minX,$maxY-$minY)*0.05);$minX=max(0,$minX-$pad);$minY=max(0,$minY-$pad);$maxX=min($w-1,$maxX+$pad);$maxY=min($h-1,$maxY+$pad);$cw=max(1,$maxX-$minX+1);$ch=max(1,$maxY-$minY+1);
    $canvas=imagecreatetruecolor(800,1000);$white=imagecolorallocate($canvas,255,255,255);imagefill($canvas,0,0,$white);$scale=min(720/$cw,920/$ch);$dw=max(1,(int)round($cw*$scale));$dh=max(1,(int)round($ch*$scale));$dx=(int)((800-$dw)/2);$dy=(int)((1000-$dh)/2);imagecopyresampled($canvas,$src,$dx,$dy,$minX,$minY,$dw,$dh,$cw,$ch);
    ensure_image_dir();$safe=preg_replace('/[^A-Za-z0-9_-]+/','-',trim($sku))?:'product';$filename=$safe.'-'.substr(hash('sha256',$bytes),0,10).'.jpg';$path=image_dir().'/'.$filename;if(!imagejpeg($canvas,$path,90)){imagedestroy($src);imagedestroy($canvas);throw new RuntimeException('Kunne ikke gemme billedet.');}imagedestroy($src);imagedestroy($canvas);return image_web_path($filename);
}
function hsg_product_supplier_context(PDO $pdo,int $productId): array {
    $st=$pdo->prepare('SELECT p.supplier_domain,p.supplier_url,b.website_url brand_website,b.image_search_url brand_image_search FROM lager_products p LEFT JOIN lager_brands b ON b.id=p.brand_id WHERE p.id=?');
    $st->execute([$productId]);$row=$st->fetch(PDO::FETCH_ASSOC);if(!$row)throw new RuntimeException('Produktet findes ikke.');
    $root='';foreach([$row['brand_image_search']??'',$row['brand_website']??'',$row['supplier_url']??''] as $candidate){$candidate=trim((string)$candidate);if($candidate!==''){$root=$candidate;break;}}
    if($root===''&&!empty($row['supplier_domain']))$root='https://'.trim((string)$row['supplier_domain']);
    if($root==='')throw new RuntimeException('Der er ikke angivet en leverandør-URL på produktets brand/leverandør. Billedsøgning kræver en officiel leverandørside.');
    $root=validate_remote_url($root);$host=supplier_host($root);if($host==='')throw new RuntimeException('Leverandør-URL har ikke et gyldigt domæne.');
    return ['root'=>$root,'host'=>$host];
}
function hsg_canonical_compare_url(string $url): string {
    $url=trim(html_entity_decode($url,ENT_QUOTES|ENT_HTML5,'UTF-8'));$p=parse_url($url);if(!$p||empty($p['host']))return strtolower($url);
    $scheme=strtolower((string)($p['scheme']??'https'));$host=strtolower((string)$p['host']);$path=rawurldecode((string)($p['path']??'/'));
    return $scheme.'://'.$host.$path;
}
function hsg_supplier_page_references_image(string $supplierPageUrl,string $imageUrl,string $supplierHost,array $product): bool {
    if(!supplier_same_host($supplierPageUrl,$supplierHost))return false;
    $r=http_fetch($supplierPageUrl,4500000,['text/html']);
    $target=hsg_canonical_compare_url($imageUrl);
    foreach(html_product_image_candidates($r['body'],$r['url'],$product) as $candidate){$u=trim((string)($candidate['url']??''));if($u!==''&&hsg_canonical_compare_url($u)===$target)return true;}
    // Some sites embed CDN URLs in JSON/scripts without rendering a normal img tag.
    $decoded=html_entity_decode($r['body'],ENT_QUOTES|ENT_HTML5,'UTF-8');
    return str_contains($decoded,$imageUrl) || str_contains($decoded,str_replace('/','\\/',$imageUrl));
}
function save_product_image_from_url(PDO $pdo,int $productId,string $url,string $method='manual',?int $confidence=null,?string $note=null,?string $supplierProofUrl=null): array {
    $st=$pdo->prepare('SELECT p.sku,p.name,p.distillery,p.age_text,p.vintage_year,p.abv,p.cask_type,p.cask_number,b.name brand_name FROM lager_products p LEFT JOIN lager_brands b ON b.id=p.brand_id WHERE p.id=?');$st->execute([$productId]);$p=$st->fetch();if(!$p)throw new RuntimeException('Produktet findes ikke.');
    $supplierHost='';try{$supplier=hsg_product_supplier_context($pdo,$productId);$supplierHost=$supplier['host'];}catch(Throwable $e){}
    if($method!=='manual'&&hsg_image_url_is_rejected(hsg_image_rejection_hashes($pdo,$productId),$url))throw new RuntimeException('Billedkilden er tidligere afvist for dette produkt.');
    $url=validate_remote_url($url);$r=http_fetch($url);$resolved=$r['url'];$sourcePage='';$imageUrl='';$bytes='';
    $isHtml=str_starts_with(strtolower((string)$r['content_type']),'text/html');
    $isImage=str_starts_with(strtolower((string)$r['content_type']),'image/');
    if($isHtml){
        if($supplierHost!=='' && !supplier_same_host($resolved,$supplierHost))throw new RuntimeException('Produktsiden skal ligge på leverandørens officielle hjemmeside ('.$supplierHost.').');
        $images=html_product_image_candidates($r['body'],$resolved,$p);$found=$images[0]['url']??null;if(!$found)throw new RuntimeException('Der blev ikke fundet et sandsynligt produktbillede på leverandørens side.');
        $ir=http_fetch($found,8388608,['image/']);$imageUrl=$ir['url'];$bytes=$ir['body'];$sourcePage=$resolved;
    }elseif($isImage){
        $imageUrl=$resolved;$bytes=$r['body'];
        $sourcePage=$supplierProofUrl?validate_remote_url($supplierProofUrl):$imageUrl;
    }else throw new RuntimeException('URL skal pege på en produktside eller et billede.');
    $path=crop_and_store_image($bytes,(string)$p['sku']);
    $method=in_array($method,['manual','supplier','ai'],true)?$method:'manual';$confidence=$confidence===null?null:max(0,min(100,$confidence));$note=$note===null?null:substr(trim($note),0,500);
    $approvalStatus = ($method==='manual') ? 'approved' : 'pending';
    $approvedAt = ($method==='manual') ? date('Y-m-d H:i:s') : null;
    $pdo->prepare("UPDATE lager_products SET image_path=?,image_source_url=?,supplier_domain=?,image_checked_at=NOW(),image_method=?,image_confidence=?,image_ai_note=?,image_validation_score=NULL,image_validation_status=NULL,image_validation_note=NULL,image_validated_at=NULL,image_validation_model=NULL,image_approval_status=?,image_approved_at=?,image_approved_by_admin=NULL WHERE id=?")->execute([$path,$sourcePage,$supplierHost,$method,$confidence,$note,$approvalStatus,$approvedAt,$productId]);
    return ['path'=>$path,'source'=>$sourcePage,'source_url'=>$sourcePage,'image_url'=>$imageUrl,'domain'=>$supplierHost,'method'=>$method,'confidence'=>$confidence,'note'=>$note];
}
function supplier_host(string $url): string {
    $h=strtolower((string)parse_url($url,PHP_URL_HOST));
    return preg_replace('/^www\./i','',$h)?:'';
}
function supplier_same_host(string $url,string $preferredHost): bool {
    $h=supplier_host($url);$preferredHost=preg_replace('/^www\./i','',strtolower($preferredHost))?:'';
    return $h!=='' && $preferredHost!=='' && ($h===$preferredHost || str_ends_with($h,'.'.$preferredHost) || str_ends_with($preferredHost,'.'.$h));
}
function product_search_tokens(string $name): array {
    $v=strtolower($name);
    $v=preg_replace('/\b\d{1,3}(?:[.,]\d+)?\s*%\b/u',' ',$v)??$v;
    $v=preg_replace('/\b(19|20)\d{2}\b/u',' ',$v)??$v;
    $v=preg_replace('/\b\d+\s*(?:year|years|yr|yrs|år)\b/u',' ',$v)??$v;
    if(function_exists('iconv')){$t=@iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$v);if($t!==false)$v=$t;}
    $parts=preg_split('/[^a-z0-9]+/',$v)?:[];
    $stop=['the','and','with','from','single','malt','scotch','whisky','whiskey','rum','gin','vodka','cask','strength','bottle','limited','edition','old','years','year','aged'];
    $out=[];foreach($parts as $x){$x=(string)$x;if(strlen($x)<3||in_array($x,$stop,true))continue;if(!in_array($x,$out,true))$out[]=$x;}
    return array_values(array_map('strval',$out));
}
function supplier_candidate_score(string $url,string $label,array $tokens): int {
    $hay=hsg_normalize_search_text(rawurldecode($url).' '.$label);$score=0;
    foreach($tokens as $t){$t=(string)$t;if($t!==''&&str_contains($hay,$t))$score+=9;}
    if(str_contains($hay,'product'))$score+=5;if(str_contains($hay,'shop'))$score+=3;if(str_contains($hay,'collections'))$score+=1;
    if(str_contains($hay,'blog'))$score-=8;if(str_contains($hay,'news'))$score-=6;if(str_contains($hay,'category'))$score-=4;
    return $score;
}
function supplier_links_from_html(string $html,string $base,string $host,array $tokens): array {
    libxml_use_internal_errors(true);$dom=new DOMDocument();if(!@$dom->loadHTML($html))return [];$xp=new DOMXPath($dom);$scored=[];
    foreach($xp->query('//a[@href]')?:[] as $a){$href=trim($a->getAttribute('href'));if($href===''||str_starts_with($href,'#')||str_starts_with(strtolower($href),'javascript:'))continue;$u=absolute_url($base,$href);if(!supplier_same_host($u,$host))continue;$label=trim((string)$a->textContent);$score=supplier_candidate_score($u,$label,$tokens);if($score>0)$scored[$u]=max($scored[$u]??-999,$score);}
    arsort($scored);return array_keys($scored);
}
function supplier_xml_locations(string $xml): array {
    $out=[];
    if(function_exists('simplexml_load_string')){libxml_use_internal_errors(true);$sx=@simplexml_load_string($xml);if($sx!==false){foreach($sx->xpath('//*[local-name()="loc"]')?:[] as $loc){$u=trim((string)$loc);if($u!=='')$out[]=$u;}}}
    if(!$out && preg_match_all('~<loc>\s*(.*?)\s*</loc>~is',$xml,$m)){foreach($m[1] as $u)$out[]=html_entity_decode(trim(strip_tags($u)),ENT_QUOTES|ENT_HTML5,'UTF-8');}
    return array_values(array_unique($out));
}
function supplier_sitemap_candidates(string $root,string $host,array $tokens): array {
    $base=(parse_url($root,PHP_URL_SCHEME)?:'https').'://'.(parse_url($root,PHP_URL_HOST)?:$host);if(parse_url($root,PHP_URL_PORT))$base.=':'.parse_url($root,PHP_URL_PORT);
    $queue=[];try{$robots=http_fetch($base.'/robots.txt',500000,['text/']);foreach(preg_split('/\r?\n/',$robots['body'])?:[] as $line){if(preg_match('~^\s*Sitemap:\s*(https?://\S+)~i',$line,$m))$queue[]=$m[1];}}catch(Throwable $e){}
    $queue=array_values(array_unique(array_merge($queue,[$base.'/sitemap.xml',$base.'/sitemap_index.xml',$base.'/product-sitemap.xml',$base.'/wp-sitemap-posts-product-1.xml'])));
    $seen=[];$scored=[];$files=0;$urlsSeen=0;
    while($queue && $files<18 && $urlsSeen<5000){$sm=array_shift($queue);if(isset($seen[$sm])||!supplier_same_host($sm,$host))continue;$seen[$sm]=true;$files++;
        try{$r=http_fetch($sm,6500000,['text/','application/xml','application/rss+xml','application/octet-stream']);}catch(Throwable $e){continue;}
        foreach(supplier_xml_locations($r['body']) as $u){$urlsSeen++;if(!supplier_same_host($u,$host))continue;$path=strtolower((string)parse_url($u,PHP_URL_PATH));if(str_ends_with($path,'.xml')||str_contains($path,'sitemap')){if(count($queue)<50)$queue[]=$u;continue;}$score=supplier_candidate_score($u,'',$tokens);if($score>0)$scored[$u]=max($scored[$u]??-999,$score);if($urlsSeen>=5000)break;}
    }
    arsort($scored);return array_slice(array_keys($scored),0,45);
}

function hsg_supplier_base(string $root,string $host=''): string {
    $scheme=(string)(parse_url($root,PHP_URL_SCHEME)?:'https');$h=(string)(parse_url($root,PHP_URL_HOST)?:$host);$base=$scheme.'://'.$h;
    $port=parse_url($root,PHP_URL_PORT);if($port)$base.=':'.$port;return rtrim($base,'/');
}
function hsg_supplier_query_terms(array $product): array {
    $p=hsg_product_search_profile($product);$queries=[];
    if($p['name']!=='')$queries[]=$p['name'];
    $compact=array_filter([$p['distillery'],$p['vintage']?:'', $p['age'], $p['abv']!==null?rtrim(rtrim(number_format((float)$p['abv'],2,'.',''),'0'),'.').'%':'']);
    if($compact)$queries[]=implode(' ',$compact);
    if($p['distillery']!=='')$queries[]=trim($p['distillery'].' '.($p['vintage']?:'').' '.($p['age']?:''));
    $tokens=array_slice($p['tokens'],0,6);if($tokens)$queries[]=implode(' ',$tokens);
    $out=[];foreach($queries as $q){$q=trim(preg_replace('/\s+/',' ',$q)??$q);if($q!==''&&!in_array($q,$out,true))$out[]=$q;}return array_slice($out,0,4);
}
function hsg_supplier_json(string $url,int $maxBytes=3500000): ?array {
    try{$r=http_fetch($url,$maxBytes,['application/json','text/json','application/ld+json']);$j=json_decode($r['body'],true);return is_array($j)?$j:null;}catch(Throwable $e){return null;}
}
function hsg_supplier_platform_candidates(string $root,array $product): array {
    $root=validate_remote_url($root);$host=supplier_host($root);if($host==='')return ['pages'=>[],'images'=>[]];$base=hsg_supplier_base($root,$host);$pages=[];$images=[];
    foreach(hsg_supplier_query_terms($product) as $query){
        // WooCommerce Store API is public and often exposes the exact product image even when the theme is JS-heavy.
        $woo=$base.'/wp-json/wc/store/v1/products?search='.rawurlencode($query).'&per_page=20';$j=hsg_supplier_json($woo,5000000);
        if(is_array($j) && array_is_list($j))foreach($j as $row){if(!is_array($row))continue;$title=trim((string)($row['name']??''));$page=trim((string)($row['permalink']??''));if($page==='')continue;$fake='<title>'.htmlspecialchars($title,ENT_QUOTES|ENT_HTML5,'UTF-8').'</title><body>'.($row['short_description']??'').' '.($row['description']??'').'</body>';$match=hsg_page_match_score($fake,$page,$product);$score=max(50,min(99,(int)$match['score']+8));$pages[$page]=max($pages[$page]??0,$score);
            $ims=$row['images']??[];if(is_array($ims))foreach(array_slice($ims,0,4) as $im){if(!is_array($im))continue;$src=trim((string)($im['src']??$im['thumbnail']??''));if($src==='')continue;$images[$src]=['page_url'=>$page,'image_url'=>$src,'confidence'=>$score,'reason'=>'WooCommerce Store API: '.($match['reason']??'produktmatch').'.','provider'=>'supplier-api'];}
        }
        // WordPress REST search can reveal product URLs even when the rendered search page is empty to cURL.
        foreach([$base.'/wp-json/wp/v2/search?search='.rawurlencode($query).'&subtype=product&per_page=20',$base.'/?rest_route=/wp/v2/search&search='.rawurlencode($query).'&subtype=product&per_page=20'] as $wp){$wj=hsg_supplier_json($wp,2500000);if(is_array($wj)&&array_is_list($wj))foreach($wj as $row){if(!is_array($row))continue;$page=trim((string)($row['url']??''));$title=trim(strip_tags((string)($row['title']??'')));if($page===''||!supplier_same_host($page,$host))continue;$score=supplier_candidate_score($page,$title,hsg_product_search_profile($product)['tokens'])+20;$pages[$page]=max($pages[$page]??0,$score);}}
        // Shopify Predictive Search API. The response commonly contains product URL + featured_image.
        $shop=$base.'/search/suggest.json?q='.rawurlencode($query).'&resources[type]=product&resources[limit]=10&resources[options][unavailable_products]=show';$sj=hsg_supplier_json($shop,3000000);$products=$sj['resources']['results']['products']??[];
        if(is_array($products))foreach($products as $row){if(!is_array($row))continue;$title=trim((string)($row['title']??''));$rel=trim((string)($row['url']??''));if($rel==='')continue;$page=absolute_url($base.'/',$rel);if(!supplier_same_host($page,$host))continue;$fake='<title>'.htmlspecialchars($title,ENT_QUOTES|ENT_HTML5,'UTF-8').'</title><body>'.strip_tags((string)($row['body']??'')).'</body>';$match=hsg_page_match_score($fake,$page,$product);$score=max(50,min(99,(int)$match['score']+8));$pages[$page]=max($pages[$page]??0,$score);$featured=$row['featured_image']??null;$src='';if(is_string($featured))$src=$featured;elseif(is_array($featured))$src=(string)($featured['url']??$featured['src']??'');if($src!==''){$src=absolute_url($page,$src);$images[$src]=['page_url'=>$page,'image_url'=>$src,'confidence'=>$score,'reason'=>'Shopify Predictive Search: '.($match['reason']??'produktmatch').'.','provider'=>'supplier-api'];}}
    }
    arsort($pages);return ['pages'=>array_slice(array_keys($pages),0,50),'images'=>array_values($images)];
}
function hsg_supplier_script_urls(string $html,string $base,string $host): array {
    $out=[];$patterns=['~(?:https?:\\/\\/[^"\'<>\\s]+|/[^"\'<>\\s]*(?:products?|shop|bottlings?|releases?|whisk(?:y|ies)|spirits?)/[^"\'<>\\s]+)~i'];
    foreach($patterns as $pattern)if(preg_match_all($pattern,$html,$m)){foreach($m[0] as $raw){$raw=str_replace('\\/','/',$raw);$raw=html_entity_decode($raw,ENT_QUOTES|ENT_HTML5,'UTF-8');$u=absolute_url($base,$raw);if(supplier_same_host($u,$host))$out[$u]=true;if(count($out)>=300)break 2;}}
    return array_keys($out);
}
function hsg_supplier_index_cache_file(string $host): string {
    $dir=__DIR__.'/storage/cache/image-index';if(!is_dir($dir))@mkdir($dir,0775,true);return $dir.'/'.sha1(strtolower($host)).'.json';
}
function hsg_supplier_sitemap_records(string $xml): array {
    $records=[];libxml_use_internal_errors(true);$dom=new DOMDocument();if(@$dom->loadXML($xml)){$xp=new DOMXPath($dom);foreach($xp->query('//*[local-name()="url"]')?:[] as $node){$loc='';$labels=[];$images=[];foreach($xp->query('./*[local-name()="loc"]',$node)?:[] as $n){$loc=trim((string)$n->textContent);break;}foreach($xp->query('.//*[local-name()="title" or local-name()="caption"]',$node)?:[] as $n){$label=trim((string)$n->textContent);if($label!=='')$labels[]=$label;}foreach($xp->query('.//*[local-name()="image"]/*[local-name()="loc"]',$node)?:[] as $n){$im=trim((string)$n->textContent);if($im!==''&&!in_array($im,$images,true))$images[]=$im;if(count($images)>=6)break;}if($loc!=='')$records[]=['url'=>$loc,'label'=>trim(implode(' ',$labels)),'image'=>$images[0]??'','images'=>$images];}}
    return $records;
}
function hsg_build_supplier_site_index(string $root,string $host): array {
    $base=hsg_supplier_base($root,$host);$records=[];$queue=[];$seen=[];
    try{$robots=http_fetch($base.'/robots.txt',500000,['text/']);foreach(preg_split('/\r?\n/',$robots['body'])?:[] as $line)if(preg_match('~^\s*Sitemap:\s*(https?://\S+)~i',$line,$m))$queue[]=$m[1];}catch(Throwable $e){}
    $queue=array_values(array_unique(array_merge($queue,[$base.'/sitemap.xml',$base.'/sitemap_index.xml',$base.'/product-sitemap.xml',$base.'/wp-sitemap-posts-product-1.xml'])));$files=0;$locCount=0;
    while($queue&&$files<25&&$locCount<12000){$sm=array_shift($queue);if(isset($seen[$sm])||!supplier_same_host($sm,$host))continue;$seen[$sm]=true;$files++;try{$r=http_fetch($sm,8000000,['text/','application/xml','application/rss+xml','application/octet-stream']);}catch(Throwable $e){continue;}
        foreach(supplier_xml_locations($r['body']) as $u){$path=strtolower((string)parse_url($u,PHP_URL_PATH));if((str_ends_with($path,'.xml')||str_contains($path,'sitemap'))&&supplier_same_host($u,$host)){if(count($queue)<100)$queue[]=$u;}}
        foreach(hsg_supplier_sitemap_records($r['body']) as $rec){if(!supplier_same_host($rec['url'],$host))continue;$records[$rec['url']]=['url'=>$rec['url'],'label'=>$rec['label'],'image'=>$rec['image'],'images'=>$rec['images']??($rec['image']!==''?[$rec['image']]:[])];if(++$locCount>=12000)break;}
    }
    // Crawl likely catalogue landing pages. This catches sites without usable sitemaps and JS themes with server-rendered links/data.
    $crawl=[$base.'/',$base.'/shop/',$base.'/products/',$base.'/collections/all',$base.'/collections/',$base.'/whisky/',$base.'/whiskies/',$base.'/bottlings/',$base.'/releases/',$base.'/our-range/'];$cseen=[];$done=0;
    while($crawl&&$done<60){$u=array_shift($crawl);if(isset($cseen[$u])||!supplier_same_host($u,$host))continue;$cseen[$u]=true;$done++;try{$r=http_fetch($u,4000000,['text/html']);}catch(Throwable $e){continue;}libxml_use_internal_errors(true);$dom=new DOMDocument();if(@$dom->loadHTML($r['body'])){$xp=new DOMXPath($dom);foreach($xp->query('//a[@href]')?:[] as $a){$href=trim($a->getAttribute('href'));if($href===''||str_starts_with($href,'#'))continue;$link=absolute_url($r['url'],$href);if(!supplier_same_host($link,$host))continue;$label=trim(preg_replace('/\s+/',' ',(string)$a->textContent)??'');$path=strtolower((string)parse_url($link,PHP_URL_PATH));if(preg_match('~/(?:product|products|shop|bottling|bottlings|release|releases|whisky|whiskies|spirit|spirits|collections?)/~',$path)){$records[$link]=['url'=>$link,'label'=>$label,'image'=>''];if(substr_count(trim($path,'/'),'/')<=3&&count($crawl)<180)$crawl[]=$link;}}}
        foreach(hsg_supplier_script_urls($r['body'],$r['url'],$host) as $link)$records[$link]=$records[$link]??['url'=>$link,'label'=>'','image'=>''];
    }
    return array_values($records);
}
function hsg_supplier_cached_index(string $root,string $host): array {
    $file=hsg_supplier_index_cache_file($host);if(is_file($file)&&filemtime($file)>time()-43200){$j=json_decode((string)@file_get_contents($file),true);if(is_array($j))return $j;}
    $records=hsg_build_supplier_site_index($root,$host);@file_put_contents($file,json_encode($records,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE),LOCK_EX);return $records;
}
function hsg_supplier_cached_index_candidates(string $root,string $host,array $product): array {
    $profile=hsg_product_search_profile($product);$tokens=$profile['tokens'];$scored=[];
    foreach(hsg_supplier_cached_index($root,$host) as $rec){if(!is_array($rec))continue;$u=trim((string)($rec['url']??''));if($u===''||!supplier_same_host($u,$host))continue;$label=trim((string)($rec['label']??''));$score=supplier_candidate_score($u,$label,$tokens);$norm=hsg_normalize_search_text($label.' '.$u);if($profile['name_norm']!==''&&str_contains($norm,$profile['name_norm']))$score+=28;if($profile['distillery_norm']!==''&&str_contains($norm,$profile['distillery_norm']))$score+=10;if($profile['vintage']>0&&str_contains($norm,(string)$profile['vintage']))$score+=8;if($score>0)$scored[$u]=max($scored[$u]??-999,$score);}
    arsort($scored);return array_slice(array_keys($scored),0,70);
}

function hsg_supplier_cached_image_candidates(string $root,string $host,array $product): array {
    $profile=hsg_product_search_profile($product);$tokens=$profile['tokens'];$out=[];
    foreach(hsg_supplier_cached_index($root,$host) as $rec){
        if(!is_array($rec))continue;$page=trim((string)($rec['url']??''));$label=trim((string)($rec['label']??''));
        $images=$rec['images']??[];if(!is_array($images))$images=[];$legacy=trim((string)($rec['image']??''));if($legacy!==''&&!in_array($legacy,$images,true))array_unshift($images,$legacy);
        if($page===''||!$images||!supplier_same_host($page,$host))continue;
        $score=supplier_candidate_score($page,$label,$tokens)+20;$norm=hsg_normalize_search_text($label.' '.$page);
        if($profile['name_norm']!==''&&str_contains($norm,$profile['name_norm']))$score+=28;
        if($profile['distillery_norm']!==''&&str_contains($norm,$profile['distillery_norm']))$score+=12;
        if($profile['vintage']>0&&str_contains($norm,(string)$profile['vintage']))$score+=9;
        if($profile['age_num']!==null&&preg_match('/\b'.preg_quote((string)$profile['age_num'],'/').'\s*(?:year|years|yr|yrs|år)\b/u',$norm))$score+=7;
        $confidence=max(50,min(98,$score));if($score<18)continue;
        foreach(array_slice($images,0,6) as $image){$image=trim((string)$image);if($image==='')continue;$out[$image]=['page_url'=>$page,'image_url'=>$image,'confidence'=>$confidence,'reason'=>'Leverandørens image-sitemap matcher produktet.','provider'=>'supplier-sitemap'];}
    }
    $out=array_values($out);usort($out,static fn($a,$b)=>($b['confidence']??0)<=>($a['confidence']??0));return array_slice($out,0,25);
}
function hsg_clear_supplier_index_cache(string $host): void {
    $file=hsg_supplier_index_cache_file($host);if(is_file($file))@unlink($file);
}

function supplier_internal_candidates(string $root,array|string $product): array {
    $root=validate_remote_url($root);$host=supplier_host($root);if($host==='')return [];$productArray=is_array($product)?$product:['name'=>(string)$product];$profile=hsg_product_search_profile($productArray);$tokens=$profile['tokens'];$scored=[];
    // Cached full-domain index first: sitemap titles/images + crawl of catalogue landing pages.
    foreach(hsg_supplier_cached_index_candidates($root,$host,$productArray) as $u){$scored[$u]=max($scored[$u]??-999,supplier_candidate_score($u,'',$tokens)+10);}
    // Legacy sitemap scorer remains useful when sitemap URL slugs themselves contain the product name.
    foreach(supplier_sitemap_candidates($root,$host,$tokens) as $u){$scored[$u]=max($scored[$u]??-999,supplier_candidate_score($u,'',$tokens)+7);}
    $base=hsg_supplier_base($root,$host);foreach(hsg_supplier_query_terms($productArray) as $qtext){$queries=[
        $base.'/search?q='.rawurlencode($qtext),$base.'/search?type=product&q='.rawurlencode($qtext),$base.'/?s='.rawurlencode($qtext),$base.'/?s='.rawurlencode($qtext).'&post_type=product',
        $base.'/shop/?s='.rawurlencode($qtext).'&post_type=product'
    ];foreach($queries as $q){try{$r=http_fetch($q,3500000,['text/html']);foreach(supplier_links_from_html($r['body'],$r['url'],$host,$tokens) as $u){$scored[$u]=max($scored[$u]??-999,supplier_candidate_score($u,'',$tokens)+14);}foreach(hsg_supplier_script_urls($r['body'],$r['url'],$host) as $u){$score=supplier_candidate_score($u,'',$tokens);if($score>0)$scored[$u]=max($scored[$u]??-999,$score+8);}}catch(Throwable $e){}}}
    arsort($scored);return array_slice(array_keys($scored),0,70);
}
function hsg_discover_page_image_candidates(array $product,array $pageUrls,string $provider,?string $preferredHost=null,int $maxPages=18): array {
    $out=[];$seenPages=[];$count=0;
    foreach($pageUrls as $pageUrl){
        $pageUrl=trim((string)$pageUrl);if($pageUrl===''||isset($seenPages[$pageUrl]))continue;$seenPages[$pageUrl]=true;if($preferredHost&& !supplier_same_host($pageUrl,$preferredHost))continue;if(++$count>$maxPages)break;
        try{$r=http_fetch($pageUrl,3500000,['text/html','image/']);}catch(Throwable $e){continue;}
        if(str_starts_with(strtolower((string)$r['content_type']),'image/')){$out[]=['page_url'=>$r['url'],'image_url'=>$r['url'],'confidence'=>72,'reason'=>'Direkte billedresultat fra '.$provider.'.','provider'=>$provider];continue;}
        $match=hsg_page_match_score($r['body'],$r['url'],$product);$pageScore=(int)$match['score'];if($pageScore<28)continue;
        $images=html_product_image_candidates($r['body'],$r['url'],$product);foreach(array_slice($images,0,4) as $img){$imageScore=(int)($img['score']??0);$confidence=(int)round(($pageScore*0.78)+($imageScore*0.22));if($provider==='supplier')$confidence+=4;if($provider==='search'&&!$preferredHost)$confidence=min(94,$confidence);$confidence=max(0,min(99,$confidence));if($confidence<50)continue;$reason=ucfirst($provider).': '.$match['reason'].'; billede via '.($img['source']??'side').'.';$out[]=['page_url'=>$r['url'],'image_url'=>$img['url'],'confidence'=>$confidence,'reason'=>$reason,'provider'=>$provider];}
    }
    $dedupe=[];foreach($out as $c){$key=(string)($c['image_url']?:$c['page_url']);if($key==='')continue;if(!isset($dedupe[$key])||$c['confidence']>$dedupe[$key]['confidence'])$dedupe[$key]=$c;}
    $out=array_values($dedupe);usort($out,static fn($a,$b)=>($b['confidence']??0)<=>($a['confidence']??0));return array_slice($out,0,20);
}
function hsg_normal_search_candidates(array $product,?string $root=null): array {
    if($root===null||trim($root)==='')return [];
    $preferred=supplier_host($root);if($preferred==='')return [];$pageUrls=[];$providerByUrl=[];$directImages=[];
    $explicit=trim((string)($product['supplier_url']??''));if($explicit!==''&&preg_match('~^https?://~i',$explicit)&&supplier_same_host($explicit,$preferred)){$path=(string)parse_url($explicit,PHP_URL_PATH);if($path!==''&&$path!=='/'){$pageUrls[]=$explicit;$providerByUrl[$explicit]='supplier';}}
    try{foreach(hsg_supplier_cached_image_candidates($root,$preferred,$product) as $c)if(is_array($c)&&supplier_same_host((string)($c['page_url']??''),$preferred))$directImages[]=$c;}catch(Throwable $e){}
    try{$platform=hsg_supplier_platform_candidates($root,$product);foreach($platform['pages']??[] as $u){if(supplier_same_host((string)$u,$preferred)){$pageUrls[]=$u;$providerByUrl[$u]='supplier-api';}}foreach($platform['images']??[] as $c)if(is_array($c)&&supplier_same_host((string)($c['page_url']??''),$preferred))$directImages[]=$c;}catch(Throwable $e){}
    try{foreach(supplier_internal_candidates($root,$product) as $u){if(supplier_same_host((string)$u,$preferred)){$pageUrls[]=$u;$providerByUrl[$u]='supplier';}}}catch(Throwable $e){}
    $pageUrls=array_values(array_unique($pageUrls));$supplierPages=[];$apiPages=[];foreach($pageUrls as $u){$p=$providerByUrl[$u]??'supplier';if($p==='supplier-api')$apiPages[]=$u;else $supplierPages[]=$u;}
    $candidates=array_merge($directImages,hsg_discover_page_image_candidates($product,$apiPages,'supplier-api',$preferred,24),hsg_discover_page_image_candidates($product,$supplierPages,'supplier',$preferred,28));
    $dedupe=[];foreach($candidates as $c){$page=trim((string)($c['page_url']??''));if($page===''||!supplier_same_host($page,$preferred))continue;$key=(string)($c['image_url']?:$page);if($key==='' )continue;if(!isset($dedupe[$key])||$c['confidence']>$dedupe[$key]['confidence'])$dedupe[$key]=$c;}$candidates=array_values($dedupe);usort($candidates,static fn($a,$b)=>($b['confidence']??0)<=>($a['confidence']??0));return array_slice($candidates,0,30);
}
function hsg_rejection_canonical_url(string $url): string {
    $url=trim(html_entity_decode($url,ENT_QUOTES|ENT_HTML5,'UTF-8'));if($url==='')return '';
    $p=parse_url($url);if(!$p||empty($p['host']))return strtolower($url);
    $scheme=strtolower((string)($p['scheme']??'https'));$host=strtolower((string)$p['host']);$host=preg_replace('/^www\./i','',$host)?:$host;$path=(string)($p['path']??'/');
    // Ignore image CDN resize/cache query strings so the same wrong bottle cannot reappear in another size.
    return $scheme.'://'.$host.$path;
}
function hsg_rejection_hash(string $url): string { $u=hsg_rejection_canonical_url($url);return $u===''?'':hash('sha256',$u); }
function hsg_image_rejection_hashes(PDO $pdo,int $productId): array {
    if(!function_exists('db_table_exists')||!db_table_exists($pdo,'lager_image_rejections'))return [];$q=$pdo->prepare('SELECT url_hash FROM lager_image_rejections WHERE product_id=?');$q->execute([$productId]);$out=[];foreach($q->fetchAll(PDO::FETCH_COLUMN) as $h)$out[(string)$h]=true;return $out;
}
function hsg_image_url_is_rejected(array $hashes,string $url): bool { $h=hsg_rejection_hash($url);return $h!==''&&isset($hashes[$h]); }
function hsg_reject_image_urls(PDO $pdo,int $productId,array $urls,string $reason='Forkert billede'): int {
    if(!function_exists('db_table_exists')||!db_table_exists($pdo,'lager_image_rejections'))throw new RuntimeException('Databasen mangler billedafvisningstabellen. Kør systemopgraderingen igen.');
    $ins=$pdo->prepare('INSERT INTO lager_image_rejections(product_id,url_hash,url,reason,created_at) VALUES(?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE url=VALUES(url),reason=VALUES(reason),created_at=VALUES(created_at)');$count=0;
    foreach($urls as $url){$url=trim((string)$url);$hash=hsg_rejection_hash($url);if($url===''||$hash==='')continue;$ins->execute([$productId,$hash,substr($url,0,1000),substr($reason,0,300)]);$count++;}
    return $count;
}
function hsg_reject_candidate(PDO $pdo,int $productId,int $candidateId): array {
    $q=$pdo->prepare('SELECT id,page_url,image_url FROM lager_image_candidates WHERE id=? AND product_id=?');$q->execute([$candidateId,$productId]);$c=$q->fetch(PDO::FETCH_ASSOC);if(!$c)throw new RuntimeException('Billedkandidaten findes ikke længere.');
    $count=hsg_reject_image_urls($pdo,$productId,[$c['page_url']??'',$c['image_url']??''],'Afvist manuelt i Billedtjek');$pdo->prepare('DELETE FROM lager_image_candidates WHERE id=? AND product_id=?')->execute([$candidateId,$productId]);return ['rejected'=>$count];
}
function hsg_reject_current_product_image(PDO $pdo,int $productId): array {
    $q=$pdo->prepare('SELECT image_path,image_source_url FROM lager_products WHERE id=?');$q->execute([$productId]);$p=$q->fetch(PDO::FETCH_ASSOC);if(!$p)throw new RuntimeException('Produktet findes ikke.');if(empty($p['image_path']))throw new RuntimeException('Produktet har ikke et billede at afvise.');
    $count=hsg_reject_image_urls($pdo,$productId,[$p['image_source_url']??''],'Det valgte produktbillede blev afvist manuelt');
    $file=__DIR__.'/'.ltrim((string)$p['image_path'],'/');$productDir=realpath(image_dir());$real=is_file($file)?realpath($file):false;if($real&&$productDir&&str_starts_with($real,$productDir.DIRECTORY_SEPARATOR))@unlink($real);
    $pdo->prepare("UPDATE lager_products SET image_path=NULL,image_source_url=NULL,image_checked_at=NOW(),image_method=NULL,image_confidence=NULL,image_ai_note=NULL,image_validation_score=NULL,image_validation_status=NULL,image_validation_note=NULL,image_validated_at=NULL,image_validation_model=NULL,image_approval_status='rejected',image_approved_at=NULL,image_approved_by_admin=NULL WHERE id=?")->execute([$productId]);
    $pdo->prepare('DELETE FROM lager_image_candidates WHERE product_id=?')->execute([$productId]);return ['rejected'=>$count];
}
function hsg_approve_current_product_image(PDO $pdo,int $productId,?int $adminId=null): array {
    $q=$pdo->prepare('SELECT image_path,image_source_url,image_ai_note,image_validation_score,image_validation_status FROM lager_products WHERE id=?');$q->execute([$productId]);$p=$q->fetch(PDO::FETCH_ASSOC);if(!$p)throw new RuntimeException('Produktet findes ikke.');
    if(!image_is_present($p['image_path']??null))throw new RuntimeException('Produktet har ikke et billede at godkende.');
    $score=$p['image_validation_score']!==null?(int)$p['image_validation_score']:null;$status=(string)($p['image_validation_status']??'');
    if($score===null||$status!=='verified')throw new RuntimeException('Billedet skal AI-valideres, før det kan sendes til manuel godkendelse.');
    if($score<80)throw new RuntimeException('Billedet har kun '.$score.' % valideringssandsynlighed. Kun billeder på mindst 80 % kan godkendes manuelt.');
    $pdo->prepare("UPDATE lager_products SET image_approval_status='approved',image_approved_at=NOW(),image_approved_by_admin=? WHERE id=?")->execute([$adminId,$productId]);
    return ['approved'=>true,'approved_at'=>date('Y-m-d H:i:s'),'validation_score'=>$score,'validation_status'=>$status];
}

/* Automatic image discovery was removed in v1.4.1. Images are imported from HSG catalogs or added manually. */

/**
 * Validate the actual cached bottle image against HSG product metadata.
 * 80% is the AI validation gate before an image is sent to manual HSG approval.
 */
function validate_product_image(PDO $pdo,int $productId): array {
    if(total_available_for($pdo,$productId)<=0) throw new RuntimeException('Produktet har ikke disponibelt lager og springes derfor over i Billedtjek.');
    $st=$pdo->prepare('SELECT p.id,p.sku,p.name,p.distillery,p.age_text,p.abv,p.cask_type,p.cask_number,p.image_path,b.name brand_name FROM lager_products p LEFT JOIN lager_brands b ON b.id=p.brand_id WHERE p.id=?');
    $st->execute([$productId]);$product=$st->fetch();
    if(!$product) throw new RuntimeException('Produktet findes ikke.');
    if(!image_is_present($product['image_path']??null)) throw new RuntimeException('Produktet har ikke et lokalt billede at validere.');
    if(!hsg_ai_image_validation_ready($pdo)) throw new RuntimeException('Billedvalidering kræver en gemt Groq API-nøgle og PHP cURL.');
    $absolute=__DIR__.'/'.(string)$product['image_path'];
    try{
        $validation=hsg_ai_validate_product_image($pdo,$product,$absolute);
        $score=max(0,min(100,(int)($validation['score']??0)));
        $status=$score>=80?'verified':'flagged';
        $note=substr(trim((string)($validation['reason']??'')),0,1000);
        $model=substr(trim((string)($validation['model']??'')),0,120);
        $pdo->prepare('UPDATE lager_products SET image_validation_score=?,image_validation_status=?,image_validation_note=?,image_validated_at=NOW(),image_validation_model=? WHERE id=?')
            ->execute([$score,$status,$note,$model,$productId]);
        return ['score'=>$score,'status'=>$status,'note'=>$note,'model'=>$model,'threshold'=>80];
    }catch(Throwable $e){
        // Rate limiting is transient and must not flag an otherwise unknown image as invalid.
        if($e instanceof HsgGroqRateLimitException) throw $e;
        $note=substr($e->getMessage(),0,1000);
        $pdo->prepare("UPDATE lager_products SET image_validation_score=NULL,image_validation_status='error',image_validation_note=?,image_validated_at=NOW() WHERE id=?")
            ->execute([$note,$productId]);
        throw $e;
    }
}
