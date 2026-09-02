<?php
declare(strict_types=1);

/**
 * HSG Administration self-update service.
 *
 * Update packages are normal HSG distribution ZIP files containing
 * hsg-package.json in the archive root. Mutable/customer data is never
 * overwritten by an update package.
 */

function hsg_update_delete_tree(string $dir): void {
    if(!is_dir($dir)) return;
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($it as $f){ $f->isDir()?@rmdir($f->getPathname()):@unlink($f->getPathname()); }
    @rmdir($dir);
}

function hsg_update_storage_dir(): string {
    $dir=dirname(__DIR__).'/storage/tmp/updates';
    if(!is_dir($dir) && !mkdir($dir,0775,true) && !is_dir($dir)) {
        throw new RuntimeException('Kunne ikke oprette midlertidig opgraderingsmappe.');
    }
    return $dir;
}

function hsg_update_protected_relpath(string $rel): bool {
    $rel=ltrim(str_replace('\\','/',$rel),'/');
    if($rel==='' || in_array($rel,['config.php','.env','config.local.php','.hsg-maintenance'],true)) return true;
    foreach(['uploads/','storage/backups/','storage/tmp/','storage/data/','.git/'] as $prefix){
        if(str_starts_with($rel,$prefix)) return true;
    }
    return false;
}

function hsg_update_normalize_entry(string $name): string {
    if(str_contains($name,"\0") || preg_match('/[\x00-\x1F]/',$name)) throw new RuntimeException('Opgraderingspakken indeholder et ugyldigt filnavn.');
    $name=str_replace('\\','/',$name);
    if(str_starts_with($name,'/') || preg_match('/^[A-Za-z]:\//',$name)) throw new RuntimeException('Opgraderingspakken indeholder en absolut filsti.');
    $parts=[];
    foreach(explode('/',$name) as $part){
        if($part==='' || $part==='.') continue;
        if($part==='..') throw new RuntimeException('Opgraderingspakken indeholder en usikker filsti.');
        $parts[]=$part;
    }
    return implode('/',$parts);
}

function hsg_update_php_bytes(string $value): int {
    $value=trim($value);
    if($value==='') return 0;
    $last=strtolower(substr($value,-1));
    $num=(float)$value;
    return match($last){'g'=>(int)($num*1024*1024*1024),'m'=>(int)($num*1024*1024),'k'=>(int)($num*1024),default=>(int)$num};
}

