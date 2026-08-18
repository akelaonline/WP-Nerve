<?php

/**
 * Atomic WordPress database storage for idempotency operations.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Security\Idempotency;

final class WpdbRepository implements Repository
{
    private const DEFAULT_RETENTION_TTL = 86400;

    private const MINIMUM_RETENTION_TTL = 3600;

    public static function installSchema(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table   = self::tableName();
        $charset = $wpdb->get_charset_collate();

        dbDelta(
            "CREATE TABLE {$table} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                user_id bigint(20) unsigned NOT NULL,
                credential_hash char(64) NOT NULL,
                tool_name varchar(191) NOT NULL,
                tool_hash char(64) NOT NULL,
                key_hash char(64) NOT NULL,
                request_hash char(64) NOT NULL,
                status varchar(20) NOT NULL DEFAULT 'in_progress',
                outcome longtext NULL,
                created_at datetime NOT NULL,
                completed_at datetime NULL,
                expires_at datetime NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY operation (user_id, credential_hash, tool_hash, key_hash),
                KEY expires_at (expires_at),
                KEY status (status)
            ) {$charset};"
        );
    }

    public function claim(
        int $userId,
        string $credentialId,
        string $toolName,
        string $key,
        string $requestHash
    ): Claim {
        global $wpdb;

        $toolHash = self::hash($toolName);
        $keyHash  = self::hash($key);
        $credentialHash = self::hash($credentialId);
        $inserted = $wpdb->query(
            $wpdb->prepare(
                'INSERT IGNORE INTO %i '
                . '(user_id, credential_hash, tool_name, tool_hash, key_hash, request_hash, status, created_at) '
                . "VALUES (%d, %s, %s, %s, %s, %s, 'in_progress', %s)",
                self::tableName(),
                $userId,
                $credentialHash,
                self::text($toolName, 191),
                $toolHash,
                $keyHash,
                $requestHash,
                current_time('mysql', true)
            )
        );

        if (1 === $inserted) {
            return Claim::acquired();
        }

        if (false === $inserted) {
            return new Claim(ClaimState::Unavailable);
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT request_hash, status, outcome, expires_at FROM %i '
                . 'WHERE user_id = %d AND credential_hash = %s AND tool_hash = %s AND key_hash = %s',
                self::tableName(),
                $userId,
                $credentialHash,
                $toolHash,
                $keyHash
            ),
            'ARRAY_A'
        );

        if (! is_array($row)) {
            return new Claim(ClaimState::Unavailable);
        }

        $storedHash = (string) ($row['request_hash'] ?? '');

        if (64 !== strlen($storedHash) || ! hash_equals($storedHash, $requestHash)) {
            return new Claim(ClaimState::Conflict);
        }

        if ('completed' !== ($row['status'] ?? null)) {
            return new Claim(ClaimState::InProgress);
        }

        $expiresAt = strtotime((string) ($row['expires_at'] ?? ''));

        if (false === $expiresAt || $expiresAt <= time()) {
            return new Claim(ClaimState::Expired);
        }

        $outcome = json_decode((string) ($row['outcome'] ?? ''), true);

        return is_array($outcome)
            ? Claim::replay($outcome)
            : new Claim(ClaimState::Unavailable);
    }

    /**
     * @param array<string, mixed> $outcome
     */
    public function complete(
        int $userId,
        string $credentialId,
        string $toolName,
        string $key,
        string $requestHash,
        array $outcome
    ): bool {
        global $wpdb;

        $encoded = wp_json_encode($outcome, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (! is_string($encoded)) {
            return false;
        }

        $updated = $wpdb->query(
            $wpdb->prepare(
                'UPDATE %i SET status = %s, outcome = %s, completed_at = %s, expires_at = %s '
                . 'WHERE user_id = %d AND credential_hash = %s AND tool_hash = %s '
                . 'AND key_hash = %s AND request_hash = %s AND status = %s',
                self::tableName(),
                'completed',
                $encoded,
                current_time('mysql', true),
                gmdate('Y-m-d H:i:s', time() + self::retentionTtl()),
                $userId,
                self::hash($credentialId),
                self::hash($toolName),
                self::hash($key),
                $requestHash,
                'in_progress'
            )
        );

        return 1 === $updated;
    }

    public static function tableName(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'wp_nerve_idempotency';
    }

    public static function retentionTtl(): int
    {
        /**
         * Filters how long completed idempotency outcomes remain replayable.
         *
         * In-progress entries are deliberately not expired automatically. An
         * interrupted mutation is indeterminate and must never be retried by
         * silently recycling its key.
         *
         * @param int $seconds Completed outcome lifetime.
         */
        $seconds = apply_filters('wp_nerve_idempotency_retention_ttl', self::DEFAULT_RETENTION_TTL);

        return is_int($seconds) && $seconds >= self::MINIMUM_RETENTION_TTL
            ? $seconds
            : self::DEFAULT_RETENTION_TTL;
    }

    private static function hash(string $value): string
    {
        return hash('sha256', $value);
    }

    private static function text(string $value, int $length): string
    {
        $value = sanitize_text_field($value);

        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $length);
        }

        return wp_check_invalid_utf8(substr($value, 0, $length), true);
    }
}
