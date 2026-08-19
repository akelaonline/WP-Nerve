<?php

/**
 * WPNerve uninstall routine.
 *
 * Audit records are preserved by default. Site owners must explicitly opt in to
 * destructive cleanup with the wp_nerve_delete_data_on_uninstall option.
 *
 * @package WPNerve
 */

declare(strict_types=1);

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

if (true !== get_option('wp_nerve_delete_data_on_uninstall', false)) {
    return;
}

global $wpdb;

foreach (
    array(
        'wp_nerve_audit_log',
        'wp_nerve_idempotency',
        'wp_nerve_confirmations',
        'wp_nerve_oauth_clients',
        'wp_nerve_oauth_tokens',
    ) as $suffix
) {
    $table = $wpdb->prefix . $suffix;
    $sql   = $wpdb->prepare('DROP TABLE IF EXISTS %i', $table);

    if (is_string($sql)) {
        $wpdb->query($sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared directly above with an identifier placeholder.
    }
}

delete_option('wp_nerve_schema_version');
delete_option('wp_nerve_delete_data_on_uninstall');
