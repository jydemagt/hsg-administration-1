<?php
declare(strict_types=1);

require_once __DIR__.'/settings.php';

final class HsgGroqRateLimitException extends RuntimeException {
    public function __construct(string $message, public readonly int $retryAfterSeconds=20) {
        parent::__construct($message);
    }
}

function hsg_groq_validation_from_text(string $text): array {
    $json=hsg_groq_json_from_text($text);
    if($json) return $json;
    $out=[];
    $map=[
        'score'=>'score','verdict'=>'verdict','reason'=>'reason',
        'observed_name'=>'observed_name','observed_brand'=>'observed_brand',
        'observed_age'=>'observed_age','observed_abv'=>'observed_abv',
    ];
    foreach(preg_split('/\R/u',trim($text))?:[] as $line){
        if(!preg_match('/^\s*([A-Z_]+)\s*[=:]\s*(.*?)\s*$/iu',$line,$m)) continue;
        $key=strtolower($m[1]);
        if(isset($map[$key])) $out[$map[$key]]=trim($m[2]," \t\n\r\0\x0B\"'");
    }
    if(!isset($out['score']) && preg_match('/\bscore\s*[:=]\s*(\d{1,3})\b/i',$text,$m)) $out['score']=(int)$m[1];
    return isset($out['score']) ? $out : [];
}

function hsg_groq_api_key(PDO $pdo): string {
    return trim((string)setting_get($pdo,'groq_api_key',''));
}

function hsg_groq_model(PDO $pdo): string {
    // Image discovery needs Groq's Compound web-search system. Text-only GPT-OSS
    // models have much lower Free Plan TPM limits and are not the right backend
    // for this search step. Normalize stale/custom values automatically.
    $model=trim((string)setting_get($pdo,'image_ai_model','groq/compound-mini'));
    if(!in_array($model,['groq/compound-mini','groq/compound'],true)){
        $model='groq/compound-mini';
        setting_set($pdo,'image_ai_model',$model);
    }
    return $model;
}

