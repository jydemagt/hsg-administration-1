<?php
declare(strict_types=1);
require_once __DIR__.'/core/settings.php';
require_once __DIR__.'/core/product_enrichment.php';
require_once __DIR__.'/core/catalog_image_seed.php';

function db_table_exists(PDO $pdo, string $table): bool {
    $st=$pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $st->execute([$table]); return (int)$st->fetchColumn()>0;
}
function db_column_exists(PDO $pdo,string $table,string $column): bool {
    $st=$pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
    $st->execute([$table,$column]); return (int)$st->fetchColumn()>0;
}
function meta_get(PDO $pdo,string $key,?string $default=null): ?string {
    if(!db_table_exists($pdo,'lager_meta')) return $default;
    $st=$pdo->prepare('SELECT meta_value FROM lager_meta WHERE meta_key=?');$st->execute([$key]);$v=$st->fetchColumn();return $v===false?$default:(string)$v;
}
function meta_set(PDO $pdo,string $key,string $value): void {
    $pdo->prepare('INSERT INTO lager_meta(meta_key,meta_value) VALUES(?,?) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)')->execute([$key,$value]);
}
function ensure_schema_updates(PDO $pdo): void {
    if(!db_table_exists($pdo,'lager_users')) return;
    $targetVersion=(string)(require __DIR__.'/app_version.php');
    $currentVersion=meta_get($pdo,'schema_version','0.0.0') ?? '0.0.0';
    if(version_compare($currentVersion,$targetVersion,'>=') && db_table_exists($pdo,'hsg_settings') && db_table_exists($pdo,'hsg_module_versions') && db_table_exists($pdo,'hsg_audit_log') && db_table_exists($pdo,'hsg_user_module_access') && db_table_exists($pdo,'hsg_backup_runs') && db_table_exists($pdo,'hsg_update_runs')) return;
    $schema=file_get_contents(__DIR__.'/schema.sql');
    if($schema!==false){ foreach(preg_split('/;\s*(?:\r?\n|$)/',$schema)?:[] as $sql){$sql=trim($sql);if($sql!==''){$pdo->exec($sql);}} }

    // Platform core columns. Kept backward compatible with v1.0.x databases.
    if(db_table_exists($pdo,'hsg_module_versions')){
        if(!db_column_exists($pdo,'hsg_module_versions','enabled')) $pdo->exec('ALTER TABLE hsg_module_versions ADD enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER version');
        if(!db_column_exists($pdo,'hsg_module_versions','is_core')) $pdo->exec('ALTER TABLE hsg_module_versions ADD is_core TINYINT(1) NOT NULL DEFAULT 0 AFTER enabled');
    }

    if(db_table_exists($pdo,'lager_users') && !db_column_exists($pdo,'lager_users','token_cipher')) $pdo->exec('ALTER TABLE lager_users ADD token_cipher TEXT NULL AFTER token_last4');

    if(db_table_exists($pdo,'lager_brands')){
        if(!db_column_exists($pdo,'lager_brands','parent_id')) $pdo->exec('ALTER TABLE lager_brands ADD parent_id INT UNSIGNED NULL AFTER id');
        if(!db_column_exists($pdo,'lager_brands','image_search_url')) $pdo->exec('ALTER TABLE lager_brands ADD image_search_url VARCHAR(500) NULL AFTER website_url');
        if(!db_column_exists($pdo,'lager_brands','show_in_catalog')) $pdo->exec('ALTER TABLE lager_brands ADD show_in_catalog TINYINT(1) NOT NULL DEFAULT 1 AFTER logo_path');
    }

    $productChanges=[
      'call_name'=>'ALTER TABLE lager_products ADD call_name VARCHAR(180) NULL AFTER name',
      'brand_id'=>'ALTER TABLE lager_products ADD brand_id INT UNSIGNED NULL AFTER name',
      'category'=>'ALTER TABLE lager_products ADD category VARCHAR(140) NULL AFTER brand_id',
      'distillery'=>'ALTER TABLE lager_products ADD distillery VARCHAR(160) NULL AFTER category',
      'country'=>'ALTER TABLE lager_products ADD country VARCHAR(100) NULL AFTER distillery',
      'age_text'=>'ALTER TABLE lager_products ADD age_text VARCHAR(80) NULL AFTER country',
      'vintage_year'=>'ALTER TABLE lager_products ADD vintage_year SMALLINT UNSIGNED NULL AFTER age_text',
      'abv'=>'ALTER TABLE lager_products ADD abv DECIMAL(5,2) NULL AFTER vintage_year',
      'bottle_size_cl'=>'ALTER TABLE lager_products ADD bottle_size_cl DECIMAL(6,2) NULL AFTER abv',
      'cask_type'=>'ALTER TABLE lager_products ADD cask_type VARCHAR(220) NULL AFTER bottle_size_cl',
      'cask_number'=>'ALTER TABLE lager_products ADD cask_number VARCHAR(80) NULL AFTER cask_type',
      'bottle_count'=>'ALTER TABLE lager_products ADD bottle_count VARCHAR(80) NULL AFTER cask_type',
      'wholesale_price'=>'ALTER TABLE lager_products ADD wholesale_price DECIMAL(10,2) NULL AFTER bottle_count',
      'retail_price'=>'ALTER TABLE lager_products ADD retail_price DECIMAL(10,2) NULL AFTER wholesale_price',
      'is_new'=>'ALTER TABLE lager_products ADD is_new TINYINT(1) NOT NULL DEFAULT 0 AFTER retail_price',
      'show_in_catalog'=>'ALTER TABLE lager_products ADD show_in_catalog TINYINT(1) NOT NULL DEFAULT 1 AFTER is_new',
      'supplier_name'=>'ALTER TABLE lager_products ADD supplier_name VARCHAR(180) NULL AFTER notes',
      'supplier_domain'=>'ALTER TABLE lager_products ADD supplier_domain VARCHAR(190) NULL AFTER supplier_name',
      'supplier_url'=>'ALTER TABLE lager_products ADD supplier_url TEXT NULL AFTER supplier_domain',
      'image_path'=>'ALTER TABLE lager_products ADD image_path VARCHAR(255) NULL AFTER supplier_url',
      'image_source_url'=>'ALTER TABLE lager_products ADD image_source_url TEXT NULL AFTER image_path',
      'image_checked_at'=>'ALTER TABLE lager_products ADD image_checked_at DATETIME NULL AFTER image_source_url',
      'image_method'=>"ALTER TABLE lager_products ADD image_method ENUM('manual','supplier','search','ai') NULL AFTER image_checked_at",
      'image_confidence'=>'ALTER TABLE lager_products ADD image_confidence TINYINT UNSIGNED NULL AFTER image_method',
      'image_ai_note'=>'ALTER TABLE lager_products ADD image_ai_note VARCHAR(500) NULL AFTER image_confidence',
      'image_validation_score'=>'ALTER TABLE lager_products ADD image_validation_score TINYINT UNSIGNED NULL AFTER image_ai_note',
      'image_validation_status'=>"ALTER TABLE lager_products ADD image_validation_status ENUM('verified','flagged','error') NULL AFTER image_validation_score",
      'image_validation_note'=>'ALTER TABLE lager_products ADD image_validation_note VARCHAR(1000) NULL AFTER image_validation_status',
      'image_validated_at'=>'ALTER TABLE lager_products ADD image_validated_at DATETIME NULL AFTER image_validation_note',
      'image_validation_model'=>'ALTER TABLE lager_products ADD image_validation_model VARCHAR(120) NULL AFTER image_validated_at',
      'image_approval_status'=>"ALTER TABLE lager_products ADD image_approval_status ENUM('pending','approved','rejected') NULL AFTER image_validation_model",
      'image_approved_at'=>'ALTER TABLE lager_products ADD image_approved_at DATETIME NULL AFTER image_approval_status',
      'image_approved_by_admin'=>'ALTER TABLE lager_products ADD image_approved_by_admin INT UNSIGNED NULL AFTER image_approved_at',
      'data_enrichment_score'=>'ALTER TABLE lager_products ADD data_enrichment_score TINYINT UNSIGNED NULL AFTER image_approved_by_admin',
      'data_enrichment_source'=>'ALTER TABLE lager_products ADD data_enrichment_source VARCHAR(30) NULL AFTER data_enrichment_score',
      'data_enrichment_note'=>'ALTER TABLE lager_products ADD data_enrichment_note VARCHAR(1000) NULL AFTER data_enrichment_source',
      'data_enriched_at'=>'ALTER TABLE lager_products ADD data_enriched_at DATETIME NULL AFTER data_enrichment_note'
    ];
    foreach($productChanges as $col=>$sql){if(!db_column_exists($pdo,'lager_products',$col))$pdo->exec($sql);}
    try{$pdo->exec('CREATE INDEX idx_product_cask_number ON lager_products(cask_number)');}catch(Throwable $e){}
    if(!db_column_exists($pdo,'lager_products','quality_approved_at'))$pdo->exec('ALTER TABLE lager_products ADD quality_approved_at DATETIME NULL AFTER data_enriched_at');
    if(!db_column_exists($pdo,'lager_products','quality_approved_by_admin'))$pdo->exec('ALTER TABLE lager_products ADD quality_approved_by_admin INT UNSIGNED NULL AFTER quality_approved_at');
    if(!db_table_exists($pdo,'hsg_product_field_exemptions')){
        $pdo->exec("CREATE TABLE hsg_product_field_exemptions (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,product_id INT UNSIGNED NOT NULL,field_key VARCHAR(80) NOT NULL,reason VARCHAR(255) NULL,created_by_admin INT UNSIGNED NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,UNIQUE KEY uniq_product_quality_exemption(product_id,field_key),INDEX idx_quality_exemption_product(product_id),CONSTRAINT fk_quality_exemption_product FOREIGN KEY(product_id) REFERENCES lager_products(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    if(!db_table_exists($pdo,'hsg_supplier_import_runs')){
        $pdo->exec("CREATE TABLE hsg_supplier_import_runs (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,filename VARCHAR(255) NOT NULL,sheet_name VARCHAR(180) NULL,rows_detected INT UNSIGNED NOT NULL DEFAULT 0,rows_updated INT UNSIGNED NOT NULL DEFAULT 0,created_by_admin INT UNSIGNED NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX idx_supplier_import_created(created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    // v1.3.4: every existing stored product image must be manually approved once.
    // Never overwrite an explicit approval/rejection on later upgrades.
    if(db_column_exists($pdo,'lager_products','image_approval_status')){
        $pdo->exec("UPDATE lager_products SET image_approval_status='pending',image_approved_at=NULL,image_approved_by_admin=NULL WHERE image_path IS NOT NULL AND image_path<>'' AND image_approval_status IS NULL");
    }
    // v1.4.1: no automatic image discovery. Import the locally bundled May 2026 HSG catalog images.
    if(db_table_exists($pdo,'hsg_settings')) setting_set($pdo,'image_ai_enabled','0');
    if(db_table_exists($pdo,'lager_image_candidates')) $pdo->exec('DELETE FROM lager_image_candidates');
    hsg_import_catalog_seed_images($pdo,false);
    // v1.5.2: refresh the bundled HSG May-2026 images after whitespace-cropping and trust this first-party HSG catalog source.
    if(function_exists('meta_get') && meta_get($pdo,'catalog_images_2026_05_layout_refresh','0')!=='1'){hsg_import_catalog_seed_images($pdo,true);meta_set($pdo,'catalog_images_2026_05_layout_refresh','1');}

    // Backfill fields that can be safely read directly from existing product names. No external AI calls run during upgrade.
    $enrichRows=$pdo->query('SELECT id,name,distillery,country,age_text,vintage_year,abv,bottle_size_cl,cask_type,cask_number,category FROM lager_products')->fetchAll(PDO::FETCH_ASSOC);
    foreach($enrichRows as $er){
        $parsed=hsg_product_parse_text((string)$er['name']);$sets=[];$vals=[];
        foreach(['distillery','country','age_text','vintage_year','abv','bottle_size_cl','cask_type','cask_number','category'] as $field){
            if(($er[$field]??null)!==null && trim((string)($er[$field]??''))!=='') continue;
            if(!array_key_exists($field,$parsed['fields']??[])) continue;
            $v=$parsed['fields'][$field];if($v===null||trim((string)$v)==='')continue;$sets[]=$field.'=?';$vals[]=$v;
        }
        if($sets){
            $scores=array_values((array)($parsed['confidence']??[]));
            $sets[]='data_enrichment_score=?';$vals[]=$scores?(int)round(array_sum($scores)/count($scores)):0;
            $sets[]="data_enrichment_source='rules'";$sets[]='data_enrichment_note=?';$vals[]=substr((string)($parsed['reason']??''),0,1000);$sets[]='data_enriched_at=NOW()';
            $vals[]=(int)$er['id'];$pdo->prepare('UPDATE lager_products SET '.implode(',',$sets).' WHERE id=?')->execute($vals);
        }
    }
    if(!db_table_exists($pdo,'lager_image_candidates')){
        $pdo->exec("CREATE TABLE lager_image_candidates (
          id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          product_id BIGINT UNSIGNED NOT NULL,
          page_url VARCHAR(1000) NULL,
          image_url VARCHAR(1000) NULL,
          confidence TINYINT UNSIGNED NOT NULL DEFAULT 0,
          reason VARCHAR(600) NULL,
          provider VARCHAR(30) NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          INDEX idx_image_candidates_product (product_id),
          INDEX idx_image_candidates_score (confidence)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    // v1.3.7: previous versions could store candidates discovered via general web search.
    // Clear the transient candidate list once so it is rebuilt strictly from official supplier pages.
    if(version_compare($currentVersion,'1.3.7','<') && db_table_exists($pdo,'lager_image_candidates')){
        $pdo->exec('DELETE FROM lager_image_candidates');
    }
    if(version_compare($currentVersion,'1.3.7','<') && db_column_exists($pdo,'lager_products','image_source_url')){
        $srcRows=$pdo->query("SELECT p.id,p.image_source_url,p.supplier_domain,p.supplier_url,b.website_url,b.image_search_url FROM lager_products p LEFT JOIN lager_brands b ON b.id=p.brand_id WHERE p.image_path IS NOT NULL AND p.image_path<>'' AND p.image_approval_status='approved'")->fetchAll(PDO::FETCH_ASSOC);
        $pend=$pdo->prepare("UPDATE lager_products SET image_approval_status='pending',image_approved_at=NULL,image_approved_by_admin=NULL WHERE id=?");
        foreach($srcRows as $sr){
            $root='';foreach([$sr['image_search_url']??'',$sr['website_url']??'',$sr['supplier_url']??''] as $rv){$rv=trim((string)$rv);if($rv!==''){$root=$rv;break;}}if($root===''&&!empty($sr['supplier_domain']))$root='https://'.trim((string)$sr['supplier_domain']);
            $rootHost=strtolower((string)parse_url($root,PHP_URL_HOST));$rootHost=preg_replace('/^www\./i','',$rootHost)?:$rootHost;$sourceHost=strtolower((string)parse_url((string)($sr['image_source_url']??''),PHP_URL_HOST));$sourceHost=preg_replace('/^www\./i','',$sourceHost)?:$sourceHost;
            $valid=$rootHost!==''&&$sourceHost!==''&&($sourceHost===$rootHost||str_ends_with($sourceHost,'.'.$rootHost)||str_ends_with($rootHost,'.'.$sourceHost));if(!$valid)$pend->execute([(int)$sr['id']]);
        }
    }
    if(!db_table_exists($pdo,'lager_image_rejections')){
        $pdo->exec("CREATE TABLE lager_image_rejections (
          id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          product_id BIGINT UNSIGNED NOT NULL,
          url_hash CHAR(64) NOT NULL,
          url VARCHAR(1000) NOT NULL,
          reason VARCHAR(300) NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY uniq_image_rejection_product_url (product_id,url_hash),
          INDEX idx_image_rejections_product (product_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    if(!db_column_exists($pdo,'lager_locations','sort_order'))$pdo->exec('ALTER TABLE lager_locations ADD sort_order INT NOT NULL DEFAULT 100 AFTER active');
    if(!db_column_exists($pdo,'lager_reservations','customer_name'))$pdo->exec('ALTER TABLE lager_reservations ADD customer_name VARCHAR(180) NULL AFTER quantity');
    if(!db_column_exists($pdo,'lager_reservations','created_by_admin'))$pdo->exec('ALTER TABLE lager_reservations ADD created_by_admin INT UNSIGNED NULL AFTER created_by');
    if(!db_column_exists($pdo,'lager_stock_movements','created_by_admin'))$pdo->exec('ALTER TABLE lager_stock_movements ADD created_by_admin INT UNSIGNED NULL AFTER created_by');
    if(db_table_exists($pdo,'lager_admins') && (int)$pdo->query('SELECT COUNT(*) FROM lager_admins')->fetchColumn()>0)$pdo->exec("UPDATE lager_users SET role='user' WHERE role='admin'");
    if(db_table_exists($pdo,'lager_login_attempts'))$pdo->exec("DELETE FROM lager_login_attempts WHERE attempted_at < (NOW() - INTERVAL 30 DAY)");
    $pdo->exec("UPDATE lager_products p SET status='inactive' WHERE p.status='active' AND COALESCE((SELECT SUM(s.quantity) FROM lager_stock s WHERE s.product_id=p.id),0) - COALESCE((SELECT SUM(r.quantity) FROM lager_reservations r WHERE r.product_id=p.id AND r.status='reserved'),0) <= 0");
    $old=$pdo->query("SELECT id FROM lager_locations WHERE name='Lager Gert' LIMIT 1")->fetchColumn();$new=$pdo->query("SELECT id FROM lager_locations WHERE name='Gert Lager' LIMIT 1")->fetchColumn();if($old && !$new)$pdo->prepare('UPDATE lager_locations SET name=? WHERE id=?')->execute(['Gert Lager',(int)$old]);
    $pdo->exec("INSERT IGNORE INTO lager_locations(name,description,active,sort_order) VALUES ('Hovedlager','Primært lager',1,10),('Gert Lager','Gert lager',1,20)");
    $brandSeeds=[
      ["Woodrow's of Edinburgh",'Woodrow’s of Edinburgh er en uafhængig aftapper og fadhandler. Når de modtager fade, udvælger de de særlige fade, som er for gode til at sende videre, og aftapper dem blandt andet under Warehouse Reserve.',10,null],
      ['Fragrant Drops','Fragrant Drops er en lille uafhængig aftapper drevet af George og Rachel med fokus på unikke aftapninger og et særpræget flaskedesign inspireret af 1920’erne.',20,null],
      ['Edinburgh Whisky','Edinburgh Whisky laver en serie af single malts med fokus på god kvalitet til fornuftige priser og et markant, mørkt flaskedesign.',30,null],
      ['Lady of the Glen','Lady of the Glen er en uafhængig aftapper med fokus på enkeltfade, høj styrke og begrænsede aftapninger. Sortimentet omfatter også serier som St Bridget’s Kirk og Dalgety Bay.',40,null],
      ['Samhain Series',null,45,'Lady of the Glen'],['Dalgety Bay',null,50,'Lady of the Glen'],['St. Bridget Kirk',null,55,'Lady of the Glen'],
      ['Uncharted Whisky','Uncharted Whisky drives af Jack Breslin og Dana Devos fra Fintry nær Glasgow. De udvælger kvalitetsfade og aftapper ofte ved høj styrke med navne inspireret af musik.',60,null],
      ['Nyborg Destilleri','Nyborg Destilleri er et dansk økologisk destilleri i Nyborg. De producerer blandt andet whisky under navnet Isle of Fionia samt gin, rom, bitter, kaffelikør og akvavit.',70,null]
    ];
    foreach($brandSeeds as $b){
        $parentName=$b[3]??null;
        $parentId=null;
        if($parentName){
            $stP=$pdo->prepare('SELECT id FROM lager_brands WHERE name=?');$stP->execute([$parentName]);$parentId=(int)($stP->fetchColumn()?:0)?:null;
        }
        $pdo->prepare('INSERT IGNORE INTO lager_brands(name,description,active,sort_order,parent_id) VALUES(?,?,1,?,?)')->execute([$b[0],$b[1],$b[2],$parentId]);
        $pdo->prepare("UPDATE lager_brands SET sort_order=?,parent_id=?,description=COALESCE(NULLIF(description,''),?) WHERE name=?")->execute([$b[2],$parentId,$b[1],$b[0]]);
    }
    $pdo->exec("UPDATE lager_products p JOIN lager_brands b ON b.name='Woodrow''s of Edinburgh' SET p.brand_id=b.id WHERE p.brand_id IS NULL AND p.sku LIKE '17-%'");
    $pdo->exec("UPDATE lager_products p JOIN lager_brands b ON b.name='Fragrant Drops' SET p.brand_id=b.id WHERE p.brand_id IS NULL AND p.sku LIKE '18-%'");
    $pdo->exec("UPDATE lager_products p JOIN lager_brands b ON b.name='Edinburgh Whisky' SET p.brand_id=b.id WHERE p.brand_id IS NULL AND p.sku LIKE '19-%'");
    $pdo->exec("UPDATE lager_products p JOIN lager_brands b ON b.name='Uncharted Whisky' SET p.brand_id=b.id WHERE p.brand_id IS NULL AND p.sku LIKE '13-%'");
    $pdo->exec("UPDATE lager_products p JOIN lager_brands b ON b.name='Nyborg Destilleri' SET p.brand_id=b.id WHERE p.brand_id IS NULL AND p.sku LIKE '14-%'");
    $pdo->exec("UPDATE lager_products p JOIN lager_brands b ON b.name='Lady of the Glen' SET p.brand_id=b.id WHERE p.brand_id IS NULL AND p.sku LIKE '12-%'");
    $pdo->exec("UPDATE lager_products p JOIN lager_brands b ON b.name='Dalgety Bay' SET p.brand_id=b.id WHERE p.name LIKE 'Dalgety%'");
    $pdo->exec("UPDATE lager_products p JOIN lager_brands b ON b.name='St. Bridget Kirk' SET p.brand_id=b.id WHERE p.name LIKE 'St Bridget%' OR p.name LIKE 'St. Bridget%'");
    if(db_table_exists($pdo,'hsg_backup_runs')){
        $pdo->exec("ALTER TABLE hsg_backup_runs MODIFY status ENUM('running','success','warning','failed') NOT NULL DEFAULT 'running'");
    }
    // Default link permissions for existing and new users. Direct links remain non-administrative.
    if(db_table_exists($pdo,'hsg_user_module_access')){
        $defaults=[['dashboard',1,0],['inventory',1,0],['reservations',1,1],['catalog',1,0]];
        $users=$pdo->query('SELECT id FROM lager_users')->fetchAll(PDO::FETCH_COLUMN);
        $perm=$pdo->prepare('INSERT IGNORE INTO hsg_user_module_access(user_id,module_id,can_view,can_operate) VALUES(?,?,?,?)');
        foreach($users as $uid){foreach($defaults as $d)$perm->execute([(int)$uid,$d[0],$d[1],$d[2]]);}
    }
    if(db_table_exists($pdo,'hsg_settings')){
        // Keep existing OpenAI installations on OpenAI. New installations default
        // to Groq because its Free Plan can be used as the no-cost web-search fallback.
        $providerExisting=trim((string)setting_get($pdo,'image_ai_provider',''));
        $legacyOpenAI=trim((string)setting_get($pdo,'openai_api_key',''));
        $providerDefault=$providerExisting!==''?$providerExisting:($legacyOpenAI!==''?'openai':'groq');
        $modelDefault=$providerDefault==='openai'?'gpt-5.6-luna':'groq/compound-mini';
        $defaults=[
          'backup_enabled'=>'1','backup_full_weekly'=>'1','backup_full_weekday'=>'0',
          'backup_keep_data'=>'30','backup_keep_full'=>'12','onedrive_enabled'=>'0',
          'onedrive_folder'=>'HSG Administration/Backups','timezone'=>'Europe/Copenhagen',
          'image_ai_enabled'=>'0','image_ai_provider'=>$providerDefault,'image_ai_model'=>$modelDefault,'image_ai_min_confidence'=>'80',
          'image_validation_model'=>'qwen/qwen3.6-27b','product_data_ai_model'=>'llama-3.3-70b-versatile',
          'quality_required_fields'=>'[\"image\",\"brand_id\",\"distillery\",\"abv\",\"cask_type\",\"cask_number\",\"wholesale_price\",\"retail_price\"]'
        ];
        $st=$pdo->prepare('INSERT IGNORE INTO hsg_settings(setting_key,setting_value) VALUES(?,?)');
        foreach($defaults as $k=>$v)$st->execute([$k,$v]);
        $validationModel=trim((string)setting_get($pdo,'image_validation_model',''));
        if($validationModel==='' || str_contains($validationModel,'llama-4-scout')){
            setting_set($pdo,'image_validation_model','qwen/qwen3.6-27b');
        }
        // v1.3.2: keep free-plan Groq workloads on the models intended for each job.
        // Compound Mini has a much higher Free Plan TPM allowance for web-search
        // image discovery than GPT-OSS 120B, while product text extraction uses
        // the lighter text models and short outputs.
        $imageSearchModel=trim((string)setting_get($pdo,'image_ai_model',''));
        if((string)setting_get($pdo,'image_ai_provider','groq')==='groq' && !in_array($imageSearchModel,['groq/compound-mini','groq/compound'],true)){
            setting_set($pdo,'image_ai_model','groq/compound-mini');
        }
        $productTextModel=trim((string)setting_get($pdo,'product_data_ai_model',''));
        if($productTextModel==='' || str_starts_with($productTextModel,'openai/gpt-oss')){
            setting_set($pdo,'product_data_ai_model','llama-3.3-70b-versatile');
        }
        if((string)setting_get($pdo,'backup_cron_key','')==='') setting_set($pdo,'backup_cron_key',bin2hex(random_bytes(24)));
    }
    meta_set($pdo,'schema_version',(string)(require __DIR__.'/app_version.php'));
}
