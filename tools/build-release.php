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

    // Export tracked files using git archive or git ls-files
    $gitArchiveCmd = sprintf('git archive --format=tar HEAD | tar -x -C %s', escapeshellarg($targetDir));
    exec($gitArchiveCmd, $output, $returnCode);

    if ($returnCode !== 0) {
        // Fallback to git ls-files copy if git archive fails
        hsg_build_log("git archive fejlede (kode {$returnCode}), forsøger git ls-files...", 'WARN');
        exec('git ls-files', $files, $lsCode);
        if ($lsCode !== 0 || empty($files)) {
            throw new RuntimeException("Kunne ikke hente Git-sporede filer.");
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

    $testsPassed = 0;
    $totalTests = 5;

    // Test 1: Injected prohibited pdf-cache file
    $dir1 = sys_get_temp_dir() . '/hsg-neg-1-' . bin2hex(random_bytes(4));
    try {
        hsg_build_clean_export($dir1);
        $pDir = $dir1 . '/storage/tmp/pdf-cache';
        if (!is_dir($pDir)) mkdir($pDir, 0775, true);
        file_put_contents($pDir . '/test-untracked.jpg', 'fake-jpg');
        hsg_build_check_prohibited($dir1);
        hsg_build_log("Test 1 FEJLEDE: Prohibited fil blev ikke afvist!", 'ERROR');
    } catch (RuntimeException $e) {
        $testsPassed++;
        hsg_build_log("Test 1 BESTÅET: Prohibited fil afvist (" . $e->getMessage() . ")", 'SUCCESS');
    } finally { hsg_build_rrmdir($dir1); }

    // Test 2: Injected unmanifested file into ZIP
    $dir2 = sys_get_temp_dir() . '/hsg-neg-2-' . bin2hex(random_bytes(4));
    $zip2 = sys_get_temp_dir() . '/hsg-neg-2-' . bin2hex(random_bytes(4)) . '.zip';
    try {
        hsg_build_clean_export($dir2);
        $manifest = hsg_build_generate_manifest($dir2, '10.2.2', 'testcommit');
        // Inject unmanifested extra file AFTER manifest generation
        file_put_contents($dir2 . '/unmanifested_extra.php', '<?php // extra');
        hsg_build_zip($dir2, $zip2);
        hsg_build_validate_zip($zip2, $manifest);
        hsg_build_log("Test 2 FEJLEDE: Unmanifested fil i ZIP blev ikke opdaget!", 'ERROR');
    } catch (RuntimeException $e) {
        $testsPassed++;
        hsg_build_log("Test 2 BESTÅET: Unmanifested fil afvist (" . $e->getMessage() . ")", 'SUCCESS');
    } finally { hsg_build_rrmdir($dir2); @unlink($zip2); }

    // Test 3: Altered file hash mismatch
    $dir3 = sys_get_temp_dir() . '/hsg-neg-3-' . bin2hex(random_bytes(4));
    $zip3 = sys_get_temp_dir() . '/hsg-neg-3-' . bin2hex(random_bytes(4)) . '.zip';
    try {
        hsg_build_clean_export($dir3);
        $manifest = hsg_build_generate_manifest($dir3, '10.2.2', 'testcommit');
        // Alter file contents after manifest generation
        file_put_contents($dir3 . '/app_version.php', '<?php return "99.99.99"; // tampered');
        hsg_build_zip($dir3, $zip3);
        hsg_build_validate_zip($zip3, $manifest);
        hsg_build_log("Test 3 FEJLEDE: Ændret filhash blev ikke opdaget!", 'ERROR');
    } catch (RuntimeException $e) {
        $testsPassed++;
        hsg_build_log("Test 3 BESTÅET: Ændret filhash afvist (" . $e->getMessage() . ")", 'SUCCESS');
    } finally { hsg_build_rrmdir($dir3); @unlink($zip3); }

    // Test 4: Missing hsg-package.json manifest
    $dir4 = sys_get_temp_dir() . '/hsg-neg-4-' . bin2hex(random_bytes(4));
    $zip4 = sys_get_temp_dir() . '/hsg-neg-4-' . bin2hex(random_bytes(4)) . '.zip';
    try {
        hsg_build_clean_export($dir4);
        hsg_build_zip($dir4, $zip4);
        hsg_update_validate_package($zip4, true);
        hsg_build_log("Test 4 FEJLEDE: Manglende manifest blev ikke opdaget!", 'ERROR');
    } catch (RuntimeException $e) {
        $testsPassed++;
        hsg_build_log("Test 4 BESTÅET: Manglende manifest afvist (" . $e->getMessage() . ")", 'SUCCESS');
    } finally { hsg_build_rrmdir($dir4); @unlink($zip4); }

    // Test 5: Injected config.php
    $dir5 = sys_get_temp_dir() . '/hsg-neg-5-' . bin2hex(random_bytes(4));
    try {
        hsg_build_clean_export($dir5);
        file_put_contents($dir5 . '/config.php', '<?php // secret config');
        hsg_build_check_prohibited($dir5);
        hsg_build_log("Test 5 FEJLEDE: Prohibited config.php blev ikke afvist!", 'ERROR');
    } catch (RuntimeException $e) {
        $testsPassed++;
        hsg_build_log("Test 5 BESTÅET: Prohibited config.php afvist (" . $e->getMessage() . ")", 'SUCCESS');
    } finally { hsg_build_rrmdir($dir5); }

    if ($testsPassed === $totalTests) {
        hsg_build_log("ALLE {$totalTests} NEGATIVE TESTS BESTÅET SIKKERT!", 'SUCCESS');
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

$version = '10.2.2';
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

    hsg_build_log("2. Kontrollerer for prohibiterede runtime-stier...", 'INFO');
    hsg_build_check_prohibited($buildDir);

    hsg_build_log("3. Genererer hsg-package.json manifest...", 'INFO');
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
