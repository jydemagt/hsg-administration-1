<?php
declare(strict_types=1);
$configFile=__DIR__.'/config.php';
if(!file_exists($configFile)){header('Location: install.php');exit;}
$config=require $configFile;
try{
    $pdo=new PDO('mysql:host='.$config['db_host'].';dbname='.$config['db_name'].';charset=utf8mb4',$config['db_user'],$config['db_pass'],[
      PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false
    ]);
    require_once __DIR__.'/migrations.php';
    ensure_schema_updates($pdo);
    require_once __DIR__.'/core/modules.php';
    hsg_run_module_migrations($pdo);
}catch(Throwable $e){http_response_code(500);exit('Kunne ikke forbinde til eller opdatere databasen. Kontroller config.php og databasebrugerens rettigheder.');}
