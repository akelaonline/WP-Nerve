<?php

/**
 * Plugin activation and schema installation.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Infrastructure;

use WPNerve\Audit\AuditRepository;
use WPNerve\OAuth\OAuthStore;
use WPNerve\Security\Confirmation\WpdbRepository as ConfirmationRepository;
use WPNerve\Security\Idempotency\WpdbRepository;
use WPNerve\Security\RateLimit\WpdbRepository as RateLimitRepository;

final class Activator
{
    public static function activate(): void
    {
        if (version_compare(PHP_VERSION, '8.1', '<')) {
            deactivate_plugins(plugin_basename(WP_NERVE_FILE));
            wp_die(
                esc_html__('WPNerve requires PHP 8.1 or newer.', 'wp-nerve'),
                esc_html__('Plugin activation failed', 'wp-nerve'),
                array('back_link' => true)
            );
        }

        global $wp_version;

        if (version_compare((string) $wp_version, '6.9', '<')) {
            deactivate_plugins(plugin_basename(WP_NERVE_FILE));
            wp_die(
                esc_html__('WPNerve requires WordPress 6.9 or newer.', 'wp-nerve'),
                esc_html__('Plugin activation failed', 'wp-nerve'),
                array('back_link' => true)
            );
        }

        AuditRepository::installSchema();
        OAuthStore::installSchema();
        WpdbRepository::installSchema();
        ConfirmationRepository::installSchema();
        RateLimitRepository::installSchema();
        update_option('wp_nerve_schema_version', '5', false);
    }
}
