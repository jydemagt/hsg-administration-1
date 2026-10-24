<?php
declare(strict_types=1);

require_once __DIR__.'/groq.php';

/*
 * Deterministic extraction from the product/vare text. This layer never calls AI
 * and only returns fields that can be read with reasonably high confidence.
 */

/**
 * Intern. Brug hsg_cask_number_candidates (den gamle udledning) eller hsg_extract_cask_number (den nye).
 * $legacy=true er den gamle udledning med fejlen i batch/bottle/flaske-filteret, $legacy=false den nye.
 */
function hsg_cask_number_scan(string $text, bool $legacy): array {
    $text=trim($text);if($text==='')return [];
    $patterns=[
      '/\b(?:cask|fad|barrel)\s*(?:no\.?|nr\.?|number)?\s*#?\s*([A-Z0-9][A-Z0-9.\-\/]{1,30})\b/iu',
      '/#\s*([A-Z0-9][A-Z0-9.\-\/]{1,30})\b/iu'
    ];
    $out=[];
    foreach($patterns as $pi=>$rx){
        if(!preg_match_all($rx,$text,$mm,PREG_SET_ORDER|PREG_OFFSET_CAPTURE))continue;
        foreach($mm as $m){
            $value=trim((string)$m[1][0]);$offset=(int)$m[0][1];
            if($legacy){
                $before=function_exists('mb_substr')?mb_substr($text,0,$offset,'UTF-8'):substr($text,0,$offset);
                $tail=function_exists('mb_substr')?mb_substr($before,max(0,mb_strlen($before,'UTF-8')-18),null,'UTF-8'):substr($before,-18);
            }else{
                // $text er gyldig UTF-8 (preg_match_all med /u har tjekket den), og /u giver offsets på tegngrænser.
                // Halen er de sidste højst 18 TEGN før matchet: gå baglæns og tæl tegnenes første byte. Den bruges kun for #-mønstret.
                $i=$offset;$n=0;
                while($i>0 && $n<18){$i--; if((ord($text[$i])&0xC0)!==0x80)$n++;}
                $tail=substr($text,$i,$offset-$i);
            }
            if($pi===1 && preg_match('/\b(?:batch|bottle|flaske)\s*$/iu',$tail))continue;
            if(preg_match('/^(?:19|20)\d{2}$/',$value))continue;
            $out[]=strtoupper($value);
        }
    }
    return $out;
}

/**
 * Den GAMLE udledning. Den bruges kun som bevis i oprydningen (hsg_cask_number_cleanup).
 * Alle værdier, som fadnummer-mønstrene finder i teksten, i den rækkefølge de prøves:
 * først alle match efter cask/fad/barrel, derefter alle match efter #, hver i tekstens rækkefølge.
 * Årstal filtreres fra; værdierne er trimmet og med store bogstaver.
 * Batch/bottle/flaske-filteret er bevidst det gamle, også med fejlen: Med mbstring bruges byte-offsettet som
 * tegn-offset, så filteret ser for langt frem, når der står æ, ø, å eller – før #. Uden mbstring er halen 18 bytes og
 * kan skære et tegn over, så filteret svigter (fx 'Kløver Whisky Batch #12').
 * Ret det ikke, for så passer beviset ikke længere med de værdier, som den gamle kode har gemt.
 * Det første element er præcis det, udledningen gav før cifferreglen. Den nye udledning er hsg_extract_cask_number.
 */
function hsg_cask_number_candidates(string $text): array {
    return hsg_cask_number_scan($text,true);
}

/**
 * Fadnummer (den nye udledning): den første kandidat med mindst ét ciffer. Batch/bottle/flaske-filteret ser på
 * de sidste 18 tegn lige før # (rigtigt byte-offset, samme resultat med og uden mbstring).
 * Ord som STRENGTH, FINISH og CRAFT er ikke et fadnummer.
 */
function hsg_extract_cask_number(string $text): ?string {
    foreach(hsg_cask_number_scan($text,false) as $value){
        if(preg_match('/\d/',$value))return $value;
    }
    return null;
}

/** Varetekst som reglerne læser den: – og — bliver til -, og mellemrum samles og trimmes. */
function hsg_product_text_normalize(string $text): string {
    return trim(preg_replace('/\s+/u',' ',str_replace(["\xE2\x80\x93","\xE2\x80\x94"],'-',$text))??$text);
}

/**
 * Navne fra startlageret (initial-stock.json) pr. varenummer: [sku => [navn, …]].
 * En manglende eller ugyldig fil giver [] (oprydningen må aldrig vælte en forespørgsel).
 */
