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

$deleteData = get_option('wp_nerve_delete_data_on_uninstall', false);

// WordPress persists boolean true as the scalar value "1". Accept only the
// explicit truthy representation produced by update_option/wp option update;
// every absent, malformed or ambiguous value remains fail-safe and preserves data.
if (! in_array($deleteData, array(true, 1, '1'), true)) {
    return;
}

global $wpdb;

foreach (
    array(
        'wp_nerve_audit_log',
        'wp_nerve_idempotency',
        'wp_nerve_confirmations',
        'wp_nerve_rate_limits',
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
