<?php
declare(strict_types=1);

/**
 * Reversible storage for personal access-link tokens.
 * The encryption key lives in storage/data and therefore follows HSG DATA/FULL backups,
 * but is protected from normal self-update packages and direct web access.
 */
function hsg_access_link_key_file(): string {
    return dirname(__DIR__).'/storage/data/access-link.key';
}

function hsg_access_link_key(): string {
    $file=hsg_access_link_key_file();
    $dir=dirname($file);
    if(!is_dir($dir) && !mkdir($dir,0775,true) && !is_dir($dir)) throw new RuntimeException('Kunne ikke oprette sikker datamappe til adgangslinks.');
    if(is_file($file)){
        $raw=trim((string)file_get_contents($file));
        $bin=base64_decode($raw,true);
        if(is_string($bin) && strlen($bin)===32) return $bin;
    }
    $key=random_bytes(32);
    if(file_put_contents($file,base64_encode($key),LOCK_EX)===false) throw new RuntimeException('Kunne ikke oprette krypteringsnøgle til adgangslinks.');
    @chmod($file,0600);
    return $key;
}

function hsg_access_link_encrypt(string $token): string {
    if(!function_exists('openssl_encrypt')) throw new RuntimeException('PHP OpenSSL mangler; fulde adgangslinks kan ikke gemmes sikkert.');
    $iv=random_bytes(12);$tag='';
    $cipher=openssl_encrypt($token,'aes-256-gcm',hsg_access_link_key(),OPENSSL_RAW_DATA,$iv,$tag,'HSG-ACCESS-LINK',16);
    if($cipher===false) throw new RuntimeException('Kunne ikke kryptere adgangslinket.');
    return 'v1.'.base64_encode($iv).'.'.base64_encode($tag).'.'.base64_encode($cipher);
}

function hsg_access_link_decrypt(?string $value): ?string {
    $value=trim((string)$value);if($value==='')return null;
    if(!function_exists('openssl_decrypt'))return null;
    $parts=explode('.',$value);if(count($parts)!==4||$parts[0]!=='v1')return null;
    $iv=base64_decode($parts[1],true);$tag=base64_decode($parts[2],true);$cipher=base64_decode($parts[3],true);
    if(!is_string($iv)||!is_string($tag)||!is_string($cipher))return null;
    $plain=openssl_decrypt($cipher,'aes-256-gcm',hsg_access_link_key(),OPENSSL_RAW_DATA,$iv,$tag,'HSG-ACCESS-LINK');
    return is_string($plain)&&$plain!==''?$plain:null;
}

function hsg_access_link_url(?string $encrypted): ?string {
    $token=hsg_access_link_decrypt($encrypted);return $token===null?null:base_url().'/?k='.$token;
}
