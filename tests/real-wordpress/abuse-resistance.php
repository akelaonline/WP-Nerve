<?php

/**
 * Real WordPress abuse-resistance gate for G8.
 *
 * Run only on a disposable/staging WordPress installation through WP-CLI.
 *
 * @package WPNerve
 */

declare(strict_types=1);

use WPNerve\Audit\AuditRepository;
use WPNerve\Maintenance\RetentionManager;
use WPNerve\Security\Confirmation\WpdbRepository as ConfirmationRepository;
use WPNerve\Security\Idempotency\WpdbRepository as IdempotencyRepository;
use WPNerve\Security\Privileged\PluginArchiveInspector;

if (! defined('ABSPATH') || ! defined('WP_NERVE_VERSION')) {
    throw new RuntimeException('This gate must run inside WordPress with WPNerve active.');
}

if (! class_exists(ZipArchive::class)) {
    throw new RuntimeException('G8 plugin archive evidence requires PHP ZipArchive.');
}

/** @param mixed $actual */
function wp_nerve_g8_assert(bool $condition, string $message, mixed $actual = null): void
{
    if (! $condition) {
        $detail = null === $actual ? '' : ' Actual: ' . wp_json_encode($actual);
        throw new RuntimeException('FAIL: ' . $message . $detail);
    }

    fwrite(STDOUT, "PASS: {$message}\n");
}

/**
 * @param array<string, string> $entries
 */
function wp_nerve_g8_zip(array $entries, ?string $symlink = null): string
{
    $path = tempnam(sys_get_temp_dir(), 'wpnerve-g8-');

    if (false === $path) {
        throw new RuntimeException('Unable to allocate ZIP fixture.');
    }

    unlink($path);
    $path .= '.zip';
    $zip = new ZipArchive();

    if (true !== $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE)) {
        throw new RuntimeException('Unable to create ZIP fixture.');
    }

    foreach ($entries as $name => $content) {
        if (! $zip->addFromString($name, $content)) {
            $zip->close();
            throw new RuntimeException('Unable to add ZIP fixture entry: ' . $name);
        }
    }

    if (null !== $symlink) {
        $mode = (0120000 | 0777) << 16;
        if (! $zip->setExternalAttributesName($symlink, ZipArchive::OPSYS_UNIX, $mode)) {
            $zip->close();
            throw new RuntimeException('Unable to mark ZIP fixture symlink.');
        }
    }

    if (! $zip->close()) {
        throw new RuntimeException('Unable to close ZIP fixture.');
    }

    return $path;
}

/** @param array<int, string> $paths */
function wp_nerve_g8_delete_paths(array $paths): void
{
    foreach ($paths as $path) {
        if (is_file($path) || is_link($path)) {
            unlink($path);
        }
    }
}

