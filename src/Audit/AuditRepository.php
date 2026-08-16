<?php

/**
 * Privacy-preserving MCP execution audit log.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Audit;

final class AuditRepository implements AuditRecorder
{
    public static function installSchema(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table   = self::tableName();
        $charset = $wpdb->get_charset_collate();
        $sql     = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            request_id varchar(191) NOT NULL DEFAULT '',
            occurred_at datetime NOT NULL,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            protocol_version varchar(20) NOT NULL DEFAULT '',
            client_name varchar(191) NOT NULL DEFAULT '',
            client_version varchar(64) NOT NULL DEFAULT '',
            rpc_method varchar(64) NOT NULL DEFAULT '',
            tool_name varchar(191) NOT NULL DEFAULT '',
            risk varchar(20) NOT NULL DEFAULT '',
            outcome varchar(20) NOT NULL DEFAULT '',
            duration_ms int(10) unsigned NOT NULL DEFAULT 0,
            error_code varchar(64) NOT NULL DEFAULT '',
            PRIMARY KEY  (id),
            KEY occurred_at (occurred_at),
            KEY user_id (user_id),
            KEY tool_name (tool_name),
            KEY outcome (outcome)
        ) {$charset};";

        dbDelta($sql);
    }

    /** @param array<string, int|string> $event */
    public function record(array $event): void
    {
        global $wpdb;

        $wpdb->insert(
            self::tableName(),
            array(
                'request_id'      => self::text($event['request_id'] ?? '', 191),
                'occurred_at'     => current_time('mysql', true),
                'user_id'         => get_current_user_id(),
                'protocol_version' => self::text($event['protocol_version'] ?? '', 20),
                'client_name'     => self::text($event['client_name'] ?? '', 191),
                'client_version'  => self::text($event['client_version'] ?? '', 64),
                'rpc_method'      => self::text($event['rpc_method'] ?? '', 64),
                'tool_name'       => self::text($event['tool_name'] ?? '', 191),
                'risk'            => self::text($event['risk'] ?? '', 20),
                'outcome'         => self::text($event['outcome'] ?? '', 20),
                'duration_ms'     => max(0, min(4294967295, (int) ($event['duration_ms'] ?? 0))),
                'error_code'      => self::text($event['error_code'] ?? '', 64),
            ),
            array('%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s')
        );
    }

    public static function tableName(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'wp_nerve_audit_log';
    }

    private static function text(int|string $value, int $length): string
    {
        $value = sanitize_text_field((string) $value);

        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $length);
        }

        return wp_check_invalid_utf8(substr($value, 0, $length), true);
    }
}