function hsg_update_validate_package(string $zipPath,bool $allowSameVersion=false): array {
    if(!class_exists('ZipArchive')) throw new RuntimeException('PHP ZipArchive er nødvendig for opgraderingsmodulet.');
    if(!is_file($zipPath) || filesize($zipPath)===0) throw new RuntimeException('Den uploadede opgraderingspakke er tom.');
    $max=100*1024*1024;
    if((filesize($zipPath)?:0)>$max) throw new RuntimeException('Opgraderingspakken er større end 100 MB og afvises af sikkerhedshensyn.');

    $zip=new ZipArchive();
    if($zip->open($zipPath)!==true) throw new RuntimeException('ZIP-filen kan ikke åbnes.');
    try{
        $rawEntries=[];$uncompressedTotal=0;
        if($zip->numFiles>1000) throw new RuntimeException('Opgraderingspakken indeholder for mange filer.');

        // First pass: collect all raw relative paths
        for($i=0;$i<$zip->numFiles;$i++){
            $stat=$zip->statIndex($i);
            if(!$stat) continue;
            $raw=(string)$stat['name'];
            $rel=hsg_update_normalize_entry($raw);
            if($rel==='') continue;
            $size=(int)($stat['size']??0);
            $rawEntries[]=['index'=>$i,'raw'=>$raw,'rel'=>$rel,'size'=>$size,'dir'=>str_ends_with($raw,'/')];
        }

        // Auto-detect if all files live inside a single top-level directory (e.g. GitHub ZIPs like hsg-administration-1-main/)
        $prefix='';
        if(!empty($rawEntries)) {
            $firstRel=$rawEntries[0]['rel'];
            $topFolder=explode('/',$firstRel)[0];
            if($topFolder!=='') {
                $candidate=$topFolder.'/';
                $allSharePrefix=true;
                foreach($rawEntries as $e) {
                    if($e['rel']!==$topFolder && !str_starts_with($e['rel'],$candidate)) {
                        $allSharePrefix=false;
                        break;
                    }
                }
                // Only consider it a subfolder wrapper if hsg-package.json is NOT in the root, but IS in candidate
                $hasRootManifest=false;
                $hasCandidateManifest=false;
                foreach($rawEntries as $e) {
                    if($e['rel']==='hsg-package.json') { $hasRootManifest=true; }
                    if($e['rel']===$candidate.'hsg-package.json') { $hasCandidateManifest=true; }
                }
                if(!$hasRootManifest && $hasCandidateManifest && $allSharePrefix) {
                    $prefix=$candidate;
                }
            }
        }

        $entries=[];
        foreach($rawEntries as $e) {
            $rel=$e['rel'];
            if($prefix!=='' && str_starts_with($rel,$prefix)) {
                $rel=substr($rel,strlen($prefix));
            }
            if($rel==='') continue;
            if(isset($entries[$rel])) throw new RuntimeException('Opgraderingspakken indeholder dublerede filstier: '.$rel);
            if($e['size']>50*1024*1024) throw new RuntimeException('En enkelt fil i opgraderingspakken er større end 50 MB: '.$rel);
            $uncompressedTotal+=$e['size'];
            if($uncompressedTotal>300*1024*1024) throw new RuntimeException('Opgraderingspakken fylder for meget udpakket og afvises.');
            $entries[$rel]=['index'=>$e['index'],'raw'=>$e['raw'],'size'=>$e['size'],'dir'=>$e['dir']];
        }

        if(!isset($entries['hsg-package.json'])) throw new RuntimeException('ZIP-filen er ikke en gyldig HSG-opgraderingspakke (hsg-package.json mangler).');
        $manifestRaw=$zip->getFromIndex((int)$entries['hsg-package.json']['index']);
        if($manifestRaw===false) throw new RuntimeException('Kunne ikke læse pakkemanifestet.');
        $manifest=json_decode($manifestRaw,true,32,JSON_THROW_ON_ERROR);
        $product = (string)($manifest['product'] ?? (($manifest['name']??'') === 'hsg-administration' ? 'HSG Administration' : ''));
        if(!is_array($manifest) || $product !== 'HSG Administration') throw new RuntimeException('Pakken er ikke beregnet til HSG Administration.');
        $packageType = (string)($manifest['package_type'] ?? 'distribution');
        if(!in_array($packageType,['distribution','update'],true)) throw new RuntimeException('Ukendt HSG-pakketype.');
        $target=trim((string)($manifest['version']??''));
        if(!preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/',$target)) throw new RuntimeException('Pakken har et ugyldigt versionsnummer.');
        $current=app_version();
        $cmp=version_compare($target,$current);
        if($cmp<0) throw new RuntimeException('Nedgradering understøttes ikke. Installeret version er '.$current.', pakken er '.$target.'.');
        if($cmp===0 && !$allowSameVersion) throw new RuntimeException('Version '.$target.' er allerede installeret.');
        $minPhp=(string)($manifest['min_php']??'8.1.0');
        if(version_compare(PHP_VERSION,$minPhp,'<')) throw new RuntimeException('Pakken kræver PHP '.$minPhp.' eller nyere. Webhotellet kører PHP '.PHP_VERSION.'.');
        foreach((array)($manifest['required_extensions']??[]) as $ext){
            $ext=(string)$ext;
            if($ext!=='' && !extension_loaded($ext)) throw new RuntimeException('PHP-udvidelsen '.$ext.' mangler på webhotellet.');
        }
        $required=(array)($manifest['required_files']??['app_version.php','auth.php','migrations.php','core/modules.php']);
        foreach($required as $file){
            $file=hsg_update_normalize_entry((string)$file);
            if($file==='' || !isset($entries[$file]) || $entries[$file]['dir']) throw new RuntimeException('Pakken mangler den nødvendige fil '.$file.'.');
        }
        foreach(['config.php','.env','config.local.php'] as $forbidden){
            if(isset($entries[$forbidden])) throw new RuntimeException('Opgraderingspakken må ikke indeholde '.$forbidden.'.');
        }
        foreach(array_keys($entries) as $rel){
            if(str_starts_with($rel,'uploads/') || str_starts_with($rel,'storage/backups/') || str_starts_with($rel,'storage/data/')) {
                throw new RuntimeException('Opgraderingspakken forsøger at indeholde brugerdata: '.$rel);
            }
        }

        // Integrity hashes are primarily corruption detection. Package authenticity
        // still depends on the administrator only uploading trusted HSG packages.
        $hashes=(array)($manifest['files']??[]);
        $ignoredMetaFiles=['.gitignore','.gitattributes','.htaccess','.DS_Store','README.md','storage/.htaccess'];
        foreach($entries as $rel=>$entry){
            if(!empty($entry['dir']) || $rel==='hsg-package.json' || str_ends_with(strtolower($rel),'.zip')) continue;
            if(in_array($rel,$ignoredMetaFiles,true) && !array_key_exists($rel,$hashes)) continue;
            if(!array_key_exists($rel,$hashes)) throw new RuntimeException('Pakken indeholder en fil, som ikke er med i integritetsmanifestet: '.$rel);
        }
        foreach($hashes as $rel=>$expected){
            $rel=hsg_update_normalize_entry((string)$rel);
            $expected=strtolower(trim((string)$expected));
            if((in_array($rel,$ignoredMetaFiles,true) || str_ends_with(strtolower($rel),'.zip')) && !isset($entries[$rel])) continue;
            if($rel==='' || !isset($entries[$rel]) || $entries[$rel]['dir']) throw new RuntimeException('Manifestet refererer til en manglende fil: '.$rel);
            if(!preg_match('/^[a-f0-9]{64}$/',$expected)) throw new RuntimeException('Ugyldig filhash i pakkemanifestet.');
            $contents=$zip->getFromIndex((int)$entries[$rel]['index']);
            if($contents===false) throw new RuntimeException('Integritetskontrol fejlede for '.$rel.'.');
            $hash = hash('sha256', $contents);
            if(!hash_equals($expected, $hash)) {
                $lfNormalized = str_replace(["\r\n", "\r"], "\n", $contents);
                $crlfNormalized = str_replace("\n", "\r\n", $lfNormalized);
                if(!hash_equals($expected, hash('sha256', $lfNormalized)) && !hash_equals($expected, hash('sha256', $crlfNormalized))) {
                    throw new RuntimeException('Integritetskontrol fejlede for '.$rel.'.');
                }
            }
        }

        $delete=[];
        foreach((array)($manifest['delete']??[]) as $rel){
            $rel=hsg_update_normalize_entry((string)$rel);
            if($rel==='' || hsg_update_protected_relpath($rel)) throw new RuntimeException('Pakken indeholder en ugyldig sletteinstruks: '.$rel);
            $delete[]=$rel;
        }

        return [
            'product'=>'HSG Administration',
            'version'=>$target,
            'current_version'=>$current,
            'package_type'=>(string)$manifest['package_type'],
            'release_notes'=>(string)($manifest['release_notes']??''),
            'min_php'=>$minPhp,
            'entries'=>$entries,
            'delete'=>$delete,
            'file_count'=>count(array_filter($entries,static fn($e)=>!$e['dir'])),
            'package_sha256'=>hash_file('sha256',$zipPath),
            'package_size'=>filesize($zipPath)?:0,
        ];
    } catch(JsonException $e){
        throw new RuntimeException('Pakkemanifestet er ugyldig JSON.');
    } finally {
        $zip->close();
    }
}

function hsg_update_cleanup_old_staging(int $maxAgeSeconds=86400): void {
    $dir=hsg_update_storage_dir();$cutoff=time()-max(3600,$maxAgeSeconds);
    foreach(glob($dir.'/*')?:[] as $path){
        if((int)@filemtime($path)>=$cutoff) continue;
        if(is_file($path)) @unlink($path);
        elseif(is_dir($path)) hsg_update_delete_tree($path);
    }
}

function hsg_update_stage_uploaded_file(array $upload): array {
    if(($upload['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK){
        $code=(int)($upload['error']??UPLOAD_ERR_NO_FILE);
        $messages=[UPLOAD_ERR_INI_SIZE=>'Pakken overstiger upload_max_filesize.',UPLOAD_ERR_FORM_SIZE=>'Pakken er for stor.',UPLOAD_ERR_PARTIAL=>'Upload blev kun delvist gennemført.',UPLOAD_ERR_NO_FILE=>'Vælg en ZIP-pakke.',UPLOAD_ERR_NO_TMP_DIR=>'Webhotellet mangler en midlertidig uploadmappe.',UPLOAD_ERR_CANT_WRITE=>'Webhotellet kunne ikke skrive uploadfilen.',UPLOAD_ERR_EXTENSION=>'En PHP-udvidelse stoppede uploaden.'];
        throw new RuntimeException($messages[$code]??'Upload fejlede (kode '.$code.').');
    }
    $original=basename((string)($upload['name']??'hsg-update.zip'));
    if(strtolower(pathinfo($original,PATHINFO_EXTENSION))!=='zip') throw new RuntimeException('Opgraderingspakken skal være en ZIP-fil.');
    $dest=hsg_update_storage_dir().'/staged-'.bin2hex(random_bytes(12)).'.zip';
    $tmp=(string)($upload['tmp_name']??'');
    if($tmp==='' || !is_uploaded_file($tmp) || !move_uploaded_file($tmp,$dest)) throw new RuntimeException('Kunne ikke gemme den uploadede pakke sikkert.');
    try{
        $info=hsg_update_validate_package($dest);
        $info['path']=$dest;$info['original_name']=$original;
        return $info;
    } catch(Throwable $e){
        @unlink($dest);throw $e;
    }
}

function hsg_update_extract_to_stage(string $zipPath,array $info): string {
    $stage=hsg_update_storage_dir().'/extract-'.bin2hex(random_bytes(10));
    if(!mkdir($stage,0775,true) && !is_dir($stage)) throw new RuntimeException('Kunne ikke oprette stagingmappe til opgraderingen.');
    $zip=new ZipArchive();
    if($zip->open($zipPath)!==true) throw new RuntimeException('Kunne ikke åbne opgraderingspakken under installationen.');
    try{
        foreach($info['entries'] as $rel=>$entry){
            if(!empty($entry['dir'])) continue;
            if(hsg_update_protected_relpath($rel)) continue;
            $target=$stage.'/'.$rel;
            $dir=dirname($target);
            if(!is_dir($dir) && !mkdir($dir,0775,true) && !is_dir($dir)) throw new RuntimeException('Kunne ikke oprette mappe til '.$rel.'.');
            $stream=$zip->getStream($entry['raw']??$rel);
            if(!$stream) throw new RuntimeException('Kunne ikke læse '.$rel.' fra pakken.');
            $out=fopen($target,'wb');
            if(!$out){fclose($stream);throw new RuntimeException('Kunne ikke skrive '.$rel.' i stagingmappen.');}
            stream_copy_to_stream($stream,$out);fclose($stream);fclose($out);
        }
    } finally {$zip->close();}
    return $stage;
}

function hsg_update_copy_tree(string $stage,string $siteRoot,array &$changed): void {
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stage,FilesystemIterator::SKIP_DOTS));
    foreach($it as $file){
        if(!$file->isFile()) continue;
        $rel=ltrim(str_replace('\\','/',substr($file->getPathname(),strlen($stage))),'/');
        if($rel==='' || hsg_update_protected_relpath($rel)) continue;
        $dest=$siteRoot.'/'.$rel;
        $dir=dirname($dest);
        if(!is_dir($dir) && !mkdir($dir,0775,true) && !is_dir($dir)) throw new RuntimeException('Kunne ikke oprette mappen '.dirname($rel).'.');
        $tmp=$dest.'.hsg-new-'.bin2hex(random_bytes(4));
        if(!copy($file->getPathname(),$tmp)) throw new RuntimeException('Kunne ikke skrive den nye fil '.$rel.'.');
        @chmod($tmp,0644);
        if(is_file($dest) && !@unlink($dest)){@unlink($tmp);throw new RuntimeException('Kunne ikke erstatte '.$rel.'. Kontroller filrettigheder på webhotellet.');}
        if(!@rename($tmp,$dest)){@unlink($tmp);throw new RuntimeException('Kunne ikke aktivere den nye fil '.$rel.'.');}
        $changed[]=$rel;
    }
}

function hsg_update_run_start(PDO $pdo,string $from,string $to,string $package): int {
    if(!db_table_exists($pdo,'hsg_update_runs')) return 0;
    $pdo->prepare("INSERT INTO hsg_update_runs(version_from,version_to,package_name,status,created_at) VALUES(?,?,?,'running',NOW())")->execute([$from,$to,$package]);
    return (int)$pdo->lastInsertId();
}

function hsg_update_run_finish(PDO $pdo,int $id,string $status,?string $backup,?string $message,int $files=0): void {
    if($id<=0 || !db_table_exists($pdo,'hsg_update_runs')) return;
    $pdo->prepare('UPDATE hsg_update_runs SET status=?,backup_filename=?,message=?,files_changed=?,completed_at=NOW() WHERE id=?')->execute([$status,$backup,$message,$files,$id]);
}

function hsg_install_staged_update(PDO $pdo,string $zipPath,string $expectedSha,string $packageName): array {
    if(!is_file($zipPath)) throw new RuntimeException('Den kontrollerede opgraderingspakke findes ikke længere. Upload den igen.');
    $actual=hash_file('sha256',$zipPath);
    if(!hash_equals($expectedSha,$actual)) throw new RuntimeException('Opgraderingspakken er ændret efter kontrollen. Upload den igen.');
    $info=hsg_update_validate_package($zipPath);
    $siteRoot=dirname(__DIR__);
    $runId=hsg_update_run_start($pdo,app_version(),$info['version'],$packageName);
    $backupName=null;$stage=null;$rollback=null;$changed=[];$maintenance=$siteRoot.'/.hsg-maintenance';
    try{
        require_once __DIR__.'/backup.php';
        $backup=hsg_make_backup($pdo,'full','pre-update-'.$info['version']);
        $backupName=(string)($backup['filename']??'');

        $stage=hsg_update_extract_to_stage($zipPath,$info);
        $rollback=hsg_update_storage_dir().'/rollback-'.bin2hex(random_bytes(10));
        if(!mkdir($rollback,0775,true) && !is_dir($rollback)) throw new RuntimeException('Kunne ikke oprette rollbackmappe.');

        // Keep copies of files that will be replaced/deleted for file-level rollback.
        foreach($info['entries'] as $rel=>$entry){
            if(!empty($entry['dir']) || hsg_update_protected_relpath($rel)) continue;
            $current=$siteRoot.'/'.$rel;
            if(is_file($current)){
                $target=$rollback.'/'.$rel;$dir=dirname($target);
                if(!is_dir($dir)) @mkdir($dir,0775,true);
                if(!copy($current,$target)) throw new RuntimeException('Kunne ikke forberede rollback af '.$rel.'.');
            }
        }
        foreach($info['delete'] as $rel){
            $current=$siteRoot.'/'.$rel;
            if(is_file($current)){
                $target=$rollback.'/'.$rel;$dir=dirname($target);
                if(!is_dir($dir)) @mkdir($dir,0775,true);
                if(!copy($current,$target)) throw new RuntimeException('Kunne ikke forberede rollback af '.$rel.'.');
            }
        }

        file_put_contents($maintenance,json_encode(['started_at'=>date('c'),'from'=>app_version(),'to'=>$info['version']],JSON_UNESCAPED_SLASHES));
        hsg_update_copy_tree($stage,$siteRoot,$changed);
        foreach($info['delete'] as $rel){
            $dest=$siteRoot.'/'.$rel;
            if(is_file($dest) && !@unlink($dest)) throw new RuntimeException('Kunne ikke fjerne den udgåede fil '.$rel.'.');
            $changed[]='- '.$rel;
        }
        clearstatcache(true);
        if(function_exists('opcache_reset')) @opcache_reset();

        // Core migrations read schema.sql/app_version.php from disk, so the newly
        // installed schema is applied in the same request. Module migrations for
        // entirely new modules are also guaranteed on the next normal request.
        ensure_schema_updates($pdo);

        if(function_exists('audit_log')) audit_log($pdo,'system.update','system',null,['from'=>$info['current_version'],'to'=>$info['version'],'package'=>$packageName,'backup'=>$backupName,'files_changed'=>count($changed)]);
        hsg_update_run_finish($pdo,$runId,'success',$backupName,'Opgradering gennemført.',count($changed));
        @unlink($maintenance);
        @unlink($zipPath);
        return ['version'=>$info['version'],'backup'=>$backupName,'files_changed'=>count($changed),'message'=>'HSG Administration er opgraderet til '.$info['version'].'.'];
    } catch(Throwable $e){
        // Restore changed application files. Database recovery remains available
        // from the mandatory FULL backup if a DB migration itself failed.
        if($rollback && is_dir($rollback)){
            try{
                // Remove files that were newly introduced by the failed package.
                foreach($changed as $rel){
                    if(str_starts_with($rel,'- ')) continue;
                    if(!is_file($rollback.'/'.$rel) && is_file($siteRoot.'/'.$rel)) @unlink($siteRoot.'/'.$rel);
                }
                $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($rollback,FilesystemIterator::SKIP_DOTS));
                foreach($it as $file){
                    if(!$file->isFile()) continue;
                    $rel=ltrim(str_replace('\\','/',substr($file->getPathname(),strlen($rollback))),'/');
                    $dest=$siteRoot.'/'.$rel;$dir=dirname($dest);if(!is_dir($dir)) @mkdir($dir,0775,true);@copy($file->getPathname(),$dest);
                }
            }catch(Throwable){/* FULL backup is the final recovery path. */}
        }
        @unlink($maintenance);
        if(function_exists('opcache_reset')) @opcache_reset();
        hsg_update_run_finish($pdo,$runId,'failed',$backupName,$e->getMessage(),count($changed));
        throw new RuntimeException($e->getMessage().($backupName?' Der blev oprettet en FULL-backup før forsøget: '.$backupName.'.':''),0,$e);
    } finally {
        if($stage && is_dir($stage)) hsg_update_delete_tree($stage);
        if($rollback && is_dir($rollback)) hsg_update_delete_tree($rollback);
    }
}