function hsg_groq_json_from_text(string $text): array {
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

function hsg_groq_collect_urls(mixed $value,array &$out): void {
    if(is_string($value)){
        if(preg_match_all('~https?://[^\s\]\[\)\(<>"\']+~i',$value,$m)){
            foreach($m[0] as $url)$out[rtrim($url,".,;:!?")]=true;
        }
        return;
    }
    if(!is_array($value)) return;
    foreach($value as $child) hsg_groq_collect_urls($child,$out);
}

function hsg_groq_chat(PDO $pdo,array $payload): array {
    if(!function_exists('curl_init')) throw new RuntimeException('PHP cURL mangler. Groq AI-fallback kræver cURL på webhotellet.');
    $apiKey=hsg_groq_api_key($pdo);
    if($apiKey==='') throw new RuntimeException('Groq API-nøgle mangler under Billedtjek > AI-fallback.');

    // One short server-side retry makes normal single-item actions resilient to
    // brief TPM windows (for example "try again in 9.5s"). Longer limits are
    // returned to the browser, where bulk validation already waits/retries.
    $maxShortRetries=1;
    for($attempt=0;;$attempt++){
        $headers=[];
        $ch=curl_init('https://api.groq.com/openai/v1/chat/completions');
        curl_setopt_array($ch,[
            CURLOPT_POST=>true,
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_CONNECTTIMEOUT=>10,
            CURLOPT_TIMEOUT=>55,
            CURLOPT_HTTPHEADER=>[
                'Authorization: Bearer '.$apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_HEADERFUNCTION=>static function($ch,string $line) use (&$headers): int {
                $len=strlen($line);$pos=strpos($line,':');
                if($pos!==false){$name=strtolower(trim(substr($line,0,$pos)));$value=trim(substr($line,$pos+1));if($name!=='')$headers[$name]=$value;}
                return $len;
            },
            CURLOPT_POSTFIELDS=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),
        ]);
        $raw=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$err=curl_error($ch);curl_close($ch);
        if($raw===false) throw new RuntimeException('Groq-forbindelsen fejlede: '.$err);
        $json=json_decode((string)$raw,true);
        if(!is_array($json)) throw new RuntimeException('Groq returnerede et ugyldigt svar.');
        if($code<200 || $code>=300){
            $message=(string)($json['error']['message']??('HTTP '.$code));
            if($code===429){
                $retry=0;
                if(isset($headers['retry-after']) && is_numeric($headers['retry-after'])) $retry=(int)ceil((float)$headers['retry-after']);
                if($retry<1 && preg_match('/try again in\\s+([0-9.]+)s/i',$message,$m)) $retry=(int)ceil((float)$m[1]);
                if($retry<1 && isset($headers['x-ratelimit-reset-tokens']) && preg_match('/([0-9.]+)s/i',(string)$headers['x-ratelimit-reset-tokens'],$m)) $retry=(int)ceil((float)$m[1]);
                $retry=max(2,min(120,$retry?:20));
                if($attempt<$maxShortRetries && $retry<=15){
                    usleep(($retry*1000000)+250000);
                    continue;
                }
                throw new HsgGroqRateLimitException('Groq rate limit: '.$message,$retry);
            }
            throw new RuntimeException('Groq API-fejl: '.$message);
        }
        $json['_hsg_rate_headers']=$headers;
        return $json;
    }
}

/**
 * Free-plan-friendly AI fallback. Groq Compound performs web search and HSG
 * still downloads/crops the actual image from the source page afterwards.
 */
function hsg_groq_find_product_pages(PDO $pdo,array $product,?string $preferredHost=null): array {
    $preferredHost=trim((string)$preferredHost);
    $facts=[];
    foreach([
        'Produktnavn'=>$product['name']??'',
        'Brand/leverandør'=>$product['brand_name']??'',
        'Destilleri'=>$product['distillery']??'',
        'Alder'=>$product['age_text']??'',
        'Alkoholprocent'=>isset($product['abv'])&&$product['abv']!==null&&$product['abv']!==''?((string)$product['abv'].'%'):'',
        'Fadtype'=>$product['cask_type']??'',
        'Fadnummer'=>$product['cask_number']??'',
        'SKU'=>$product['sku']??'',
    ] as $label=>$value){if(trim((string)$value)!=='')$facts[]=$label.': '.trim((string)$value);}

    $domainRule=$preferredHost!==''
        ? "Søg kun efter den præcise produktside på {$preferredHost}. Alle page_url/image_url skal være på dette domæne eller et subdomæne."
        : 'Prioritér producentens, brandets eller den officielle leverandørs egen produktside. Undgå markedspladser, sociale medier og generiske forhandlere.';

    $prompt="Du er billed-fallback for HSG Whisky. Brug web search til at finde den officielle produktside for denne præcise flaske.\n"
        .$domainRule."\nProduktdata:\n- ".implode("\n- ",$facts)."\n\n"
        ."Vær konservativ. Fadnummer er det stærkeste matchsignal, når det findes. Derefter skal årgang, alder, ABV og produktnavn bruges til at skelne mellem næsten ens aftapninger. "
        ."Opfind aldrig URL'er. Returnér højst 5 kandidater. Hvis du er usikker, sænk confidence. "
        ."Svar kun med gyldig JSON uden markdown: "
        .'{"candidates":[{"page_url":"https://...","image_url":"","confidence":92,"reason":"kort begrundelse"}]}' ;

    $payload=[
        'model'=>hsg_groq_model($pdo),
        'messages'=>[['role'=>'user','content'=>$prompt]],
        'temperature'=>0.1,
        'max_completion_tokens'=>1200,
    ];
    // Groq Compound/Compound Mini performs web search itself. Do not send
    // citation_options: some Compound endpoints reject that request field even
    // though search sources/citations are returned automatically in responses.
    if($preferredHost!==''){
        $payload['search_settings']=['include_domains'=>[$preferredHost]];
    }
    $response=hsg_groq_chat($pdo,$payload);
    $text=trim((string)($response['choices'][0]['message']['content']??''));
    $parsed=hsg_groq_json_from_text($text);
    $candidates=[];$seen=[];
    foreach((array)($parsed['candidates']??[]) as $candidate){
        if(!is_array($candidate)) continue;
        $page=trim((string)($candidate['page_url']??''));$image=trim((string)($candidate['image_url']??''));
        if($page===''&&$image==='') continue;
        $key=$page!==''?$page:$image;if(isset($seen[$key]))continue;$seen[$key]=true;
        $candidates[]=[
            'page_url'=>$page,
            'image_url'=>$image,
            'confidence'=>max(0,min(100,(int)($candidate['confidence']??0))),
            'reason'=>substr(trim((string)($candidate['reason']??'')),0,430).' [Groq]',
        ];
    }

    // Compound can attach source URLs outside the JSON. Reuse only matching
    // official-domain URLs as conservative fallback candidates.
    if(!$candidates){
        $urls=[];hsg_groq_collect_urls($response,$urls);
        foreach(array_keys($urls) as $url){
            $host=strtolower((string)parse_url($url,PHP_URL_HOST));$host=preg_replace('/^www\./','',$host)?:'';
            if($preferredHost!=='' && !($host===$preferredHost || str_ends_with($host,'.'.$preferredHost))) continue;
            if($preferredHost==='' && $host==='') continue;
            $candidates[]=['page_url'=>$url,'image_url'=>'','confidence'=>72,'reason'=>'Kilde fundet af Groq Compound web search. [Groq]'];
            if(count($candidates)>=5)break;
        }
    }
    usort($candidates,static fn($a,$b)=>($b['confidence']??0)<=>($a['confidence']??0));
    return $candidates;
}

/**
 * Vision validation of a locally cached bottle image. This is deliberately
 * separate from Compound web search: Qwen vision inspects the actual saved
 * image and scores how well the visible label matches HSG's product data.
 */
function hsg_groq_available_model_ids(PDO $pdo): array {
    if(!function_exists('curl_init')) return [];
    $apiKey=hsg_groq_api_key($pdo);
    if($apiKey==='') return [];

    $ch=curl_init('https://api.groq.com/openai/v1/models');
    curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_CONNECTTIMEOUT=>8,
        CURLOPT_TIMEOUT=>20,
        CURLOPT_HTTPHEADER=>[
            'Authorization: Bearer '.$apiKey,
            'Content-Type: application/json',
        ],
    ]);
    $raw=curl_exec($ch);
    $code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if($raw===false || $code<200 || $code>=300) return [];
    $json=json_decode((string)$raw,true);
    if(!is_array($json)) return [];
    $ids=[];
    foreach((array)($json['data']??[]) as $model){
        if(!is_array($model)) continue;
        $id=trim((string)($model['id']??''));
        if($id!=='') $ids[]=$id;
    }
    return array_values(array_unique($ids));
}

