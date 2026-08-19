<?php

/**
 * Hard security boundaries for privileged WordPress data surfaces.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Security\Privileged;

final class SurfaceGuard
{
    /** @var array<int, string> */
    private const SAFE_READ_OPTIONS = array(
        'blogname',
        'blogdescription',
        'timezone_string',
        'date_format',
        'time_format',
        'start_of_week',
        'posts_per_page',
        'posts_per_rss',
        'default_category',
        'default_post_format',
        'show_on_front',
        'page_on_front',
        'page_for_posts',
        'thumbnail_size_w',
        'thumbnail_size_h',
        'thumbnail_crop',
        'medium_size_w',
        'medium_size_h',
        'medium_large_size_w',
        'medium_large_size_h',
        'large_size_w',
        'large_size_h',
        'uploads_use_yearmonth_folders',
    );

    /** @var array<int, string> */
    private const SAFE_WRITE_OPTIONS = array(
        'blogname',
        'blogdescription',
        'timezone_string',
        'date_format',
        'time_format',
        'start_of_week',
        'posts_per_page',
        'posts_per_rss',
        'default_category',
        'default_post_format',
        'show_on_front',
        'page_on_front',
        'page_for_posts',
        'thumbnail_size_w',
        'thumbnail_size_h',
        'thumbnail_crop',
        'medium_size_w',
        'medium_size_h',
        'medium_large_size_w',
        'medium_large_size_h',
        'large_size_w',
        'large_size_h',
        'uploads_use_yearmonth_folders',
    );

    /** @var array<int, string> */
    private const ALWAYS_PROTECTED_OPTIONS = array(
        'siteurl',
        'home',
        'admin_email',
        'new_admin_email',
        'users_can_register',
        'default_role',
        'active_plugins',
        'recently_activated',
        'uninstall_plugins',
        'cron',
        'rewrite_rules',
        'wp_user_roles',
        'db_version',
        'initial_db_version',
        'wp_nerve_enabled_risk_classes',
        'wp_nerve_delete_data_on_uninstall',
        'wp_nerve_schema_version',
    );

    public function canReadOption(string $key): bool
    {
        return $this->optionAllowed($key, self::SAFE_READ_OPTIONS, 'wp_nerve_allowed_option_keys');
    }

    public function canWriteOption(string $key): bool
    {
        return $this->optionAllowed($key, self::SAFE_WRITE_OPTIONS, 'wp_nerve_writable_option_keys');
    }

    /** @return array<int, string> */
    public function readableOptionKeys(): array
    {
        return $this->allowedOptionKeys(self::SAFE_READ_OPTIONS, 'wp_nerve_allowed_option_keys');
    }

    public function canReadTransient(string $key): bool
    {
        $key = $this->normalizeKey($key);

        if ('' === $key || $this->looksSensitive($key)) {
            return false;
        }

        /**
         * Filters the exact transient keys WPNerve may disclose.
         *
         * The default is intentionally empty because transient values commonly
         * contain tokens, cached credentials, private API responses, or session
         * material. Callers must opt in key by key.
         *
         * @param array<int, string> $keys Allowed transient keys.
         */
        $keys = apply_filters('wp_nerve_allowed_transient_keys', array());

        return is_array($keys) && in_array($key, $this->normalizeKeys($keys), true);
    }

    public function valueIsSafe(mixed $value, int $depth = 0): bool
    {
        if ($depth > 4) {
            return false;
        }

        if (null === $value || is_bool($value) || is_int($value) || is_float($value)) {
            return true;
        }

        if (is_string($value)) {
            return strlen($value) <= 65536;
        }

        if (! is_array($value) || count($value) > 100) {
            return false;
        }

        foreach ($value as $key => $item) {
            if ((! is_int($key) && ! is_string($key)) || ! $this->valueIsSafe($item, $depth + 1)) {
                return false;
            }
        }

        return true;
    }

    public function redactLog(string $content): string
    {
        $credentialPattern = '/(["\']?(?:password|passwd|pwd|secret|token|api[_-]?key|client[_-]?secret|'
            . 'access[_-]?token|refresh[_-]?token|credential)["\']?\s*[:=]\s*["\']?)[^\s,;"\']+/i';
        $patterns = array(
            '/(Authorization\s*:\s*)(?:Bearer|Basic)\s+[^\s]+/i' => '$1[REDACTED]',
            $credentialPattern => '$1[REDACTED]',
            '#(https?://)[^/\s:@]+:[^@\s/]+@#i' => '$1[REDACTED]@',
        );

        foreach ($patterns as $pattern => $replacement) {
            $content = preg_replace($pattern, $replacement, $content) ?? $content;
        }

        return $content;
    }

    /**
     * @param array<int, string> $defaults
     * @param non-empty-string  $filter
     */
    private function optionAllowed(string $key, array $defaults, string $filter): bool
    {
        $key = $this->normalizeKey($key);

        if ('' === $key || $this->isProtectedOption($key)) {
            return false;
        }

        return in_array($key, $this->allowedOptionKeys($defaults, $filter), true);
    }

    /**
     * @param array<int, string> $defaults
     * @param non-empty-string  $filter
     * @return array<int, string>
     */
    private function allowedOptionKeys(array $defaults, string $filter): array
    {
        /**
         * Filters the exact WordPress option keys WPNerve may access.
         *
         * Core security-sensitive keys remain protected even if added here.
         *
         * @param array<int, string> $keys Allowed option keys.
         */
        $filtered = apply_filters($filter, $defaults);
        $keys     = is_array($filtered) ? $this->normalizeKeys($filtered) : $defaults;

        return array_values(
            array_filter(
                array_unique($keys),
                fn (string $key): bool => ! $this->isProtectedOption($key)
            )
        );
    }

    private function isProtectedOption(string $key): bool
    {
        if (
            in_array($key, self::ALWAYS_PROTECTED_OPTIONS, true)
            || str_starts_with($key, 'wp_nerve_')
            || str_starts_with($key, '_transient_')
            || str_starts_with($key, '_site_transient_')
            || $this->looksSensitive($key)
        ) {
            return true;
        }

        /**
         * Filters additional option keys that must always remain inaccessible.
         *
         * This hook can only add protections; it cannot remove WPNerve's built-in
         * protected keys or sensitive-name patterns.
         *
         * @param array<int, string> $keys Protected option keys.
         */
        $extra = apply_filters('wp_nerve_protected_option_keys', array());

        return is_array($extra) && in_array($key, $this->normalizeKeys($extra), true);
    }

    private function looksSensitive(string $key): bool
    {
        return 1 === preg_match(
            '/(?:password|passwd|secret|token|api[_-]?key|private[_-]?key|client[_-]?secret|credential|license[_-]?key)/i',
            $key
        );
    }

    private function normalizeKey(string $key): string
    {
        return strtolower(trim($key));
    }

    /**
     * @param array<int, mixed> $keys
     * @return array<int, string>
     */
    private function normalizeKeys(array $keys): array
    {
        $normalized = array();

        foreach ($keys as $key) {
            if (! is_string($key)) {
                continue;
            }

            $key = $this->normalizeKey($key);

            if ('' !== $key) {
                $normalized[] = $key;
            }
        }

        return $normalized;
    }
}