function hsg_update_cleanup_staged(?string $path): void {
    if(!$path || !is_file($path)) return;
    $base=realpath(hsg_update_storage_dir());$real=realpath($path);
    if($base && $real && str_starts_with($real,$base.DIRECTORY_SEPARATOR)) @unlink($real);
}

function hsg_github_http_get(string $url, int &$status = 0): string {
    $ch=curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL=>$url,
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_FOLLOWLOCATION=>true,
        CURLOPT_MAXREDIRS=>5,
        CURLOPT_TIMEOUT=>30,
        CURLOPT_USERAGENT=>'HSG-Administration-Updater',
        CURLOPT_HTTPHEADER=>['Accept: application/vnd.github.v3+json'],
    ]);
    $res=curl_exec($ch);
    $status=(int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err=curl_error($ch);
    curl_close($ch);
    if($err) throw new RuntimeException('Fejl ved forbindelse til GitHub: '.$err);
    if($status === 404) return '';
    if($status < 200 || $status >= 300) throw new RuntimeException('GitHub API svarede med HTTP-fejl '.$status.'.');
    return (string)$res;
}

function hsg_github_check_latest_release(string $repo = 'jydemagt/hsg-administration-1'): array {
    $releaseData = null;
    $releaseVersion = '0.0.0';

    // Check GitHub Releases first
    $releaseUrl = "https://api.github.com/repos/{$repo}/releases/latest";
    try {
        $httpStatus = 0;
        $json = hsg_github_http_get($releaseUrl, $httpStatus);
        if($httpStatus === 200 && trim($json) !== '') {
            $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
            $tag = (string)($data['tag_name'] ?? '');
            $version = ltrim($tag, 'v');
            $downloadUrl = '';
            if(!empty($data['assets']) && is_array($data['assets'])) {
                foreach($data['assets'] as $asset) {
                    if(str_ends_with(strtolower((string)$asset['name']), '.zip')) {
                        $downloadUrl = (string)($asset['browser_download_url'] ?? '');
                        break;
                    }
                }
            }
            if($downloadUrl === '') {
                $downloadUrl = (string)($data['zipball_url'] ?? "https://github.com/{$repo}/archive/refs/tags/{$tag}.zip");
            }
            $releaseVersion = $version;
            $releaseData = [
                'tag' => $tag,
                'version' => $version,
                'current_version' => app_version(),
                'has_update' => version_compare($version, app_version(), '>'),
                'name' => (string)($data['name'] ?? $tag),
                'notes' => (string)($data['body'] ?? ''),
                'download_url' => $downloadUrl,
                'published_at' => (string)($data['published_at'] ?? ''),
            ];
        }
    } catch(Throwable $e) {
        // Fallthrough to main branch check if releases call fails
    }

    // Direct GitHub branch checks
    $branchesToCheck = ['main', 'master'];
    foreach($branchesToCheck as $branchName) {
        try {
            $rawManifestUrl = "https://raw.githubusercontent.com/{$repo}/{$branchName}/hsg-package.json";
            $manifestStatus = 0;
            $manifestJson = hsg_github_http_get($rawManifestUrl, $manifestStatus);
            if($manifestStatus === 200 && trim($manifestJson) !== '') {
                $manifest = json_decode($manifestJson, true, 32, JSON_THROW_ON_ERROR);
                $branchVersion = (string)($manifest['version'] ?? app_version());
                if(version_compare($branchVersion, $releaseVersion, '>=')) {
                    return [
                        'tag' => $branchName,
                        'version' => $branchVersion,
                        'current_version' => app_version(),
                        'has_update' => version_compare($branchVersion, app_version(), '>'),
                        'name' => 'GitHub '.$branchName.' (v'.$branchVersion.')',
                        'notes' => (string)($manifest['release_notes'] ?? 'Ny opdatering fra GitHub.'),
                        'download_url' => "https://github.com/{$repo}/archive/refs/heads/{$branchName}.zip",
                        'published_at' => date('Y-m-d H:i:s'),
                    ];
                }
            }
        } catch(Throwable $e) {
            // Check next branch
        }
    }

    if($releaseData) {
        return $releaseData;
    }

    return [
        'tag' => '',
        'version' => app_version(),
        'current_version' => app_version(),
        'has_update' => false,
        'name' => 'Ingen GitHub Releases endnu',
        'notes' => 'Der er endnu ikke oprettet nogen officielle releases eller opdateringer på GitHub-repositoryet.',
        'download_url' => '',
        'published_at' => '',
    ];
}

