<?php
require __DIR__.'/auth.php';require_module_enabled('images');require_capability('images.manage');require_once __DIR__.'/image_tools.php';

$filter=(int)($_GET['product']??0);$brandFilter=(int)($_GET['brand']??0);$approvalFilter=(string)($_GET['approval']??'all');if(!in_array($approvalFilter,['all','pending','approved','missing'],true))$approvalFilter='all';
if($_SERVER['REQUEST_METHOD']==='POST' && (string)($_POST['action']??'')==='save_validation_settings'){
    try{
        if(!empty($_POST['remove_groq_api_key'])) setting_set($pdo,'groq_api_key','');
        elseif(trim((string)($_POST['groq_api_key']??''))!=='') setting_set($pdo,'groq_api_key',trim((string)$_POST['groq_api_key']));
        audit_log($pdo,'images.validation_settings','system',null,['groq_key_changed'=>trim((string)($_POST['groq_api_key']??''))!==''||!empty($_POST['remove_groq_api_key'])]);
        flash('success','Indstillinger til billedvalidering er gemt.');
    }catch(Throwable $e){flash('error',$e->getMessage());}
    $q=$brandFilter?'?brand='.$brandFilter:($filter?'?product='.$filter:'');redirect('image_check.php'.$q);
}
$where=['(COALESCE(st.physical,0)-COALESCE(rs.reserved,0)) > 0'];$params=[];if($filter){$where[]='p.id=?';$params[]=$filter;}if($brandFilter){$where[]='p.brand_id=?';$params[]=$brandFilter;}$whereSql='WHERE '.implode(' AND ',$where);
$sql="SELECT p.id,p.sku,p.name,p.image_path,p.image_source_url,p.supplier_domain,p.supplier_url,p.image_method,p.image_confidence,p.image_ai_note,
 p.image_validation_score,p.image_validation_status,p.image_validation_note,p.image_validated_at,p.image_validation_model,
 p.image_approval_status,p.image_approved_at,p.image_approved_by_admin,
 (COALESCE(st.physical,0)-COALESCE(rs.reserved,0)) available,
 b.name brand_name,b.website_url
 FROM lager_products p LEFT JOIN lager_brands b ON b.id=p.brand_id
 LEFT JOIN (SELECT product_id,SUM(quantity) physical FROM lager_stock GROUP BY product_id) st ON st.product_id=p.id
 LEFT JOIN (SELECT product_id,SUM(quantity) reserved FROM lager_reservations WHERE status='reserved' GROUP BY product_id) rs ON rs.product_id=p.id
 $whereSql
 ORDER BY CASE
   WHEN p.image_path IS NULL OR p.image_path='' THEN 0
   WHEN COALESCE(p.image_approval_status,'pending')<>'approved' THEN 1
   WHEN p.image_validation_status IN ('flagged','error') THEN 2
   WHEN p.image_validation_score IS NULL THEN 3
   ELSE 4 END,p.name";
