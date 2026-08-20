<?php

/**
 * Fail-closed preflight for plugin ZIP archives before extraction.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Security\Privileged;

use WP_Error;

final class PluginArchiveInspector
{
    private const MAX_ENTRIES = 5000;

    private const MAX_ENTRY_NAME_BYTES = 1024;

    private const MAX_UNCOMPRESSED_BYTES = 209715200;

    /**
     * Inspect decoded archive bytes without placing the package in a public uploads directory.
     *
     * @return array{entries: array<int, string>, roots: array<int, string>, uncompressed_bytes: int}|WP_Error
     */
    public function inspectBytes(string $bits, string $pluginsDir): array|WP_Error
    {
        $temporary = tempnam(sys_get_temp_dir(), 'wpnerve-zip-');

        if (false === $temporary) {
            return new WP_Error(
                'wp_nerve_archive_inspection_failed',
                __('WPNerve could not allocate a temporary file for plugin archive inspection.', 'wp-nerve')
            );
        }

        try {
            if (false === file_put_contents($temporary, $bits)) {
                return new WP_Error(
                    'wp_nerve_archive_inspection_failed',
                    __('WPNerve could not stage the plugin archive for inspection.', 'wp-nerve')
                );
            }

            return $this->inspect($temporary, $pluginsDir);
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    /**
     * @return array{entries: array<int, string>, roots: array<int, string>, uncompressed_bytes: int}|WP_Error
     */
    public function inspect(string $archivePath, string $pluginsDir): array|WP_Error
    {
        if (! class_exists(\ZipArchive::class)) {
            return new WP_Error(
                'wp_nerve_zip_inspection_unavailable',
                __('Secure plugin archive inspection requires the PHP Zip extension.', 'wp-nerve')
            );
        }

        $zip    = new \ZipArchive();
        $opened = $zip->open($archivePath, \ZipArchive::CHECKCONS);

        if (true !== $opened) {
            return new WP_Error(
                'wp_nerve_invalid_archive',
                __('The uploaded plugin package is not a structurally valid ZIP archive.', 'wp-nerve')
            );
        }

        try {
            if ($zip->numFiles < 1 || $zip->numFiles > self::MAX_ENTRIES) {
                return new WP_Error(
                    'wp_nerve_archive_entry_limit',
                    __('The plugin archive contains an unsafe number of entries.', 'wp-nerve')
                );
            }

            $entries    = array();
            $roots      = array();
            $seen       = array();
            $total      = 0;
            $hasPhpFile = false;
            $maxExpanded = $this->maxUncompressedBytes();

            for ($index = 0; $index < $zip->numFiles; ++$index) {
                $name = $zip->getNameIndex($index, \ZipArchive::FL_UNCHANGED);

                if (! is_string($name)) {
                    return new WP_Error(
                        'wp_nerve_invalid_archive_entry',
                        __('The plugin archive contains an unreadable entry.', 'wp-nerve')
                    );
                }

                $normalized = $this->normalizeEntryName($name);

                if (null === $normalized) {
                    return new WP_Error(
                        'wp_nerve_unsafe_archive_path',
                        __('The plugin archive contains an unsafe path.', 'wp-nerve')
                    );
                }

                $collisionKey = strtolower(rtrim($normalized, '/'));

                if (isset($seen[$collisionKey])) {
                    return new WP_Error(
                        'wp_nerve_duplicate_archive_path',
                        __('The plugin archive contains duplicate or case-colliding paths.', 'wp-nerve')
                    );
                }

                $seen[$collisionKey] = true;

                $entryType = $this->entryType($zip, $index);

                if ('symlink' === $entryType) {
                    return new WP_Error(
                        'wp_nerve_archive_symlink',
                        __('Plugin archives containing symbolic links are not accepted.', 'wp-nerve')
                    );
                }

                if ('special' === $entryType) {
                    return new WP_Error(
                        'wp_nerve_archive_special_file',
                        __('Plugin archives containing device, socket, FIFO, or other special file entries are not accepted.', 'wp-nerve')
                    );
                }

                $stat = $zip->statIndex($index, \ZipArchive::FL_UNCHANGED);

                if (! is_array($stat) || ! isset($stat['size']) || ! is_numeric($stat['size'])) {
                    return new WP_Error(
                        'wp_nerve_invalid_archive_entry',
                        __('The plugin archive contains an entry with invalid metadata.', 'wp-nerve')
                    );
                }

                $size = max(0, (int) $stat['size']);
                $total += $size;

                if ($total > $maxExpanded) {
                    return new WP_Error(
                        'wp_nerve_archive_expansion_limit',
                        __('The plugin archive expands beyond WPNerve\'s safety limit.', 'wp-nerve')
                    );
                }

                $trimmed = rtrim($normalized, '/');
                $parts   = explode('/', $trimmed);
                $root    = (string) ($parts[0] ?? '');

                if ('' === $root) {
                    return new WP_Error(
                        'wp_nerve_unsafe_archive_path',
                        __('The plugin archive contains an unsafe top-level path.', 'wp-nerve')
                    );
                }

                if (! str_ends_with($normalized, '/') && str_ends_with(strtolower($trimmed), '.php')) {
                    $hasPhpFile = true;
                }

                $roots[strtolower($root)] = $root;
                $entries[] = $normalized;
            }

            if (1 !== count($roots)) {
                return new WP_Error(
                    'wp_nerve_archive_root_count',
                    __('The plugin archive must contain exactly one top-level plugin root.', 'wp-nerve')
                );
            }

            if (! $hasPhpFile) {
                return new WP_Error(
                    'wp_nerve_archive_no_plugin_file',
                    __('The plugin archive does not contain a PHP plugin file.', 'wp-nerve')
                );
            }

            $installedRoots = $this->installedPluginRoots();

            foreach ($roots as $root) {
                if (isset($installedRoots[strtolower($root)])) {
                    return new WP_Error(
                        'wp_nerve_plugin_exists',
                        __('The plugin archive would replace or extend an installed plugin path.', 'wp-nerve')
                    );
                }

                $target = trailingslashit($pluginsDir) . $root;

                if (file_exists($target) || is_link($target)) {
                    return new WP_Error(
                        'wp_nerve_plugin_target_exists',
                        __('The plugin archive would write into an existing plugin path.', 'wp-nerve')
                    );
                }
            }

            return array(
                'entries'            => $entries,
                'roots'              => array_values($roots),
                'uncompressed_bytes' => $total,
            );
        } finally {
            $zip->close();
        }
    }

    /**
     * Best-effort rollback for archive entries that did not exist before extraction.
     *
     * @param array<int, string> $entries
     */
    public function rollback(array $entries, string $pluginsDir): void
    {
        global $wp_filesystem;

        $files       = array();
        $directories = array();
        $root        = rtrim($pluginsDir, '/\\');

        foreach ($entries as $entry) {
            $relative = rtrim($entry, '/');

            if ('' === $relative) {
                continue;
            }

            $target = trailingslashit($pluginsDir) . $relative;

            if (is_file($target) || is_link($target)) {
                $files[] = $target;
            }

            $parent = dirname($target);

            while ($parent !== $root && str_starts_with($parent, $root . DIRECTORY_SEPARATOR)) {
                $directories[$parent] = true;
                $next = dirname($parent);

                if ($next === $parent) {
                    break;
                }

                $parent = $next;
            }

            if (str_ends_with($entry, '/') && is_dir($target)) {
                $directories[$target] = true;
            }
        }

        foreach (array_unique($files) as $file) {
            if (is_object($wp_filesystem) && method_exists($wp_filesystem, 'delete')) {
                $wp_filesystem->delete($file, false, 'f');
            } else {
                wp_delete_file($file);
            }
        }

        $directories = array_keys($directories);
        usort(
            $directories,
            static fn (string $left, string $right): int => strlen($right) <=> strlen($left)
        );

        if (! is_object($wp_filesystem) || ! method_exists($wp_filesystem, 'delete')) {
            return;
        }

        foreach ($directories as $directory) {
            if (is_dir($directory)) {
                $wp_filesystem->delete($directory, false, 'd');
            }
        }
    }

    /** @return array<string, true> */
    private function installedPluginRoots(): array
    {
        if (! function_exists('get_plugins')) {
            return array();
        }

        $roots = array();

        foreach (array_keys(get_plugins()) as $plugin) {
            $plugin = str_replace('\\', '/', (string) $plugin);
            $parts  = explode('/', $plugin);
            $root   = (string) ($parts[0] ?? '');

            if ('' !== $root) {
                $roots[strtolower($root)] = true;
            }
        }

        return $roots;
    }

    private function maxUncompressedBytes(): int
    {
        /**
         * Filters the maximum total uncompressed plugin archive size.
         *
         * The filter may only reduce WPNerve's hard 200 MiB ceiling. This makes
         * expansion-limit behavior reproducible in tests without allowing another
         * plugin to weaken the production boundary.
         *
         * @param int $bytes Maximum total uncompressed bytes.
         */
        $bytes = apply_filters('wp_nerve_max_plugin_uncompressed_bytes', self::MAX_UNCOMPRESSED_BYTES);

        return is_int($bytes) && $bytes > 0
            ? min($bytes, self::MAX_UNCOMPRESSED_BYTES)
            : self::MAX_UNCOMPRESSED_BYTES;
    }

    private function normalizeEntryName(string $name): ?string
    {
        if (
            '' === $name
            || strlen($name) > self::MAX_ENTRY_NAME_BYTES
            || str_contains($name, "\0")
            || 1 === preg_match('/[\x00-\x1F\x7F]/', $name)
        ) {
            return null;
        }

        $name = str_replace('\\', '/', $name);

        if (
            str_starts_with($name, '/')
            || 1 === preg_match('/^[A-Za-z]:\//', $name)
            || str_contains($name, ':')
        ) {
            return null;
        }

        $directory = str_ends_with($name, '/');
        $trimmed   = rtrim($name, '/');

        if ('' === $trimmed || 0 !== validate_file($trimmed)) {
            return null;
        }

        foreach (explode('/', $trimmed) as $segment) {
            if ('' === $segment || '.' === $segment || '..' === $segment) {
                return null;
            }
        }

        return $directory ? $trimmed . '/' : $trimmed;
    }

    /**
     * @return 'regular'|'directory'|'symlink'|'special'|'unknown'
     */
    private function entryType(\ZipArchive $zip, int $index): string
    {
        $operationsSystem = 0;
        $attributes       = 0;

        if (! $zip->getExternalAttributesIndex($index, $operationsSystem, $attributes, \ZipArchive::FL_UNCHANGED)) {
            return 'unknown';
        }

        if (\ZipArchive::OPSYS_UNIX !== $operationsSystem) {
            return 'unknown';
        }

        $unixType = ($attributes >> 16) & 0xF000;

        return match ($unixType) {
            0xA000 => 'symlink',
            0x8000 => 'regular',
            0x4000 => 'directory',
            0x0000 => 'unknown',
            default => 'special',
        };
    }
}
