<?php
declare(strict_types=1);

function hsg_import_catalog_seed_images(PDO $pdo,bool $force=false): array {
    $manifestFile=__DIR__.'/../seed/catalog-images.json';
    $seedDir=__DIR__.'/../seed/catalog-images';
    if(!is_file($manifestFile)||!is_dir($seedDir)) return ['imported'=>0,'skipped'=>0,'missing'=>0];
    if(function_exists('meta_get') && !$force && meta_get($pdo,'catalog_images_2026_05_imported','0')==='1') return ['imported'=>0,'skipped'=>0,'missing'=>0];
    $rows=json_decode((string)file_get_contents($manifestFile),true);if(!is_array($rows))return ['imported'=>0,'skipped'=>0,'missing'=>0];
    $destDir=__DIR__.'/../uploads/products';if(!is_dir($destDir)&&!mkdir($destDir,0775,true)&&!is_dir($destDir))throw new RuntimeException('Kunne ikke oprette uploads/products til katalogbilleder.');
    $find=$pdo->prepare('SELECT id,sku,image_path,image_approval_status FROM lager_products WHERE sku=? LIMIT 1');
    $update=$pdo->prepare("UPDATE lager_products SET image_path=?,image_source_url=NULL,image_checked_at=NOW(),image_method='manual',image_confidence=NULL,image_ai_note=?,image_validation_score=NULL,image_validation_status=NULL,image_validation_note=NULL,image_validated_at=NULL,image_validation_model=NULL,image_approval_status='approved',image_approved_at=NOW(),image_approved_by_admin=NULL WHERE id=?");
    $imported=0;$skipped=0;$missing=0;
    foreach($rows as $row){
        $src=$seedDir.'/'.basename((string)($row['file']??''));if(!is_file($src)){$missing++;continue;}
        foreach((array)($row['skus']??[]) as $sku){
            $find->execute([(string)$sku]);$product=$find->fetch(PDO::FETCH_ASSOC);if(!$product){$missing++;continue;}
            $existingPath=(string)($product['image_path']??'');$isCatalogExisting=str_contains($existingPath,'-hsg-katalog-maj-2026.jpg');
            if(($product['image_approval_status']??'')==='approved' && $existingPath!=='' && is_file(__DIR__.'/../'.ltrim($existingPath,'/')) && (!$force || !$isCatalogExisting)){$skipped++;continue;}
            $safe=preg_replace('/[^A-Za-z0-9_-]+/','-',(string)$sku)?:'product';$destName=$safe.'-hsg-katalog-maj-2026.jpg';
            if(!copy($src,$destDir.'/'.$destName)){$missing++;continue;}
            $note='Importeret fra HSG Whisky Katalog, opdateret 19. maj 2026: '.substr((string)($row['title']??''),0,350);
            $update->execute(['uploads/products/'.$destName,$note,(int)$product['id']]);$imported++;
        }
    }
    if(function_exists('meta_set'))meta_set($pdo,'catalog_images_2026_05_imported','1');
    return compact('imported','skipped','missing');
}
