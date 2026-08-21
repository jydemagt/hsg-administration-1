<?php
declare(strict_types=1);

/** HSG backup/disaster recovery helpers. */
function hsg_backup_storage_dir(): string {
    $dir = dirname(__DIR__).'/storage/backups';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Kunne ikke oprette backupmappen: '.$dir);
    }
    return $dir;
}

function hsg_backup_sensitive_setting_keys(): array {
    return ['onedrive_client_secret', 'backup_cron_key', 'openai_api_key', 'groq_api_key'];
}

function hsg_backup_tables(PDO $pdo): array {
    $st=$pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND (TABLE_NAME LIKE 'lager\\_%' ESCAPE '\\\\' OR TABLE_NAME LIKE 'hsg\\_%' ESCAPE '\\\\') ORDER BY TABLE_NAME");
    return $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

function hsg_sql_literal(mixed $value): string {
    if ($value === null) return 'NULL';
    return '0x'.bin2hex((string)$value);
}

function hsg_dump_database(PDO $pdo, string $dest): array {
    $fh=fopen($dest,'wb');
    if(!$fh) throw new RuntimeException('Kunne ikke oprette databasebackup.');
    $tables=hsg_backup_tables($pdo);
    fwrite($fh,"-- HSG Administration disaster-recovery database\n-- Generated: ".date('c')."\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");
    $rowCount=0;
    foreach($tables as $table){
        $safe=str_replace('`','``',(string)$table);
        $cr=$pdo->query('SHOW CREATE TABLE `'.$safe.'`')->fetch(PDO::FETCH_ASSOC);
        $create=$cr['Create Table'] ?? array_values($cr ?: [])[1] ?? null;
        if(!$create) continue;
        fwrite($fh,'DROP TABLE IF EXISTS `'.$safe."`;\n".$create.";\n\n");

        $sql='SELECT * FROM `'.$safe.'`';
        if($table==='hsg_settings'){
            $keys=hsg_backup_sensitive_setting_keys();
            $quoted=implode(',',array_map(fn($k)=>$pdo->quote($k),$keys));
            $sql.=' WHERE setting_key NOT IN ('.$quoted.')';
        }elseif($table==='hsg_backup_runs'){
            $sql.=" WHERE status <> 'running'";
        }
        $rows=$pdo->query($sql);
        while($row=$rows->fetch(PDO::FETCH_ASSOC)){
            $cols=array_map(fn($c)=>'`'.str_replace('`','``',(string)$c).'`',array_keys($row));
            $vals=array_map('hsg_sql_literal',array_values($row));
            fwrite($fh,'INSERT INTO `'.$safe.'` ('.implode(',',$cols).') VALUES ('.implode(',',$vals).");\n");
            $rowCount++;
        }
        fwrite($fh,"\n");
    }
    fwrite($fh,"SET FOREIGN_KEY_CHECKS=1;\n");
    fclose($fh);
    return ['tables'=>count($tables),'rows'=>$rowCount,'sha256'=>hash_file('sha256',$dest),'size'=>filesize($dest) ?: 0];
}

function hsg_backup_excluded_relpath(string $rel): bool {
    $rel=ltrim(str_replace('\\','/',$rel),'/');
    if(in_array($rel,['config.php','.env','config.local.php'],true)) return true; // new secrets are entered during restore
    foreach(['storage/backups/','storage/tmp/','.git/','.idea/'] as $prefix){
        if(str_starts_with($rel,$prefix)) return true;
    }
    if(in_array($rel,['restore.php','database.sql','manifest.json','README-RESTORE.txt'],true)) return true;
    return false;
}

function hsg_add_site_to_zip(ZipArchive $zip, string $siteRoot, bool $dataOnly=false): int {
    $count=0;
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($siteRoot,FilesystemIterator::SKIP_DOTS));
    foreach($it as $file){
        if(!$file->isFile()) continue;
        $abs=$file->getPathname();
        $rel=ltrim(str_replace('\\','/',substr($abs,strlen($siteRoot))),'/');
        if(hsg_backup_excluded_relpath($rel)) continue;
        if($dataOnly && !(str_starts_with($rel,'uploads/') || str_starts_with($rel,'storage/data/'))) continue;
        if(!$zip->addFile($abs,'site/'.$rel)) throw new RuntimeException('Kunne ikke tilføje fil til backup: '.$rel);
        $count++;
    }
    return $count;
}

