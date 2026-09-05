<?php
declare(strict_types=1);

require __DIR__ . '/auth.php';
require_module_enabled('reports');
require_capability('reports.view');
require_once __DIR__ . '/core/woocommerce_sync.php';

$tab = (string)($_GET['tab'] ?? 'dashboard');
if (!in_array($tab, ['dashboard', 'builder', 'saved', 'settings'], true)) {
    $tab = 'dashboard';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save_settings') {
        require_capability('reports.manage');
        verify_csrf();
        setting_set($pdo, 'woocommerce_shop_url', trim((string)($_POST['woocommerce_shop_url'] ?? '')));
        setting_set($pdo, 'woocommerce_consumer_key', trim((string)($_POST['woocommerce_consumer_key'] ?? '')));
        setting_set($pdo, 'woocommerce_consumer_secret', trim((string)($_POST['woocommerce_consumer_secret'] ?? '')));
        flash('success', 'WooCommerce-indstillinger er gemt.');
        redirect('reports.php?tab=settings');
    }

    if ($action === 'sync_wc') {
        require_capability('reports.manage');
        verify_csrf();
        try {
            $res = hsg_wc_sync_orders_api($pdo, 5);
            flash('success', "Synkronisering gennemført! {$res['orders']} ordrer og {$res['items']} varer blev opdateret.");
        } catch (Throwable $e) {
            flash('error', 'Synkroniseringsfejl: ' . $e->getMessage());
        }
        redirect('reports.php?tab=' . $tab);
    }

    if ($action === 'save_report_template') {
        require_capability('reports.manage');
        verify_csrf();
        $name = trim((string)($_POST['template_name'] ?? 'Min rapport'));
        $desc = trim((string)($_POST['template_desc'] ?? ''));
        $filtersJson = json_encode((array)($_POST['filters'] ?? []), JSON_UNESCAPED_SLASHES);
        if ($name === '') {
            flash('error', 'Angiv et navn til rapportskabelonen.');
        } else {
            $st = $pdo->prepare('INSERT INTO hsg_saved_reports (name, description, filters_json, created_by_admin) VALUES (?, ?, ?, ?)');
            $st->execute([$name, $desc, $filtersJson, current_admin_id()]);
            flash('success', 'Rapportskabelon gemt.');
        }
        redirect('reports.php?tab=saved');
    }

    if ($action === 'delete_report_template') {
        require_capability('reports.manage');
        verify_csrf();
        $tplId = (int)($_POST['template_id'] ?? 0);
        if ($tplId > 0) {
            $st = $pdo->prepare('DELETE FROM hsg_saved_reports WHERE id=?');
            $st->execute([$tplId]);
            flash('success', 'Rapportskabelon slettet.');
        }
        redirect('reports.php?tab=saved');
    }
}

// Global Filter Parameters
$period = (string)($_GET['period'] ?? '30days');
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));
$status = trim((string)($_GET['status'] ?? 'completed'));
$brand = trim((string)($_GET['brand'] ?? ''));
$distillery = trim((string)($_GET['distillery'] ?? ''));
$country = trim((string)($_GET['country'] ?? ''));
$sku = trim((string)($_GET['sku'] ?? ''));

// Calculate Date Range based on Period
$now = new DateTime('now', new DateTimeZone('Europe/Copenhagen'));
if ($period === 'today') {
    $dFrom = $now->format('Y-m-d 00:00:00');
    $dTo = $now->format('Y-m-d 23:59:59');
} elseif ($period === '7days') {
    $dFrom = (clone $now)->modify('-7 days')->format('Y-m-d 00:00:00');
    $dTo = $now->format('Y-m-d 23:59:59');
} elseif ($period === 'this_month') {
    $dFrom = $now->format('Y-m-01 00:00:00');
    $dTo = $now->format('Y-m-t 23:59:59');
} elseif ($period === 'last_month') {
    $dFrom = (clone $now)->modify('first day of last month')->format('Y-m-d 00:00:00');
    $dTo = (clone $now)->modify('last day of last month')->format('Y-m-d 23:59:59');
} elseif ($period === 'this_year') {
    $dFrom = $now->format('Y-01-01 00:00:00');
    $dTo = $now->format('Y-12-31 23:59:59');
} elseif ($period === 'custom' && $dateFrom !== '' && $dateTo !== '') {
    $dFrom = date('Y-m-d 00:00:00', strtotime($dateFrom));
    $dTo = date('Y-m-d 23:59:59', strtotime($dateTo));
} else { // Default 30 days
    $period = '30days';
    $dFrom = (clone $now)->modify('-30 days')->format('Y-m-d 00:00:00');
    $dTo = $now->format('Y-m-d 23:59:59');
}

