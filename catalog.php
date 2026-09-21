<?php
require __DIR__.'/auth.php';
require_module_enabled('catalog');
require_capability('catalog.view');

$price = (($_GET['price'] ?? 'retail') === 'wholesale') ? 'wholesale' : 'retail';
$newOnly = !empty($_GET['news']) || !empty($_GET['new_only']);

$pdfParams = 'price=' . urlencode($price) . '&new_only=' . ($newOnly ? '1' : '0');
$pdfUrl = 'catalog_pdf.php?' . $pdfParams;

page_header('Produktkatalog');
?>
<div class="card catalog-control-card">
  <form method="get" class="catalog-select-form">
    <div class="catalog-form-grid">
      <div class="catalog-form-group">
        <label for="catalog_price">Pristype</label>
        <select id="catalog_price" name="price" onchange="this.form.submit()">
          <option value="retail" <?=$price==='retail'?'selected':''?>>Vejl. pris inkl. moms</option>
          <option value="wholesale" <?=$price==='wholesale'?'selected':''?>>Engrospris ekskl. moms</option>
        </select>
      </div>
      <div class="catalog-form-group">
        <label for="catalog_news">Katalogtype</label>
        <select id="catalog_news" name="news" onchange="this.form.submit()">
          <option value="0" <?=$newOnly?'':'selected'?>>Hele kataloget</option>
          <option value="1" <?=$newOnly?'selected':''?>>Kun nyheder (Nyhedskatalog)</option>
        </select>
      </div>
      <div class="catalog-form-actions">
        <button type="submit" class="button">Vis PDF</button>
        <a class="button secondary" href="<?=h($pdfUrl)?>" target="_blank" rel="noopener">Åbn PDF i nyt vindue</a>
        <?php if(is_admin()): ?>
          <a class="button secondary" href="products.php">Rediger produkter</a>
        <?php endif; ?>
      </div>
    </div>
  </form>
</div>

<div class="card catalog-pdf-card" style="padding:0; overflow:hidden;">
  <div class="catalog-pdf-wrapper">
    <iframe
      src="<?=h($pdfUrl)?>"
      title="HSG Produktkatalog PDF"
      class="catalog-pdf-iframe"
    ></iframe>
  </div>
  <div class="catalog-pdf-fallback muted">
    Kan du ikke se PDF'en direkte herover? <a href="<?=h($pdfUrl)?>" target="_blank" rel="noopener">Klik her for at åbne katalog som PDF i nyt vindue</a>.
  </div>
</div>

<?php page_footer();
