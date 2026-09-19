<?php
declare(strict_types=1);

function h(mixed $value): string { return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8'); }
function base_url(): string { $https=!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off';$scheme=$https?'https':'http';$host=$_SERVER['HTTP_HOST']??'localhost';$dir=rtrim(str_replace('\\','/',dirname($_SERVER['SCRIPT_NAME']??'/')),'/');return $scheme.'://'.$host.($dir?:''); }
function redirect(string $url): never { header('Location: '.$url);exit; }
function csrf_token(): string { if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(24));return (string)$_SESSION['csrf']; }
function csrf_field(): string { return '<input type="hidden" name="csrf" value="'.h(csrf_token()).'">'; }
function verify_csrf(): void { if($_SERVER['REQUEST_METHOD']==='POST'){ $t=$_POST['csrf']??'';if(!is_string($t)||!hash_equals($_SESSION['csrf']??'',$t)){http_response_code(419);exit('Ugyldig formularsession. Genindlæs siden og prøv igen.');}} }
function flash(string $type,string $message): void { $_SESSION['flash'][]=[$type,$message]; }
function render_flash(): void {
    foreach($_SESSION['flash']??[] as [$t,$m]) {
        $role = in_array($t, ['danger','error','warning'], true) ? 'role="alert" aria-live="assertive"' : 'role="status" aria-live="polite"';
        echo '<div class="flash '.h($t).'" '.$role.'>'.h($m).'</div>';
    }
    unset($_SESSION['flash']);
}
function is_authenticated(): bool { return in_array($_SESSION['auth_mode']??'', ['link','admin'], true); }
function is_admin(): bool { return ($_SESSION['auth_mode']??'')==='admin' && !empty($_SESSION['admin_id']); }
function is_superadmin(): bool {
    if(!is_admin()) return false;
    if(isset($_SESSION['is_superadmin']) && $_SESSION['is_superadmin'] === true) return true;
    if(isset($GLOBALS['pdo']) && ($GLOBALS['pdo'] instanceof PDO)){
        $st=$GLOBALS['pdo']->prepare('SELECT is_superadmin FROM lager_admins WHERE id=?');
        $st->execute([current_admin_id()]);
        $val=$st->fetchColumn();
        if($val === false || (int)$val === 1) {
            $_SESSION['is_superadmin'] = true;
            return true;
        }
        $superCount = (int)$GLOBALS['pdo']->query('SELECT COUNT(*) FROM lager_admins WHERE is_superadmin=1')->fetchColumn();
        if($superCount === 0) {
            $GLOBALS['pdo']->exec('UPDATE lager_admins SET is_superadmin=1');
            $_SESSION['is_superadmin'] = true;
            return true;
        }
        $_SESSION['is_superadmin'] = (bool)$val;
        return $_SESSION['is_superadmin'];
    }
    return true;
}
function is_link_user(): bool { return ($_SESSION['auth_mode']??'')==='link' && !empty($_SESSION['user_id']); }
function current_link_user_id(): ?int { return is_link_user()?(int)$_SESSION['user_id']:null; }
function current_admin_id(): ?int { return is_admin()?(int)$_SESSION['admin_id']:null; }
function current_actor_name(): string { return is_admin()?(string)($_SESSION['admin_name']??'Administrator'):(string)($_SESSION['user_name']??''); }
function require_admin(): void { if(!is_admin()){http_response_code(403);exit('Denne funktion kræver administrator-login med brugernavn og adgangskode.');} }
function money_dkk(mixed $value): string { return ($value===null||$value==='')?'-':number_format((float)$value,2,',','.').' kr.'; }
function parse_decimal(mixed $value): ?float { $s=trim((string)$value);if($s==='')return null;$s=str_replace(['kr.','kr',' '],'',$s);if(str_contains($s,',')&&str_contains($s,'.'))$s=str_replace('.','',$s);$s=str_replace(',','.',$s);return is_numeric($s)?(float)$s:null; }
function product_status_label(string $s): string { return ['active'=>'Aktiv','inactive'=>'Inaktiv','discontinued'=>'Udgået'][$s]??$s; }
function reservation_status_label(string $s): string { return ['reserved'=>'Reserveret','completed'=>'Solgt/afsluttet','cancelled'=>'Annulleret'][$s]??$s; }
function app_version(): string { return (string)(require __DIR__.'/app_version.php'); }
function nav_active(string $file): string { return basename($_SERVER['SCRIPT_NAME']??'')===$file?'active':''; }
function product_image_url(?string $path): string { return ($path&&is_file(__DIR__.'/'.$path))?$path:'assets/bottle-placeholder.svg'; }
function actor_home_url(): string { if(is_admin()) return 'index.php'; if(function_exists('hsg_visible_modules')){ $mods=hsg_visible_modules(); $first=$mods?reset($mods):null; if(is_array($first)&&!empty($first['href'])) return (string)$first['href']; } return 'index.php'; }

function page_header(string $title): void {
  $user=current_actor_name();$admin=is_admin();
  $platformName='HSG Administration';
  if(isset($GLOBALS['pdo']) && function_exists('setting_get')) $platformName=setting_get($GLOBALS['pdo'],'platform_name','HSG Administration') ?: 'HSG Administration';
  echo '<!doctype html><html lang="da"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="theme-color" content="#ffffff"><title>'.h($title).' · HSG Whisky</title><link rel="stylesheet" href="assets/style.css?v='.rawurlencode(app_version()).'"></head><body>';
  echo '<a class="skip-link" href="#main-content">Spring til hovedindhold</a>';
  echo '<header class="top"><a class="brand" href="'.h(actor_home_url()).'"><span class="brandmark" aria-hidden="true">🥃</span><span><strong>HSG Whisky</strong><small>'.h($platformName).'</small></span></a>';
  echo '<div class="global-search-wrap"><input type="search" id="globalSearchInput" placeholder="🔍 Søg produkt, SKU, brand, ref... (Ctrl+K)" aria-label="Global søgning" autocomplete="off"><div id="globalSearchResults" class="global-search-dropdown" hidden></div></div>';
  echo '<div class="top-actions">';
  if(!$admin && (!function_exists('hsg_module_is_enabled') || hsg_module_is_enabled('catalog')) && can('catalog.view')) echo '<a class="catalog-top" href="catalog.php">Katalog</a>';
  if($admin) echo '<span class="access-badge admin">Admin</span><a class="admin-link" href="admin-account.php">'.h($user).'</a>';
  else echo '<span class="access-badge readonly">Personligt link</span><span class="user">'.h($user).'</span><a class="admin-link" href="admin-login.php">Admin-login</a>';
  echo '</div></header>';
  echo '<aside class="sidebar" aria-label="Hovednavigation"><nav>';
  if($admin){
    $allNav=[
      'dashboard'=>['index.php','⌂','Overblik'],
      'stock'=>['status.php','▦','Lager'],
      'products'=>['products.php','◇','Produkter'],
      'catalog'=>['catalog.php','▤','Katalog'],
      'reports'=>['reports.php','📊','Rapporter'],
      'supplier_upload'=>['import_center.php','⇅','Import / Upload'],
      'system'=>['admin.php','⚙','Administration']
    ];
    $current=basename($_SERVER['SCRIPT_NAME']??'');
    $adminFiles=['admin.php','brands.php','locations.php','image_check.php','quality.php','users.php','user_permissions.php','backup.php','update.php','system.php','admin-account.php','stock.php'];
    $importFiles=['import_center.php','import.php','supplier_upload.php','export.php'];
    foreach($allNav as $mId=>[$href,$icon,$name]){
      if(!is_superadmin() && function_exists('hsg_admin_can_view_module') && !hsg_admin_can_view_module($mId)) continue;
      $active=($current===basename($href)) || ($href==='status.php' && $current==='reservations.php') || ($href==='admin.php' && in_array($current,$adminFiles,true)) || ($href==='import_center.php' && in_array($current,$importFiles,true));
      echo '<a class="'.($active?'active':'').'" '.($active?'aria-current="page"':'').' href="'.h($href).'">'.h($icon).' <span>'.h($name).'</span></a>';
    }
  } elseif(function_exists('hsg_visible_modules')){
    foreach(hsg_visible_modules() as $module){
      $href=(string)($module['href']??'#');$icon=(string)($module['icon']??'•');$name=(string)($module['name']??$module['id']);
      $act=nav_active(basename($href));
      echo '<a class="'.h($act).'" '.($act==='active'?'aria-current="page"':'').' href="'.h($href).'">'.h($icon).' <span>'.h($name).'</span></a>';
    }
  } else {
    echo '<a href="status.php">▦ <span>Lagerstatus</span></a><a href="reservations.php">▣ <span>Reservationer</span></a><a href="catalog.php">▤ <span>Katalog</span></a>';
  }
  echo '<a href="logout.php">↪ <span>Afslut</span></a></nav></aside><main class="content" id="main-content"><div class="page-title"><h1>'.h($title).'</h1></div>';render_flash();
  if(!$admin) echo '<div class="readonly-note"><strong>Linkadgang:</strong> Du ser kun de moduler, administrator har tildelt dig. Kritiske ændringer af lager, produkter, priser og opsætning kræver administrator-login.</div>';
}
function page_footer(): void {
  $current=basename($_SERVER['SCRIPT_NAME']??'');
  echo '</main><nav class="mobile-nav" aria-label="Mobilnavigation">';
  if(is_admin()){
    if(can('inventory.view')) echo '<a href="status.php" '.($current==='status.php'?'aria-current="page"':'').'>▦<span>Lager</span></a>';
    if(can('reservations.view')) echo '<a href="reservations.php" '.($current==='reservations.php'?'aria-current="page"':'').'>▣<span>Reservér</span></a>';
    if((!function_exists('hsg_module_is_enabled') || hsg_module_is_enabled('catalog')) && can('catalog.view')) echo '<a href="catalog.php" '.($current==='catalog.php'?'aria-current="page"':'').'>▤<span>Katalog</span></a>';
    echo '<a href="admin.php" '.($current==='admin.php'?'aria-current="page"':'').'>•••<span>Mere</span></a>';
  }else{
    if(can('inventory.view')) echo '<a href="status.php" '.($current==='status.php'?'aria-current="page"':'').'>▦<span>Lager</span></a>';
    if(can('reservations.view')) echo '<a href="reservations.php" '.($current==='reservations.php'?'aria-current="page"':'').'>▣<span>Reservér</span></a>';
    if((!function_exists('hsg_module_is_enabled') || hsg_module_is_enabled('catalog')) && can('catalog.view')) echo '<a href="catalog.php" '.($current==='catalog.php'?'aria-current="page"':'').'>▤<span>Katalog</span></a>';
    if(can('dashboard.view')) echo '<a href="index.php" '.($current==='index.php'?'aria-current="page"':'').'>⌂<span>Overblik</span></a>';
  }
  echo '</nav><footer>HSG Whisky · Administration '.h(app_version()).'</footer>';
  echo '<script>
  (function(){
    // Double submit protection
    document.addEventListener("submit", function(e){
      var form = e.target;
      if(!form || form.dataset.noDisable) return;
      var btn = form.querySelector("button[type=\'submit\'], input[type=\'submit\']");
      if(btn && !btn.disabled){
        setTimeout(function(){
          btn.disabled = true;
          if(btn.tagName === "BUTTON"){
            var origText = btn.textContent;
            if(!btn.dataset.origText) btn.dataset.origText = origText;
            if(origText.indexOf("Gem") !== -1) btn.textContent = "Gemmer...";
            else if(origText.indexOf("Opret") !== -1) btn.textContent = "Opretter...";
            else if(origText.indexOf("Slet") !== -1) btn.textContent = "Sletter...";
            else if(origText.indexOf("Opdatér") !== -1 || origText.indexOf("Opdater") !== -1) btn.textContent = "Opdaterer...";
            else if(origText.indexOf("Synkroniser") !== -1) btn.textContent = "Synkroniserer...";
            else btn.textContent = "Arbejder...";
          }
        }, 0);
      }
    });

    // Global Quick Search
    var inp = document.getElementById("globalSearchInput");
    var box = document.getElementById("globalSearchResults");
    if(!inp || !box) return;

    var timer = null;
    var selectedIndex = -1;

    function clearResults(){
      box.hidden = true;
      box.textContent = "";
      selectedIndex = -1;
    }

    inp.addEventListener("input", function(){
      clearTimeout(timer);
      var q = inp.value.trim();
      if(q.length < 2) { clearResults(); return; }
      timer = setTimeout(function(){
        fetch("core/bootstrap.php?action=quick_search&q=" + encodeURIComponent(q))
          .then(function(res){ return res.json(); })
          .then(function(data){
            box.textContent = "";
            selectedIndex = -1;
            if(!data.results || !data.results.length) {
              var empty = document.createElement("div");
              empty.className = "global-search-item muted";
              empty.textContent = "Ingen resultater fundet";
              box.appendChild(empty);
              box.hidden = false;
              return;
            }
            data.results.forEach(function(r){
              var a = document.createElement("a");
              a.className = "global-search-item";
              a.href = r.url;

              var badge = document.createElement("span");
              badge.className = "badge blue";
              badge.textContent = r.type;

              var title = document.createElement("strong");
              title.textContent = r.title;

              var sub = document.createElement("small");
              sub.textContent = r.subtitle;

              a.appendChild(badge);
              a.appendChild(document.createTextNode(" "));
              a.appendChild(title);
              a.appendChild(sub);
              box.appendChild(a);
            });
            box.hidden = false;
          }).catch(function(){ clearResults(); });
      }, 200);
    });

    // Keyboard navigation & shortcuts
    document.addEventListener("keydown", function(e){
      var activeTag = document.activeElement ? document.activeElement.tagName.toLowerCase() : "";
      var isEditing = activeTag === "input" || activeTag === "textarea" || activeTag === "select";

      if((e.ctrlKey && e.key.toLowerCase() === "k") || (e.key === "/" && !isEditing)){
        e.preventDefault();
        inp.focus();
        inp.select();
        return;
      }

      if(e.key === "Escape"){
        if(!box.hidden || document.activeElement === inp){
          clearResults();
          inp.blur();
        }
        return;
      }

      if(!box.hidden && document.activeElement === inp){
        var items = box.querySelectorAll("a.global-search-item");
        if(!items.length) return;

        if(e.key === "ArrowDown"){
          e.preventDefault();
          selectedIndex = (selectedIndex + 1) % items.length;
          items.forEach(function(it, idx){ it.classList.toggle("active", idx === selectedIndex); });
        } else if(e.key === "ArrowUp"){
          e.preventDefault();
          selectedIndex = (selectedIndex - 1 + items.length) % items.length;
          items.forEach(function(it, idx){ it.classList.toggle("active", idx === selectedIndex); });
        } else if(e.key === "Enter" && selectedIndex >= 0 && items[selectedIndex]){
          e.preventDefault();
          items[selectedIndex].click();
        }
      }
    });

    document.addEventListener("click", function(e){
      if(!inp.contains(e.target) && !box.contains(e.target)) clearResults();
    });
  })();
  </script></body></html>';
}

function create_reservation(PDO $pdo,int $pid,int $lid,int $qty,string $customer,string $ref,string $note,?int $userId,?int $adminId=null): void {
  if($qty<=0)throw new RuntimeException('Antal skal være større end 0.');$pdo->beginTransaction();
  try{$st=$pdo->prepare('SELECT quantity FROM lager_stock WHERE product_id=? AND location_id=? FOR UPDATE');$st->execute([$pid,$lid]);$physical=$st->fetchColumn();if($physical===false)throw new RuntimeException('Produktet findes ikke på den valgte lokation.');$rs=$pdo->prepare("SELECT COALESCE(SUM(quantity),0) FROM lager_reservations WHERE product_id=? AND location_id=? AND status='reserved'");$rs->execute([$pid,$lid]);$reserved=(int)$rs->fetchColumn();if(((int)$physical-$reserved)<$qty)throw new RuntimeException('Der er ikke nok disponibelt lager.');$pdo->prepare("INSERT INTO lager_reservations(product_id,location_id,quantity,customer_name,reference,note,status,created_by,created_by_admin) VALUES(?,?,?,?,?,?,'reserved',?,?)")->execute([$pid,$lid,$qty,$customer,$ref,$note,$userId,$adminId]);$reservationId=(int)$pdo->lastInsertId();$pdo->commit();if(function_exists('audit_log'))audit_log($pdo,'reservation.create','reservation',(string)$reservationId,['product_id'=>$pid,'location_id'=>$lid,'quantity'=>$qty,'customer'=>$customer,'reference'=>$ref]);if(function_exists('hsg_do_action'))hsg_do_action('reservation.created',['reservation_id'=>$reservationId,'product_id'=>$pid,'location_id'=>$lid,'quantity'=>$qty,'customer'=>$customer,'reference'=>$ref]);}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function canonical_location_name(string $name): string { $n=trim($name);$key=strtolower(str_replace([' ','-','_'], '', strtr($n,['æ'=>'ae','ø'=>'o','å'=>'a'])));if(in_array($key,['lagergert','gertlager'],true))return 'Gert Lager';if($key==='hovedlager')return 'Hovedlager';return $n; }
function get_locations(PDO $pdo,bool $activeOnly=true): array { return $pdo->query('SELECT * FROM lager_locations '.($activeOnly?'WHERE active=1 ':'').'ORDER BY sort_order,name')->fetchAll(); }
function available_for(PDO $pdo,int $pid,int $lid): int { $st=$pdo->prepare("SELECT COALESCE(s.quantity,0)-COALESCE(r.qty,0) FROM lager_stock s LEFT JOIN (SELECT product_id,location_id,SUM(quantity) qty FROM lager_reservations WHERE status='reserved' GROUP BY product_id,location_id) r ON r.product_id=s.product_id AND r.location_id=s.location_id WHERE s.product_id=? AND s.location_id=?");$st->execute([$pid,$lid]);$v=$st->fetchColumn();return $v===false?0:(int)$v; }
function total_available_for(PDO $pdo,int $pid): int { $st=$pdo->prepare("SELECT COALESCE((SELECT SUM(quantity) FROM lager_stock WHERE product_id=?),0)-COALESCE((SELECT SUM(quantity) FROM lager_reservations WHERE product_id=? AND status='reserved'),0)");$st->execute([$pid,$pid]);return (int)$st->fetchColumn(); }

function hsg_sync_product_stock_status(PDO $pdo, int $productId): void {
    if($productId <= 0) return;
    $avail = total_available_for($pdo, $productId);
    if($avail <= 0) {
        $pdo->prepare("UPDATE lager_products SET status='inactive' WHERE id=? AND status='active'")->execute([$productId]);
    } else {
        $pdo->prepare("UPDATE lager_products SET status='active' WHERE id=? AND status='inactive'")->execute([$productId]);
    }
}