// Build SQL conditions
$orderConds = ["date_created >= ? AND date_created <= ?"];
$orderParams = [$dFrom, $dTo];

if ($status !== 'all' && $status !== '') {
    $orderConds[] = "status = ?";
    $orderParams[] = $status;
}
if ($country !== '') {
    $orderConds[] = "customer_country = ?";
    $orderParams[] = $country;
}

$orderWhereSql = implode(' AND ', $orderConds);

// Query Orders Metrics
$sqlOrderKpi = "SELECT
    COUNT(*) AS total_orders,
    COALESCE(SUM(total_amount), 0) AS gross_revenue,
    COALESCE(AVG(total_amount), 0) AS avg_order_value,
    COALESCE(SUM(shipping_total), 0) AS total_shipping,
    COALESCE(SUM(discount_total), 0) AS total_discounts
    FROM hsg_woocommerce_orders WHERE {$orderWhereSql}";

$stKpi = $pdo->prepare($sqlOrderKpi);
$stKpi->execute($orderParams);
$kpi = $stKpi->fetch(PDO::FETCH_ASSOC) ?: [
    'total_orders' => 0, 'gross_revenue' => 0, 'avg_order_value' => 0, 'total_shipping' => 0, 'discount_total' => 0
];

// Query Line Items Metrics
$itemConds = ["i.date_created >= ? AND i.date_created <= ?"];
$itemParams = [$dFrom, $dTo];

if ($status !== 'all' && $status !== '') {
    $itemConds[] = "o.status = ?";
    $itemParams[] = $status;
}
if ($brand !== '') {
    $itemConds[] = "i.brand_name = ?";
    $itemParams[] = $brand;
}
if ($distillery !== '') {
    $itemConds[] = "i.distillery = ?";
    $itemParams[] = $distillery;
}
if ($sku !== '') {
    $itemConds[] = "(i.sku LIKE ? OR i.product_name LIKE ?)";
    $itemParams[] = '%' . $sku . '%';
    $itemParams[] = '%' . $sku . '%';
}

$itemWhereSql = implode(' AND ', $itemConds);

$sqlItemsKpi = "SELECT COALESCE(SUM(i.quantity), 0) AS total_items_sold
    FROM hsg_woocommerce_order_items i
    LEFT JOIN hsg_woocommerce_orders o ON o.wc_order_id = i.wc_order_id
    WHERE {$itemWhereSql}";

$stItemKpi = $pdo->prepare($sqlItemsKpi);
$stItemKpi->execute($itemParams);
$totalItemsSold = (int)$stItemKpi->fetchColumn();

// Export to CSV if requested
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=WooCommerce_Salgsrapport_' . date('Y-m-d') . '.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Ordre #', 'Dato', 'Kunde', 'E-mail', 'Land', 'Status', 'Metode', 'Omsætning (DKK)', 'Fragtrate', 'Rabat']);

    $sqlExport = "SELECT order_number, date_created, customer_name, customer_email, customer_country, status, payment_method, total_amount, shipping_total, discount_total FROM hsg_woocommerce_orders WHERE {$orderWhereSql} ORDER BY date_created DESC";
    $stExp = $pdo->prepare($sqlExport);
    $stExp->execute($orderParams);
    foreach ($stExp->fetchAll(PDO::FETCH_ASSOC) as $row) {
        fputcsv($out, [
            $row['order_number'], $row['date_created'], $row['customer_name'],
            $row['customer_email'], $row['customer_country'], $row['status'],
            $row['payment_method'], number_format((float)$row['total_amount'], 2, ',', ''),
            number_format((float)$row['shipping_total'], 2, ',', ''),
            number_format((float)$row['discount_total'], 2, ',', '')
        ]);
    }
    fclose($out);
    exit;
}

