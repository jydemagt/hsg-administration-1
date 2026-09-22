<?php
require __DIR__.'/auth.php';
require_module_enabled('catalog');
require_capability('catalog.view');

$price = (($_GET['price'] ?? 'retail') === 'wholesale') ? 'wholesale' : 'retail';
$newOnly = !empty($_GET['news']) || !empty($_GET['new_only']);

$pdfParams = 'price=' . urlencode($price) . '&new_only=' . ($newOnly ? '1' : '0');
$pdfInlineUrl = 'catalog_pdf.php?' . $pdfParams . '&inline=1';
$pdfDownloadUrl = 'catalog_pdf.php?' . $pdfParams . '&download=1';

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
        <a class="button secondary" href="<?=h($pdfInlineUrl)?>" target="_blank" rel="noopener">Åbn PDF i browser</a>
        <a class="button secondary" href="<?=h($pdfDownloadUrl)?>">Download PDF</a>
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
      src="<?=h($pdfInlineUrl)?>"
      title="HSG Produktkatalog PDF"
      class="catalog-pdf-iframe"
    ></iframe>
  </div>
  <div class="catalog-pdf-fallback muted">
    Kan du ikke se PDF'en direkte herover? <a href="<?=h($pdfInlineUrl)?>" target="_blank" rel="noopener">Åbn PDF i nyt vindue</a> eller <a href="<?=h($pdfDownloadUrl)?>">Download PDF</a>.
  </div>
</div>

<?php page_footer();