$st=$pdo->prepare($sql);$st->execute($params);$allRows=$st->fetchAll();
$present=0;$validatedOk=0;$flagged=0;$unvalidated=0;$manualApproved=0;$manualPending=0;$manualRejected=0;
foreach($allRows as $r){
    $has=image_is_present($r['image_path']);$approval=(string)($r['image_approval_status']??'');
    if(!$has){if($approval==='rejected')$manualRejected++;continue;}
    $present++;
    $vs=(string)($r['image_validation_status']??'');$score=$r['image_validation_score'];$scoreInt=$score!==null?(int)$score:null;
    if($approval==='approved')$manualApproved++;
    elseif($vs==='verified'&&$scoreInt!==null&&$scoreInt>=80)$manualPending++;
    if($vs==='verified' && $scoreInt!==null && $scoreInt>=80)$validatedOk++;
    elseif($vs==='flagged'||$vs==='error'||($scoreInt!==null&&$scoreInt<80))$flagged++;
    else $unvalidated++;
}
$missing=count($allRows)-$present;
$rows=array_values(array_filter($allRows,static function(array $r)use($approvalFilter):bool{
    if($approvalFilter==='all')return true;
    $has=image_is_present($r['image_path']??null);$approval=(string)($r['image_approval_status']??'');
    if($approvalFilter==='missing')return !$has;
    if($approvalFilter==='approved')return $has&&$approval==='approved';
    return $has&&$approval!=='approved'&&(string)($r['image_validation_status']??'')==='verified'&&$r['image_validation_score']!==null&&(int)$r['image_validation_score']>=80;
}));
$groqKeySet=trim((string)setting_get($pdo,'groq_api_key',''))!=='';
$validationReady=hsg_ai_image_validation_ready($pdo);
function image_method_text(?string $method): string {return $method==='manual'?'HSG katalog / manuel':'Lokalt billede';}
page_header('Billedtjek');
?>
<div class="grid">
  <div class="card metric"><strong><?=$present?></strong><span>Billeder fundet</span></div>
  <div class="card metric"><strong><?=$manualPending?></strong><span>Afventer manuel godkendelse</span></div>
  <div class="card metric"><strong><?=$manualApproved?></strong><span>Manuelt godkendt</span></div>
  <div class="card metric"><strong><?=$missing?></strong><span>Mangler billede</span></div>
  <div class="card metric"><strong><?=$flagged?></strong><span>AI-flagget &lt;80% / fejl</span></div>
</div>
<div class="card">
  <div class="actions">
    <a class="button <?=$approvalFilter==='all'?'':'secondary'?>" href="image_check.php<?=($brandFilter?'?brand='.$brandFilter.'&':'?')?>approval=all">Alle</a>
    <a class="button <?=$approvalFilter==='pending'?'':'secondary'?>" href="image_check.php<?=($brandFilter?'?brand='.$brandFilter.'&':'?')?>approval=pending">Ikke godkendt (<?=$manualPending?>)</a>
    <a class="button <?=$approvalFilter==='approved'?'':'secondary'?>" href="image_check.php<?=($brandFilter?'?brand='.$brandFilter.'&':'?')?>approval=approved">Godkendt (<?=$manualApproved?>)</a>
    <a class="button <?=$approvalFilter==='missing'?'':'secondary'?>" href="image_check.php<?=($brandFilter?'?brand='.$brandFilter.'&':'?')?>approval=missing">Mangler billede (<?=$missing?>)</a>
  </div>
  <p class="muted">AI-score er kun vejledende. <strong>Alle billeder skal godkendes manuelt</strong>, før de betragtes som godkendte i HSG.</p>
</div>

<div class="card">
  <h2>Billedvalidering</h2>
  <p class="muted"><strong>Automatisk billedsøgning er fjernet.</strong> Billeder kommer fra HSG-kataloget eller tilføjes manuelt. Groq Vision bruges kun til at validere et allerede gemt billede. Kun billeder med mindst 80 % valideringsscore kan godkendes manuelt.</p>
  <span class="badge <?=$validationReady?'green':'red'?>"><?=$validationReady?'Groq billedvalidering er klar':'Groq API-nøgle mangler – validering er ikke tilgængelig'?></span>
  <details style="margin-top:12px"><summary>Indstillinger til Groq-validering</summary>
    <form method="post" style="margin-top:12px"><?=csrf_field()?><input type="hidden" name="action" value="save_validation_settings">
      <label>Groq API-nøgle<input type="password" name="groq_api_key" autocomplete="new-password" placeholder="<?=$groqKeySet?'Nøgle er gemt – lad feltet være tomt for at beholde den':'Indsæt Groq API-nøgle'?>"></label>
      <?php if($groqKeySet):?><label class="check"><input type="checkbox" name="remove_groq_api_key" value="1"> Fjern gemt Groq API-nøgle</label><?php endif;?>
      <div class="actions"><button>Gem valideringsindstillinger</button></div>
    </form>
  </details>
</div>