// Fetch lists for filter dropdowns
$brandsList = $pdo->query("SELECT DISTINCT brand_name FROM hsg_woocommerce_order_items WHERE brand_name IS NOT NULL AND brand_name<>'' ORDER BY brand_name")->fetchAll(PDO::FETCH_COLUMN);
$distilleriesList = $pdo->query("SELECT DISTINCT distillery FROM hsg_woocommerce_order_items WHERE distillery IS NOT NULL AND distillery<>'' ORDER BY distillery")->fetchAll(PDO::FETCH_COLUMN);
$countriesList = $pdo->query("SELECT DISTINCT customer_country FROM hsg_woocommerce_orders WHERE customer_country IS NOT NULL AND customer_country<>'' ORDER BY customer_country")->fetchAll(PDO::FETCH_COLUMN);

page_header('Rapporter & WooCommerce');
?>

<div class="simple-tabs" style="margin-bottom:1rem; flex-wrap:wrap; gap:6px;">
  <a class="button <?=$tab==='dashboard'?'':'secondary'?>" href="reports.php?tab=dashboard">📊 Mobil-Dashboard</a>
  <a class="button <?=$tab==='builder'?'':'secondary'?>" href="reports.php?tab=builder">🔍 Rapportbygger</a>
  <a class="button <?=$tab==='saved'?'':'secondary'?>" href="reports.php?tab=saved">⭐ Gemte Rapporter</a>
  <a class="button <?=$tab==='settings'?'':'secondary'?>" href="reports.php?tab=settings">⚙️ WooCommerce Indstillinger</a>
</div>