function hsg_groq_validation_models(PDO $pdo): array {
    // Groq's currently documented vision model is Qwen 3.6 27B.
    // Do not keep stale hard-coded fallbacks: an unavailable fallback used to
    // hide the real error from the primary model.
    $supportedVision=['qwen/qwen3.6-27b'];
    $configured=trim((string)setting_get($pdo,'image_validation_model','qwen/qwen3.6-27b'));
    if($configured==='' || !in_array($configured,$supportedVision,true)){
        $configured='qwen/qwen3.6-27b';
        setting_set($pdo,'image_validation_model',$configured);
    }

    $available=hsg_groq_available_model_ids($pdo);
    if($available){
        $models=[];
        foreach(array_unique(array_merge([$configured],$supportedVision)) as $model){
            if(in_array($model,$available,true)) $models[]=$model;
        }
        if(!$models){
            throw new RuntimeException('Din Groq-konto viser ingen understøttet vision-model. HSG forventer qwen/qwen3.6-27b. Kontrollér Groq Model Permissions eller prøv igen senere.');
        }
        return $models;
    }

    // If the model-list endpoint itself is temporarily unavailable, try the
    // documented vision model directly so validation can still work.
    return [$configured];
}

function hsg_groq_validate_product_image(PDO $pdo,array $product,string $absolutePath): array {
    if(hsg_groq_api_key($pdo)==='') throw new RuntimeException('Groq API-nøgle mangler. Billedvalidering bruger Groq Vision.');
    if(!is_file($absolutePath)) throw new RuntimeException('Det lokale produktbillede findes ikke.');
    $bytes=@file_get_contents($absolutePath);
    if($bytes===false || $bytes==='') throw new RuntimeException('Produktbilledet kunne ikke læses.');
    if(strlen($bytes)>20*1024*1024) throw new RuntimeException('Produktbilledet er for stort til AI-validering.');

    $mime='image/jpeg';
    if(function_exists('finfo_open')){
        $fi=@finfo_open(FILEINFO_MIME_TYPE);
        if($fi){$detected=@finfo_file($fi,$absolutePath);@finfo_close($fi);if(is_string($detected)&&str_starts_with($detected,'image/'))$mime=$detected;}
    }
    $dataUrl='data:'.$mime.';base64,'.base64_encode($bytes);

    $facts=[];
    foreach([
        'Produktnavn'=>$product['name']??'',
        'Brand/leverandør'=>$product['brand_name']??'',
        'Destilleri'=>$product['distillery']??'',
        'Alder'=>$product['age_text']??'',
        'Alkoholprocent'=>isset($product['abv'])&&$product['abv']!==null&&$product['abv']!==''?((string)$product['abv'].'%'):'',
        'Fadtype'=>$product['cask_type']??'',
        'Fadnummer'=>$product['cask_number']??'',
        'SKU'=>$product['sku']??'',
    ] as $label=>$value){if(trim((string)$value)!=='')$facts[]=$label.': '.trim((string)$value);}

    $prompt="Du er en streng kvalitetskontrollør af whiskyflaskebilleder for HSG Whisky.\n"
        ."Sammenlign DET VEDHÆFTEDE BILLEDE med produktdataene nedenfor. Vurder kun det, der faktisk kan ses eller læses på flasken/etiketten. Gæt aldrig ud fra en generisk flaskefacon.\n\n"
        ."Produktdata:\n- ".implode("\n- ",$facts)."\n\n"
        ."Scoringsregel (skal følges konservativt):\n"
        ."98-100: præcis flaske er tydeligt bekræftet af navn/brand og afgørende variantoplysninger.\n"
        ."95-97: meget stærkt match; ingen synlige modsigelser, og mindst produktnavn/brand plus én vigtig variantoplysning matcher.\n"
        ."80-94: sandsynligt match, men mindst én vigtig identifikator er ulæselig/mangler.\n"
        ."50-79: kun brand/destilleri eller generel serie kan bekræftes.\n"
        ."0-49: forkert produkt, modstridende alder/ABV/årgang/navn, ikke en flaske eller utilstrækkeligt billede.\n"
        ."Hvis tekst er for lille/ulæselig til at bekræfte den konkrete aftapning, må score IKKE være 95 eller højere. "
        ."En synlig modsigelse i fadnummer, produktnavn, alder, årgang eller ABV skal normalt give under 50. Hvis korrekt fadnummer tydeligt kan aflæses, skal det vægte meget højt.\n"
        ."Svar KUN med disse 7 linjer og ingen markdown eller ekstra tekst:\n"
        ."SCORE=0-100\nVERDICT=match|uncertain|mismatch\nREASON=kort dansk forklaring\nOBSERVED_NAME=aflæst navn eller tom\nOBSERVED_BRAND=aflæst brand eller tom\nOBSERVED_AGE=aflæst alder eller tom\nOBSERVED_ABV=aflæst ABV eller tom\n";

    $lastError=null;
    foreach(hsg_groq_validation_models($pdo) as $model){
        $payload=[
            'model'=>$model,
            'messages'=>[[
                'role'=>'user',
                'content'=>[
                    ['type'=>'text','text'=>$prompt],
                    ['type'=>'image_url','image_url'=>['url'=>$dataUrl]],
                ],
            ]],
            'temperature'=>0.1,
            'max_completion_tokens'=>420,
            'reasoning_effort'=>'none',
        ];
        try{
            $response=hsg_groq_chat($pdo,$payload);
            $text=trim((string)($response['choices'][0]['message']['content']??''));
            $json=hsg_groq_validation_from_text($text);
            if(!$json) throw new RuntimeException('Vision-modellen returnerede ikke et læsbart valideringssvar.');
            $score=max(0,min(100,(int)($json['score']??0)));
            $verdict=strtolower(trim((string)($json['verdict']??'uncertain')));
            if(!in_array($verdict,['match','uncertain','mismatch'],true))$verdict=$score>=95?'match':($score<50?'mismatch':'uncertain');
            $reason=trim((string)($json['reason']??''));
            $observed=[];
            foreach(['observed_name'=>'navn','observed_brand'=>'brand','observed_age'=>'alder','observed_abv'=>'ABV'] as $key=>$label){
                $v=trim((string)($json[$key]??''));if($v!=='')$observed[]=$label.': '.$v;
            }
            if($observed)$reason.=($reason!==''?' · ':'').'Aflæst '.implode(', ',$observed);
            return [
                'score'=>$score,
                'verdict'=>$verdict,
                'reason'=>substr($reason,0,950),
                'model'=>$model,
            ];
        }catch(Throwable $e){$lastError=$e;}
    }
    throw new RuntimeException('Groq Vision kunne ikke validere billedet'.($lastError?': '.$lastError->getMessage():'.'));
}
