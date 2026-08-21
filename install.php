<?php
declare(strict_types=1);
require_once __DIR__.'/session.php';
require_once __DIR__.'/functions.php';
require_once __DIR__.'/core/access_links.php';
require_once __DIR__.'/core/product_enrichment.php';
require_once __DIR__.'/core/catalog_image_seed.php';
if(file_exists(__DIR__.'/config.php')){echo '<!doctype html><html lang="da"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="assets/style.css"><title>Installeret</title></head><body><main class="public"><div class="card narrow"><h1>Systemet er allerede installeret</h1><p>Af sikkerhedshensyn kan installationen ikke køres igen, mens <code>config.php</code> findes.</p><a class="button" href="admin-login.php">Administrator-login</a></div></main></body></html>';exit;}
$error='';$newLink='';$installed=false;
if($_SERVER['REQUEST_METHOD']==='POST'){
  verify_csrf();
  $host=trim($_POST['db_host']??'localhost');$name=trim($_POST['db_name']??'');$user=trim($_POST['db_user']??'');$pass=(string)($_POST['db_pass']??'');
  $admin=trim($_POST['admin_name']??'Administrator');$adminUsername=trim($_POST['admin_username']??'admin');$adminPassword=(string)($_POST['admin_password']??'');$adminConfirm=(string)($_POST['admin_password_confirm']??'');$seed=!empty($_POST['seed_stock']);
  try{
    if($name===''||$user===''||$admin===''||strlen($adminUsername)<3)throw new RuntimeException('Database, databasebruger, administratornavn og admin-brugernavn skal udfyldes.');
    if(strlen($adminPassword)<12)throw new RuntimeException('Admin-adgangskoden skal være mindst 12 tegn.');
    if(!hash_equals($adminPassword,$adminConfirm))throw new RuntimeException('De to admin-adgangskoder er ikke ens.');
    $pdo=new PDO('mysql:host='.$host.';dbname='.$name.';charset=utf8mb4',$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
    $schema=file_get_contents(__DIR__.'/schema.sql');if($schema===false)throw new RuntimeException('schema.sql mangler.');
    foreach(preg_split('/;\s*(?:\r?\n|$)/',$schema)?:[] as $sql){$sql=trim($sql);if($sql!=='')$pdo->exec($sql);}

    $pdo->prepare('INSERT INTO lager_admins(username,display_name,password_hash,active) VALUES(?,?,?,1)')->execute([$adminUsername,$admin,password_hash($adminPassword,PASSWORD_DEFAULT)]);
    $raw=bin2hex(random_bytes(32));$hash=hash('sha256',$raw);
    $pdo->prepare("INSERT INTO lager_users(name,role,token_hash,token_last4,token_cipher,active) VALUES(?,'user',?,?,?,1)")->execute(['Lagerlink',$hash,substr($raw,-4),hsg_access_link_encrypt($raw)]);
    $linkUserId=(int)$pdo->lastInsertId();
    $perm=$pdo->prepare('INSERT INTO hsg_user_module_access(user_id,module_id,can_view,can_operate) VALUES(?,?,?,?)');
    foreach([['dashboard',1,0],['inventory',1,0],['reservations',1,1],['catalog',1,0]] as $d)$perm->execute([$linkUserId,$d[0],$d[1],$d[2]]);
    $setting=$pdo->prepare('INSERT INTO hsg_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
    foreach(['backup_enabled'=>'1','backup_full_weekly'=>'1','backup_full_weekday'=>'0','backup_keep_data'=>'30','backup_keep_full'=>'12','onedrive_enabled'=>'0','onedrive_folder'=>'HSG Administration/Backups','backup_cron_key'=>bin2hex(random_bytes(24)),'timezone'=>'Europe/Copenhagen'] as $k=>$v)$setting->execute([$k,$v]);

    foreach([['Hovedlager','Primært lager',10],['Gert Lager','Gert lager',20]] as $l)$pdo->prepare('INSERT IGNORE INTO lager_locations(name,description,active,sort_order) VALUES(?,?,1,?)')->execute($l);
    $brands=[
      ['Woodrow\'s of Edinburgh','Woodrow’s of Edinburgh er en uafhængig aftapper og fadhandler. Når de modtager fade, udvælger de de særlige fade, som er for gode til at sende videre, og aftapper dem blandt andet under Warehouse Reserve.','https://woodrowswhisky.com',10],
      ['Fragrant Drops','Fragrant Drops er en lille uafhængig aftapper drevet af George og Rachel med fokus på unikke aftapninger og et særpræget flaskedesign inspireret af 1920’erne.',null,20],
      ['Edinburgh Whisky','Edinburgh Whisky laver en serie af single malts med fokus på god kvalitet til fornuftige priser og et markant, mørkt flaskedesign.',null,30],
      ['Lady of the Glen','Lady of the Glen er en uafhængig aftapper med fokus på enkeltfade, høj styrke og begrænsede aftapninger. Sortimentet omfatter også serier som St Bridget’s Kirk og Dalgety Bay.','https://www.ladyoftheglen.com',40],
      ['Samhain Series','Samhain Series er en særserie af begrænsede aftapninger.',null,45],
      ['Dalgety Bay','Dalgety Bay er en serie af whisky aftappet med fokus på klassiske destillerikarakterer og fadmodning.',null,50],
      ['St. Bridget Kirk','St. Bridget Kirk er en serie med whiskyblends og særlige aftapninger.',null,55],
      ['Uncharted Whisky','Uncharted Whisky drives af Jack Breslin og Dana Devos fra Fintry nær Glasgow. De udvælger kvalitetsfade og aftapper ofte ved høj styrke med navne inspireret af musik.','https://unchartedwhisky.com',60],
      ['Nyborg Destilleri','Nyborg Destilleri er et dansk økologisk destilleri i Nyborg. De producerer blandt andet whisky under navnet Isle of Fionia samt gin, rom, bitter, kaffelikør og akvavit.','https://nyborgdestilleri.com',70]
    ];
    $bst=$pdo->prepare('INSERT IGNORE INTO lager_brands(name,description,website_url,active,sort_order) VALUES(?,?,?,1,?)');foreach($brands as $b)$bst->execute($b);
    if($seed && is_file(__DIR__.'/initial-stock.json')){
      $data=json_decode((string)file_get_contents(__DIR__.'/initial-stock.json'),true);$locStmt=$pdo->prepare('SELECT id FROM lager_locations WHERE name=?');$prodStmt=$pdo->prepare("INSERT INTO lager_products(sku,name,cask_number,status,show_in_catalog) VALUES(?,?,?,'active',1) ON DUPLICATE KEY UPDATE name=VALUES(name),cask_number=COALESCE(NULLIF(cask_number,''),VALUES(cask_number))");$prodId=$pdo->prepare('SELECT id FROM lager_products WHERE sku=?');$stock=$pdo->prepare('INSERT INTO lager_stock(product_id,location_id,quantity) VALUES(?,?,?) ON DUPLICATE KEY UPDATE quantity=VALUES(quantity)');
      foreach(($data['products']??[]) as $p){$locations=(array)($p['locations']??[]);$negative=false;foreach($locations as $qty){if((int)$qty<0){$negative=true;break;}}if($negative)continue;$parsed=hsg_product_parse_text((string)$p['name']);$prodStmt->execute([$p['sku'],$p['name'],$parsed['fields']['cask_number']??null]);$prodId->execute([$p['sku']]);$pid=(int)$prodId->fetchColumn();foreach($locations as $loc=>$qty){$loc=canonical_location_name((string)$loc);$locStmt->execute([$loc]);$lid=(int)$locStmt->fetchColumn();if(!$lid){$pdo->prepare('INSERT INTO lager_locations(name,active) VALUES(?,1)')->execute([$loc]);$lid=(int)$pdo->lastInsertId();}$stock->execute([$pid,$lid,(int)$qty]);}}
      $pdo->exec("UPDATE lager_products p JOIN lager_brands b ON b.name='Woodrow''s of Edinburgh' SET p.brand_id=b.id WHERE p.sku LIKE '17-%'");
      $pdo->exec("UPDATE lager_products p JOIN lager_brands b ON b.name='Fragrant Drops' SET p.brand_id=b.id WHERE p.sku LIKE '18-%'");
      $pdo->exec("UPDATE lager_products p JOIN lager_brands b ON b.name='Edinburgh Whisky' SET p.brand_id=b.id WHERE p.sku LIKE '19-%'");
      $pdo->exec("UPDATE lager_products p JOIN lager_brands b ON b.name='Uncharted Whisky' SET p.brand_id=b.id WHERE p.sku LIKE '13-%'");
      $pdo->exec("UPDATE lager_products p JOIN lager_brands b ON b.name='Nyborg Destilleri' SET p.brand_id=b.id WHERE p.sku LIKE '14-%'");
      $pdo->exec("UPDATE lager_products p JOIN lager_brands b ON b.name='Lady of the Glen' SET p.brand_id=b.id WHERE p.sku LIKE '12-%'");
      $pdo->exec("UPDATE lager_products p JOIN lager_brands b ON b.name='Dalgety Bay' SET p.brand_id=b.id WHERE p.name LIKE 'Dalgety%'");
      $pdo->exec("UPDATE lager_products p JOIN lager_brands b ON b.name='St. Bridget Kirk' SET p.brand_id=b.id WHERE p.name LIKE 'St Bridget%' OR p.name LIKE 'St. Bridget%'");
    }
    hsg_import_catalog_seed_images($pdo,false);
    $pdo->prepare("INSERT INTO lager_meta(meta_key,meta_value) VALUES('schema_version',?) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)")->execute([app_version()]);
    $cfg="<?php\nreturn ".var_export(['db_host'=>$host,'db_name'=>$name,'db_user'=>$user,'db_pass'=>$pass],true).";\n";if(file_put_contents(__DIR__.'/config.php',$cfg)===false)throw new RuntimeException('Kunne ikke skrive config.php. Giv PHP skriveadgang til mappen under installationen.');
    $newLink=base_url().'/?k='.$raw;$installed=true;
  }catch(Throwable $e){$error=$e->getMessage();}
}
?><!doctype html><html lang="da"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="assets/style.css"><title>Installer HSG Administration</title></head><body><main class="public"><div class="card narrow"><h1>Installer HSG Administration</h1><?php if($error):?><div class="flash error"><?=h($error)?></div><?php endif;?><?php if($installed):?><div class="flash success"><strong>Installationen er færdig.</strong></div><h2>Administrator</h2><p>Administrator logger fremover ind med brugernavn og adgangskode:</p><p><a class="button" href="admin-login.php">Åbn administrator-login</a></p><h2>Direkte lagerlink</h2><p>Dette link er <strong>read-only</strong> bortset fra reservationer. Linket kan også ses senere af administrator under Brugere & adgang:</p><input readonly value="<?=h($newLink)?>" onclick="this.select()"><p><a class="button secondary" href="<?=h($newLink)?>">Test lagerlink</a></p><p class="muted">Slet eller omdøb gerne <code>install.php</code> efter installationen.</p><?php else:?><form method="post"><?=csrf_field()?><label>MySQL host<input name="db_host" value="localhost" required></label><label>Databasenavn<input name="db_name" required></label><label>Databasebruger<input name="db_user" required></label><label>Databasekodeord<input type="password" name="db_pass"></label><hr><h2>Sikker administrator</h2><label>Administratornavn<input name="admin_name" value="Administrator" required></label><label>Admin-brugernavn<input name="admin_username" value="admin" autocomplete="username" required></label><label>Admin-adgangskode <span class="muted">(mindst 12 tegn)</span><input type="password" name="admin_password" autocomplete="new-password" required></label><label>Gentag admin-adgangskode<input type="password" name="admin_password_confirm" autocomplete="new-password" required></label><label class="check"><input type="checkbox" name="seed_stock" value="1" checked> Indlæs Lager HSG.xlsx som startlager (181 produkter)</label><button>Installer systemet</button></form><?php endif;?></div></main></body></html>
