<?php
declare(strict_types=1);

require_once __DIR__.'/groq.php';

/**
 * Deterministic extraction from the product/vare text. This layer never calls AI
 * and only returns fields that can be read with reasonably high confidence.
 */
function hsg_extract_cask_number(string $text): ?string {
    $text=trim($text);if($text==='')return null;
    $patterns=[
      '/\b(?:cask|fad|barrel)\s*(?:no\.?|nr\.?|number)?\s*#?\s*([A-Z0-9][A-Z0-9.\-\/]{1,30})\b/iu',
      '/#\s*([A-Z0-9][A-Z0-9.\-\/]{1,30})\b/iu'
    ];
    foreach($patterns as $pi=>$rx){
        if(!preg_match_all($rx,$text,$mm,PREG_SET_ORDER|PREG_OFFSET_CAPTURE))continue;
        foreach($mm as $m){
            $value=trim((string)$m[1][0]);$offset=(int)$m[0][1];
            $before=function_exists('mb_substr')?mb_substr($text,0,$offset,'UTF-8'):substr($text,0,$offset);
            $tail=function_exists('mb_substr')?mb_substr($before,max(0,mb_strlen($before,'UTF-8')-18),null,'UTF-8'):substr($before,-18);
            if($pi===1 && preg_match('/\b(?:batch|bottle|flaske)\s*$/iu',$tail))continue;
            if(preg_match('/^(?:19|20)\d{2}$/',$value))continue;
            return strtoupper($value);
        }
    }
    return null;
}

