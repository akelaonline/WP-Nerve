<?php

/**
 * Real WordPress Multisite-specific runtime gate for WPNerve.
 *
 * Run in a fresh WP-CLI process for a Multisite URL after single-site.php has
 * been executed for each selected site in the network.
 *
 * @package WPNerve
 */

declare(strict_types=1);

use WPNerve\Abilities\PluginAbilities;
use WPNerve\Infrastructure\Activator;
use WPNerve\Security\Confirmation\WpdbRepository as ConfirmationRepository;
use WPNerve\Security\Idempotency\WpdbRepository as IdempotencyRepository;
use WPNerve\Security\RateLimit\WpdbRepository as RateLimitRepository;

if (! defined('ABSPATH') || ! is_multisite()) {
    throw new RuntimeException('This gate must run inside a real WordPress Multisite installation.');
}

/** @param mixed $actual */
function wp_nerve_multisite_assert(bool $condition, string $message, mixed $actual = null): void
{
    if (! $condition) {
        $detail = null === $actual ? '' : ' Actual: ' . wp_json_encode($actual);
        throw new RuntimeException('FAIL: ' . $message . $detail);
    }

    fwrite(STDOUT, "PASS: {$message}\n");
}

$superAdmins = get_super_admins();
wp_nerve_multisite_assert(array() !== $superAdmins, 'Multisite has at least one super admin');
$superUser = get_user_by('login', (string) $superAdmins[0]);
wp_nerve_multisite_assert($superUser instanceof WP_User, 'super admin user resolves');
wp_set_current_user((int) $superUser->ID);
wp_nerve_multisite_assert(is_super_admin(), 'runtime actor is a real super admin');
wp_nerve_multisite_assert(Activator::SCHEMA_VERSION === (string) get_option('wp_nerve_schema_version'), 'current site schema is migrated');

$sites = get_sites(array('number' => 10));
wp_nerve_multisite_assert(array() !== $sites, 'network exposes at least one site');

$pluginFile = plugin_basename(WP_NERVE_FILE);
wp_nerve_multisite_assert('' !== $pluginFile, 'WPNerve plugin basename resolves', $pluginFile);

$plugins = new PluginAbilities();
$deactivate = $plugins->deactivatePlugin(array('plugin' => $pluginFile));
wp_nerve_multisite_assert(is_wp_error($deactivate), 'WPNerve cannot deactivate itself through its privileged ability');
wp_nerve_multisite_assert(
    in_array($deactivate->get_error_code(), array('wp_nerve_protected_plugin', 'wp_nerve_network_plugin'), true),
    'self/network deactivation is rejected by a protected-plugin error',
    $deactivate->get_error_code()
);

$delete = $plugins->deletePlugin(array('plugin' => $pluginFile));
wp_nerve_multisite_assert(is_wp_error($delete), 'WPNerve cannot delete itself through its destructive ability');
wp_nerve_multisite_assert(
    in_array($delete->get_error_code(), array('wp_nerve_protected_plugin', 'wp_nerve_network_plugin'), true),
    'self/network deletion is rejected by a protected-plugin error',
    $delete->get_error_code()
);

global $wpdb;
$tables = array(
    IdempotencyRepository::tableName(),
    ConfirmationRepository::tableName(),
    RateLimitRepository::tableName(),
    $wpdb->prefix . 'wp_nerve_oauth_clients',
    $wpdb->prefix . 'wp_nerve_oauth_tokens',
);

foreach ($tables as $table) {
    $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
    wp_nerve_multisite_assert($table === $found, 'Multisite current-blog table exists: ' . $table, $found);
}

fwrite(STDOUT, "WPNERVE_REAL_WORDPRESS_MULTISITE_OK\n");
