<?php

/**
 * OAuth-specific WordPress runtime helpers for unit tests.
 *
 * @package WPNerve
 */

declare(strict_types=1);

if (! function_exists('wp_parse_url')) {
    /** @return array<string, mixed>|int|string|null|false */
    function wp_parse_url(string $url, int $component = -1): array|int|string|null|false
    {
        return parse_url($url, $component);
    }
}
