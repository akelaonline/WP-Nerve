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

        $zip = new \ZipArchive();
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

            $entries = array();
            $roots   = array();
            $seen    = array();
            $total   = 0;

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

                if ($this->isSymlink($zip, $index)) {
                    return new WP_Error(
                        'wp_nerve_archive_symlink',
                        __('Plugin archives containing symbolic links are not accepted.', 'wp-nerve')
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

                if ($total > self::MAX_UNCOMPRESSED_BYTES) {
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

                $roots[strtolower($root)] = $root;
                $entries[] = $normalized;
            }

            foreach ($roots as $root) {
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
        $files       = array();
        $directories = array();

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
            $root   = rtrim($pluginsDir, '/\\');

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
            wp_delete_file($file);
        }

        $directories = array_keys($directories);
        usort(
            $directories,
            static fn (string $left, string $right): int => strlen($right) <=> strlen($left)
        );

        foreach ($directories as $directory) {
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
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

    private function isSymlink(\ZipArchive $zip, int $index): bool
    {
        $operationsSystem = 0;
        $attributes       = 0;

        if (! $zip->getExternalAttributesIndex($index, $operationsSystem, $attributes, \ZipArchive::FL_UNCHANGED)) {
            return false;
        }

        unset($operationsSystem);

        $unixType = ($attributes >> 16) & 0xF000;

        return 0xA000 === $unixType;
    }
}