function hsg_cask_number_seed_names(?string $file=null): array {
    $file??=dirname(__DIR__).'/initial-stock.json';
    if(!is_file($file)) return [];
    $raw=@file_get_contents($file);
    if(!is_string($raw) || $raw==='') return [];
    $data=json_decode($raw,true);
    if(!is_array($data) || !is_array($data['products']??null)) return [];
    $out=[];
    foreach($data['products'] as $p){
        if(!is_array($p) || !is_scalar($p['sku']??null) || !is_scalar($p['name']??null)) continue;
        $sku=trim((string)$p['sku']);
        if($sku==='') continue;
        $out[$sku][]=(string)$p['name'];
    }
    return $out;
}

/**
 * Engangsoprydning af fadnumre uden ciffer (modulmigration products 1.5.1).
 * Kun værdier, der beviseligt er udledt automatisk, ændres: den gamle udledning (første kandidat) af produktets
 * nuværende navn eller af startlagerets navn for samme varenummer giver præcis den gemte værdi. Værdien
 * genudledes da med den nye regel (et rigtigt nummer eller NULL). Resten står urørt og kommer på listen "kept".
 * Skriver præcis én audit-post (aktør System). Åbner ikke selv en transaktion; modulmekanismen har gjort det.
 * Kræver db_table_exists/db_column_exists fra migrations.php.
 *
 * @param array<string,array<int,string>>|null $seedNames [sku => [navn, …]]; null læser initial-stock.json
 * @return array{cleared: array<int,array<string,mixed>>, kept: array<int,array<string,mixed>>}
 */
function hsg_cask_number_cleanup(PDO $pdo, ?array $seedNames=null): array {
    $cleared=[];$kept=[];
    if(!db_table_exists($pdo,'lager_products') || !db_column_exists($pdo,'lager_products','cask_number')) return ['cleared'=>$cleared,'kept'=>$kept];
    $seedNames??=hsg_cask_number_seed_names();
    $seed=[];
    foreach($seedNames as $sku=>$names){
        foreach((array)$names as $n){ if(is_scalar($n)) $seed[trim((string)$sku)][]=(string)$n; }
    }

    $rows=$pdo->query("SELECT id,sku,name,cask_number FROM lager_products WHERE cask_number IS NOT NULL AND cask_number<>'' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    $update=$pdo->prepare('UPDATE lager_products SET cask_number=? WHERE id=? AND cask_number=?');
    foreach($rows as $row){
        $stored=(string)$row['cask_number'];
        $value=trim($stored);
        if($value==='' || preg_match('/\d/',$value)) continue;
        $sources=[['name',(string)($row['name']??'')]];
        foreach($seed[trim((string)($row['sku']??''))]??[] as $n) $sources[]=['initial_stock',$n];
        $proof=null;
        foreach($sources as [$source,$text]){
            $text=hsg_product_text_normalize($text);
            if((hsg_cask_number_candidates($text)[0]??null)===$value){$proof=[$source,$text];break;}
        }
        if($proof===null){
            $kept[]=['id'=>(int)$row['id'],'sku'=>(string)$row['sku'],'value'=>$stored];
            continue;
        }
        $new=hsg_extract_cask_number($proof[1]);
        $update->execute([$new,(int)$row['id'],$stored]);
        // 0 rækker: værdien er ændret af en anden forespørgsel imellem, og så er der intet ryddet her.
        if($update->rowCount()>0) $cleared[]=['id'=>(int)$row['id'],'sku'=>(string)$row['sku'],'old'=>$stored,'new'=>$new,'source'=>$proof[0]];
    }

    if(db_table_exists($pdo,'hsg_audit_log')){
        $details=['rule'=>'Fadnummer uden ciffer, udledt automatisk af navnet','cleared_count'=>count($cleared),'cleared'=>$cleared,'kept_count'=>count($kept),'kept'=>$kept];
        $pdo->prepare('INSERT INTO hsg_audit_log(actor_type,actor_id,actor_name,action,entity_type,entity_id,details_json,ip_hash) VALUES(?,?,?,?,?,?,?,?)')
            ->execute(['system',null,'System (opdatering)','product.cask_number_cleanup','product',null,json_encode($details,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE)?:null,null]);
    }
    return ['cleared'=>$cleared,'kept'=>$kept];
}

function hsg_product_parse_text(string $text, array $context=[]): array {
    $text=hsg_product_text_normalize($text);
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
