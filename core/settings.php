<?php
declare(strict_types=1);

function &hsg_settings_cache_ref(): array {
    static $cache = [];
    return $cache;
}

function setting_get(PDO $pdo, string $key, ?string $default = null): ?string {
    $cache = &hsg_settings_cache_ref();
    if (array_key_exists($key, $cache)) return $cache[$key];
    if (!db_table_exists($pdo, 'hsg_settings')) return $default;
    $st=$pdo->prepare('SELECT setting_value FROM hsg_settings WHERE setting_key=?');
    $st->execute([$key]); $v=$st->fetchColumn();
    $res = $v===false ? $default : (string)$v;
    return $cache[$key] = $res;
}

function setting_set(PDO $pdo, string $key, string $value): void {
    $pdo->prepare('INSERT INTO hsg_settings(setting_key,setting_value,updated_at) VALUES(?,?,NOW()) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_at=NOW()')->execute([$key,$value]);
    $cache = &hsg_settings_cache_ref();
    $cache[$key] = $value;
}
