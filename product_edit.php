<?php
require __DIR__.'/auth.php';
require_capability('products.manage');
require_once __DIR__.'/core/quality.php';
require_once __DIR__.'/core/catalog_layout.php';

$id = (int)($_GET['id'] ?? $_GET['edit'] ?? $_POST['id'] ?? 0);
$edit = null;

if ($id > 0) {
    $st = $pdo->prepare('SELECT * FROM lager_products WHERE id=?');
    $st->execute([$id]);
    $edit = $st->fetch(PDO::FETCH_ASSOC);
    if (!$edit) {
        flash('error', 'Produktet findes ikke.');
        redirect('products.php');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $sku = trim((string)($_POST['sku'] ?? ''));
        $callName = trim((string)($_POST['call_name'] ?? ''));
        if ($sku === '') throw new RuntimeException('SKU / nummer skal udfyldes.');

        $brand = (int)($_POST['brand_id'] ?? 0) ?: null;
        $status = in_array($_POST['status'] ?? 'active', ['active', 'inactive', 'discontinued'], true) ? $_POST['status'] : 'active';

        $vintage = trim((string)($_POST['vintage_year'] ?? ''));
        $vintage = $vintage !== '' ? (int)$vintage : null;
        if ($vintage !== null && ($vintage < 1900 || $vintage > (int)date('Y'))) {
            throw new RuntimeException('Årgang skal være et gyldigt årstal (fx 2015).');
        }

        $distillery = trim((string)($_POST['distillery'] ?? ''));
        $age = trim((string)($_POST['age_text'] ?? ''));
        $abv = parse_decimal($_POST['abv'] ?? '');

        $composedP = [
            'distillery' => $distillery,
            'vintage_year' => $vintage,
            'age_text' => $age,
            'abv' => $abv,
            'name' => trim((string)($_POST['name'] ?? '')) ?: ($distillery ?: ($sku ?: 'Nyt produkt'))
        ];
        $name = hsg_catalog_product_title($composedP);

        $vals = [
            $sku, $name, $callName ?: null, $brand, trim((string)($_POST['category'] ?? '')), $distillery,
            trim((string)($_POST['country'] ?? '')), $age, $vintage,
            $abv, parse_decimal($_POST['bottle_size_cl'] ?? ''), trim((string)($_POST['cask_type'] ?? '')), trim((string)($_POST['cask_number'] ?? '')),
            trim((string)($_POST['bottle_count'] ?? '')), parse_decimal($_POST['wholesale_price'] ?? ''), parse_decimal($_POST['retail_price'] ?? ''),
            !empty($_POST['is_new']) ? 1 : 0, !empty($_POST['show_in_catalog']) ? 1 : 0, $status, trim((string)($_POST['supplier_name'] ?? '')),
            trim((string)($_POST['supplier_domain'] ?? '')), trim((string)($_POST['supplier_url'] ?? '')), trim((string)($_POST['notes'] ?? ''))
        ];

        if ($id > 0) {
            $sql = 'UPDATE lager_products SET sku=?,name=?,call_name=?,brand_id=?,category=?,distillery=?,country=?,age_text=?,vintage_year=?,abv=?,bottle_size_cl=?,cask_type=?,cask_number=?,bottle_count=?,wholesale_price=?,retail_price=?,is_new=?,show_in_catalog=?,status=?,supplier_name=?,supplier_domain=?,supplier_url=?,notes=? WHERE id=?';
            $vals[] = $id;
            $pdo->prepare($sql)->execute($vals);
            hsg_sync_product_stock_status($pdo, $id);
        } else {
            $sql = 'INSERT INTO lager_products(sku,name,call_name,brand_id,category,distillery,country,age_text,vintage_year,abv,bottle_size_cl,cask_type,cask_number,bottle_count,wholesale_price,retail_price,is_new,show_in_catalog,status,supplier_name,supplier_domain,supplier_url,notes) VALUES(' . implode(',', array_fill(0, 23, '?')) . ')';
            $pdo->prepare($sql)->execute($vals);
            $id = (int)$pdo->lastInsertId();
            hsg_sync_product_stock_status($pdo, $id);
        }

        hsg_quality_invalidate($pdo, $id);
        audit_log($pdo, 'product.save', 'product', (string)$id, ['sku' => $sku, 'name' => $name, 'status' => $status]);
        hsg_do_action('product.saved', ['product_id' => $id, 'sku' => $sku, 'name' => $name, 'status' => $status]);

        flash('success', 'Produkt gemt.');
        redirect('product_edit.php?id=' . $id);
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        flash('error', 'Kunne ikke gemme: ' . $e->getMessage());
    }
}

$brands = $pdo->query('SELECT b.id,b.name,b.parent_id,pb.name parent_name FROM lager_brands b LEFT JOIN lager_brands pb ON pb.id=b.parent_id WHERE b.active=1 ORDER BY COALESCE(pb.sort_order,b.sort_order),COALESCE(pb.name,b.name),b.parent_id IS NOT NULL,b.sort_order,b.name')->fetchAll(PDO::FETCH_ASSOC);
$requiredQualityFields = hsg_quality_required_fields($pdo);
$reqMark = static fn(string $field): string => in_array($field, $requiredQualityFields, true) ? ' *' : '';