<div class="card">
  <div class="actions">
    <button id="validateAll" <?=$present&&$validationReady?'':'disabled'?>>Valider alle billeder</button>
    <a class="button secondary" href="products.php">Produkter</a>
  </div>
  <p id="progress" class="muted">AI-validering: 80–100 % går videre til manuel godkendelse. Under 80 % flagges. Der foretages ingen automatisk billedsøgning.</p>
</div>

<div class="table-wrap"><table><thead><tr><th>Billede</th><th>Produkt</th><th>Billedstatus</th><th>AI-validering</th><th>Manuel godkendelse</th><th>Kilde</th><th>Handling</th></tr></thead><tbody>
<?php foreach($rows as $r):
$ok=image_is_present($r['image_path']);$method=(string)($r['image_method']??'');$confidence=$r['image_confidence']!==null?(int)$r['image_confidence']:null;
$vscore=$r['image_validation_score']!==null?(int)$r['image_validation_score']:null;$vstatus=(string)($r['image_validation_status']??'');
$approval=(string)($r['image_approval_status']??'');$isApproved=$ok&&$approval==='approved';
$sourceVerified=true;
$readyForManual=$ok&&$vstatus==='verified'&&$vscore!==null&&$vscore>=80;$rowFlag=$ok&&(!$isApproved||$vstatus==='flagged'||$vstatus==='error'||($vscore!==null&&$vscore<80));
?>
<tr id="row-<?=$r['id']?>" class="<?=$rowFlag?'validation-flagged-row':''?>" data-product="<?=$r['id']?>" data-missing="<?=$ok?'0':'1'?>" data-has-image="<?=$ok?'1':'0'?>">
  <td><button type="button" class="image-preview-button" data-full-image="<?=h(product_image_url($r['image_path']))?>" data-product-id="<?=$r['id']?>" data-product-name="<?=h($r['name'])?>" data-sku="<?=h($r['sku'])?>" data-brand="<?=h($r['brand_name']??'')?>" data-search-score="<?=$confidence!==null?$confidence:''?>" data-validation-score="<?=$vscore!==null?$vscore:''?>" data-validation-status="<?=h($vstatus)?>" data-approval-status="<?=h($approval?:($ok?'pending':''))?>" data-source-url="<?=h($r['image_source_url']??'')?>" data-source-verified="<?=$sourceVerified?'1':'0'?>" aria-label="Vis <?=h($r['name'])?> i stort format"><img class="image-preview" src="<?=h(product_image_url($r['image_path']))?>" alt="<?=h($r['name'])?>"></button></td>
  <td><strong><?=h($r['name'])?></strong><br><span class="muted"><?=h($r['sku'])?><?=!empty($r['brand_name'])?' · '.h($r['brand_name']):''?></span></td>
  <td class="img-status"><span class="badge <?=$ok?'green':'red'?>"><?=$ok?'Billede fundet':'Mangler'?></span><?php if($ok&&$method):?><br><span class="badge"><?=h(image_method_text($method))?></span><?php endif;?></td>
  <td class="validation-status">
    <?php if(!$ok):?><span class="muted">Intet billede</span>
    <?php elseif($vstatus==='verified'&&$vscore!==null):?><span class="badge green">AI-match <?=$vscore?>%</span>
    <?php elseif(($vstatus==='flagged'||($vscore!==null&&$vscore<80))&&$vscore!==null):?><span class="badge red">AI FLAGGET <?=$vscore?>%</span>
    <?php elseif($vstatus==='error'):?><span class="badge red">Valideringsfejl</span>
    <?php else:?><span class="badge">Ikke AI-valideret</span><?php endif;?>
    <?php if($ok&&!empty($r['image_validation_note'])):?><br><small class="muted validation-note"><?=h($r['image_validation_note'])?></small><?php endif;?>
  </td>
  <td class="approval-status">
    <?php if(!$ok):?><span class="muted">Intet billede</span>
    <?php elseif($isApproved):?><span class="badge green">✓ Manuelt godkendt</span><?php if(!empty($r['image_approved_at'])):?><br><small class="muted"><?=h($r['image_approved_at'])?></small><?php endif;?>
    <?php elseif($readyForManual):?><span class="badge amber">Afventer manuel godkendelse</span>
    <?php elseif($vscore!==null&&$vscore<80):?><span class="badge red">Under 80 % – ikke til manuel tjek</span>
    <?php elseif($vstatus==='error'):?><span class="badge red">Valideringsfejl</span>
    <?php else:?><span class="badge">Afventer AI-validering</span><?php endif;?>
  </td>
  <td><span class="muted"><?php if(!empty($r['image_ai_note'])):?><?=h($r['image_ai_note'])?><?php elseif($ok):?>Lokalt billede<?php else:?>Intet billede<?php endif;?></span></td>
  <td>
    <div class="actions">
      <?php if($ok):?><button type="button" class="secondary validate-one" <?=$validationReady?'':'disabled'?>>AI-validér</button><?php endif;?>
      <?php if($ok&&!$isApproved&&$readyForManual):?><button type="button" class="success approve-one">Godkend billede</button><?php endif;?>
      <?php if($ok):?><button type="button" class="danger reject-current">Afvis billede</button><?php endif;?>
    </div>
    <form class="image-url-form"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="product_id" value="<?=$r['id']?>"><label>Manuel billed-/produktside-URL<input name="url" placeholder="https://leverandør.dk/produkt... eller ekstern billed-URL"></label><label>Dokumentation fra leverandør <input name="supplier_page_url" placeholder="https://leverandør.dk/produkt..."><small class="muted">Kræves kun hvis selve billedfilen ligger på et andet domæne/CDN. HSG kontrollerer, at leverandørsiden refererer til billedet.</small></label><button>Hent og kontrollér</button></form>
  </td>
