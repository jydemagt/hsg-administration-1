<?php
require __DIR__.'/auth.php';require_module_enabled('images');require_capability('images.manage');require_once __DIR__.'/image_tools.php';require_once __DIR__.'/core/quality.php';header('Content-Type: application/json; charset=utf-8');
try{
    $pid=(int)($_POST['product_id']??0);$mode=(string)($_POST['mode']??'');if(!$pid)throw new RuntimeException('Produkt mangler.');
    if($mode==='validate'){
        $validation=validate_product_image($pdo,$pid);
        audit_log($pdo,'product.image_validate','product',(string)$pid,['score'=>$validation['score']??null,'status'=>$validation['status']??null,'model'=>$validation['model']??null]);
        echo json_encode(['ok'=>true,'validation'=>$validation],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;
    }
    if($mode==='approve_current'){
        $result=hsg_approve_current_product_image($pdo,$pid,current_admin_id());hsg_quality_invalidate($pdo,$pid);
        audit_log($pdo,'product.image_approve','product',(string)$pid,['validation_score'=>$result['validation_score']??null,'validation_status'=>$result['validation_status']??null]);
        echo json_encode(['ok'=>true,'approval'=>$result],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;
    }
    if($mode==='reject_current'){
        $result=hsg_reject_current_product_image($pdo,$pid);hsg_quality_invalidate($pdo,$pid);
        audit_log($pdo,'product.image_reject','product',(string)$pid,['rejected'=>$result['rejected']??0]);
        echo json_encode(['ok'=>true,'rejected'=>true,'count'=>(int)($result['rejected']??0)],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;
    }
    if($mode==='url'){
        $url=trim((string)($_POST['url']??''));$supplierProofUrl=trim((string)($_POST['supplier_page_url']??''));if($url==='')throw new RuntimeException('URL mangler.');$result=save_product_image_from_url($pdo,$pid,$url,'manual',null,null,$supplierProofUrl!==''?$supplierProofUrl:null);hsg_quality_invalidate($pdo,$pid);
    }else{
        throw new RuntimeException('Automatisk billedsøgning er fjernet. Brug katalogbilledet eller tilføj et billede manuelt.');
    }

    // Every newly assigned image is validated immediately when Groq Vision is available.
    // AI may also return review candidates without assigning an image; those are shown in Billedtjek instead.
    $validation=null;$validationError=null;$validationRetryAfter=0;
    $candidateOnly=!empty($result['candidate_only']) || empty($result['path']);
    if(!$candidateOnly && hsg_ai_image_validation_ready($pdo)){
        try{$validation=validate_product_image($pdo,$pid);}catch(Throwable $ve){$validationError=$ve->getMessage();if($ve instanceof HsgGroqRateLimitException)$validationRetryAfter=$ve->retryAfterSeconds;}
    }
    audit_log($pdo,'product.image_update','product',(string)$pid,[
        'mode'=>$mode,'method'=>$result['method']??null,'confidence'=>$result['confidence']??null,'source'=>$result['source_url']??($url??null),
        'validation_score'=>$validation['score']??null,'validation_status'=>$validation['status']??($validationError?'error':null)
    ]);
    echo json_encode([
        'ok'=>true,'path'=>$result['path']??null,'method'=>$result['method']??$mode,'confidence'=>$result['confidence']??null,'source'=>$result['source_url']??null,
        'candidate_only'=>$candidateOnly,'candidate_count'=>(int)($result['candidate_count']??0),'note'=>$result['note']??null,
        'validation'=>$validation,'validation_error'=>$validationError,'validation_retry_after'=>$validationRetryAfter
    ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}catch(Throwable $e){
    $isRate=$e instanceof HsgGroqRateLimitException;
    http_response_code($isRate?429:400);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage(),'retry_after'=>$isRate?$e->retryAfterSeconds:0],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}
