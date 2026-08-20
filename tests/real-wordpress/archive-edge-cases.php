<?php

/**
 * Real WordPress archive edge-case gate for G8.
 *
 * Exercises malformed central-directory input, reduced expansion limits,
 * package-shape rules, Unix special files, Unicode paths and rollback cleanup.
 * Run only on a disposable/staging WordPress installation.
 *
 * @package WPNerve
 */

declare(strict_types=1);

use WPNerve\Security\Privileged\PluginArchiveInspector;

if (! defined('ABSPATH') || ! defined('WP_NERVE_VERSION')) {
    throw new RuntimeException('This gate must run inside WordPress with WPNerve active.');
}

if (! class_exists(ZipArchive::class)) {
    throw new RuntimeException('G8 archive edge-case evidence requires PHP ZipArchive.');
}

/** @param mixed $actual */
function wp_nerve_archive_edge_assert(bool $condition, string $message, mixed $actual = null): void
{
    if (! $condition) {
        $detail = null === $actual ? '' : ' Actual: ' . wp_json_encode($actual);
        throw new RuntimeException('FAIL: ' . $message . $detail);
    }

    fwrite(STDOUT, "PASS: {$message}\n");
}

/** @param array<string, string> $entries */
function wp_nerve_archive_edge_zip(array $entries, ?array $special = null): string
{
    $base = tempnam(sys_get_temp_dir(), 'wpnerve-edge-');
    if (false === $base) {
        throw new RuntimeException('Unable to allocate ZIP fixture.');
    }
    unlink($base);
    $path = $base . '.zip';

    $zip = new ZipArchive();
    if (true !== $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE)) {
        throw new RuntimeException('Unable to create ZIP fixture.');
    }

    foreach ($entries as $name => $content) {
        if (! $zip->addFromString($name, $content)) {
            $zip->close();
            throw new RuntimeException('Unable to add ZIP fixture entry: ' . $name);
        }
    }

    if (null !== $special) {
        $name = (string) ($special['name'] ?? '');
        $mode = (int) ($special['mode'] ?? 0);
        if (! $zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, $mode << 16)) {
            $zip->close();
            throw new RuntimeException('Unable to set ZIP fixture Unix attributes.');
        }
    }

    if (! $zip->close()) {
        throw new RuntimeException('Unable to close ZIP fixture.');
    }

    return $path;
}

/** @param array<int, string> $paths */
function wp_nerve_archive_edge_cleanup(array $paths): void
{
    foreach (array_reverse($paths) as $path) {
        if (is_file($path) || is_link($path)) {
            unlink($path);
        } elseif (is_dir($path)) {
            rmdir($path);
        }
    }
}

$inspector  = new PluginArchiveInspector();
$pluginsDir = defined('WP_PLUGIN_DIR') ? WP_PLUGIN_DIR : WP_CONTENT_DIR . '/plugins';
$runId      = bin2hex(random_bytes(6));
$fixtures   = array();