</tr>
<?php endforeach;?></tbody></table></div>

<div id="imageLightbox" class="image-lightbox" hidden aria-hidden="true">
  <div class="image-lightbox-backdrop" data-lightbox-close></div>
  <div class="image-lightbox-dialog" role="dialog" aria-modal="true" aria-labelledby="imageLightboxTitle">
    <button type="button" class="image-lightbox-close" data-lightbox-close aria-label="Luk stort billede">×</button>
    <div class="image-lightbox-content">
      <div class="image-lightbox-stage"><img id="imageLightboxImg" src="" alt=""></div>
      <aside class="image-lightbox-meta">
        <h2 id="imageLightboxTitle"></h2>
        <div class="lightbox-meta-row"><span>SKU</span><strong id="imageLightboxSku">—</strong></div>
        <div class="lightbox-meta-row"><span>Brand</span><strong id="imageLightboxBrand">—</strong></div>
        <div class="lightbox-meta-row"><span>AI-validering</span><strong id="imageLightboxValidation">—</strong></div>
        <div class="lightbox-meta-row"><span>Manuel status</span><strong id="imageLightboxApproval">—</strong></div>
        <div id="imageLightboxFlag" class="lightbox-flag" hidden></div>
        <a id="imageLightboxSource" class="button secondary" href="#" target="_blank" rel="noopener noreferrer" hidden>Åbn kilde</a>
        <button id="imageLightboxUse" type="button" class="success" hidden>Brug dette billede</button>
        <button id="imageLightboxApprove" type="button" class="success" hidden>Godkend billede</button>
        <button id="imageLightboxReject" type="button" class="danger" hidden>Afvis billede</button>
      </aside>
    </div>
  </div>
