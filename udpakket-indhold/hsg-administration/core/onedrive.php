<?php
declare(strict_types=1);

function hsg_http_request(string $method,string $url,array $headers=[],?string $body=null): array {
    if(!function_exists('curl_init')) throw new RuntimeException('PHP cURL er ikke installeret.');
    $ch=curl_init($url);
    curl_setopt_array($ch,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>15,CURLOPT_TIMEOUT=>120,CURLOPT_HTTPHEADER=>$headers]);
    if($body!==null) curl_setopt($ch,CURLOPT_POSTFIELDS,$body);
    $resp=curl_exec($ch); $err=curl_error($ch); $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
    if($resp===false) throw new RuntimeException('HTTP-fejl: '.$err);
    return ['status'=>$status,'body'=>(string)$resp];
}

function hsg_onedrive_token(PDO $pdo): string {
    static $cached=null; if(is_string($cached) && $cached!=='') return $cached;
    $tenant=trim((string)setting_get($pdo,'onedrive_tenant_id',''));
    $client=trim((string)setting_get($pdo,'onedrive_client_id',''));
    $secret=(string)setting_get($pdo,'onedrive_client_secret','');
    if($tenant===''||$client===''||$secret==='') throw new RuntimeException('OneDrive tenant ID, client ID og client secret skal udfyldes.');
    $body=http_build_query(['client_id'=>$client,'scope'=>'https://graph.microsoft.com/.default','client_secret'=>$secret,'grant_type'=>'client_credentials']);
    $r=hsg_http_request('POST','https://login.microsoftonline.com/'.rawurlencode($tenant).'/oauth2/v2.0/token',['Content-Type: application/x-www-form-urlencoded'],$body);
    $json=json_decode($r['body'],true);
    if($r['status']<200||$r['status']>=300||empty($json['access_token'])) throw new RuntimeException('Microsoft-login fejlede: '.($json['error_description']??('HTTP '.$r['status'])));
    $cached=(string)$json['access_token']; return $cached;
}

function hsg_graph(PDO $pdo,string $method,string $path,?array $jsonBody=null): array {
    $token=hsg_onedrive_token($pdo);
    $body=$jsonBody===null?null:json_encode($jsonBody,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    $headers=['Authorization: Bearer '.$token,'Accept: application/json'];
    if($body!==null)$headers[]='Content-Type: application/json';
    $r=hsg_http_request($method,'https://graph.microsoft.com/v1.0'.$path,$headers,$body);
    $json=$r['body']!==''?json_decode($r['body'],true):[];
    if($r['status']<200||$r['status']>=300) throw new RuntimeException('Microsoft Graph fejlede (HTTP '.$r['status'].'): '.(($json['error']['message']??'Ukendt fejl')));
    return is_array($json)?$json:[];
}

function hsg_onedrive_user_segment(PDO $pdo): string {
    $user=trim((string)setting_get($pdo,'onedrive_user_id',''));
    if($user==='') throw new RuntimeException('OneDrive bruger/UPN skal udfyldes, fx backup@firma.dk.');
    return '/users/'.rawurlencode($user).'/drive';
}

function hsg_onedrive_ensure_folder(PDO $pdo,string $folderPath): string {
    $drive=hsg_onedrive_user_segment($pdo);
    $root=hsg_graph($pdo,'GET',$drive.'/root');
    $parent=(string)($root['id']??''); if($parent==='') throw new RuntimeException('Kunne ikke finde OneDrive root.');
    $parts=array_values(array_filter(array_map('trim',explode('/',str_replace('\\','/',$folderPath))),fn($p)=>$p!==''));
    $acc=[];
    foreach($parts as $part){
        $acc[]=$part; $encoded=implode('/',array_map('rawurlencode',$acc));
        try{ $item=hsg_graph($pdo,'GET',$drive.'/root:/'.$encoded); $parent=(string)$item['id']; continue; }
        catch(Throwable){ /* create below */ }
        $item=hsg_graph($pdo,'POST',$drive.'/items/'.rawurlencode($parent).'/children',['name'=>$part,'folder'=>(object)[],'@microsoft.graph.conflictBehavior'=>'fail']);
        $parent=(string)($item['id']??''); if($parent==='') throw new RuntimeException('Kunne ikke oprette OneDrive-mappen '.$part);
    }
    return $parent;
}

function hsg_onedrive_upload_backup(PDO $pdo,string $file): array {
    if(!is_file($file)) throw new RuntimeException('Backupfilen findes ikke.');
    $folder=trim((string)setting_get($pdo,'onedrive_folder','HSG Administration/Backups'));
    $parentId=hsg_onedrive_ensure_folder($pdo,$folder);
    $drive=hsg_onedrive_user_segment($pdo); $name=basename($file); $size=filesize($file) ?: 0;
    $token=hsg_onedrive_token($pdo);
    if($size<=10*1024*1024){
        $url='https://graph.microsoft.com/v1.0'.$drive.'/items/'.rawurlencode($parentId).':/'.rawurlencode($name).':/content';
        $body=file_get_contents($file); if($body===false) throw new RuntimeException('Kunne ikke læse backupfilen.');
        $r=hsg_http_request('PUT',$url,['Authorization: Bearer '.$token,'Content-Type: application/zip'],$body);
        $json=json_decode($r['body'],true);
        if($r['status']<200||$r['status']>=300) throw new RuntimeException('OneDrive-upload fejlede: '.($json['error']['message']??('HTTP '.$r['status'])));
        return $json;
    }
    // Upload session for larger backup files. Chunks are 5 MiB = 16 * 320 KiB.
    $session=hsg_graph($pdo,'POST',$drive.'/items/'.rawurlencode($parentId).':/'.rawurlencode($name).':/createUploadSession',['item'=>['@microsoft.graph.conflictBehavior'=>'replace','name'=>$name]]);
    $uploadUrl=(string)($session['uploadUrl']??''); if($uploadUrl==='') throw new RuntimeException('Microsoft returnerede ingen upload-session.');
    $fh=fopen($file,'rb'); if(!$fh) throw new RuntimeException('Kunne ikke åbne backupfilen.');
    $chunkSize=5*1024*1024; $start=0; $last=[];
    try{
        while(!feof($fh)){
            $chunk=fread($fh,$chunkSize); if($chunk===false) throw new RuntimeException('Fejl ved læsning af backupfil.');
            if($chunk==='') break; $len=strlen($chunk); $end=$start+$len-1;
            $r=hsg_http_request('PUT',$uploadUrl,['Content-Length: '.$len,'Content-Range: bytes '.$start.'-'.$end.'/'.$size,'Content-Type: application/octet-stream'],$chunk);
            $last=json_decode($r['body'],true) ?: [];
            if(!in_array($r['status'],[200,201,202],true)) throw new RuntimeException('OneDrive chunk-upload fejlede (HTTP '.$r['status'].').');
            $start=$end+1;
        }
    }finally{ fclose($fh); }
    return $last;
}

function hsg_onedrive_test(PDO $pdo): array {
    $drive=hsg_onedrive_user_segment($pdo);
    $me=hsg_graph($pdo,'GET',$drive.'/root');
    $folder=trim((string)setting_get($pdo,'onedrive_folder','HSG Administration/Backups'));
    $folderId=hsg_onedrive_ensure_folder($pdo,$folder);
    return ['drive_root'=>$me['name']??'OneDrive','folder_id'=>$folderId];
}
