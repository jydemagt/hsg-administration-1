<?php
declare(strict_types=1);

$isCli=PHP_SAPI==='cli';
if(!$isCli) header('Content-Type: text/plain; charset=utf-8');
require_once dirname(__DIR__).'/functions.php';
require_once dirname(__DIR__).'/db.php';
require_once dirname(__DIR__).'/core/settings.php';
require_once dirname(__DIR__).'/core/audit.php';
require_once dirname(__DIR__).'/core/backup.php';
date_default_timezone_set((string)setting_get($pdo,'timezone','Europe/Copenhagen'));

if(!$isCli){
    $provided=(string)($_GET['key']??'');
    $expected=(string)setting_get($pdo,'backup_cron_key','');
    if($expected===''||$provided===''||!hash_equals($expected,$provided)){http_response_code(403);exit("Ugyldig cron-nøgle.\n");}
}

try{
    $result=hsg_run_scheduled_backup($pdo);
    audit_log($pdo,'backup.cron','system',null,['result'=>$result['message']??'ok']);
    echo ($result['message']??'OK')."\n";
    foreach($result['backups']??[] as $b) echo ($b['filename']??'backup')." - ".($b['message']??'OK')."\n";
}catch(Throwable $e){
    if(!$isCli) http_response_code(500);
    echo 'BACKUP FEJLEDE: '.$e->getMessage()."\n";
    exit(1);
}
