<?php

/**
 * Real WordPress Multisite retention-isolation gate for G8.
 *
 * Requires at least two migrated sites in the network. It proves that running
 * WPNerve retention for one blog prefix cannot delete another site's rows.
 * Run only on a disposable/staging Multisite installation.
 *
 * @package WPNerve
 */

declare(strict_types=1);

use WPNerve\Maintenance\RetentionManager;

if (! defined('ABSPATH') || ! defined('WP_NERVE_VERSION') || ! is_multisite()) {
    throw new RuntimeException('This gate must run inside WordPress Multisite with WPNerve active.');
}

/** @param mixed $actual */
function wp_nerve_multisite_retention_assert(bool $condition, string $message, mixed $actual = null): void
{
    if (! $condition) {
        $detail = null === $actual ? '' : ' Actual: ' . wp_json_encode($actual);
        throw new RuntimeException('FAIL: ' . $message . $detail);
    }

    fwrite(STDOUT, "PASS: {$message}\n");
}

/** @return array<string, string> */
function wp_nerve_multisite_retention_tables(string $prefix): array
{
    return array(
        'audit'         => $prefix . 'wp_nerve_audit_log',
        'idempotency'   => $prefix . 'wp_nerve_idempotency',
        'confirmations' => $prefix . 'wp_nerve_confirmations',
        'oauth_clients' => $prefix . 'wp_nerve_oauth_clients',
        'oauth_tokens'  => $prefix . 'wp_nerve_oauth_tokens',
    );
}

/** @param array<string, string> $tables */
function wp_nerve_multisite_retention_seed(array $tables, string $marker): void
{
    global $wpdb;

    $old      = '2000-01-01 00:00:00';
    $clientId = 'g8-ms-' . $marker;

    $wpdb->insert(
        $tables['audit'],
        array(
            'request_id'       => $marker,
            'occurred_at'      => $old,
            'user_id'          => 1,
            'protocol_version' => '2026-07-28',
            'client_name'      => 'g8-multisite-retention',
            'client_version'   => '1',
            'rpc_method'       => 'tools/call',
            'tool_name'        => $marker,
            'risk'             => 'read',
            'outcome'          => 'success',
            'duration_ms'      => 1,
            'error_code'       => '',
        )
    );

    $wpdb->insert(
        $tables['idempotency'],
        array(
            'user_id'         => 1,
            'credential_hash' => hash('sha256', 'credential-' . $marker),
            'tool_name'       => $marker,
            'tool_hash'       => hash('sha256', 'tool-' . $marker),
            'key_hash'        => hash('sha256', 'key-' . $marker),
            'request_hash'    => hash('sha256', 'request-' . $marker),
            'status'          => 'completed',
            'outcome'         => '{}',
            'created_at'      => $old,
            'completed_at'    => $old,
            'expires_at'      => $old,
        )
    );

    $wpdb->insert(
        $tables['confirmations'],
        array(
            'user_id'         => 1,
            'credential_hash' => hash('sha256', 'confirm-credential-' . $marker),
            'tool_name'       => $marker,
            'tool_hash'       => hash('sha256', 'confirm-tool-' . $marker),
            'risk'            => 'privileged',
            'request_hash'    => hash('sha256', 'confirm-request-' . $marker),
            'key_hash'        => hash('sha256', 'confirm-key-' . $marker),
            'token_hash'      => hash('sha256', 'confirm-token-' . $marker),
            'display_code'    => strtoupper(substr(hash('sha256', $marker), 0, 8)),
            'status'          => 'consumed',
            'created_at'      => $old,
            'expires_at'      => $old,
            'decided_at'      => $old,
            'decided_by'      => 1,
            'consumed_at'     => $old,
        )
    );

    $wpdb->insert(
        $tables['oauth_clients'],
        array(
            'client_id'     => $clientId,
            'client_name'   => 'G8 multisite retention',
            'redirect_uris' => '[]',
            'created_at'    => $old,
        )
    );

    $wpdb->insert(
        $tables['oauth_tokens'],
        array(
            'token_hash'          => hash('sha256', 'oauth-' . $marker),
            'token_type'          => 'access',
            'client_id'           => $clientId,
            'user_id'             => 1,
            'expires_at'          => $old,
            'auth_code_challenge' => null,
            'redirect_uri'        => null,
            'created_at'          => $old,
        )
    );
}

/** @param array<string, string> $tables */
function wp_nerve_multisite_retention_count(array $tables, string $marker): array
{
    global $wpdb;

    $clientId = 'g8-ms-' . $marker;

    return array(
        'audit' => (int) $wpdb->get_var(
            $wpdb->prepare('SELECT COUNT(*) FROM %i WHERE request_id = %s', $tables['audit'], $marker)
        ),
        'idempotency' => (int) $wpdb->get_var(
            $wpdb->prepare('SELECT COUNT(*) FROM %i WHERE tool_name = %s', $tables['idempotency'], $marker)
        ),
        'confirmations' => (int) $wpdb->get_var(
            $wpdb->prepare('SELECT COUNT(*) FROM %i WHERE tool_name = %s', $tables['confirmations'], $marker)
        ),
        'oauth_clients' => (int) $wpdb->get_var(
            $wpdb->prepare('SELECT COUNT(*) FROM %i WHERE client_id = %s', $tables['oauth_clients'], $clientId)
        ),
        'oauth_tokens' => (int) $wpdb->get_var(
            $wpdb->prepare('SELECT COUNT(*) FROM %i WHERE client_id = %s', $tables['oauth_tokens'], $clientId)
        ),
    );
}