function hsg_github_download_and_stage(string $downloadUrl, string $version, bool $allowSameVersion = true): array {
    if(!filter_var($downloadUrl, FILTER_VALIDATE_URL)) {
        throw new RuntimeException('Ugyldig opdaterings-URL fra GitHub.');
    }
    $host = strtolower((string)parse_url($downloadUrl, PHP_URL_HOST));
    if(!in_array($host, ['github.com', 'api.github.com', 'codeload.github.com', 'objects.githubusercontent.com', 'raw.githubusercontent.com'], true) && !str_ends_with($host, '.githubusercontent.com')) {
        throw new RuntimeException('Opdateringer kan kun hentes direkte fra GitHub-domæner.');
    }
    $dest = hsg_update_storage_dir().'/github-'.$version.'-'.bin2hex(random_bytes(6)).'.zip';
    $fp = fopen($dest, 'wb');
    if(!$fp) throw new RuntimeException('Kunne ikke oprette midlertidig fil til GitHub-download.');

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $downloadUrl,
        CURLOPT_FILE => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_USERAGENT => 'HSG-Administration-Updater',
    ]);
    $success = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    fclose($fp);

    if(!$success || $status !== 200) {
        @unlink($dest);
        throw new RuntimeException('Download fra GitHub fejlede (HTTP '.$status.($err ? ': '.$err : '').').');
    }

    try {
        $info = hsg_update_validate_package($dest, $allowSameVersion);
        $info['path'] = $dest;
        $info['original_name'] = 'GitHub Release '.$version;
        return $info;
    } catch(Throwable $e) {
        @unlink($dest);
        throw $e;
    }
}
