<?php

/**
 * Atomic WordPress database storage for fixed-window rate limiting.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Security\RateLimit;

final class WpdbRepository
{
    public static function installSchema(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table   = self::tableName();
        $charset = $wpdb->get_charset_collate();

        dbDelta(
            "CREATE TABLE {$table} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                bucket_name varchar(64) NOT NULL,
                bucket_hash char(64) NOT NULL,
                subject_hash char(64) NOT NULL,
                window_start bigint(20) unsigned NOT NULL,
                request_count int(10) unsigned NOT NULL DEFAULT 0,
                expires_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY rate_window (bucket_hash, subject_hash, window_start),
                KEY expires_at (expires_at)
            ) {$charset};"
        );
    }

    /**
     * Atomically consumes one request from a fixed-window budget.
     *
     * @return array{accepted: bool, count: int}|null Null means storage is unavailable.
     */
    public function consume(
        string $bucket,
        string $subject,
        int $limit,
        int $windowStart,
        int $expiresAt
    ): ?array {
        global $wpdb;

        $cleanup = $wpdb->query(
            $wpdb->prepare(
                'DELETE FROM %i WHERE expires_at <= %s LIMIT 100',
                self::tableName(),
                current_time('mysql', true)
            )
        );

        if (false === $cleanup) {
            return null;
        }

        $bucketHash  = self::hash($bucket);
        $subjectHash = self::hash($subject);
        $consumed    = $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO %i '
                . '(bucket_name, bucket_hash, subject_hash, window_start, request_count, expires_at) '
                . 'VALUES (%s, %s, %s, %d, 1, %s) '
                . 'ON DUPLICATE KEY UPDATE '
                . 'request_count = IF(request_count < %d, request_count + 1, request_count), '
                . 'expires_at = VALUES(expires_at)',
                self::tableName(),
                self::text($bucket, 64),
                $bucketHash,
                $subjectHash,
                $windowStart,
                gmdate('Y-m-d H:i:s', $expiresAt),
                $limit
            )
        );

        if (false === $consumed) {
            return null;
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT request_count FROM %i '
                . 'WHERE bucket_hash = %s AND subject_hash = %s AND window_start = %d',
                self::tableName(),
                $bucketHash,
                $subjectHash,
                $windowStart
            ),
            'ARRAY_A'
        );

        if (! is_array($row) || ! isset($row['request_count']) || ! is_numeric($row['request_count'])) {
            return null;
        }

        $count = max(1, min($limit, (int) $row['request_count']));

        return array(
            'accepted' => 0 !== $consumed,
            'count'    => $count,
        );
    }

    public static function tableName(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'wp_nerve_rate_limits';
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