try {
    $valid = wp_nerve_archive_edge_zip(
        array(
            'g8-edge-' . $runId . '/plugin.php' => '<?php /* Plugin Name: G8 Edge */',
            'g8-edge-' . $runId . '/documentación.md' => 'unicode filename',
        )
    );
    $fixtures[] = $valid;
    $result = $inspector->inspect($valid, $pluginsDir);
    wp_nerve_archive_edge_assert(is_array($result), 'Unicode filename inside one valid plugin root is accepted', $result);

    $bits = file_get_contents($valid);
    if (! is_string($bits)) {
        throw new RuntimeException('Unable to read valid ZIP fixture.');
    }
    $eocd = strrpos($bits, "PK\x05\x06");
    if (false === $eocd) {
        throw new RuntimeException('Unable to locate ZIP end-of-central-directory signature.');
    }
    $corruptBits = substr_replace($bits, 'BAD!', $eocd, 4);
    $corrupt = tempnam(sys_get_temp_dir(), 'wpnerve-edge-corrupt-');
    if (false === $corrupt) {
        throw new RuntimeException('Unable to allocate malformed ZIP fixture.');
    }
    file_put_contents($corrupt, $corruptBits);
    $fixtures[] = $corrupt;
    $result = $inspector->inspect($corrupt, $pluginsDir);
    wp_nerve_archive_edge_assert(is_wp_error($result), 'malformed central-directory ZIP is rejected');
    wp_nerve_archive_edge_assert('wp_nerve_invalid_archive' === $result->get_error_code(), 'malformed ZIP uses stable invalid-archive error');

    $multiRoot = wp_nerve_archive_edge_zip(
        array(
            'g8-a-' . $runId . '/a.php' => '<?php /* Plugin Name: A */',
            'g8-b-' . $runId . '/b.php' => '<?php /* Plugin Name: B */',
        )
    );
    $fixtures[] = $multiRoot;
    $result = $inspector->inspect($multiRoot, $pluginsDir);
    wp_nerve_archive_edge_assert(is_wp_error($result), 'multi-root plugin ZIP is rejected');
    wp_nerve_archive_edge_assert('wp_nerve_archive_root_count' === $result->get_error_code(), 'multi-root rejection uses stable error code');

    $noPhp = wp_nerve_archive_edge_zip(array('g8-doc-' . $runId . '/readme.txt' => 'not a plugin'));
    $fixtures[] = $noPhp;
    $result = $inspector->inspect($noPhp, $pluginsDir);
    wp_nerve_archive_edge_assert(is_wp_error($result), 'ZIP without a PHP plugin file is rejected');
    wp_nerve_archive_edge_assert('wp_nerve_archive_no_plugin_file' === $result->get_error_code(), 'missing-plugin-file rejection uses stable error code');

    $fifoName = 'g8-special-' . $runId . '/pipe';
    $special = wp_nerve_archive_edge_zip(
        array(
            'g8-special-' . $runId . '/plugin.php' => '<?php /* Plugin Name: G8 Special */',
            $fifoName => '',
        ),
        array('name' => $fifoName, 'mode' => 0010000 | 0600)
    );
    $fixtures[] = $special;
    $result = $inspector->inspect($special, $pluginsDir);
    wp_nerve_archive_edge_assert(is_wp_error($result), 'Unix FIFO/special-file ZIP entry is rejected');
    wp_nerve_archive_edge_assert('wp_nerve_archive_special_file' === $result->get_error_code(), 'special-file rejection uses stable error code');

    add_filter('wp_nerve_max_plugin_uncompressed_bytes', static fn (): int => 1024);
    $expanded = wp_nerve_archive_edge_zip(
        array(
            'g8-expanded-' . $runId . '/plugin.php' => '<?php /* Plugin Name: G8 Expanded */' . str_repeat('A', 4096),
        )
    );
    $fixtures[] = $expanded;
    $result = $inspector->inspect($expanded, $pluginsDir);
    wp_nerve_archive_edge_assert(is_wp_error($result), 'high-expansion package is rejected at the configured lower evidence ceiling');
    wp_nerve_archive_edge_assert('wp_nerve_archive_expansion_limit' === $result->get_error_code(), 'expansion rejection uses stable error code');
    remove_all_filters('wp_nerve_max_plugin_uncompressed_bytes');

    // Exercise rollback against the real WordPress filesystem transport without
    // relying on an unsafe arbitrary path. The root is unique and inspection is
    // completed before simulating partial extraction inside WP_PLUGIN_DIR.
    require_once ABSPATH . 'wp-admin/includes/file.php';
    wp_nerve_archive_edge_assert(WP_Filesystem(), 'WordPress filesystem transport initializes for rollback evidence');

    $rollbackRoot = 'g8-rollback-' . $runId;
    $rollbackZip = wp_nerve_archive_edge_zip(
        array(
            $rollbackRoot . '/plugin.php' => '<?php /* Plugin Name: G8 Rollback */',
            $rollbackRoot . '/nested/file.txt' => 'partial extraction fixture',
        )
    );
    $fixtures[] = $rollbackZip;
    $inspection = $inspector->inspect($rollbackZip, $pluginsDir);
    wp_nerve_archive_edge_assert(is_array($inspection), 'rollback fixture passes preflight before simulated partial extraction', $inspection);

    $rootPath = trailingslashit($pluginsDir) . $rollbackRoot;
    $nestedPath = $rootPath . '/nested';
    $filePath = $nestedPath . '/file.txt';
    $pluginPath = $rootPath . '/plugin.php';
    wp_mkdir_p($nestedPath);
    file_put_contents($pluginPath, '<?php /* partial */');
    file_put_contents($filePath, 'partial');

    $inspector->rollback($inspection['entries'], $pluginsDir);
    wp_nerve_archive_edge_assert(! file_exists($pluginPath), 'rollback removes partially extracted plugin file');
    wp_nerve_archive_edge_assert(! file_exists($filePath), 'rollback removes partially extracted nested file');
    wp_nerve_archive_edge_assert(! is_dir($rootPath), 'rollback removes empty package directories');

    fwrite(STDOUT, "WPNERVE_ARCHIVE_EDGE_CASES_OK\n");
} finally {
    remove_all_filters('wp_nerve_max_plugin_uncompressed_bytes');
    wp_nerve_archive_edge_cleanup($fixtures);

    $rollbackRoot = trailingslashit($pluginsDir) . 'g8-rollback-' . $runId;
    if (is_dir($rollbackRoot . '/nested')) {
        @rmdir($rollbackRoot . '/nested');
    }
    if (is_dir($rollbackRoot)) {
        @rmdir($rollbackRoot);
    }
}