page_header($edit ? 'Rediger produkt: ' . h($edit['sku']) : 'Opret nyt produkt');
?>
<div class="actions" style="margin-bottom:16px;">
  <a class="button secondary" href="products.php">← Tilbage til produktlisten</a>
  <?php if ($edit): ?>
    <a class="button secondary" href="image_check.php?product=<?=$edit['id']?>">Billedkontrol</a>
  <?php endif; ?>
</div>

<div class="card">
  <h2><?=$edit ? 'Rediger produkt' : 'Nyt produkt'?></h2>
  <form method="post" id="productForm"><?=csrf_field()?><input type="hidden" name="id" value="<?=$edit['id'] ?? 0?>">
    <div class="three">
      <label>SKU / nummer *<input name="sku" required value="<?=h($edit['sku'] ?? '')?>"></label>
      <label>Produktnavn / varetekst (Automatisk sammensat)<input name="name" id="product_name_input" readonly tabindex="-1" style="background-color:var(--bg-body,#f3f4f6);cursor:not-allowed;" value="<?=h($edit ? hsg_catalog_product_title($edit) : '')?>"></label>
      <label>Kaldenavn (Valgfri underoverskrift)<input name="call_name" value="<?=h($edit['call_name'] ?? '')?>" placeholder="fx The Chain - Chapter 2"></label>
    </div>

    <div class="product-assistant-box">
      <div class="actions">
        <button type="button" id="enrichProductBtn">✨ Udfyld fra varetekst</button>
        <label class="check" style="margin:0"><input type="checkbox" id="enrichUseAi" checked> Brug AI til usikre/manglende felter</label>
      </div>
      <div id="enrichProductStatus" class="muted" style="margin-top:8px">Eksisterende værdier overskrives ikke automatisk.</div>
    </div>

    <div class="three">
      <label>Brand<?=$reqMark('brand_id')?>
        <select name="brand_id" id="brand_id">
          <option value="">– Intet brand –</option>
          <?php foreach ($brands as $b): $bDisplayName = !empty($b['parent_id']) ? $b['parent_name'] . ' › ' . $b['name'] : $b['name']; ?>
            <option value="<?=$b['id']?>" data-brand-name="<?=h($b['name'])?>" <?=($edit['brand_id'] ?? null) == $b['id'] ? 'selected' : ''?>><?=h($bDisplayName)?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Kategori<?=$reqMark('category')?><input name="category" value="<?=h($edit['category'] ?? '')?>" placeholder="Single Malt, Rom..."></label>
      <label>Destilleri<?=$reqMark('distillery')?><input name="distillery" value="<?=h($edit['distillery'] ?? '')?>"></label>
    </div>

    <div class="three">
      <label>Land<?=$reqMark('country')?><input name="country" value="<?=h($edit['country'] ?? '')?>"></label>
      <label>Alder<?=$reqMark('age_text')?><input name="age_text" value="<?=h($edit['age_text'] ?? '')?>" placeholder="12 år"></label>
      <label>Årgang / destilleret<?=$reqMark('vintage_year')?><input type="number" min="1900" max="<?=date('Y')?>" name="vintage_year" value="<?=h($edit['vintage_year'] ?? '')?>" placeholder="2013"></label>
    </div>

    <div class="three">
      <label>Alc. %<?=$reqMark('abv')?><input inputmode="decimal" name="abv" value="<?=h($edit['abv'] ?? '')?>"></label>
      <label>Flaskestørrelse (cl)<?=$reqMark('bottle_size_cl')?><input inputmode="decimal" name="bottle_size_cl" value="<?=h($edit['bottle_size_cl'] ?? 70)?>"></label>
      <label>Fadtype<?=$reqMark('cask_type')?><input name="cask_type" value="<?=h($edit['cask_type'] ?? '')?>"></label>
    </div>

    <div class="split">
      <label>Fadnummer<?=$reqMark('cask_number')?><input name="cask_number" value="<?=h($edit['cask_number'] ?? '')?>" placeholder="fx 300805 eller 892-4"><span class="muted">Nummeret efter # / Cask No. bruges som stærkt match ved leverandørupload og billedtjek.</span></label>
      <label>Antal flasker i aftapning<?=$reqMark('bottle_count')?><input name="bottle_count" value="<?=h($edit['bottle_count'] ?? '')?>"></label>
    </div>

    <div class="split">
      <label>Engrospris (ekskl. moms)<?=$reqMark('wholesale_price')?><input inputmode="decimal" name="wholesale_price" value="<?=h($edit['wholesale_price'] ?? '')?>"></label>
      <label>Udsalgspris (inkl. moms)<?=$reqMark('retail_price')?><input inputmode="decimal" name="retail_price" value="<?=h($edit['retail_price'] ?? '')?>"></label>
    </div>

    <div class="split">
      <label>Leverandør<input name="supplier_name" value="<?=h($edit['supplier_name'] ?? '')?>"></label>
      <label>Leverandør-domæne<input name="supplier_domain" value="<?=h($edit['supplier_domain'] ?? '')?>" placeholder="example.com"></label>
    </div>

    <label>Produktets direkte leverandør-URL (valgfri)<input type="url" name="supplier_url" value="<?=h($edit['supplier_url'] ?? '')?>"><span class="muted">Overstyrer brandets leverandør-URL for netop dette produkt.</span></label>

    <div class="three">
      <label>Status
        <select name="status">
          <?php foreach (['active' => 'Aktiv', 'inactive' => 'Inaktiv', 'discontinued' => 'Udgået'] as $k => $v): ?>
            <option value="<?=$k?>" <?=($edit['status'] ?? 'active') === $k ? 'selected' : ''?>><?=$v?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="check"><input type="checkbox" name="is_new" value="1" <?=!$edit || !empty($edit['is_new']) ? 'checked' : ''?>> Nyhed</label>
      <label class="check"><input type="checkbox" name="show_in_catalog" value="1" <?=!$edit || !empty($edit['show_in_catalog']) ? 'checked' : ''?>> Vis i katalog</label>
    </div>

    <label>Noter<textarea name="notes"><?=h($edit['notes'] ?? '')?></textarea></label>

    <?php if ($edit && !empty($edit['data_enriched_at'])): ?>
      <div class="flash success">
        <strong>Senest analyseret:</strong> <?=h($edit['data_enriched_at'])?> · <?=h($edit['data_enrichment_source'] ?? '')?> · score <?=intval($edit['data_enrichment_score'] ?? 0)?>%
        <?php if ($edit['data_enrichment_note']): ?>
          <br><span class="muted"><?=h($edit['data_enrichment_note'])?></span>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <div class="actions">
      <button type="submit">Gem produkt</button>
      <a class="button secondary" href="products.php">Annuller</a>
    </div>
  </form>