<?php if ($tab === 'dashboard'): ?>

  <div class="card" style="padding:12px; margin-bottom:12px; border-left:4px solid var(--accent-color,#1e40af);">
    <form method="get" style="display:flex; flex-wrap:wrap; gap:8px; align-items:center;">
      <input type="hidden" name="tab" value="dashboard">
      <strong>Periode:</strong>
      <select name="period" onchange="this.form.submit()" style="padding:4px 8px; font-weight:600;">
        <option value="today" <?=$period==='today'?'selected':''?>>I dag</option>
        <option value="7days" <?=$period==='7days'?'selected':''?>>Seneste 7 dage</option>
        <option value="30days" <?=$period==='30days'?'selected':''?>>Seneste 30 dage</option>
        <option value="this_month" <?=$period==='this_month'?'selected':''?>>Denne måned</option>
        <option value="last_month" <?=$period==='last_month'?'selected':''?>>Sidste måned</option>
        <option value="this_year" <?=$period==='this_year'?'selected':''?>>I år (<?=date('Y')?>)</option>
        <option value="custom" <?=$period==='custom'?'selected':''?>>Brugerdefineret dato</option>
      </select>

      <?php if ($period === 'custom'): ?>
        <input type="date" name="date_from" value="<?=h(substr($dFrom,0,10))?>" required style="padding:4px 6px;">
        <span>til</span>
        <input type="date" name="date_to" value="<?=h(substr($dTo,0,10))?>" required style="padding:4px 6px;">
        <button class="button small">Opdatér</button>
      <?php endif; ?>

      <div style="margin-left:auto; display:flex; gap:6px;">
        <?php if (can('reports.manage')): ?>
          <form method="post" style="margin:0;"><?=csrf_field()?><input type="hidden" name="action" value="sync_wc"><button class="button secondary small">🔄 Synkronisér WooCommerce</button></form>
        <?php endif; ?>
        <a class="button secondary small" href="reports.php?<?=http_build_query(array_merge($_GET, ['export'=>'csv']))?>">📥 Eksportér CSV</a>
      </div>
    </form>
  </div>

  <!-- Mobile-Friendly KPI Metrics Grid -->
  <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap:10px; margin-bottom:12px;">
    <div class="card metric" style="text-align:center; padding:12px; background:var(--bg-card,#ffffff);">
      <small class="muted">Samlet Omsætning</small>
      <strong style="font-size:1.4rem; color:#059669; display:block; margin-top:4px;"><?=number_format((float)$kpi['gross_revenue'], 2, ',', '.')?> kr</strong>
    </div>
    <div class="card metric" style="text-align:center; padding:12px; background:var(--bg-card,#ffffff);">
      <small class="muted">Antal Ordrer</small>
      <strong style="font-size:1.4rem; color:#2563eb; display:block; margin-top:4px;"><?=intval($kpi['total_orders'])?></strong>
    </div>
    <div class="card metric" style="text-align:center; padding:12px; background:var(--bg-card,#ffffff);">
      <small class="muted">Gns. Ordreværdi (AOV)</small>
      <strong style="font-size:1.4rem; color:#d97706; display:block; margin-top:4px;"><?=number_format((float)$kpi['avg_order_value'], 2, ',', '.')?> kr</strong>
    </div>
    <div class="card metric" style="text-align:center; padding:12px; background:var(--bg-card,#ffffff);">
      <small class="muted">Solgte Flasker/Enheder</small>
      <strong style="font-size:1.4rem; color:#7c3aed; display:block; margin-top:4px;"><?=$totalItemsSold?></strong>
    </div>
  </div>

  <!-- Top Products & Brands Grid -->
  <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap:12px;">
    <div class="card">
      <h3 style="margin-top:0;">🏆 Top Sælgende Produkter</h3>
      <div class="table-wrap">
        <table>
          <thead>
            <tr><th>Produkt / SKU</th><th style="text-align:center;">Solgt</th><th style="text-align:right;">Omsætning</th></tr>
          </thead>
          <tbody>
            <?php
            $sqlTopProd = "SELECT i.product_name, i.sku, SUM(i.quantity) AS total_qty, SUM(i.line_total) AS total_sum
                FROM hsg_woocommerce_order_items i
                LEFT JOIN hsg_woocommerce_orders o ON o.wc_order_id = i.wc_order_id
                WHERE {$itemWhereSql}
                GROUP BY i.product_name, i.sku
                ORDER BY total_sum DESC LIMIT 5";
            $stTP = $pdo->prepare($sqlTopProd);
            $stTP->execute($itemParams);
            $topProds = $stTP->fetchAll(PDO::FETCH_ASSOC);
            foreach ($topProds as $tp):
            ?>
              <tr>
                <td><strong><?=h($tp['product_name'])?></strong><br><small class="muted"><?=h($tp['sku']?:'Ingen SKU')?></small></td>
                <td style="text-align:center; font-weight:600;"><?=$tp['total_qty']?> stf</td>
                <td style="text-align:right; font-weight:bold; color:#059669;"><?=number_format((float)$tp['total_sum'], 2, ',', '.')?> kr</td>
              </tr>
            <?php endforeach; if (!$topProds): ?>
              <tr><td colspan="3" class="muted">Ingen salgsdata fundet for denne periode.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card">
      <h3 style="margin-top:0;">🏷️ Top Brands & Destillerier</h3>
      <div class="table-wrap">
        <table>
          <thead>
            <tr><th>Brand / Mærke</th><th style="text-align:center;">Solgt</th><th style="text-align:right;">Omsætning</th></tr>
          </thead>
          <tbody>
            <?php
            $sqlTopBrand = "SELECT COALESCE(NULLIF(i.brand_name,''), NULLIF(i.distillery,''), 'Øvrige') AS brand, SUM(i.quantity) AS total_qty, SUM(i.line_total) AS total_sum
                FROM hsg_woocommerce_order_items i
                LEFT JOIN hsg_woocommerce_orders o ON o.wc_order_id = i.wc_order_id
                WHERE {$itemWhereSql}
                GROUP BY brand
                ORDER BY total_sum DESC LIMIT 5";
            $stTB = $pdo->prepare($sqlTopBrand);
            $stTB->execute($itemParams);
            $topBrands = $stTB->fetchAll(PDO::FETCH_ASSOC);
            foreach ($topBrands as $tb):
            ?>
              <tr>
                <td><strong><?=h($tb['brand'])?></strong></td>
                <td style="text-align:center; font-weight:600;"><?=$tb['total_qty']?> stf</td>
                <td style="text-align:right; font-weight:bold; color:#2563eb;"><?=number_format((float)$tb['total_sum'], 2, ',', '.')?> kr</td>
              </tr>
            <?php endforeach; if (!$topBrands): ?>
              <tr><td colspan="3" class="muted">Ingen brand-data fundet for denne periode.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

