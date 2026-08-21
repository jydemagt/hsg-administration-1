<?php
declare(strict_types=1);

/**
 * HSG module registry.
 * A module consists of modules/<id>/module.php and may additionally contain
 * migrations.php and hooks.php. This keeps new business areas isolated from
 * the platform core and the existing inventory data model.
 */
function hsg_module_manifests(): array {
    static $modules = null;
    if ($modules !== null) return $modules;

    $modules = [];
    foreach (glob(__DIR__ . '/../modules/*/module.php') ?: [] as $file) {
        $manifest = require $file;
        if (!is_array($manifest) || empty($manifest['id'])) continue;
        $id = preg_replace('/[^a-z0-9_\-]/i', '', (string)$manifest['id']);
        if ($id === '') continue;
        $manifest['id'] = $id;
        $manifest['version'] = (string)($manifest['version'] ?? '1.0.0');
        $manifest['enabled'] = $manifest['enabled'] ?? true;
        $manifest['core'] = (bool)($manifest['core'] ?? false);
        $manifest['sort'] = (int)($manifest['sort'] ?? 100);
        $manifest['description'] = (string)($manifest['description'] ?? '');
        $modules[$id] = $manifest;
    }
    uasort($modules, static fn(array $a, array $b): int => [$a['sort'], $a['name'] ?? ''] <=> [$b['sort'], $b['name'] ?? '']);
    return $modules;
}

function hsg_module_state(PDO $pdo): array {
    $out = [];
    if (!db_table_exists($pdo, 'hsg_module_versions')) return $out;
    $hasEnabled = db_column_exists($pdo, 'hsg_module_versions', 'enabled');
    $hasCore = db_column_exists($pdo, 'hsg_module_versions', 'is_core');
    $cols = 'module_id,version,updated_at'.($hasEnabled?',enabled':'').($hasCore?',is_core':'');
    foreach ($pdo->query('SELECT '.$cols.' FROM hsg_module_versions')->fetchAll() as $r) {
        if (!$hasEnabled) $r['enabled'] = 1;
        if (!$hasCore) $r['is_core'] = 0;
        $out[$r['module_id']] = $r;
    }
    return $out;
}

function hsg_module_is_enabled(string $moduleId): bool {
    $manifest = hsg_module_manifests()[$moduleId] ?? null;
    if (!$manifest || empty($manifest['enabled'])) return false;
    if (!isset($GLOBALS['pdo']) || !($GLOBALS['pdo'] instanceof PDO)) return true;
    $pdo = $GLOBALS['pdo'];
    if (!db_table_exists($pdo, 'hsg_module_versions') || !db_column_exists($pdo, 'hsg_module_versions', 'enabled')) return true;
    $st = $pdo->prepare('SELECT enabled FROM hsg_module_versions WHERE module_id=?');
    $st->execute([$moduleId]);
    $value = $st->fetchColumn();
    return $value === false ? true : (bool)$value;
}


function require_module_enabled(string $moduleId): void {
    if (!hsg_module_is_enabled($moduleId)) {
        http_response_code(404);
        exit('Modulet er deaktiveret.');
    }
}

function hsg_visible_modules(): array {
    return array_filter(hsg_module_manifests(), static function(array $m): bool {
        if (empty($m['nav']) || !hsg_module_is_enabled((string)$m['id'])) return false;
        $id=(string)$m['id'];
        if(is_link_user()){
            if(empty($m['link_access'])) return false;
            if(!hsg_link_can_view_module($id)) return false;
        }
        $cap = (string)($m['capability'] ?? '');
        return $cap === '' || can($cap);
    });
}

function hsg_link_accessible_modules(): array {
    return array_filter(hsg_module_manifests(), static fn(array $m): bool => !empty($m['link_access']) && hsg_module_is_enabled((string)$m['id']));
}

function hsg_module_versions(PDO $pdo): array {
    return hsg_module_state($pdo);
}

function hsg_module_db_version(PDO $pdo, string $moduleId): string {
    if (!db_table_exists($pdo, 'hsg_module_versions')) return '0.0.0';
    $st=$pdo->prepare('SELECT version FROM hsg_module_versions WHERE module_id=?');
    $st->execute([$moduleId]); $v=$st->fetchColumn();
    return $v===false ? '0.0.0' : (string)$v;
}

function hsg_set_module_db_version(PDO $pdo, string $moduleId, string $version, ?bool $enabled = null, ?bool $isCore = null): void {
    $manifest = hsg_module_manifests()[$moduleId] ?? [];
    $enabled ??= (bool)($manifest['enabled'] ?? true);
    $isCore ??= (bool)($manifest['core'] ?? false);
    $pdo->prepare('INSERT INTO hsg_module_versions(module_id,version,enabled,is_core,updated_at) VALUES(?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE version=VALUES(version),is_core=VALUES(is_core),updated_at=NOW()')
        ->execute([$moduleId,$version,$enabled?1:0,$isCore?1:0]);
}

function hsg_set_module_enabled(PDO $pdo, string $moduleId, bool $enabled): void {
    $manifest = hsg_module_manifests()[$moduleId] ?? null;
    if (!$manifest) throw new RuntimeException('Ukendt modul.');
    if (!empty($manifest['core']) && !$enabled) throw new RuntimeException('Kernemoduler kan ikke deaktiveres.');
    $current = hsg_module_db_version($pdo, $moduleId);
    if ($current === '0.0.0') $current = (string)($manifest['version'] ?? '1.0.0');
    $pdo->prepare('INSERT INTO hsg_module_versions(module_id,version,enabled,is_core,updated_at) VALUES(?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),is_core=VALUES(is_core),updated_at=NOW()')
        ->execute([$moduleId,$current,$enabled?1:0,!empty($manifest['core'])?1:0]);
}

function hsg_run_module_migrations(PDO $pdo): void {
    if (!db_table_exists($pdo, 'hsg_module_versions')) return;
    foreach (hsg_module_manifests() as $id => $manifest) {
        $target=(string)($manifest['version']??'1.0.0');
        $current=hsg_module_db_version($pdo,$id);
        $migrationFile=__DIR__.'/../modules/'.$id.'/migrations.php';
        $migrations=[];
        if (is_file($migrationFile)) {
            $loaded=require $migrationFile;
            if (is_array($loaded)) $migrations=$loaded;
        }
        uksort($migrations,'version_compare');
        foreach($migrations as $version=>$migration){
            $version=(string)$version;
            if(version_compare($version,$current,'<=') || version_compare($version,$target,'>')) continue;
            if(!is_callable($migration)) throw new RuntimeException('Ugyldig migration i modul '.$id.' '.$version);
            $pdo->beginTransaction();
            try {
                $migration($pdo);
                hsg_set_module_db_version($pdo,$id,$version,null,(bool)($manifest['core']??false));
                if ($pdo->inTransaction()) $pdo->commit();
                $current=$version;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
        }
        if(version_compare($current,$target,'<')) hsg_set_module_db_version($pdo,$id,$target,null,(bool)($manifest['core']??false));
        elseif ($current !== '0.0.0') hsg_set_module_db_version($pdo,$id,$current,null,(bool)($manifest['core']??false));
    }
}

function hsg_load_module_hooks(): void {
    foreach(hsg_module_manifests() as $id=>$manifest){
        if(!hsg_module_is_enabled($id)) continue;
        $file=__DIR__.'/../modules/'.$id.'/hooks.php';
        if(is_file($file)) require_once $file;
    }
}
