<?php
declare(strict_types=1);

function rh(string $v): string { return htmlspecialchars($v,ENT_QUOTES,'UTF-8'); }
function rcopy_dir(string $src,string $dst): void {
    if(!is_dir($src)) throw new RuntimeException('Mappen site/ mangler i backup-pakken.');
    if(!is_dir($dst) && !mkdir($dst,0775,true) && !is_dir($dst)) throw new RuntimeException('Kan ikke oprette destinationsmappe.');
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST);
    foreach($it as $item){
        $rel=ltrim(str_replace('\\','/',substr($item->getPathname(),strlen($src))),'/');
        $target=$dst.'/'.$rel;
        if($item->isDir()){ if(!is_dir($target) && !mkdir($target,0775,true) && !is_dir($target)) throw new RuntimeException('Kan ikke oprette '.$rel); }
        else { if(!is_dir(dirname($target))) mkdir(dirname($target),0775,true); if(!copy($item->getPathname(),$target)) throw new RuntimeException('Kan ikke kopiere '.$rel); }
    }
}
function rimport_sql(PDO $pdo,string $file): int {
    $sql=file_get_contents($file); if($sql===false) throw new RuntimeException('database.sql kan ikke læses.');
    $parts=preg_split('/;\s*(?:\r?\n|$)/',$sql) ?: []; $count=0;
    foreach($parts as $part){ $part=trim($part); if($part==='' || str_starts_with($part,'--')) { if(str_contains($part,"\n")){ $part=preg_replace('/^(?:--[^\n]*\n)+/','',$part)??''; $part=trim($part);} } if($part==='') continue; $pdo->exec($part); $count++; }
    return $count;
}
function rdelete_dir(string $dir): void { if(!is_dir($dir))return; $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST); foreach($it as $f){$f->isDir()?@rmdir($f->getPathname()):@unlink($f->getPathname());}@rmdir($dir); }

$root=__DIR__; $error=''; $success='';
$manifest=[]; if(is_file($root.'/manifest.json')) $manifest=json_decode((string)file_get_contents($root.'/manifest.json'),true) ?: [];
$backupType=(string)($manifest['backup_type']??'unknown');
if($_SERVER['REQUEST_METHOD']==='POST'){
    $host=trim((string)($_POST['db_host']??'localhost'));$name=trim((string)($_POST['db_name']??''));$user=trim((string)($_POST['db_user']??''));$pass=(string)($_POST['db_pass']??'');$cleanup=!empty($_POST['cleanup']);
    try{
        if($backupType!=='full') throw new RuntimeException('Automatisk fuld restore kræver en FULL backup.');
        if(!is_file($root.'/database.sql')||!is_dir($root.'/site')) throw new RuntimeException('Backup-pakken er ikke komplet. database.sql og site/ skal ligge ved restore.php.');
        if($name===''||$user==='') throw new RuntimeException('Databasenavn og databasebruger skal udfyldes.');
        $pdo=new PDO('mysql:host='.$host.';dbname='.$name.';charset=utf8mb4',$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
        rcopy_dir($root.'/site',$root);
        $statements=rimport_sql($pdo,$root.'/database.sql');
        $config="<?php\nreturn ".var_export(['db_host'=>$host,'db_name'=>$name,'db_user'=>$user,'db_pass'=>$pass],true).";\n";
        if(file_put_contents($root.'/config.php',$config)===false) throw new RuntimeException('Kunne ikke skrive config.php.');
        $success='Restore gennemført. '.$statements.' SQL-statements er kørt. Du kan nu åbne admin-login.php.';
        if($cleanup){
            @unlink($root.'/database.sql'); @unlink($root.'/manifest.json'); @unlink($root.'/README-RESTORE.txt'); rdelete_dir($root.'/site');
            register_shutdown_function(static function() use($root){ @unlink($root.'/restore.php'); });
        }
    }catch(Throwable $e){$error=$e->getMessage();}
}
?><!doctype html><html lang="da"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>HSG Restore</title><style>body{font-family:system-ui,sans-serif;background:#f5f5f3;color:#171717;margin:0}.wrap{max-width:720px;margin:40px auto;padding:0 18px}.card{background:#fff;border:1px solid #ddd;border-radius:14px;padding:24px;box-shadow:0 8px 28px #0000000d}label{display:block;margin:14px 0;font-weight:600}input{box-sizing:border-box;width:100%;padding:11px;border:1px solid #bbb;border-radius:8px;margin-top:5px}button{background:#111;color:#fff;border:0;border-radius:9px;padding:12px 18px;font-weight:700}.ok{background:#e7f7ec;padding:12px;border-radius:8px}.err{background:#feecec;padding:12px;border-radius:8px}.muted{color:#666;font-size:.92rem}</style></head><body><div class="wrap"><div class="card"><h1>HSG Administration – Restore</h1><p>Backup: <strong><?=rh(strtoupper($backupType))?></strong> · Version <?=rh((string)($manifest['app_version']??'?'))?></p><?php if($error):?><p class="err"><?=rh($error)?></p><?php endif;?><?php if($success):?><p class="ok"><?=rh($success)?></p><p><a href="admin-login.php">Åbn administrator-login</a></p><?php else:?><p class="muted">Opret først en tom database på det nye webhotel. Restore kopierer derefter sitefilerne, importerer databasen og opretter config.php med de nye databaseoplysninger.</p><form method="post"><label>MySQL host<input name="db_host" value="localhost" required></label><label>Databasenavn<input name="db_name" required></label><label>Databasebruger<input name="db_user" required></label><label>Databasekodeord<input type="password" name="db_pass"></label><label><input type="checkbox" name="cleanup" value="1" checked style="width:auto"> Fjern restore-pakkens midlertidige filer efter succes</label><button>Gendan HSG Administration</button></form><?php endif;?></div></div></body></html>