function hsg_restore_readme(string $type): string {
    return "HSG ADMINISTRATION – RESTOREVEJLEDNING\n".
        "======================================\n\n".
        "Backup-type: ".strtoupper($type)."\n".
        "Oprettet: ".date('c')."\n\n".
        "FORMÅL\n".
        "Denne ZIP er lavet til disaster recovery. En FULL backup indeholder sitefiler, uploads og database. En DATA backup indeholder database og mutable uploads.\n\n".
        "GENDAN HELE SITET FRA EN FULL BACKUP\n".
        "1. Opret en tom MySQL/MariaDB-database på det nye webhotel.\n".
        "2. Upload backup-ZIP'en til den mappe, hvor HSG Administration skal ligge.\n".
        "3. Udpak ZIP'en med webhotellets filhåndtering. Efter udpakning skal restore.php, database.sql, manifest.json og mappen site/ ligge sammen.\n".
        "4. Åbn restore.php i browseren, fx https://ditdomæne.dk/hsg/restore.php.\n".
        "5. Indtast host, databasenavn, databasebruger og databasekodeord til den NYE database.\n".
        "6. Restore-værktøjet kopierer sitefilerne på plads, importerer database.sql og opretter en ny config.php.\n".
        "7. Log ind via admin-login.php med dit eksisterende HSG admin-login. Password-hashen ligger i databasen og gendannes.\n".
        "8. OneDrive client secret, Groq/OpenAI API-nøgler og cron-nøgle er bevidst IKKE med i backup. Indtast dem igen under System > Backup.\n".
        "9. Slet restorefilerne efter succes. Restore-værktøjet kan gøre det automatisk.\n\n".
        "DATA BACKUP\n".
        "En DATA backup kan bruges til at gendanne database og uploads oven på en kompatibel HSG Administration-installation. Til totalt tab af hele sitet skal en FULL backup bruges.\n\n".
        "KONTROL\n".
        "manifest.json indeholder versionsnummer, database-SHA-256 og filantal. Backupmodulet validerer arkivet efter oprettelse.\n\n".
        "SIKKERHED\n".
        "Backupfiler indeholder forretningsdata og password-hashes. Opbevar dem privat. OneDrive-/AI-hemmeligheder og databasekodeord pakkes ikke med.\n";
}

function hsg_backup_record_start(PDO $pdo,string $type,string $destination): int {
    $pdo->prepare("INSERT INTO hsg_backup_runs(backup_type,destination,status,created_at) VALUES(?,?,'running',NOW())")->execute([$type,$destination]);
    return (int)$pdo->lastInsertId();
}

function hsg_backup_record_finish(PDO $pdo,int $id,string $status,?string $filename=null,int $size=0,?string $sha=null,?string $message=null): void {
    $pdo->prepare('UPDATE hsg_backup_runs SET status=?,filename=?,file_size=?,checksum_sha256=?,message=?,completed_at=NOW() WHERE id=?')->execute([$status,$filename,$size,$sha,$message,$id]);
}