</div>
<script>
const csrf=<?=json_encode(csrf_token())?>;
const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
function methodLabel(){return 'Manuelt tilføjet';}
function updateValidation(row,v,error=''){
 const cell=row.querySelector('.validation-status'),preview=row.querySelector('.image-preview-button');row.classList.remove('validation-flagged-row');
 const approval=preview?.dataset.approvalStatus||'pending';if(approval!=='approved')row.classList.add('validation-flagged-row');
 if(error){cell.innerHTML='<span class="badge red">Valideringsfejl</span><br><small class="muted validation-note">'+esc(error)+'</small>';if(preview){preview.dataset.validationStatus='error';preview.dataset.validationScore='';}row.classList.add('validation-flagged-row');return;}
 if(!v){cell.innerHTML='<span class="badge">Ikke AI-valideret</span>';if(preview){preview.dataset.validationStatus='';preview.dataset.validationScore='';}return;}
 const score=Number(v.score||0),ok=score>=80&&v.status==='verified';if(preview){preview.dataset.validationStatus=v.status||'';preview.dataset.validationScore=String(score);}
 cell.innerHTML='<span class="badge '+(ok?'green':'red')+'">'+(ok?'AI-match ':'AI FLAGGET ')+score+'%</span>'+(v.note?'<br><small class="muted validation-note">'+esc(v.note)+'</small>':'');
 if(!ok)row.classList.add('validation-flagged-row');
}
async function postImage(id,mode='',url='',supplierPageUrl=''){
 const fd=new FormData();fd.append('csrf',csrf);fd.append('product_id',id);fd.append('mode',mode);if(url)fd.append('url',url);if(supplierPageUrl)fd.append('supplier_page_url',supplierPageUrl);
 const res=await fetch('image_action.php',{method:'POST',body:fd});let data={};try{data=await res.json();}catch(e){throw new Error('Serveren returnerede et ugyldigt svar.');}
 if(!data.ok){const err=new Error(data.error||'Fejl');err.retryAfter=Number(data.retry_after||0);err.httpStatus=res.status;throw err;}return data;
}
const sleep=ms=>new Promise(resolve=>setTimeout(resolve,ms));
async function validateWithRetry(row,maxRetries=3){
 let attempt=0;
 while(true){
   try{return await validateImage(row.dataset.product);}
   catch(err){
     const retry=Math.max(0,Number(err.retryAfter||0));
     if(!retry || attempt>=maxRetries)throw err;
     attempt++;
     for(let left=retry;left>0;left--){document.getElementById('progress').textContent=`Groq gratis rate limit. Fortsætter automatisk om ${left} sek. (${row.querySelector('strong')?.textContent||'produkt'})`;await sleep(1000);}
   }
 }
}
async function validateImage(id){const data=await postImage(id,'validate');const row=document.getElementById('row-'+id);updateValidation(row,data.validation);return data.validation;}
async function fetchImage(id,mode='url',url='',supplierPageUrl=''){
 const data=await postImage(id,mode,url,supplierPageUrl);const row=document.getElementById('row-'+id);
 if(data.candidate_only){document.getElementById('progress').textContent=data.note||('AI fandt '+Number(data.candidate_count||0)+' kandidater til manuel kontrol.');return data;}
 row.dataset.missing='0';row.dataset.hasImage='1';
 const cls=data.method==='ai'?'badge blue':'badge';row.querySelector('.img-status').innerHTML='<span class="badge green">Billede fundet</span><br><span class="'+cls+'">'+methodLabel(data.method,data.confidence)+'</span>';
 const approvalCell=row.querySelector('.approval-status');if(approvalCell)approvalCell.innerHTML='<span class="badge red">Afventer godkendelse</span>';
 if(data.path){const fresh=data.path+'?v='+Date.now();const img=row.querySelector('.image-preview');if(img)img.src=fresh;const previewButton=row.querySelector('.image-preview-button');if(previewButton){previewButton.dataset.fullImage=fresh;previewButton.dataset.approvalStatus='pending';}}
 updateValidation(row,data.validation,data.validation_error||'');return data;
}
document.querySelectorAll('.validate-one').forEach(btn=>btn.addEventListener('click',async()=>{const row=btn.closest('tr');btn.disabled=true;btn.textContent='Validerer…';try{const v=await validateWithRetry(row,2);btn.textContent=v.score>=80?'Klar til manuel tjek '+v.score+'%':'Flagget '+v.score+'%';}catch(e){if(Number(e.retryAfter||0)<=0)updateValidation(row,null,e.message);btn.textContent='Valider igen';}finally{btn.disabled=false;}}));
document.querySelectorAll('.image-url-form').forEach(form=>form.addEventListener('submit',async e=>{e.preventDefault();const row=form.closest('tr');const btn=form.querySelector('button');btn.disabled=true;try{await fetchImage(row.dataset.product,'url',form.url.value,form.supplier_page_url?.value||'');form.url.value='';setTimeout(()=>location.reload(),350);}catch(err){alert(err.message);}finally{btn.disabled=false;}}));
document.getElementById('validateAll')?.addEventListener('click',async e=>{
 const btn=e.currentTarget;btn.disabled=true;const rows=[...document.querySelectorAll('tr[data-has-image="1"]')];let approved=0,flaggedCount=0,errors=0;
 for(let i=0;i<rows.length;i++){
   const name=rows[i].querySelector('strong')?.textContent||'produkt';document.getElementById('progress').textContent=`Validerer ${i+1} af ${rows.length}: ${name}`;
   try{const v=await validateWithRetry(rows[i],4);if(v.score>=80)approved++;else flaggedCount++;}
   catch(err){updateValidation(rows[i],null,err.message);errors++;}
 }
 document.getElementById('progress').textContent=`AI-validering færdig: ${approved} klar til manuel godkendelse, ${flaggedCount} flagget under 80%${errors?', '+errors+' valideringsfejl':''}.`;
 btn.disabled=false;
});