function hsg_product_parse_text(string $text, array $context=[]): array {
    $text=trim(preg_replace('/\s+/u',' ',str_replace(["\xE2\x80\x93","\xE2\x80\x94"],'-',$text))??$text);
    $fields=[];$conf=[];$reasons=[];
    if($text==='') return ['fields'=>[],'confidence'=>[],'source'=>'rules','reason'=>'Ingen varetekst.'];

    // ABV: 60,7%, 60.7 %, 46% vol.
    if(preg_match('/(?<!\d)(\d{1,2}(?:[\.,]\d{1,2})?)\s*%\s*(?:vol\.?|alc\.?)?/iu',$text,$m)){
        $abv=(float)str_replace(',','.',$m[1]);
        if($abv>0 && $abv<=100){$fields['abv']=$abv;$conf['abv']=99;$reasons[]='ABV aflæst direkte fra teksten';}
    }

    // Age: 12 år, 12yo, 12 year old.
    if(preg_match('/(?<!\d)(\d{1,3})\s*(?:år|a?r|yo\b|y\.o\.?\b|years?\s*old\b|years?\b)/iu',$text,$m)){
        $age=(int)$m[1];
        if($age>0 && $age<150){$fields['age_text']=$age.' år';$conf['age_text']=99;$reasons[]='alder aflæst direkte fra teksten';}
    }

    // Vintage/distillation year. Prefer a parenthesized year, then a standalone year.
    if(preg_match('/\((19\d{2}|20\d{2})\)/u',$text,$m) || preg_match('/\b(19\d{2}|20\d{2})\b/u',$text,$m)){
        $year=(int)$m[1];$now=(int)date('Y');
        if($year>=1900 && $year<=$now){$fields['vintage_year']=$year;$conf['vintage_year']=97;$reasons[]='årgang/årstal aflæst fra teksten';}
    }

    // Cask number: only explicit # / Cask No. / Fad nr. notation. Batch numbers are deliberately ignored.
    $caskNumber=hsg_extract_cask_number($text);
    if($caskNumber!==null){$fields['cask_number']=$caskNumber;$conf['cask_number']=99;$reasons[]='fadnummer aflæst direkte efter #/Cask No.';}

    // Bottle size: 70 cl, 50cl, 0.7 L, 700 ml.
    if(preg_match('/\b(\d{2,3}(?:[\.,]\d+)?)\s*cl\b/iu',$text,$m)){
        $cl=(float)str_replace(',','.',$m[1]);if($cl>=5&&$cl<=500){$fields['bottle_size_cl']=$cl;$conf['bottle_size_cl']=99;$reasons[]='flaskestørrelse aflæst i cl';}
    } elseif(preg_match('/\b(\d{2,4})\s*ml\b/iu',$text,$m)){
        $cl=((float)$m[1])/10;if($cl>=5&&$cl<=500){$fields['bottle_size_cl']=$cl;$conf['bottle_size_cl']=99;$reasons[]='flaskestørrelse aflæst i ml';}
    } elseif(preg_match('/\b(0[\.,]\d+|1[\.,]0)\s*l(?:iter|itre)?\b/iu',$text,$m)){
        $cl=(float)str_replace(',','.',$m[1])*100;if($cl>=5&&$cl<=500){$fields['bottle_size_cl']=$cl;$conf['bottle_size_cl']=98;$reasons[]='flaskestørrelse aflæst i liter';}
    }

    // Category/type – only explicit words in the text.
    $categoryRules=[
        'Single Malt'=>'/\bsingle\s+malt\b/iu',
        'Blended Malt'=>'/\bblended\s+malt\b/iu',
        'Single Grain'=>'/\bsingle\s+grain\b/iu',
        'Rye Whisky'=>'/\brye\b/iu',
        'Bourbon'=>'/\bbourbon\b/iu',
        'Rum'=>'/\brum\b/iu',
        'Gin'=>'/\bgin\b/iu',
        'Vodka'=>'/\bvodka\b/iu',
        'Akvavit'=>'/\bakvavit\b|\baquavit\b/iu',
        'Likør'=>'/\blik[oø]r\b|\bliqueur\b/iu',
        'New Make'=>'/\bnew\s+make\b/iu',
    ];
    foreach($categoryRules as $label=>$rx){if(preg_match($rx,$text)){$fields['category']=$label;$conf['category']=96;$reasons[]='kategori fundet direkte i teksten';break;}}

    // Country only where the country/nationality is explicit.
    $countryRules=[
        'Scotland'=>'/\bscotland\b|\bscottish\b|\bscotch\b/iu',
        'Denmark'=>'/\bdenmark\b|\bdanish\b|\bdansk\b/iu',
        'Ireland'=>'/\bireland\b|\birish\b/iu',
        'Japan'=>'/\bjapan\b|\bjapanese\b/iu',
        'USA'=>'/\busa\b|\bu\.s\.a\.?\b|\bamerican\b/iu',
    ];
    foreach($countryRules as $label=>$rx){if(preg_match($rx,$text)){$fields['country']=$label;$conf['country']=95;$reasons[]='land fundet direkte i teksten';break;}}

    // Cask information. Prefer text explicitly mentioning cask/barrel/hogshead/butt/octave/finish.
    $caskTerms='(?:1st\s*fill|first\s*fill|refill|fresh|re-charred|virgin|ex[- ]?)?\s*(?:oloroso|pedro\s+xim[eé]nez|px|palo\s+cortado|tawny\s+port|ruby\s+port|port|bourbon|sherry|madeira|amarone|sauternes|rivesaltes|wine|cognac)?\s*(?:cask|barrel|hogshead|butt|barrique|octave|finish)';
    if(preg_match('/\b('.$caskTerms.')\b/iu',$text,$m)){
        $v=trim(preg_replace('/\s+/u',' ',$m[1])??$m[1]);
        if(strlen($v)>=5){$fields['cask_type']=$v;$conf['cask_type']=91;$reasons[]='fadtype/fadfinish aflæst fra teksten';}
    }

    // Distillery heuristic. A non-year parenthesis is often the disclosed distillery,
    // e.g. Motherlover (Macallan) (2015). Otherwise use the leading name only when it
    // looks like a distillery/product family rather than a generic series/region.
    $dist='';
    if(preg_match_all('/\(([^()]*)\)/u',$text,$mm)){
        foreach($mm[1] as $inside){
            $inside=trim((string)$inside," \t\n\r\0\x0B'\"");
            if($inside==='' || preg_match('/^(?:19|20)\d{2}$/',$inside)) continue;
            if(preg_match('/^[\p{L}][\p{L}\p{N} .&\'’\-]{1,60}$/u',$inside)){$dist=$inside;break;}
        }
    }
    if($dist===''){
        $lead=preg_split('/\s+-\s+|\s+–\s+|,\s*\d{1,2}(?:[\.,]\d+)?\s*%/u',$text,2)[0]??$text;
        $lead=trim(preg_replace('/\s*\((?:19|20)\d{2}\)\s*$/u','',$lead)??$lead," \t\n\r\0\x0B'\"");
        // Handle series prefixes such as Dalgety - 'Inchgower'.
        if(preg_match('/^Dalgety\s*-\s*[\'\"]?([^\'\"]+)[\'\"]?/iu',$text,$m)) $lead=trim($m[1]);
        $blocked='/^(?:speyside|islay|highland|lowland|campbeltown|blended malt|single malt|new make|st\.?\s*bridget|samhain|fionia|isle of fionia)\b/iu';
        if(!preg_match($blocked,$lead) && !preg_match('/\b(?:rum|gin|vodka|akvavit|liqueur|lik[oø]r)\b/iu',$lead) && preg_match('/^[\p{L}][\p{L}\p{N} .&\'’\-]{1,60}$/u',$lead)){
            $dist=$lead;
        }
    }
    if($dist!==''){$fields['distillery']=$dist;$conf['distillery']=84;$reasons[]='destilleri foreslået fra produktnavnets navnedel';}

    return ['fields'=>$fields,'confidence'=>$conf,'source'=>'rules','reason'=>implode('; ',array_unique($reasons))];
}

