<?php
declare(strict_types=1);

require_once __DIR__.'/settings.php';

/**
 * Minimal OpenAI Responses API client used only as an optional fallback for
 * product image discovery. No Composer dependency is required.
 */
function hsg_openai_image_enabled(PDO $pdo): bool {
    return setting_get($pdo,'image_ai_enabled','0')==='1'
        && trim((string)setting_get($pdo,'openai_api_key',''))!=='';
}

function hsg_openai_api_key(PDO $pdo): string {
    return trim((string)setting_get($pdo,'openai_api_key',''));
}

function hsg_openai_model(PDO $pdo): string {
    $model=trim((string)setting_get($pdo,'image_ai_model','gpt-5.6-luna'));
    return $model!=='' ? $model : 'gpt-5.6-luna';
}

function hsg_openai_response_text(array $response): string {
    $parts=[];
    foreach((array)($response['output']??[]) as $item){
        if(($item['type']??'')!=='message') continue;
        foreach((array)($item['content']??[]) as $content){
            if(($content['type']??'')==='output_text' && isset($content['text'])) $parts[]=(string)$content['text'];
        }
    }
    return trim(implode("\n",$parts));
}

function hsg_openai_collect_urls(mixed $value,array &$out): void {
    if(!is_array($value)) return;
    foreach($value as $key=>$child){
        if(is_string($child) && strtolower((string)$key)==='url' && preg_match('~^https?://~i',$child)) $out[$child]=true;
        elseif(is_array($child)) hsg_openai_collect_urls($child,$out);
    }
}

function hsg_openai_json_from_text(string $text): array {
    $text=trim($text);
    $text=preg_replace('/^```(?:json)?\s*/i','',$text)??$text;
    $text=preg_replace('/\s*```$/','',$text)??$text;
    $decoded=json_decode($text,true);
    if(is_array($decoded)) return $decoded;
    $start=strpos($text,'{');$end=strrpos($text,'}');
    if($start!==false && $end!==false && $end>$start){
        $decoded=json_decode(substr($text,$start,$end-$start+1),true);
        if(is_array($decoded)) return $decoded;
    }
    return [];
}

function hsg_openai_responses(PDO $pdo,array $payload): array {
    if(!function_exists('curl_init')) throw new RuntimeException('PHP cURL mangler. AI-fallback kræver cURL på webhotellet.');
    $apiKey=hsg_openai_api_key($pdo);
    if($apiKey==='') throw new RuntimeException('OpenAI API-nøgle mangler under Billedtjek > AI-fallback.');

    $ch=curl_init('https://api.openai.com/v1/responses');
    curl_setopt_array($ch,[
        CURLOPT_POST=>true,
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_CONNECTTIMEOUT=>10,
        CURLOPT_TIMEOUT=>55,
        CURLOPT_HTTPHEADER=>[
            'Authorization: Bearer '.$apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),
    ]);
    $raw=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$err=curl_error($ch);curl_close($ch);
    if($raw===false) throw new RuntimeException('AI-forbindelsen fejlede: '.$err);
    $json=json_decode((string)$raw,true);
    if(!is_array($json)) throw new RuntimeException('OpenAI returnerede et ugyldigt svar.');
    if($code<200 || $code>=300){
        $message=(string)($json['error']['message']??('HTTP '.$code));
        throw new RuntimeException('OpenAI API-fejl: '.$message);
    }
    return $json;
}

/**
 * Ask OpenAI web search for likely official product pages. The model only
 * discovers URLs; HSG itself downloads/crops the source image afterwards.
 */
function hsg_openai_find_product_pages(PDO $pdo,array $product,?string $preferredHost=null): array {
    $model=hsg_openai_model($pdo);
    $preferredHost=trim((string)$preferredHost);
    $facts=[];
    foreach([
        'Produktnavn'=>$product['name']??'',
        'Brand/leverandør'=>$product['brand_name']??'',
        'Destilleri'=>$product['distillery']??'',
        'Alder'=>$product['age_text']??'',
        'Alkoholprocent'=>isset($product['abv'])&&$product['abv']!==null&&$product['abv']!==''?((string)$product['abv'].'%'):'',
        'Fadtype'=>$product['cask_type']??'',
        'SKU'=>$product['sku']??'',
    ] as $label=>$value){if(trim((string)$value)!=='')$facts[]=$label.': '.trim((string)$value);}

    $domainRule=$preferredHost!==''
        ? "Søg KUN efter den konkrete produktside på domænet {$preferredHost}. Returnér ikke forhandlere eller andre domæner."
        : 'Find helst producentens, brandets eller den officielle leverandørs egen produktside. Undgå markedspladser, sociale medier og generiske forhandlere.';

    $prompt="Du hjælper HSG Whisky med at finde det korrekte officielle flaskebillede til et produkt.\n"
        .$domainRule."\n"
        ."Produktdata:\n- ".implode("\n- ",$facts)."\n\n"
        ."Brug web search. Find højst 5 sider, der faktisk matcher denne præcise aftapning. "
        ."Årgang, alder, ABV og aftapningsnavn skal bruges til at skelne mellem næsten ens produkter. "
        ."Opfind aldrig en URL. Hvis du ikke er sikker, sænk confidence eller returnér ingen kandidat. "
        ."Returnér KUN gyldig JSON uden markdown i dette format: "
        .'{"candidates":[{"page_url":"https://...","image_url":"","confidence":92,"reason":"kort begrundelse"}]}';

    $tool=['type'=>'web_search_preview','search_context_size'=>'medium'];
    if($preferredHost!=='') $tool['domains']=[$preferredHost];
    $payload=[
        'model'=>$model,
        'store'=>false,
        'tools'=>[$tool],
        'tool_choice'=>'auto',
        'include'=>['web_search_call.results'],
        'input'=>$prompt,
        'max_output_tokens'=>1200,
    ];
    $response=hsg_openai_responses($pdo,$payload);
    $parsed=hsg_openai_json_from_text(hsg_openai_response_text($response));
    $candidates=[];$seen=[];
    foreach((array)($parsed['candidates']??[]) as $candidate){
        if(!is_array($candidate)) continue;
        $page=trim((string)($candidate['page_url']??''));$image=trim((string)($candidate['image_url']??''));
        if($page==='' && $image==='') continue;
        $key=$page!==''?$page:$image;if(isset($seen[$key]))continue;$seen[$key]=true;
        $candidates[]=[
            'page_url'=>$page,
            'image_url'=>$image,
            'confidence'=>max(0,min(100,(int)($candidate['confidence']??0))),
            'reason'=>substr(trim((string)($candidate['reason']??'')),0,500),
        ];
    }

    // If the model returned citations/sources but malformed JSON, same-domain source
    // URLs are still useful candidates for HSG's own page/image extractor.
    if(!$candidates && $preferredHost!==''){
        $urls=[];hsg_openai_collect_urls($response,$urls);
        foreach(array_keys($urls) as $url){
            $host=strtolower((string)parse_url($url,PHP_URL_HOST));$host=preg_replace('/^www\./','',$host)?:'';
            if($host===$preferredHost || str_ends_with($host,'.'.$preferredHost)){
                $candidates[]=['page_url'=>$url,'image_url'=>'','confidence'=>70,'reason'=>'Kilde fundet af AI-websøgning på leverandørdomænet.'];
                if(count($candidates)>=5)break;
            }
        }
    }
    usort($candidates,static fn($a,$b)=>($b['confidence']??0)<=>($a['confidence']??0));
    return $candidates;
}