function hsg_make_backup(PDO $pdo,string $type='data',string $reason='manual'): array {
    if(!class_exists('ZipArchive')) throw new RuntimeException('PHP ZipArchive er ikke installeret på webhotellet.');
    if(!in_array($type,['data','full'],true)) throw new InvalidArgumentException('Ukendt backup-type.');
    $destination=setting_get($pdo,'onedrive_enabled','0')==='1' ? 'both' : 'local';
    $runId=hsg_backup_record_start($pdo,$type,$destination);
    $storage=hsg_backup_storage_dir();
    $stamp=date('Y-m-d-His');
    $filename='HSG-'.($type==='full'?'FullBackup':'DataBackup').'-'.$stamp.'.zip';
    $final=$storage.'/'.$filename;
    $tmpDir=dirname(__DIR__).'/storage/tmp/backup-'.bin2hex(random_bytes(8));
    try{
        if(!mkdir($tmpDir,0775,true) && !is_dir($tmpDir)) throw new RuntimeException('Kunne ikke oprette midlertidig backupmappe.');
        $dbFile=$tmpDir.'/database.sql';
        $dbInfo=hsg_dump_database($pdo,$dbFile);
        $zip=new ZipArchive();
        if($zip->open($final,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true) throw new RuntimeException('Kunne ikke oprette ZIP-backup.');
        $zip->addFile($dbFile,'database.sql');
        $siteFiles=hsg_add_site_to_zip($zip,dirname(__DIR__), $type==='data');
        $restoreSource=dirname(__DIR__).'/tools/restore-standalone.php';
        if($type==='full' && is_file($restoreSource)) $zip->addFile($restoreSource,'restore.php');
        $zip->addFromString('README-RESTORE.txt',hsg_restore_readme($type));
        $manifest=[
            'product'=>'HSG Administration','app_version'=>app_version(),'backup_type'=>$type,'reason'=>$reason,
            'created_at'=>date('c'),'database'=>$dbInfo,'site_files'=>$siteFiles,
            'secrets_excluded'=>hsg_backup_sensitive_setting_keys(),
            'restore_supported'=>$type==='full',
        ];
        $zip->addFromString('manifest.json',json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        $zip->addFromString('.htaccess',"Options -Indexes\n<FilesMatch \"^(database\\.sql|manifest\\.json|README-RESTORE\\.txt)$\">\n  Require all denied\n</FilesMatch>\n<IfModule mod_rewrite.c>\nRewriteEngine On\nRewriteRule ^site/ - [F,L]\n</IfModule>\n");
        $zip->close();
        if(!is_file($final) || filesize($final)===0) throw new RuntimeException('Backup-ZIP blev ikke oprettet korrekt.');

        // Integrity check: archive must contain key restore files.
        $check=new ZipArchive();
        if($check->open($final)!==true) throw new RuntimeException('Backup-ZIP kan ikke åbnes efter oprettelse.');
        foreach(['database.sql','manifest.json','README-RESTORE.txt'] as $required){ if($check->locateName($required)===false) throw new RuntimeException('Backup mangler '.$required); }
        if($type==='full' && $check->locateName('restore.php')===false) throw new RuntimeException('Fuld backup mangler restore.php');
        $check->close();
        $size=filesize($final) ?: 0; $sha=hash_file('sha256',$final);

        $remoteMessage='';$remoteFailed=false;
        if(setting_get($pdo,'onedrive_enabled','0')==='1'){
            require_once __DIR__.'/onedrive.php';
            try{
                $remote=hsg_onedrive_upload_backup($pdo,$final);
                $remoteMessage=' OneDrive: '.($remote['name']??$filename).'.';
            }catch(Throwable $e){
                // Keep local backup even if cloud upload fails and mark warning in message.
                $remoteFailed=true;$remoteMessage=' OneDrive-fejl: '.$e->getMessage();
            }
        }
        hsg_backup_record_finish($pdo,$runId,$remoteFailed?'warning':'success',$filename,$size,$sha,'Backup valideret.'.$remoteMessage);
        if(function_exists('audit_log')) audit_log($pdo,'backup.create','backup',(string)$runId,['type'=>$type,'filename'=>$filename,'size'=>$size]);
        return ['id'=>$runId,'filename'=>$filename,'path'=>$final,'size'=>$size,'sha256'=>$sha,'message'=>'Backup valideret.'.$remoteMessage];
    }catch(Throwable $e){
        hsg_backup_record_finish($pdo,$runId,'failed',isset($filename)?$filename:null,0,null,$e->getMessage());
        if(isset($final) && is_file($final)) @unlink($final);
        throw $e;
    }finally{
        if(isset($tmpDir) && is_dir($tmpDir)) hsg_recursive_delete($tmpDir);
    }
}

function hsg_recursive_delete(string $dir): void {
    if(!is_dir($dir)) return;
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($it as $f){ $f->isDir()?@rmdir($f->getPathname()):@unlink($f->getPathname()); }
    @rmdir($dir);
}

function hsg_backup_cleanup(PDO $pdo): array {
    $daily=max(1,(int)(setting_get($pdo,'backup_keep_data','30')??30));
    $full=max(1,(int)(setting_get($pdo,'backup_keep_full','12')??12));
    $dir=hsg_backup_storage_dir(); $deleted=[];
    foreach(['data'=>['HSG-DataBackup-*.zip',$daily],'full'=>['HSG-FullBackup-*.zip',$full]] as $type=>$cfg){
        $files=glob($dir.'/'.$cfg[0]) ?: [];
        usort($files,fn($a,$b)=>filemtime($b)<=>filemtime($a));
        foreach(array_slice($files,$cfg[1]) as $file){ if(@unlink($file)) $deleted[]=basename($file); }
    }
    return $deleted;
}

function hsg_run_scheduled_backup(PDO $pdo): array {
    if(setting_get($pdo,'backup_enabled','1')!=='1') return ['message'=>'Automatisk backup er deaktiveret.'];
    $results=[];
    $results[] = hsg_make_backup($pdo,'data','scheduled-nightly');
    $weekday=(int)(setting_get($pdo,'backup_full_weekday','0')??0); // 0 = Sunday
    if((int)date('w')===$weekday && setting_get($pdo,'backup_full_weekly','1')==='1'){
        $results[] = hsg_make_backup($pdo,'full','scheduled-weekly');
    }
    hsg_backup_cleanup($pdo);
    return ['message'=>'Planlagt backup gennemført.','backups'=>$results];
}
