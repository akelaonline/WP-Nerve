<?php

/**
 * Real WordPress retention scale gate for G8.
 *
 * Seeds more rows than the configured cleanup batch and verifies that daily
 * maintenance remains bounded, repeatable, and preserves unresolved mutation
 * claims. Run only on a disposable/staging site.
 *
 * @package WPNerve
 */

declare(strict_types=1);

use WPNerve\Audit\AuditRepository;
use WPNerve\Maintenance\RetentionManager;
use WPNerve\Security\Confirmation\WpdbRepository as ConfirmationRepository;
use WPNerve\Security\Idempotency\WpdbRepository as IdempotencyRepository;

if (! defined('ABSPATH') || ! defined('WP_NERVE_VERSION')) {
    throw new RuntimeException('This gate must run inside WordPress with WPNerve active.');
}

/** @param mixed $actual */
function wp_nerve_retention_scale_assert(bool $condition, string $message, mixed $actual = null): void
{
    if (! $condition) {
        $detail = null === $actual ? '' : ' Actual: ' . wp_json_encode($actual);
        throw new RuntimeException('FAIL: ' . $message . $detail);
    }

    fwrite(STDOUT, "PASS: {$message}\n");
}

$requestedRows = (int) (getenv('WP_NERVE_RETENTION_SCALE_ROWS') ?: 600);
$rows = max(401, min(5000, $requestedRows));
$batch = 200;
$runId = bin2hex(random_bytes(6));
$tool = 'g8-scale-' . $runId;
$clientPrefix = 'g8-scale-' . $runId . '-';
$old = '2000-01-01 00:00:00';

add_filter('wp_nerve_retention_cleanup_batch', static fn (): int => $batch);
add_filter('wp_nerve_audit_retention_ttl', static fn (): int => 86400);
add_filter('wp_nerve_confirmation_retention_ttl', static fn (): int => 3600);
add_filter('wp_nerve_oauth_client_retention_ttl', static fn (): int => 86400);

global $wpdb;
$oauthClients = $wpdb->prefix . 'wp_nerve_oauth_clients';
$oauthTokens  = $wpdb->prefix . 'wp_nerve_oauth_tokens';

try {
    for ($index = 0; $index < $rows; ++$index) {
        $suffix = $runId . '-' . $index;
        $clientId = $clientPrefix . $index;

        $wpdb->insert(
            AuditRepository::tableName(),
            array(
                'request_id'       => 'g8-scale-' . $suffix,
                'occurred_at'      => $old,
                'user_id'          => 1,
                'protocol_version' => '2026-07-28',
                'client_name'      => 'g8-scale',
                'client_version'   => '1',
                'rpc_method'       => 'tools/call',
                'tool_name'        => $tool,
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
                'credential_hash' => hash('sha256', 'scale-credential-' . $suffix),
                'tool_name'       => $tool,
                'tool_hash'       => hash('sha256', 'scale-tool-' . $suffix),
                'key_hash'        => hash('sha256', 'scale-key-' . $suffix),
                'request_hash'    => hash('sha256', 'scale-request-' . $suffix),
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
                'credential_hash' => hash('sha256', 'scale-confirm-credential-' . $suffix),
                'tool_name'       => $tool,
                'tool_hash'       => hash('sha256', 'scale-confirm-tool-' . $suffix),
                'risk'            => 'privileged',
                'request_hash'    => hash('sha256', 'scale-confirm-request-' . $suffix),
                'key_hash'        => hash('sha256', 'scale-confirm-key-' . $suffix),
                'token_hash'      => hash('sha256', 'scale-confirm-token-' . $suffix),
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
            $oauthClients,
            array(
                'client_id'     => $clientId,
                'client_name'   => 'G8 scale client',
                'redirect_uris' => '[]',
                'created_at'    => $old,
            )
        );

        $wpdb->insert(
            $oauthTokens,
            array(
                'token_hash'          => hash('sha256', 'scale-oauth-' . $suffix),
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

    for ($index = 0; $index < 5; ++$index) {
        $suffix = $runId . '-in-progress-' . $index;
        $wpdb->insert(
            IdempotencyRepository::tableName(),
            array(
                'user_id'         => 1,
                'credential_hash' => hash('sha256', 'scale-in-progress-credential-' . $suffix),
                'tool_name'       => $tool,
                'tool_hash'       => hash('sha256', 'scale-in-progress-tool-' . $suffix),
                'key_hash'        => hash('sha256', 'scale-in-progress-key-' . $suffix),
                'request_hash'    => hash('sha256', 'scale-in-progress-request-' . $suffix),
                'status'          => 'in_progress',
                'outcome'         => null,
                'created_at'      => $old,
                'completed_at'    => null,
                'expires_at'      => $old,
            )
        );
    }

    $manager = new RetentionManager();
    $started = microtime(true);
    $first = $manager->cleanup();
    $firstDuration = microtime(true) - $started;

    foreach (array('audit', 'idempotency', 'confirmations', 'oauth_tokens', 'oauth_clients') as $store) {
        wp_nerve_retention_scale_assert($batch === $first[$store], $store . ' first cleanup is batch-bounded', $first);
    }

    $started = microtime(true);
    $second = $manager->cleanup();
    $secondDuration = microtime(true) - $started;

    foreach (array('audit', 'idempotency', 'confirmations', 'oauth_tokens', 'oauth_clients') as $store) {
        wp_nerve_retention_scale_assert($batch === $second[$store], $store . ' second cleanup remains batch-bounded', $second);
    }

    $inProgress = (int) $wpdb->get_var(
        $wpdb->prepare(
            'SELECT COUNT(*) FROM %i WHERE tool_name = %s AND status = %s',
            IdempotencyRepository::tableName(),
            $tool,
            'in_progress'
        )
    );
    wp_nerve_retention_scale_assert(5 === $inProgress, 'scale cleanup preserves unresolved idempotency claims', $inProgress);

    wp_nerve_retention_scale_assert($firstDuration < 30.0, 'first bounded cleanup completes within 30 seconds', $firstDuration);
    wp_nerve_retention_scale_assert($secondDuration < 30.0, 'second bounded cleanup completes within 30 seconds', $secondDuration);

    fwrite(
        STDOUT,
        sprintf(
            "WPNERVE_RETENTION_SCALE_OK rows=%d batch=%d first_ms=%d second_ms=%d\n",
            $rows,
            $batch,
            (int) round($firstDuration * 1000),
            (int) round($secondDuration * 1000)
        )
    );
} finally {
    $wpdb->delete(AuditRepository::tableName(), array('tool_name' => $tool), array('%s'));
    $wpdb->delete(IdempotencyRepository::tableName(), array('tool_name' => $tool), array('%s'));
    $wpdb->delete(ConfirmationRepository::tableName(), array('tool_name' => $tool), array('%s'));

    $like = $wpdb->esc_like($clientPrefix) . '%';
    $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE client_id LIKE %s', $oauthTokens, $like));
    $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE client_id LIKE %s', $oauthClients, $like));
}