function wp_nerve_g8_seed_retention(string $runId): void
{
    global $wpdb;

    $old = '2000-01-01 00:00:00';

    for ($index = 0; $index < 3; ++$index) {
        $suffix = $runId . '-' . $index;

        $wpdb->insert(
            AuditRepository::tableName(),
            array(
                'request_id'       => 'g8-' . $suffix,
                'occurred_at'      => $old,
                'user_id'          => 1,
                'protocol_version' => '2026-07-28',
                'client_name'      => 'g8-retention',
                'client_version'   => '1',
                'rpc_method'       => 'tools/call',
                'tool_name'        => 'g8-retention-' . $runId,
                'risk'             => 'read',
                'outcome'          => 'success',
                'duration_ms'      => 1,
                'error_code'       => '',
            )
        );

        $wpdb->insert(
            IdempotencyRepository::tableName(),
            array(
                'user_id'         => 1,
                'credential_hash' => hash('sha256', 'g8-credential-' . $suffix),
                'tool_name'       => 'g8-retention-' . $runId,
                'tool_hash'       => hash('sha256', 'g8-tool-' . $suffix),
                'key_hash'        => hash('sha256', 'g8-key-' . $suffix),
                'request_hash'    => hash('sha256', 'g8-request-' . $suffix),
                'status'          => 'completed',
                'outcome'         => '{}',
                'created_at'      => $old,
                'completed_at'    => $old,
                'expires_at'      => $old,
            )
        );

        $wpdb->insert(
            ConfirmationRepository::tableName(),
            array(
                'user_id'         => 1,
                'credential_hash' => hash('sha256', 'g8-confirm-credential-' . $suffix),
                'tool_name'       => 'g8-retention-' . $runId,
                'tool_hash'       => hash('sha256', 'g8-confirm-tool-' . $suffix),
                'risk'            => 'privileged',
                'request_hash'    => hash('sha256', 'g8-confirm-request-' . $suffix),
                'key_hash'        => hash('sha256', 'g8-confirm-key-' . $suffix),
                'token_hash'      => hash('sha256', 'g8-confirm-token-' . $suffix),
                'display_code'    => strtoupper(substr(hash('sha256', $suffix), 0, 8)),
                'status'          => 'consumed',
                'created_at'      => $old,
                'expires_at'      => $old,
                'decided_at'      => $old,
                'decided_by'      => 1,
                'consumed_at'     => $old,
            )
        );

        $wpdb->insert(
            $wpdb->prefix . 'wp_nerve_oauth_tokens',
            array(
                'token_hash'          => hash('sha256', 'g8-oauth-' . $suffix),
                'token_type'          => 'access',
                'client_id'           => 'g8-retention-' . $runId,
                'user_id'             => 1,
                'expires_at'          => $old,
                'auth_code_challenge' => null,
                'redirect_uri'        => null,
                'created_at'          => $old,
            )
        );
    }

    $wpdb->insert(
        IdempotencyRepository::tableName(),
        array(
            'user_id'         => 1,
            'credential_hash' => hash('sha256', 'g8-in-progress-credential-' . $runId),
            'tool_name'       => 'g8-retention-' . $runId,
            'tool_hash'       => hash('sha256', 'g8-in-progress-tool-' . $runId),
            'key_hash'        => hash('sha256', 'g8-in-progress-key-' . $runId),
            'request_hash'    => hash('sha256', 'g8-in-progress-request-' . $runId),
            'status'          => 'in_progress',
            'outcome'         => null,
            'created_at'      => $old,
            'completed_at'    => null,
            'expires_at'      => $old,
        )
    );
}

function wp_nerve_g8_cleanup_retention_fixtures(string $runId): void
{
    global $wpdb;

    $tool = 'g8-retention-' . $runId;
    $client = 'g8-retention-' . $runId;

    $wpdb->delete(AuditRepository::tableName(), array('tool_name' => $tool), array('%s'));
    $wpdb->delete(IdempotencyRepository::tableName(), array('tool_name' => $tool), array('%s'));
    $wpdb->delete(ConfirmationRepository::tableName(), array('tool_name' => $tool), array('%s'));
    $wpdb->delete($wpdb->prefix . 'wp_nerve_oauth_tokens', array('client_id' => $client), array('%s'));
}

$inspector  = new PluginArchiveInspector();
$pluginsDir = defined('WP_PLUGIN_DIR') ? WP_PLUGIN_DIR : WP_CONTENT_DIR . '/plugins';
$fixtures   = array();
$runId      = bin2hex(random_bytes(6));