<?php elseif ($tab === 'builder'): ?>

  <div class="card">
    <h2>🔍 Brugerdefineret Rapportbygger</h2>
    <p class="muted">Filtrér WooCommerce salgsdata på tværs af alle tilgængelige parametre og generér skræddersyede salgsoversigter.</p>

    <form method="get" style="margin-bottom:1rem;">
      <input type="hidden" name="tab" value="builder">
      <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap:10px;">
        <label>Periode
          <select name="period" onchange="this.form.submit()">
            <option value="today" <?=$period==='today'?'selected':''?>>I dag</option>
            <option value="7days" <?=$period==='7days'?'selected':''?>>Seneste 7 dage</option>
            <option value="30days" <?=$period==='30days'?'selected':''?>>Seneste 30 dage</option>
            <option value="this_month" <?=$period==='this_month'?'selected':''?>>Denne måned</option>
            <option value="last_month" <?=$period==='last_month'?'selected':''?>>Sidste måned</option>
            <option value="this_year" <?=$period==='this_year'?'selected':''?>>I år</option>
            <option value="custom" <?=$period==='custom'?'selected':''?>>Brugerdefineret dato</option>
          </select>
        </label>

        <?php if ($period === 'custom'): ?>
          <label>Fra dato<input type="date" name="date_from" value="<?=h(substr($dFrom,0,10))?>"></label>
          <label>Til dato<input type="date" name="date_to" value="<?=h(substr($dTo,0,10))?>"></label>
        <?php endif; ?>

        <label>Ordrestatus
          <select name="status">
            <option value="all" <?=$status==='all'?'selected':''?>>Alle statuser</option>
            <option value="completed" <?=$status==='completed'?'selected':''?>>Gennemført (Completed)</option>
            <option value="processing" <?=$status==='processing'?'selected':''?>>Under behandling</option>
            <option value="on-hold" <?=$status==='on-hold'?'selected':''?>>Afventer (On-hold)</option>
            <option value="refunded" <?=$status==='refunded'?'selected':''?>>Refunderet</option>
          </select>
        </label>

        <label>Brand / Mærke
          <select name="brand">
            <option value="">Alle brands</option>
            <?php foreach ($brandsList as $bName): ?>
              <option value="<?=h($bName)?>" <?=$brand===$bName?'selected':''?>><?=h($bName)?></option>
            <?php endforeach; ?>
          </select>
        </label>

        <label>Destilleri
          <select name="distillery">
            <option value="">Alle destillerier</option>
            <?php foreach ($distilleriesList as $dName): ?>
              <option value="<?=h($dName)?>" <?=$distillery===$dName?'selected':''?>><?=h($dName)?></option>
            <?php endforeach; ?>
          </select>
        </label>

        <label>Land
          <select name="country">
            <option value="">Alle lande</option>
            <?php foreach ($countriesList as $cCode): ?>
              <option value="<?=h($cCode)?>" <?=$country===$cCode?'selected':''?>><?=h($cCode)?></option>
            <?php endforeach; ?>
          </select>
        </label>

        <label>Søg SKU / Produkt<input name="sku" value="<?=h($sku)?>" placeholder="fx 17-101..."></label>
      </div>

      <div style="margin-top:12px; display:flex; gap:8px;">
        <button class="button">Filtrér rapport</button>
        <a class="button secondary" href="reports.php?tab=builder">Nulstil filtre</a>
        <a class="button secondary" href="reports.php?<?=http_build_query(array_merge($_GET, ['export'=>'csv']))?>">📥 Eksportér CSV</a>
      </div>
    </form>

    <?php if (can('reports.manage')): ?>
      <details style="margin-top:1rem; padding:12px; background:var(--bg-card,#fdfdfd); border:1px solid #ddd; border-radius:6px;">
        <summary style="cursor:pointer; font-weight:600;">⭐ Gem denne rapport som skabelon</summary>
        <form method="post" style="margin-top:8px;">
          <?=csrf_field()?>
          <input type="hidden" name="action" value="save_report_template">
          <?php foreach ($_GET as $gK => $gV): ?>
            <input type="hidden" name="filters[<?=h($gK)?>]" value="<?=h($gV)?>">
          <?php endforeach; ?>
          <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:8px;">
            <label>Navn på skabelon<input name="template_name" required placeholder="fx Månedlig Lady of the Glen rapport"></label>
            <label>Beskrivelse (valgfri)<input name="template_desc" placeholder="Kort beskrivelse..."></label>
          </div>
          <button class="button small" style="margin-top:8px;">Gem skabelon</button>
        </form>
      </details>
    <?php endif; ?>
  </div>

  <div class="card">
    <h3>Ordreoversigt (<?=$kpi['total_orders']?> ordrer)</h3>
    <div class="table-wrap">
      <table>
        <thead>
          <tr><th>Ordre #</th><th>Dato</th><th>Kunde</th><th>Land</th><th>Status</th><th>Betaling</th><th style="text-align:right;">Total</th></tr>
        </thead>
        <tbody>
          <?php
          $sqlOrdersList = "SELECT * FROM hsg_woocommerce_orders WHERE {$orderWhereSql} ORDER BY date_created DESC LIMIT 100";
          $stOL = $pdo->prepare($sqlOrdersList);
          $stOL->execute($orderParams);
          $ordersList = $stOL->fetchAll(PDO::FETCH_ASSOC);
          foreach ($ordersList as $o):
          ?>
            <tr>
              <td><strong>#<?=h($o['order_number'])?></strong></td>
              <td><?=h($o['date_created'])?></td>
              <td><strong><?=h($o['customer_name']?:'Ukendt')?></strong><br><small class="muted"><?=h($o['customer_email'])?></small></td>
              <td><?=h($o['customer_country'])?></td>
              <td><span class="badge blue"><?=h($o['status'])?></span></td>
              <td><?=h($o['payment_method'])?></td>
              <td style="text-align:right; font-weight:bold; color:#059669;"><?=number_format((float)$o['total_amount'], 2, ',', '.')?> kr</td>
            </tr>
          <?php endforeach; if (!$ordersList): ?>
            <tr><td colspan="7" class="muted">Ingen ordrer matcher de valgte filtre.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

