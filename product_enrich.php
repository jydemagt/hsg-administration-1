<?php
require __DIR__.'/auth.php';
require_capability('products.manage');
require_once __DIR__.'/core/product_enrichment.php';

header('Content-Type: application/json; charset=utf-8');
try{
    $productId=(int)($_POST['product_id']??0);
    $text=trim((string)($_POST['text']??''));
    $useAi=($_POST['use_ai']??'1')!=='0';
    $apply=($_POST['apply']??'0')==='1';
    $context=[
        'brand_name'=>trim((string)($_POST['brand_name']??'')),
        'supplier_name'=>trim((string)($_POST['supplier_name']??'')),
        'notes'=>trim((string)($_POST['notes']??'')),
    ];
    if($productId>0){
        $st=$pdo->prepare('SELECT p.*,b.name brand_name FROM lager_products p LEFT JOIN lager_brands b ON b.id=p.brand_id WHERE p.id=?');
        $st->execute([$productId]);$p=$st->fetch(PDO::FETCH_ASSOC);
        if(!$p) throw new RuntimeException('Produktet findes ikke.');
        if($text==='')$text=trim((string)$p['name']);
        foreach(['brand_name','supplier_name','notes'] as $k){if($context[$k]==='')$context[$k]=trim((string)($p[$k]??''));}
    }
    if($text==='') throw new RuntimeException('Skriv et produktnavn/varetekst først.');
    $result=hsg_enrich_product_text($pdo,$text,$context,$useAi);
    $applied=[];
    if($apply && $productId>0){
        $applied=hsg_apply_missing_product_fields($pdo,$productId,$result);
        audit_log($pdo,'product.enrich','product',(string)$productId,['source'=>$result['source'],'confidence'=>$result['confidence'],'fields'=>array_keys($applied)]);
    }
    echo json_encode(['ok'=>true,'result'=>$result,'applied'=>$applied],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
}catch(HsgGroqRateLimitException $e){
    http_response_code(429);header('Retry-After: '.$e->retryAfterSeconds);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage(),'retry_after'=>$e->retryAfterSeconds],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}catch(Throwable $e){
    http_response_code(400);echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}