try {
    $valid = wp_nerve_g8_zip(
        array(
            'g8-safe-' . $runId . '/fixture.php' => '<?php /* Plugin Name: G8 Safe Fixture */',
        )
    );
    $fixtures[] = $valid;
    $result = $inspector->inspect($valid, $pluginsDir);
    wp_nerve_g8_assert(is_array($result), 'valid non-colliding plugin ZIP passes preflight', $result);

    $traversal = wp_nerve_g8_zip(array('../escape.php' => '<?php'));
    $fixtures[] = $traversal;
    $result = $inspector->inspect($traversal, $pluginsDir);
    wp_nerve_g8_assert(is_wp_error($result), 'traversal ZIP is rejected');
    wp_nerve_g8_assert('wp_nerve_unsafe_archive_path' === $result->get_error_code(), 'traversal rejection uses stable error code');

    $collision = wp_nerve_g8_zip(
        array(
            'g8-case-' . $runId . '/Foo.php' => '<?php',
            'g8-case-' . $runId . '/foo.php' => '<?php',
        )
    );
    $fixtures[] = $collision;
    $result = $inspector->inspect($collision, $pluginsDir);
    wp_nerve_g8_assert(is_wp_error($result), 'case-colliding ZIP paths are rejected');
    wp_nerve_g8_assert('wp_nerve_duplicate_archive_path' === $result->get_error_code(), 'case collision uses stable error code');

    $selfRoot = str_replace('\\', '/', plugin_basename(WP_NERVE_FILE));
    $selfRoot = (string) explode('/', $selfRoot)[0];
    $overwrite = wp_nerve_g8_zip(array($selfRoot . '/evil.php' => '<?php'));
    $fixtures[] = $overwrite;
    $result = $inspector->inspect($overwrite, $pluginsDir);
    wp_nerve_g8_assert(is_wp_error($result), 'archive cannot target the installed WPNerve root');
    wp_nerve_g8_assert('wp_nerve_plugin_exists' === $result->get_error_code(), 'installed-root collision uses stable error code');

    $symlinkName = 'g8-link-' . $runId . '/link';
    $symlink = wp_nerve_g8_zip(
        array(
            'g8-link-' . $runId . '/fixture.php' => '<?php',
            $symlinkName                         => '../wp-config.php',
        ),
        $symlinkName
    );
    $fixtures[] = $symlink;
    $result = $inspector->inspect($symlink, $pluginsDir);
    wp_nerve_g8_assert(is_wp_error($result), 'symbolic-link ZIP entry is rejected');
    wp_nerve_g8_assert('wp_nerve_archive_symlink' === $result->get_error_code(), 'symlink rejection uses stable error code');

    wp_nerve_g8_seed_retention($runId);
    add_filter('wp_nerve_retention_cleanup_batch', static fn (): int => 2);
    add_filter('wp_nerve_audit_retention_ttl', static fn (): int => 86400);
    add_filter('wp_nerve_confirmation_retention_ttl', static fn (): int => 3600);

    $cleanup = (new RetentionManager())->cleanup();
    wp_nerve_g8_assert(2 === $cleanup['audit'], 'audit retention deletes only the configured batch', $cleanup);
    wp_nerve_g8_assert(2 === $cleanup['idempotency'], 'idempotency retention deletes only completed expired rows in the batch', $cleanup);
    wp_nerve_g8_assert(2 === $cleanup['confirmations'], 'confirmation retention deletes only the configured batch', $cleanup);
    wp_nerve_g8_assert(2 === $cleanup['oauth_tokens'], 'OAuth retention deletes only the configured batch', $cleanup);

    global $wpdb;
    $tool = 'g8-retention-' . $runId;
    $remainingInProgress = (int) $wpdb->get_var(
        $wpdb->prepare(
            'SELECT COUNT(*) FROM %i WHERE tool_name = %s AND status = %s',
            IdempotencyRepository::tableName(),
            $tool,
            'in_progress'
        )
    );
    wp_nerve_g8_assert(1 === $remainingInProgress, 'retention never recycles unresolved in-progress idempotency claims');
} finally {
    wp_nerve_g8_cleanup_retention_fixtures($runId);
    wp_nerve_g8_delete_paths($fixtures);
}

fwrite(STDOUT, "WPNERVE_REAL_WORDPRESS_G8_OK\n");