function hsg_product_enrichment_parse_ai(string $text): array {
    $out=[];
    foreach(preg_split('/\R/u',trim($text))?:[] as $line){
        if(!preg_match('/^\s*([A-Z_]+)\s*=\s*(.*?)\s*$/u',$line,$m)) continue;
        $out[strtolower($m[1])]=trim($m[2]," \t\n\r\0\x0B\"'");
    }
    return $out;
}

function hsg_groq_product_text_models(PDO $pdo): array {
    $preferred=trim((string)setting_get($pdo,'product_data_ai_model','llama-3.3-70b-versatile'));
    // Prefer the 70B text model on Free Plan (higher TPM than GPT-OSS 120B for
    // this small extraction task). Keep a custom model only as a final fallback.
    $base=['llama-3.3-70b-versatile','llama-3.1-8b-instant'];
    $choices=in_array($preferred,$base,true)
        ? array_values(array_unique(array_merge([$preferred],$base)))
        : array_values(array_unique(array_merge($base,$preferred!==''?[$preferred]:[])));
    $available=hsg_groq_available_model_ids($pdo);
    if(!$available) return $choices;
    return array_values(array_filter($choices,static fn($m)=>in_array($m,$available,true)));
}

/**
 * AI extraction uses only the supplied varetekst/context. It does NOT browse the web.
 * Unknown fields must stay empty so the assistant cannot silently invent catalog data.
 */
function hsg_groq_enrich_product_text(PDO $pdo,string $text,array $context=[]): array {
    if(hsg_groq_api_key($pdo)==='') throw new RuntimeException('Groq API-nøgle mangler. Regelbaseret udfyldning virker stadig uden AI.');
    $contextLines=[];
    foreach(['brand_name'=>'Valgt brand','supplier_name'=>'Leverandør','notes'=>'Noter'] as $key=>$label){
        $v=trim((string)($context[$key]??''));if($v!=='')$contextLines[]=$label.': '.$v;
    }
    $prompt="Du er produktdata-assistent for HSG Whisky. Udfyld KUN oplysninger, som er tydeligt understøttet af vareteksten og den givne kontekst. Brug IKKE web search og opfind aldrig facts. Hvis et felt ikke kan bestemmes sikkert, skal værdien være tom.\n\n"
        ."Varetekst: ".$text."\n".($contextLines?implode("\n",$contextLines)."\n":'')."\n"
        ."Returnér præcis disse linjer:\n"
        ."DISTILLERY=\nABV=\nAGE_TEXT=\nVINTAGE_YEAR=\nCATEGORY=\nCOUNTRY=\nBOTTLE_SIZE_CL=\nCASK_TYPE=\nBRAND=\nCONFIDENCE=0-100\nREASON=kort dansk begrundelse\n\n"
        ."Regler: ABV skal være et tal uden %-tegn. VINTAGE_YEAR skal være fire cifre. BOTTLE_SIZE_CL skal være cl som tal. AGE_TEXT fx '12 år'. Brand må kun sættes, hvis brandnavnet fremgår af tekst/kontekst. Destilleri må gerne udledes af en tydelig parentes, fx Motherlover (Macallan), men ikke fra regioner som Islay/Speyside. CONFIDENCE er samlet sikkerhed for de udfyldte felter.";

    $last=null;
    foreach(hsg_groq_product_text_models($pdo) as $model){
        try{
            $response=hsg_groq_chat($pdo,[
                'model'=>$model,
                'messages'=>[['role'=>'user','content'=>$prompt]],
                'temperature'=>0.0,
                'max_completion_tokens'=>220,
            ]);
            $raw=trim((string)($response['choices'][0]['message']['content']??''));
            $a=hsg_product_enrichment_parse_ai($raw);
            if(!$a) throw new RuntimeException('AI returnerede ikke et læsbart svar.');
            $fields=[];
            $stringMap=['distillery'=>'distillery','age_text'=>'age_text','category'=>'category','country'=>'country','cask_type'=>'cask_type','brand'=>'brand_name'];
            foreach($stringMap as $src=>$dst){$v=trim((string)($a[$src]??''));if($v!=='' && !in_array(strtolower($v),['unknown','ukendt','n/a','null','none','-'],true))$fields[$dst]=$v;}
            $abv=str_replace(',','.',trim((string)($a['abv']??'')));if($abv!==''&&is_numeric($abv)&&(float)$abv>0&&(float)$abv<=100)$fields['abv']=(float)$abv;
            $year=trim((string)($a['vintage_year']??''));if(preg_match('/^(19|20)\d{2}$/',$year))$fields['vintage_year']=(int)$year;
            $cl=str_replace(',','.',trim((string)($a['bottle_size_cl']??'')));if($cl!==''&&is_numeric($cl)&&(float)$cl>=5&&(float)$cl<=500)$fields['bottle_size_cl']=(float)$cl;
            $confidence=max(0,min(100,(int)($a['confidence']??0)));
            return ['fields'=>$fields,'confidence'=>$confidence,'source'=>'groq','model'=>$model,'reason'=>substr(trim((string)($a['reason']??'')),0,700)];
        }catch(HsgGroqRateLimitException $e){throw $e;}catch(Throwable $e){$last=$e;}
    }
    throw new RuntimeException('Groq kunne ikke analysere vareteksten'.($last?': '.$last->getMessage():'.'));
}