/** @param array<string, string> $tables */
function wp_nerve_multisite_retention_cleanup_fixture(array $tables, string $marker): void
{
    global $wpdb;

    $clientId = 'g8-ms-' . $marker;
    $wpdb->delete($tables['audit'], array('request_id' => $marker), array('%s'));
    $wpdb->delete($tables['idempotency'], array('tool_name' => $marker), array('%s'));
    $wpdb->delete($tables['confirmations'], array('tool_name' => $marker), array('%s'));
    $wpdb->delete($tables['oauth_tokens'], array('client_id' => $clientId), array('%s'));
    $wpdb->delete($tables['oauth_clients'], array('client_id' => $clientId), array('%s'));
}

global $wpdb;
$currentBlogId = get_current_blog_id();
$sites = get_sites(array('number' => 20));
$otherBlogId = 0;

foreach ($sites as $site) {
    $candidate = (int) $site->blog_id;
    if ($candidate !== $currentBlogId) {
        $otherBlogId = $candidate;
        break;
    }
}

wp_nerve_multisite_retention_assert($otherBlogId > 0, 'network contains a second site for prefix-isolation evidence');

$currentPrefix = $wpdb->get_blog_prefix($currentBlogId);
$otherPrefix   = $wpdb->get_blog_prefix($otherBlogId);
wp_nerve_multisite_retention_assert($currentPrefix !== $otherPrefix, 'current and comparison sites use distinct table prefixes');

$currentTables = wp_nerve_multisite_retention_tables($currentPrefix);
$otherTables   = wp_nerve_multisite_retention_tables($otherPrefix);

foreach (array_merge(array_values($currentTables), array_values($otherTables)) as $table) {
    $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
    wp_nerve_multisite_retention_assert($found === $table, 'required Multisite retention table exists: ' . $table, $found);
}

$runId         = bin2hex(random_bytes(6));
$currentMarker = 'g8-ms-current-' . $runId;
$otherMarker   = 'g8-ms-other-' . $runId;

add_filter('wp_nerve_retention_cleanup_batch', static fn (): int => 20);
add_filter('wp_nerve_audit_retention_ttl', static fn (): int => 86400);
add_filter('wp_nerve_confirmation_retention_ttl', static fn (): int => 3600);
add_filter('wp_nerve_oauth_client_retention_ttl', static fn (): int => 86400);

try {
    wp_nerve_multisite_retention_seed($currentTables, $currentMarker);
    wp_nerve_multisite_retention_seed($otherTables, $otherMarker);

    $beforeCurrent = wp_nerve_multisite_retention_count($currentTables, $currentMarker);
    $beforeOther   = wp_nerve_multisite_retention_count($otherTables, $otherMarker);

    foreach ($beforeCurrent as $store => $count) {
        wp_nerve_multisite_retention_assert(1 === $count, 'current-site fixture exists in ' . $store, $beforeCurrent);
    }
    foreach ($beforeOther as $store => $count) {
        wp_nerve_multisite_retention_assert(1 === $count, 'other-site fixture exists in ' . $store, $beforeOther);
    }

    $cleanup = (new RetentionManager())->cleanup();
    foreach (array('audit', 'idempotency', 'confirmations', 'oauth_tokens', 'oauth_clients') as $store) {
        wp_nerve_multisite_retention_assert(1 === $cleanup[$store], 'current-site cleanup removes its ' . $store . ' fixture', $cleanup);
    }

    $afterCurrent = wp_nerve_multisite_retention_count($currentTables, $currentMarker);
    $afterOther   = wp_nerve_multisite_retention_count($otherTables, $otherMarker);

    foreach ($afterCurrent as $store => $count) {
        wp_nerve_multisite_retention_assert(0 === $count, 'current-site fixture is removed from ' . $store, $afterCurrent);
    }
    foreach ($afterOther as $store => $count) {
        wp_nerve_multisite_retention_assert(1 === $count, 'other-site fixture survives current-site cleanup in ' . $store, $afterOther);
    }

    fwrite(
        STDOUT,
        sprintf(
            "WPNERVE_MULTISITE_RETENTION_OK current_blog=%d other_blog=%d\n",
            $currentBlogId,
            $otherBlogId
        )
    );
} finally {
    wp_nerve_multisite_retention_cleanup_fixture($currentTables, $currentMarker);
    wp_nerve_multisite_retention_cleanup_fixture($otherTables, $otherMarker);
}
