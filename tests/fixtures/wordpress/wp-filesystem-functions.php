<?php

/**
 * Minimal WordPress filesystem helpers used by privileged archive tests.
 *
 * @package WPNerve
 */

declare(strict_types=1);

if (! function_exists('WP_Filesystem')) {
    /**
     * @param mixed $args
     * @param mixed $context
     */
    function WP_Filesystem($args = false, $context = false, bool $allow_relaxed_file_ownership = false): bool
    {
        unset($args, $context, $allow_relaxed_file_ownership);

        $GLOBALS['wp_filesystem'] = new class {
            public function delete(string $file, bool $recursive = false, string|false $type = false): bool
            {
                unset($file, $recursive, $type);

                return true;
            }
        };

        return true;
    }
}

if (! function_exists('trailingslashit')) {
    function trailingslashit(string $value): string
    {
        return untrailingslashit($value) . '/';
    }
}

if (! function_exists('untrailingslashit')) {
    function untrailingslashit(string $value): string
    {
        return rtrim($value, '/\\');
    }
}

if (! function_exists('validate_file')) {
    /**
     * Minimal mirror of the WordPress path-traversal result semantics needed by tests.
     *
     * @param array<int, string> $allowed_files
     */
    function validate_file(string $file, array $allowed_files = array()): int
    {
        unset($allowed_files);

        if (str_contains($file, '../') || str_contains($file, '..\\')) {
            return 1;
        }

        if (1 === preg_match('/^[A-Za-z]:[\\\\\/]/', $file)) {
            return 3;
        }

        return 0;
    }
}

if (! function_exists('wp_delete_file')) {
    function wp_delete_file(string $file): void
    {
        unset($file);
    }
}