const lightbox=document.getElementById('imageLightbox'),lightboxImg=document.getElementById('imageLightboxImg'),lightboxTitle=document.getElementById('imageLightboxTitle'),lightboxSku=document.getElementById('imageLightboxSku'),lightboxBrand=document.getElementById('imageLightboxBrand'),lightboxValidation=document.getElementById('imageLightboxValidation'),lightboxApproval=document.getElementById('imageLightboxApproval'),lightboxFlag=document.getElementById('imageLightboxFlag'),lightboxSource=document.getElementById('imageLightboxSource'),lightboxUse=document.getElementById('imageLightboxUse'),lightboxApprove=document.getElementById('imageLightboxApprove'),lightboxReject=document.getElementById('imageLightboxReject');let lightboxReturnFocus=null;
function validationText(status,score){const n=score!==''&&score!==undefined&&score!==null?Number(score):null;if(n!==null&&!Number.isNaN(n))return n>=80?'AI-match '+n+'%':'AI FLAGGET '+n+'%';if(status==='error')return 'Valideringsfejl';if(status==='candidate')return 'Kandidat – ikke valgt';return 'Ikke AI-valideret';}
function approvalText(status,validationStatus='',validationScore=''){const score=validationScore!==''?Number(validationScore):null;if(status==='approved')return 'Manuelt godkendt';if(status==='candidate')return 'Kandidat – ikke valgt';if(status==='rejected')return 'Afvist';if(validationStatus==='verified'&&score!==null&&score>=80)return 'Afventer manuel godkendelse';if(score!==null&&score<80)return 'Ikke klar – under 80 %';return 'Afventer AI-validering';}
function openImageLightbox(button){
 lightboxReturnFocus=button;const src=button.dataset.fullImage||button.querySelector('img')?.src||'';const name=button.dataset.productName||'Produktbillede';const validationScore=button.dataset.validationScore||'';const validationStatus=button.dataset.validationStatus||'';const approvalStatus=button.dataset.approvalStatus||'pending';const productId=button.dataset.productId||'';const candidateId=button.dataset.candidateId||'';
 lightboxImg.src=src;lightboxImg.alt=name;lightboxTitle.textContent=name;lightboxSku.textContent=button.dataset.sku||'—';lightboxBrand.textContent=button.dataset.brand||'—';lightboxValidation.textContent=validationText(validationStatus,validationScore);lightboxApproval.textContent=approvalText(approvalStatus,validationStatus,validationScore);
 const sourceVerifiedFlag=true;const flagged=(validationScore!==''&&Number(validationScore)<80)||(validationStatus==='error')||(approvalStatus!=='approved'&&approvalStatus!=='candidate');
 lightboxFlag.hidden=!flagged;lightboxFlag.textContent=flagged?(!sourceVerifiedFlag?'KILDEBEVIS FRA LEVERANDØR MANGLER':(approvalStatus!=='approved'&&approvalStatus!=='candidate'?'IKKE MANUELT GODKENDT':(validationStatus==='candidate'?'FLAGGET KANDIDAT – kræver manuel kontrol':'BILLEDET ER FLAGGET – kræver manuel kontrol'))):'';
 const source=button.dataset.sourceUrl||'';lightboxSource.hidden=!source;if(source)lightboxSource.href=source;else lightboxSource.removeAttribute('href');
 lightboxUse.hidden=true;
 const isCandidate=false;const validationNumber=validationScore!==''?Number(validationScore):null;const readyForManual=validationStatus==='verified'&&validationNumber!==null&&validationNumber>=80;lightboxApprove.hidden=isCandidate||approvalStatus==='approved'||!productId||!readyForManual;lightboxApprove.dataset.productId=productId;
 lightboxReject.hidden=!productId;lightboxReject.dataset.productId=productId;lightboxReject.dataset.candidateId=candidateId;lightboxReject.textContent='Afvis billede';
 lightbox.hidden=false;lightbox.setAttribute('aria-hidden','false');document.body.classList.add('lightbox-open');lightbox.querySelector('.image-lightbox-close')?.focus();
}
function closeImageLightbox(){if(lightbox.hidden)return;lightbox.hidden=true;lightbox.setAttribute('aria-hidden','true');lightboxImg.src='';document.body.classList.remove('lightbox-open');lightboxReturnFocus?.focus();lightboxReturnFocus=null;}
async function approveCurrent(productId,button=null){if(!productId)return;const old=button?.textContent;if(button){button.disabled=true;button.textContent='Godkender…';}try{await postImage(productId,'approve_current');const row=document.getElementById('row-'+productId);if(row){row.querySelector('.approval-status').innerHTML='<span class="badge green">✓ Manuelt godkendt</span>';const preview=row.querySelector('.image-preview-button');if(preview){preview.dataset.approvalStatus='approved';const vs=preview.dataset.validationStatus||'',score=preview.dataset.validationScore!==''?Number(preview.dataset.validationScore):null;if(vs!=='error'&&(score===null||score>=80))row.classList.remove('validation-flagged-row');}}closeImageLightbox();}catch(err){alert(err.message);}finally{if(button){button.disabled=false;button.textContent=old||'Godkend billede';}}}
async function rejectCurrent(productId,button=null){if(!productId||!confirm('Afvis dette billede? Det fjernes fra produktet.'))return;const old=button?.textContent;if(button){button.disabled=true;button.textContent='Afviser…';}try{await postImage(productId,'reject_current');closeImageLightbox();location.reload();}catch(err){alert(err.message);if(button){button.disabled=false;button.textContent=old||'Afvis billede';}}}
document.querySelectorAll('.approve-one').forEach(btn=>btn.addEventListener('click',()=>approveCurrent(btn.closest('tr').dataset.product,btn)));
document.querySelectorAll('.reject-current').forEach(btn=>btn.addEventListener('click',()=>rejectCurrent(btn.closest('tr').dataset.product,btn)));
lightboxApprove?.addEventListener('click',()=>approveCurrent(lightboxApprove.dataset.productId,lightboxApprove));
lightboxReject?.addEventListener('click',()=>rejectCurrent(lightboxReject.dataset.productId,lightboxReject));
document.addEventListener('click',e=>{const button=e.target.closest('.image-preview-button');if(button)openImageLightbox(button);if(e.target.closest('[data-lightbox-close]'))closeImageLightbox();});document.addEventListener('keydown',e=>{if(e.key==='Escape'&&!lightbox.hidden)closeImageLightbox();});
</script>
<?php page_footer();