<?php elseif ($tab === 'saved'): ?>

  <div class="card">
    <h2>⭐ Gemte Rapportskabeloner</h2>
    <p class="muted">Åbn dine gemte rapportbygger-skabeloner med ét klik direkte fra mobilen eller computeren.</p>

    <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap:12px;">
      <?php
      $savedList = $pdo->query("SELECT * FROM hsg_saved_reports ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
      foreach ($savedList as $sTpl):
          $filters = json_decode((string)$sTpl['filters_json'], true) ?: [];
          $filters['tab'] = 'builder';
          $url = 'reports.php?' . http_build_query($filters);
      ?>
        <div class="card" style="padding:14px; background:var(--bg-card,#ffffff); border:1px solid #e5e7eb;">
          <h3 style="margin:0 0 4px 0;"><?=h($sTpl['name'])?></h3>
          <p class="muted" style="margin:0 0 10px 0; font-size:0.85rem;"><?=h($sTpl['description']?:'Ingen beskrivelse')?></p>
          <div style="display:flex; gap:8px;">
            <a class="button small" href="<?=h($url)?>">Åbn rapport</a>
            <?php if (can('reports.manage')): ?>
              <form method="post" style="margin:0;" onsubmit="return confirm('Vil du slette denne skabelon?')">
                <?=csrf_field()?>
                <input type="hidden" name="action" value="delete_report_template">
                <input type="hidden" name="template_id" value="<?=$sTpl['id']?>">
                <button class="button danger small">Slet</button>
              </form>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; if (!$savedList): ?>
        <p class="muted">Ingen gemte rapportskabeloner endnu. Du kan gemme enhver rapport under fanebladet <strong>Rapportbygger</strong>.</p>
      <?php endif; ?>
    </div>
  </div>

<?php elseif ($tab === 'settings'): ?>

  <div class="card">
    <h2>⚙️ WooCommerce Webshop Indstillinger</h2>
    <p class="muted">Indtast dine API-nøgler fra din WooCommerce webshop for at aktivere automatisk 1-klik salgssynkronisering.</p>

    <form method="post">
      <?=csrf_field()?>
      <input type="hidden" name="action" value="save_settings">
      <?php $creds = hsg_wc_get_api_credentials($pdo); ?>
      <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap:12px;">
        <label>Webshop URL<input name="woocommerce_shop_url" value="<?=h($creds['shop_url'])?>" placeholder="fx https://hsg-whisky.dk" required></label>
        <label>Consumer Key (CK)<input name="woocommerce_consumer_key" value="<?=h($creds['consumer_key'])?>" placeholder="ck_..."></label>
        <label>Consumer Secret (CS)<input type="password" name="woocommerce_consumer_secret" value="<?=h($creds['consumer_secret'])?>" placeholder="cs_..."></label>
      </div>
      <p class="muted" style="font-size:0.85rem; margin-top:8px;">Sidst synkroniseret: <?=h(setting_get($pdo, 'woocommerce_last_synced_at', 'Aldrig'))?></p>
      <div style="margin-top:12px; display:flex; gap:8px;">
        <button class="button">Gem indstillinger</button>
      </div>
    </form>
  </div>

<?php endif; ?>

<?php page_footer();