/** Merge rules + optional AI, with rules winning when they are high confidence. */
function hsg_enrich_product_text(PDO $pdo,string $text,array $context=[],bool $useAi=true): array {
    $rule=hsg_product_parse_text($text,$context);
    $fields=$rule['fields'];$fieldConfidence=$rule['confidence'];$notes=[];
    if(!empty($rule['reason']))$notes[]=$rule['reason'];
    $source='rules';$aiScore=null;$aiModel=null;

    if($useAi && hsg_groq_api_key($pdo)!==''){
        try{
            $ai=hsg_groq_enrich_product_text($pdo,$text,$context);
            $aiScore=(int)$ai['confidence'];$aiModel=$ai['model']??null;
            foreach((array)$ai['fields'] as $key=>$value){
                if(!array_key_exists($key,$fields) || (int)($fieldConfidence[$key]??0)<90){
                    if($value!==''&&$value!==null){$fields[$key]=$value;$fieldConfidence[$key]=$aiScore;}
                }
            }
            if(!empty($ai['reason']))$notes[]='AI: '.$ai['reason'];
            $source=$fields!==$rule['fields']?'rules+ai':'rules';
        }catch(HsgGroqRateLimitException $e){
            $notes[]='AI midlertidigt begrænset: prøv igen om ca. '.$e->retryAfterSeconds.' sek.';
        }catch(Throwable $e){
            $notes[]='AI blev ikke brugt: '.$e->getMessage();
        }
    }

    $scores=array_values(array_map('intval',$fieldConfidence));
    $score=$scores?(int)round(array_sum($scores)/count($scores)):0;
    if($aiScore!==null && $source==='rules+ai')$score=min($score,$aiScore);
    return ['fields'=>$fields,'field_confidence'=>$fieldConfidence,'confidence'=>$score,'source'=>$source,'model'=>$aiModel,'reason'=>implode(' · ',$notes)];
}

function hsg_apply_missing_product_fields(PDO $pdo,int $productId,array $result): array {
    $st=$pdo->prepare('SELECT * FROM lager_products WHERE id=?');$st->execute([$productId]);$product=$st->fetch(PDO::FETCH_ASSOC);
    if(!$product) throw new RuntimeException('Produktet findes ikke.');
    $allowed=['distillery','country','age_text','abv','bottle_size_cl','cask_type','cask_number','category','vintage_year'];
    $updates=[];$params=[];$applied=[];
    foreach($allowed as $field){
        if(!array_key_exists($field,$result['fields']??[])) continue;
        $current=$product[$field]??null;
        if($current!==null && trim((string)$current)!=='') continue;
        $value=$result['fields'][$field];
        if($value===null || trim((string)$value)==='') continue;
        $updates[]="$field=?";$params[]=$value;$applied[$field]=$value;
    }
    if($updates){
        $updates[]='data_enrichment_score=?';$params[]=max(0,min(100,(int)($result['confidence']??0)));
        $updates[]='data_enrichment_source=?';$params[]=substr((string)($result['source']??'rules'),0,30);
        $updates[]='data_enrichment_note=?';$params[]=substr((string)($result['reason']??''),0,1000);
        $updates[]='data_enriched_at=NOW()';
        $params[]=$productId;
        $pdo->prepare('UPDATE lager_products SET '.implode(',',$updates).' WHERE id=?')->execute($params);
    }
    return $applied;
}
