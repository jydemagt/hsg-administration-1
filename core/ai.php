<?php
declare(strict_types=1);

require_once __DIR__.'/settings.php';
require_once __DIR__.'/openai.php';
require_once __DIR__.'/groq.php';

function hsg_ai_provider(PDO $pdo): string {
    $provider=strtolower(trim((string)setting_get($pdo,'image_ai_provider','groq')));
    return in_array($provider,['groq','openai'],true)?$provider:'groq';
}

function hsg_ai_provider_label(PDO $pdo): string {
    return hsg_ai_provider($pdo)==='groq'?'Groq (gratis plan)':'OpenAI';
}

function hsg_ai_api_key(PDO $pdo): string {
    return hsg_ai_provider($pdo)==='groq' ? hsg_groq_api_key($pdo) : hsg_openai_api_key($pdo);
}

function hsg_ai_default_model(string $provider): string {
    return $provider==='openai'?'gpt-5.6-luna':'groq/compound-mini';
}

function hsg_ai_model(PDO $pdo): string {
    $provider=hsg_ai_provider($pdo);
    $model=trim((string)setting_get($pdo,'image_ai_model',hsg_ai_default_model($provider)));
    return $model!==''?$model:hsg_ai_default_model($provider);
}

function hsg_ai_image_enabled(PDO $pdo): bool {
    return setting_get($pdo,'image_ai_enabled','0')==='1' && hsg_ai_api_key($pdo)!=='';
}

function hsg_ai_find_product_pages(PDO $pdo,array $product,?string $preferredHost=null): array {
    return hsg_ai_provider($pdo)==='groq'
        ? hsg_groq_find_product_pages($pdo,$product,$preferredHost)
        : hsg_openai_find_product_pages($pdo,$product,$preferredHost);
}


function hsg_ai_image_validation_ready(PDO $pdo): bool {
    return trim(hsg_groq_api_key($pdo))!=='' && function_exists('curl_init');
}

function hsg_ai_validate_product_image(PDO $pdo,array $product,string $absolutePath): array {
    return hsg_groq_validate_product_image($pdo,$product,$absolutePath);
}
