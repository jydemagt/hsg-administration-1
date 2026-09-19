<?php
declare(strict_types=1);

/**
 * HSG Administration Release Builder
 *
 * Builds production release ZIP archives directly from clean Git tracked files.
 * Validates against prohibited runtime files and verifies package integrity.
 *
 * Usage:
 *   php tools/build-release.php [version] [--output=path.zip] [--test-negative]
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "Dette script kan kun afvikles fra CLI.\n");
    exit(1);
}

define('HSG_ROOT', dirname(__DIR__));

// Prohibited runtime files/directories that must NEVER be included in a release ZIP
const PROHIBITED_PATHS = [
    'config.php',
    'config.local.php',
    '.env',
    'uploads/',
    'storage/tmp/',
    'storage/data/',
    'storage/backups/',
    '.git/',
    '*.log',
];

function hsg_build_log(string $msg, string $type = 'INFO'): void {
    $prefix = match ($type) {
        'ERROR' => "\033[31m[FEJL]\033[0m ",
        'SUCCESS' => "\033[32m[OK]\033[0m ",
        'WARN' => "\033[33m[ADVARSEL]\033[0m ",
        default => "\033[34m[INFO]\033[0m ",
    };
    echo $prefix . $msg . "\n";
}

function hsg_build_rrmdir(string $dir): void {
    if (!is_dir($dir)) return;
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        if ($item->isDir()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }
    @rmdir($dir);
}

function hsg_build_clean_export(string $targetDir): void {
    if (is_dir($targetDir)) {
        hsg_build_rrmdir($targetDir);
    }
    if (!mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
        throw new RuntimeException("Kunne ikke oprette midlertidig build-mappe: {$targetDir}");
    }

    // Export tracked files using git ls-files to capture current tracked source tree cleanly
    exec('git ls-files', $files, $lsCode);
    if ($lsCode !== 0 || empty($files)) {
        // Fallback to git archive HEAD if git ls-files fails
        $gitArchiveCmd = sprintf('git archive --format=tar HEAD | tar -x -C %s', escapeshellarg($targetDir));
        exec($gitArchiveCmd, $output, $returnCode);
        if ($returnCode !== 0) {
            throw new RuntimeException("Kunne ikke hente Git-sporede filer.");
        }
        return;
    }

    foreach ($files as $file) {
        $file = trim($file);
        if ($file === '') continue;
        $src = HSG_ROOT . '/' . $file;
        $dst = $targetDir . '/' . $file;
        if (!is_file($src)) continue;
        $dstDir = dirname($dst);
        if (!is_dir($dstDir)) {
            mkdir($dstDir, 0775, true);
        }
        copy($src, $dst);
    }
}

function hsg_build_check_version_consistency(string $buildDir, string $targetVersion): void {
    $appVersionFile = $buildDir . '/app_version.php';
    if (!file_exists($appVersionFile)) {
        throw new RuntimeException("VERSION CONSISTENCY FEJLEDE: app_version.php mangler i source tree.");
    }

    $appVer = trim((string)(require $appVersionFile));
    if ($appVer !== $targetVersion) {
        throw new RuntimeException("VERSION CONSISTENCY FEJLEDE: Target release version '{$targetVersion}' matcher ikke app_version.php ('{$appVer}').");
    }

    $packageJsonFile = $buildDir . '/hsg-package.json';
    if (file_exists($packageJsonFile)) {
        $jsonRaw = file_get_contents($packageJsonFile);
        if ($jsonRaw !== false) {
            $data = json_decode($jsonRaw, true);
            $manifestVer = trim((string)($data['version'] ?? ''));
            if ($manifestVer !== '' && $manifestVer !== $targetVersion) {
                throw new RuntimeException("VERSION CONSISTENCY FEJLEDE: Target release version '{$targetVersion}' matcher ikke eksisterende hsg-package.json version ('{$manifestVer}').");
            }
        }
    }
}

function hsg_build_filter_production_source(string $buildDir): void {
    // Repository, CI, and dev-only items that must be purged before manifest generation
    $devExclusions = [
        '.github',
        '.git',
        '.gitignore',
        '.gitattributes',
    ];

    foreach ($devExclusions as $item) {
        $path = $buildDir . '/' . $item;
        if (is_dir($path)) {
            hsg_build_rrmdir($path);
        } elseif (is_file($path)) {
            @unlink($path);
        }
    }
}

function hsg_build_check_prohibited(string $buildDir): void {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($buildDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    $foundProhibited = [];

    foreach ($iterator as $item) {
        $relPath = ltrim(str_replace('\\', '/', substr($item->getPathname(), strlen($buildDir))), '/');
        if ($relPath === '') continue;

        // Check against prohibited relative paths
        if ($relPath === 'config.php' || $relPath === 'config.local.php' || $relPath === '.env') {
            $foundProhibited[] = $relPath;
            continue;
        }

        foreach (['uploads/', 'storage/backups/', 'storage/tmp/', 'storage/data/', '.git/'] as $prefix) {
            if (str_starts_with($relPath, $prefix)) {
                $foundProhibited[] = $relPath;
                break;
            }
        }

        if (str_ends_with($relPath, '.log')) {
            $foundProhibited[] = $relPath;
        }
    }

    if (!empty($foundProhibited)) {
        $list = implode(', ', array_unique($foundProhibited));
        throw new RuntimeException("RELEASE BUILD FEJLEDE: Prohibited runtime file(s) found in clean export tree: {$list}");
    }
}

function hsg_build_generate_manifest(string $buildDir, string $version, string $commitHash): array {
    $filesMap = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($buildDir, FilesystemIterator::SKIP_DOTS)
    );

    $ignoredMeta = ['.DS_Store'];

    foreach ($iterator as $item) {
        if (!$item->isFile()) continue;
        $relPath = ltrim(str_replace('\\', '/', substr($item->getPathname(), strlen($buildDir))), '/');
        if ($relPath === 'hsg-package.json' || in_array($relPath, $ignoredMeta, true)) continue;

        $content = file_get_contents($item->getPathname());
        if ($content === false) {
            throw new RuntimeException("Kunne ikke læse fil til manifest: {$relPath}");
        }

        // SHA-256 hash calculation
        $hash = hash('sha256', $content);
        $filesMap[$relPath] = $hash;
    }

    ksort($filesMap);

    $manifest = [
        'product' => 'HSG Administration',
        'name' => 'hsg-administration',
        'package_type' => 'distribution',
        'version' => $version,
        'min_php' => '8.1.0',
        'required_extensions' => ['pdo', 'pdo_mysql', 'zip', 'json', 'gd', 'curl'],
        'required_files' => [
            'app_version.php',
            'auth.php',
            'migrations.php',
            'core/modules.php'
        ],
        'release_notes' => "HSG Administration version {$version} release package.",
        'commit' => $commitHash,
        'created_at' => date('Y-m-d H:i:s'),
        'manifest' => $filesMap,
        'files' => $filesMap,
    ];

    $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException("Kunne ikke serialisere hsg-package.json");
    }

    file_put_contents($buildDir . '/hsg-package.json', $json);
    return $manifest;
}

function hsg_build_zip(string $buildDir, string $zipPath): void {
    if (file_exists($zipPath)) {
        @unlink($zipPath);
    }

    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException("Kunne ikke oprette ZIP-fil: {$zipPath}");
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($buildDir, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $item) {
        if (!$item->isFile()) continue;
        $relPath = ltrim(str_replace('\\', '/', substr($item->getPathname(), strlen($buildDir))), '/');
        $zip->addFile($item->getPathname(), $relPath);
    }

    $zip->close();
}

function hsg_build_validate_zip(string $zipPath, array $manifest): void {
    require_once HSG_ROOT . '/functions.php';
    require_once HSG_ROOT . '/core/updater.php';

    // Validate using the updater's validation logic
    $info = hsg_update_validate_package($zipPath, true);

    // Verify file count & match exact allow-list
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        throw new RuntimeException("Validation: Kunne ikke genåbne ZIP-pakken {$zipPath}");
    }

    $zipEntries = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        if (!$stat) continue;
        $name = hsg_update_normalize_entry((string)$stat['name']);
        if ($name === '' || str_ends_with((string)$stat['name'], '/')) continue;
        $zipEntries[$name] = true;
    }
    $zip->close();

    $expectedFiles = array_keys($manifest['manifest']);
    $expectedFiles[] = 'hsg-package.json';

    sort($expectedFiles);
    $actualFiles = array_keys($zipEntries);
    sort($actualFiles);

    $diffExtra = array_diff($actualFiles, $expectedFiles);
    $diffMissing = array_diff($expectedFiles, $actualFiles);

    if (!empty($diffExtra)) {
        throw new RuntimeException("RELEASE VALIDATION FEJLEDE: ZIP indeholder uventede filer: " . implode(', ', $diffExtra));
    }
    if (!empty($diffMissing)) {
        throw new RuntimeException("RELEASE VALIDATION FEJLEDE: ZIP mangler forventede filer: " . implode(', ', $diffMissing));
    }

    hsg_build_log("Release ZIP valideret og godkendt uden afvigelser ({$info['file_count']} filer).", 'SUCCESS');
}

function hsg_run_negative_test(): bool {
    hsg_build_log("Afvikler negativ test suite (prohibited stier, unmanifested filer, manglende/ændrede filer)...", 'INFO');
    require_once HSG_ROOT . '/functions.php';
    require_once HSG_ROOT . '/core/updater.php';

    $currentVersion = trim((string)(require HSG_ROOT . '/app_version.php'));
    $testsPassed = 0;
    $totalTests = 7;

    // Test A: Injected prohibited pdf-cache file
    $dirA = sys_get_temp_dir() . '/hsg-neg-A-' . bin2hex(random_bytes(4));
    try {
        hsg_build_clean_export($dirA);
        $pDir = $dirA . '/storage/tmp/pdf-cache';
        if (!is_dir($pDir)) mkdir($pDir, 0775, true);
        file_put_contents($pDir . '/test-untracked.jpg', 'fake-jpg');
        hsg_build_check_prohibited($dirA);
        hsg_build_log("Test A FEJLEDE: Prohibited pdf-cache blev ikke afvist!", 'ERROR');
    } catch (RuntimeException $e) {
        $testsPassed++;
        hsg_build_log("Test A BESTÅET: Prohibited pdf-cache afvist (" . $e->getMessage() . ")", 'SUCCESS');
    } finally { hsg_build_rrmdir($dirA); }

    // Test B: Injected unmanifested extra file
    $dirB = sys_get_temp_dir() . '/hsg-neg-B-' . bin2hex(random_bytes(4));
    $zipB = sys_get_temp_dir() . '/hsg-neg-B-' . bin2hex(random_bytes(4)) . '.zip';
    try {
        hsg_build_clean_export($dirB);
        $manifest = hsg_build_generate_manifest($dirB, $currentVersion, 'testcommit');
        file_put_contents($dirB . '/unmanifested_extra.php', '<?php // extra');
        hsg_build_zip($dirB, $zipB);
        hsg_build_validate_zip($zipB, $manifest);
        hsg_build_log("Test B FEJLEDE: Unmanifested fil blev ikke opdaget!", 'ERROR');
    } catch (RuntimeException $e) {
        $testsPassed++;
        hsg_build_log("Test B BESTÅET: Unmanifested fil afvist (" . $e->getMessage() . ")", 'SUCCESS');
    } finally { hsg_build_rrmdir($dirB); @unlink($zipB); }

    // Test C: Altered file hash mismatch after manifest
    $dirC = sys_get_temp_dir() . '/hsg-neg-C-' . bin2hex(random_bytes(4));
    $zipC = sys_get_temp_dir() . '/hsg-neg-C-' . bin2hex(random_bytes(4)) . '.zip';
    try {
        hsg_build_clean_export($dirC);
        $manifest = hsg_build_generate_manifest($dirC, $currentVersion, 'testcommit');
        file_put_contents($dirC . '/app_version.php', '<?php return "99.99.99"; // tampered');
        hsg_build_zip($dirC, $zipC);
        hsg_build_validate_zip($zipC, $manifest);
        hsg_build_log("Test C FEJLEDE: Ændret filhash blev ikke opdaget!", 'ERROR');
    } catch (RuntimeException $e) {
        $testsPassed++;
        hsg_build_log("Test C BESTÅET: Ændret filhash afvist (" . $e->getMessage() . ")", 'SUCCESS');
    } finally { hsg_build_rrmdir($dirC); @unlink($zipC); }

    // Test D: Missing hsg-package.json manifest
    $dirD = sys_get_temp_dir() . '/hsg-neg-D-' . bin2hex(random_bytes(4));
    $zipD = sys_get_temp_dir() . '/hsg-neg-D-' . bin2hex(random_bytes(4)) . '.zip';
    try {
        hsg_build_clean_export($dirD);
        if (file_exists($dirD . '/hsg-package.json')) {
            @unlink($dirD . '/hsg-package.json');
        }
        hsg_build_zip($dirD, $zipD);
        hsg_update_validate_package($zipD, true);
        hsg_build_log("Test D FEJLEDE: Manglende manifest blev ikke opdaget!", 'ERROR');
    } catch (RuntimeException $e) {
        $testsPassed++;
        hsg_build_log("Test D BESTÅET: Manglende manifest afvist (" . $e->getMessage() . ")", 'SUCCESS');
    } finally { hsg_build_rrmdir($dirD); @unlink($zipD); }

    // Test E: Injected config.php
    $dirE = sys_get_temp_dir() . '/hsg-neg-E-' . bin2hex(random_bytes(4));
    try {
        hsg_build_clean_export($dirE);
        file_put_contents($dirE . '/config.php', '<?php // secret config');
        hsg_build_check_prohibited($dirE);
        hsg_build_log("Test E FEJLEDE: Prohibited config.php blev ikke afvist!", 'ERROR');
    } catch (RuntimeException $e) {
        $testsPassed++;
        hsg_build_log("Test E BESTÅET: Prohibited config.php afvist (" . $e->getMessage() . ")", 'SUCCESS');
    } finally { hsg_build_rrmdir($dirE); }

    // Test F: Version mismatch (Git tag vs app_version.php)
    $dirF = sys_get_temp_dir() . '/hsg-neg-F-' . bin2hex(random_bytes(4));
    try {
        hsg_build_clean_export($dirF);
        file_put_contents($dirF . '/app_version.php', '<?php return "10.2.1";');
        hsg_build_check_version_consistency($dirF, $currentVersion);
        hsg_build_log("Test F FEJLEDE: Version mismatch mellem tag og app_version.php blev ikke opdaget!", 'ERROR');
    } catch (RuntimeException $e) {
        $testsPassed++;
        hsg_build_log("Test F BESTÅET: Version mismatch afvist (" . $e->getMessage() . ")", 'SUCCESS');
    } finally { hsg_build_rrmdir($dirF); }

    // Test G: Manifest version vs ZIP source app_version.php
    $dirG = sys_get_temp_dir() . '/hsg-neg-G-' . bin2hex(random_bytes(4));
    try {
        hsg_build_clean_export($dirG);
        file_put_contents($dirG . '/hsg-package.json', json_encode(['version' => $currentVersion]));
        file_put_contents($dirG . '/app_version.php', '<?php return "10.2.1";');
        hsg_build_check_version_consistency($dirG, $currentVersion);
        hsg_build_log("Test G FEJLEDE: Manifest version vs app_version.php blev ikke afvist!", 'ERROR');
    } catch (RuntimeException $e) {
        $testsPassed++;
        hsg_build_log("Test G BESTÅET: Manifest vs source version mismatch afvist (" . $e->getMessage() . ")", 'SUCCESS');
    } finally { hsg_build_rrmdir($dirG); }

    if ($testsPassed === $totalTests) {
        hsg_build_log("ALLE {$totalTests} NEGATIVE TESTS (A-G) BESTÅET SIKKERT!", 'SUCCESS');
        return true;
    } else {
        hsg_build_log("KUN {$testsPassed} / {$totalTests} NEGATIVE TESTS BESTÅET!", 'ERROR');
        return false;
    }
}

// --- Main CLI Execution ---
$args = array_slice($argv, 1);
$isNegativeTest = in_array('--test-negative', $args, true);

if ($isNegativeTest) {
    $passed = hsg_run_negative_test();
    exit($passed ? 0 : 1);
}

$version = trim((string)(require HSG_ROOT . '/app_version.php'));
$outputPath = null;

foreach ($args as $arg) {
    if (str_starts_with($arg, '--output=')) {
        $outputPath = substr($arg, 9);
    } elseif (!str_starts_with($arg, '--')) {
        $version = ltrim($arg, 'v');
    }
}

if ($outputPath === null || $outputPath === '') {
    $outputPath = HSG_ROOT . "/HSG-Administration-v{$version}.zip";
}

// Get current git commit hash
$commitHash = trim((string)shell_exec('git rev-parse HEAD 2>/dev/null'));
if ($commitHash === '') {
    $commitHash = 'unknown';
}

hsg_build_log("Starter release build for HSG Administration v{$version} (commit: {$commitHash})...");

$buildDir = sys_get_temp_dir() . '/hsg-build-release-' . bin2hex(random_bytes(6));

try {
    hsg_build_log("1. Eksporterer ren source tree fra Git...", 'INFO');
    hsg_build_clean_export($buildDir);

    hsg_build_log("2. Kontrollerer versionskonsistens (Git tag vs app_version.php vs hsg-package.json)...", 'INFO');
    hsg_build_check_version_consistency($buildDir, $version);

    hsg_build_log("3. Fjerner repository-/CI-stier (.github, .gitignore osv)...", 'INFO');
    hsg_build_filter_production_source($buildDir);

    hsg_build_log("4. Kontrollerer for prohibiterede runtime-stier...", 'INFO');
    hsg_build_check_prohibited($buildDir);

    hsg_build_log("5. Genererer hsg-package.json manifest...", 'INFO');
    $manifest = hsg_build_generate_manifest($buildDir, $version, $commitHash);

    hsg_build_log("4. Bygger ZIP-arkiv...", 'INFO');
    hsg_build_zip($buildDir, $outputPath);

    hsg_build_log("5. Validerer den færdige release ZIP mod manifest...", 'INFO');
    hsg_build_validate_zip($outputPath, $manifest);

    hsg_build_log("RELEASE BYGGET OG GODKENDT: {$outputPath}", 'SUCCESS');
} catch (Throwable $e) {
    hsg_build_log("RELEASE BUILD FEJLEDE: " . $e->getMessage(), 'ERROR');
    if (file_exists($outputPath)) {
        @unlink($outputPath);
    }
    exit(1);
} finally {
    hsg_build_rrmdir($buildDir);
}