</div>

<script>
const enrichCsrf = <?=json_encode(csrf_token())?>;
const productForm = document.getElementById('productForm');
const statusEl = document.getElementById('enrichProductStatus');
function field(name) { return productForm?.querySelector('[name="' + name + '"]') || null; }
function brandName() { const s = field('brand_id'); return s?.selectedOptions?.[0]?.dataset?.brandName || ''; }
function setIfEmpty(name, value) {
  const el = field(name); if (!el || value === undefined || value === null || String(value).trim() === '') return false;
  const current = String(el.value ?? '').trim();
  if (current !== '' && !(name === 'bottle_size_cl' && current === '70' && String(value) !== '70')) return false;
  el.value = value; el.classList.add('assistant-filled'); setTimeout(() => el.classList.remove('assistant-filled'), 1800); return true;
}
function setBrandIfMatch(value) {
  if (!value || !field('brand_id') || field('brand_id').value) return false;
  const wanted = String(value).trim().toLocaleLowerCase('da');
  for (const opt of field('brand_id').options) { if ((opt.dataset.brandName || '').trim().toLocaleLowerCase('da') === wanted) { field('brand_id').value = opt.value; return true; } }
  return false;
}
async function enrichRequest(fd) {
  const r = await fetch('product_enrich.php', { method: 'POST', body: fd, credentials: 'same-origin' });
  let j = {}; try { j = await r.json(); } catch (e) {}
  if (!r.ok || !j.ok) { const err = new Error(j.error || ('HTTP ' + r.status)); err.retryAfter = Number(j.retry_after || r.headers.get('Retry-After') || 0); throw err; } return j;
}
document.getElementById('enrichProductBtn')?.addEventListener('click', async () => {
  const btn = document.getElementById('enrichProductBtn'); btn.disabled = true; statusEl.textContent = 'Analyserer vareteksten…';
  try {
    const fd = new FormData(); fd.append('csrf', enrichCsrf); fd.append('text', field('name')?.value || ''); fd.append('product_id', field('id')?.value || '0'); fd.append('brand_name', brandName()); fd.append('supplier_name', field('supplier_name')?.value || ''); fd.append('notes', field('notes')?.value || ''); fd.append('use_ai', document.getElementById('enrichUseAi')?.checked ? '1' : '0');
    const j = await enrichRequest(fd), f = j.result.fields || {}; let n = 0;
    for (const k of ['distillery', 'country', 'age_text', 'vintage_year', 'abv', 'bottle_size_cl', 'cask_type', 'cask_number', 'category']) if (setIfEmpty(k, f[k])) n++;
    if (setBrandIfMatch(f.brand_name)) n++;
    statusEl.textContent = n + ' felt(er) foreslået · score ' + Number(j.result.confidence || 0) + '% · ' + String(j.result.source || '') + (j.result.reason ? ' · ' + String(j.result.reason) : '');
  } catch (e) { statusEl.textContent = 'Kunne ikke analysere: ' + e.message; } finally { btn.disabled = false; }
});
</script>
<?php page_footer();
