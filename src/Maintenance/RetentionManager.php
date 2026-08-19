<?php

/**
 * Bounded retention cleanup for WPNerve-owned persistence.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Maintenance;

use WPNerve\Audit\AuditRepository;
use WPNerve\Security\Confirmation\WpdbRepository as ConfirmationRepository;
use WPNerve\Security\Idempotency\WpdbRepository as IdempotencyRepository;

final class RetentionManager
{
    private const DEFAULT_AUDIT_TTL = 2592000;

    private const MIN_AUDIT_TTL = 86400;

    private const MAX_AUDIT_TTL = 31536000;

    private const DEFAULT_CONFIRMATION_TTL = 604800;

    private const MIN_CONFIRMATION_TTL = 3600;

    private const MAX_CONFIRMATION_TTL = 2592000;

    private const DEFAULT_OAUTH_CLIENT_TTL = 0;

    private const MIN_OAUTH_CLIENT_TTL = 86400;

    private const MAX_OAUTH_CLIENT_TTL = 31536000;

    private const DEFAULT_BATCH = 200;

    private const MAX_BATCH = 1000;

    /**
     * Runs from WordPress Core's daily wp_scheduled_delete cron event.
     *
     * OAuth dynamic-client pruning is intentionally opt-in. Registration is
     * already hard-capped, and silently expiring an otherwise valid client can
     * break a legitimate long-lived integration. When a site owner configures a
     * client-retention TTL, only old clients with no unexpired token/code rows
     * are removed.
     *
     * @return array{
     *     audit: int|false,
     *     idempotency: int|false,
     *     confirmations: int|false,
     *     oauth_tokens: int|false,
     *     oauth_clients: int|false
     * }
     */
    public function cleanup(): array
    {
        global $wpdb;

        $batch = $this->batchSize();
        $now   = current_time('mysql', true);
        $auditCutoff = gmdate('Y-m-d H:i:s', time() - $this->auditRetentionTtl());
        $confirmationCutoff = gmdate('Y-m-d H:i:s', time() - $this->confirmationRetentionTtl());

        $audit = $wpdb->query(
            $wpdb->prepare(
                'DELETE FROM %i WHERE occurred_at < %s ORDER BY id ASC LIMIT %d',
                AuditRepository::tableName(),
                $auditCutoff,
                $batch
            )
        );

        $idempotency = $wpdb->query(
            $wpdb->prepare(
                'DELETE FROM %i WHERE status = %s AND expires_at IS NOT NULL AND expires_at <= %s ORDER BY id ASC LIMIT %d',
                IdempotencyRepository::tableName(),
                'completed',
                $now,
                $batch
            )
        );

        $confirmations = $wpdb->query(
            $wpdb->prepare(
                'DELETE FROM %i WHERE expires_at <= %s ORDER BY id ASC LIMIT %d',
                ConfirmationRepository::tableName(),
                $confirmationCutoff,
                $batch
            )
        );

        $oauthTokens = $wpdb->query(
            $wpdb->prepare(
                'DELETE FROM %i WHERE expires_at <= %s ORDER BY id ASC LIMIT %d',
                $wpdb->prefix . 'wp_nerve_oauth_tokens',
                $now,
                $batch
            )
        );

        $oauthClients = 0;
        $clientTtl = $this->oauthClientRetentionTtl();

        if ($clientTtl > 0) {
            $clientCutoff = gmdate('Y-m-d H:i:s', time() - $clientTtl);
            $oauthClients = $wpdb->query(
                $wpdb->prepare(
                    'DELETE FROM %i WHERE created_at <= %s '
                    . 'AND client_id NOT IN (SELECT client_id FROM %i WHERE expires_at > %s) '
                    . 'ORDER BY id ASC LIMIT %d',
                    $wpdb->prefix . 'wp_nerve_oauth_clients',
                    $clientCutoff,
                    $wpdb->prefix . 'wp_nerve_oauth_tokens',
                    $now,
                    $batch
                )
            );
        }

        return array(
            'audit'         => $audit,
            'idempotency'   => $idempotency,
            'confirmations' => $confirmations,
            'oauth_tokens'  => $oauthTokens,
            'oauth_clients' => $oauthClients,
        );
    }

    public function auditRetentionTtl(): int
    {
        /**
         * Filters audit retention in seconds.
         *
         * WPNerve clamps this value so a plugin cannot accidentally disable
         * retention by requesting an unbounded lifetime.
         *
         * @param int $seconds Audit retention lifetime.
         */
        $seconds = apply_filters('wp_nerve_audit_retention_ttl', self::DEFAULT_AUDIT_TTL);

        if (! is_int($seconds)) {
            return self::DEFAULT_AUDIT_TTL;
        }

        return max(self::MIN_AUDIT_TTL, min(self::MAX_AUDIT_TTL, $seconds));
    }

    public function confirmationRetentionTtl(): int
    {
        /**
         * Filters how long expired confirmation decisions remain in storage.
         *
         * @param int $seconds Confirmation retention lifetime after expiry.
         */
        $seconds = apply_filters('wp_nerve_confirmation_retention_ttl', self::DEFAULT_CONFIRMATION_TTL);

        if (! is_int($seconds)) {
            return self::DEFAULT_CONFIRMATION_TTL;
        }

        return max(self::MIN_CONFIRMATION_TTL, min(self::MAX_CONFIRMATION_TTL, $seconds));
    }

    public function oauthClientRetentionTtl(): int
    {
        /**
         * Filters optional stale dynamic OAuth-client retention in seconds.
         *
         * Zero disables automatic client deletion. Any positive value is
         * clamped to one day through one year. A client is eligible only when it
         * is older than the cutoff and has no unexpired OAuth token/code rows.
         *
         * @param int $seconds Dynamic-client retention lifetime; 0 disables.
         */
        $seconds = apply_filters('wp_nerve_oauth_client_retention_ttl', self::DEFAULT_OAUTH_CLIENT_TTL);

        if (! is_int($seconds) || $seconds <= 0) {
            return self::DEFAULT_OAUTH_CLIENT_TTL;
        }

        return max(self::MIN_OAUTH_CLIENT_TTL, min(self::MAX_OAUTH_CLIENT_TTL, $seconds));
    }

    public function batchSize(): int
    {
        /**
         * Filters the maximum number of rows removed from each WPNerve table per
         * daily maintenance run.
         *
         * @param int $rows Cleanup batch size.
         */
        $rows = apply_filters('wp_nerve_retention_cleanup_batch', self::DEFAULT_BATCH);

        if (! is_int($rows)) {
            return self::DEFAULT_BATCH;
        }

        return max(1, min(self::MAX_BATCH, $rows));
    }
}
