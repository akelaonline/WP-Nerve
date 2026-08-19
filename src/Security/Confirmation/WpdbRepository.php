<?php

/**
 * Atomic WordPress database storage for high-risk confirmations.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Security\Confirmation;

final class WpdbRepository implements Repository
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
                user_id bigint(20) unsigned NOT NULL,
                credential_hash char(64) NOT NULL,
                tool_name varchar(191) NOT NULL,
                tool_hash char(64) NOT NULL,
                risk varchar(20) NOT NULL,
                request_hash char(64) NOT NULL,
                key_hash char(64) NOT NULL,
                token_hash char(64) NOT NULL,
                display_code varchar(12) NOT NULL,
                status varchar(20) NOT NULL DEFAULT 'pending',
                created_at datetime NOT NULL,
                expires_at datetime NOT NULL,
                decided_at datetime NULL,
                decided_by bigint(20) unsigned NULL,
                consumed_at datetime NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY token_hash (token_hash),
                KEY status_expires (status, expires_at),
                KEY actor (user_id, credential_hash)
            ) {$charset};"
        );
    }

    public function issue(
        int $userId,
        string $credentialId,
        string $toolName,
        string $risk,
        string $requestHash,
        string $idempotencyKey,
        string $tokenHash,
        string $displayCode,
        int $expiresAt
    ): bool {
        global $wpdb;

        $inserted = $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO %i '
                . '(user_id, credential_hash, tool_name, tool_hash, risk, request_hash, key_hash, '
                . 'token_hash, display_code, status, created_at, expires_at) '
                . "VALUES (%d, %s, %s, %s, %s, %s, %s, %s, %s, 'pending', %s, %s)",
                self::tableName(),
                $userId,
                self::hash($credentialId),
                self::text($toolName, 191),
                self::hash($toolName),
                self::text($risk, 20),
                $requestHash,
                self::hash($idempotencyKey),
                $tokenHash,
                self::text($displayCode, 12),
                current_time('mysql', true),
                gmdate('Y-m-d H:i:s', $expiresAt)
            )
        );

        return 1 === $inserted;
    }

    public function authorize(
        int $userId,
        string $credentialId,
        string $toolName,
        string $requestHash,
        string $idempotencyKey,
        string $tokenHash
    ): Authorization {
        global $wpdb;

        $row = $this->row($tokenHash);

        if (null === $row) {
            return new Authorization(AuthorizationState::Invalid);
        }

        if (! $this->matches($row, $userId, $credentialId, $toolName, $requestHash, $idempotencyKey)) {
            return new Authorization(AuthorizationState::Conflict);
        }

        $status      = (string) ($row['status'] ?? '');
        $displayCode = (string) ($row['display_code'] ?? '');
        $expiresAt   = strtotime((string) ($row['expires_at'] ?? '') . ' UTC');
        $expiresAt   = false === $expiresAt ? 0 : $expiresAt;

        if ('denied' === $status) {
            return new Authorization(AuthorizationState::Denied, $displayCode, $expiresAt);
        }

        if ($expiresAt <= time()) {
            return new Authorization(AuthorizationState::Expired, $displayCode, $expiresAt);
        }

        if ('consumed' === $status) {
            return new Authorization(AuthorizationState::Replay, $displayCode, $expiresAt);
        }

        if ('pending' === $status) {
            return new Authorization(AuthorizationState::Pending, $displayCode, $expiresAt);
        }

        if ('approved' !== $status) {
            return new Authorization(AuthorizationState::Unavailable, $displayCode, $expiresAt);
        }

        $updated = $wpdb->query(
            $wpdb->prepare(
                'UPDATE %i SET status = %s, consumed_at = %s '
                . 'WHERE id = %d AND status = %s AND expires_at > %s',
                self::tableName(),
                'consumed',
                current_time('mysql', true),
                (int) ($row['id'] ?? 0),
                'approved',
                current_time('mysql', true)
            )
        );

        if (1 === $updated) {
            return new Authorization(AuthorizationState::Approved, $displayCode, $expiresAt);
        }

        if (false === $updated) {
            return new Authorization(AuthorizationState::Unavailable, $displayCode, $expiresAt);
        }

        $latest = $this->row($tokenHash);

        if (is_array($latest) && 'consumed' === ($latest['status'] ?? null)) {
            return $expiresAt > time()
                ? new Authorization(AuthorizationState::Replay, $displayCode, $expiresAt)
                : new Authorization(AuthorizationState::Expired, $displayCode, $expiresAt);
        }

        return new Authorization(AuthorizationState::Unavailable, $displayCode, $expiresAt);
    }

    public function pending(): array
    {
        global $wpdb;

        /** @var array<int, array<string, mixed>>|null $rows */
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, user_id, tool_name, risk, display_code, created_at, expires_at '
                . 'FROM %i WHERE status = %s AND expires_at > %s ORDER BY id DESC LIMIT 100',
                self::tableName(),
                'pending',
                current_time('mysql', true)
            ),
            'ARRAY_A'
        );

        if (! is_array($rows)) {
            return array();
        }

        $pending = array();

        foreach ($rows as $row) {
            $pending[] = array(
                'id'           => (int) ($row['id'] ?? 0),
                'user_id'      => (int) ($row['user_id'] ?? 0),
                'tool_name'    => (string) ($row['tool_name'] ?? ''),
                'risk'         => (string) ($row['risk'] ?? ''),
                'display_code' => (string) ($row['display_code'] ?? ''),
                'created_at'   => (string) ($row['created_at'] ?? ''),
                'expires_at'   => (string) ($row['expires_at'] ?? ''),
            );
        }

        return $pending;
    }

    public function decide(int $challengeId, int $adminUserId, bool $approved): bool
    {
        global $wpdb;

        if ($challengeId <= 0 || $adminUserId <= 0) {
            return false;
        }

        $updated = $wpdb->query(
            $wpdb->prepare(
                'UPDATE %i SET status = %s, decided_at = %s, decided_by = %d '
                . 'WHERE id = %d AND status = %s AND expires_at > %s',
                self::tableName(),
                $approved ? 'approved' : 'denied',
                current_time('mysql', true),
                $adminUserId,
                $challengeId,
                'pending',
                current_time('mysql', true)
            )
        );

        return 1 === $updated;
    }

    public static function tableName(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'wp_nerve_confirmations';
    }

    /** @return array<string, mixed>|null */
    private function row(string $tokenHash): ?array
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT id, user_id, credential_hash, tool_hash, request_hash, key_hash, '
                . 'status, display_code, expires_at FROM %i WHERE token_hash = %s',
                self::tableName(),
                $tokenHash
            ),
            'ARRAY_A'
        );

        return is_array($row) ? $row : null;
    }

    /** @param array<string, mixed> $row */
    private function matches(
        array $row,
        int $userId,
        string $credentialId,
        string $toolName,
        string $requestHash,
        string $idempotencyKey
    ): bool {
        return $userId === (int) ($row['user_id'] ?? 0)
            && self::sameHash((string) ($row['credential_hash'] ?? ''), self::hash($credentialId))
            && self::sameHash((string) ($row['tool_hash'] ?? ''), self::hash($toolName))
            && self::sameHash((string) ($row['request_hash'] ?? ''), $requestHash)
            && self::sameHash((string) ($row['key_hash'] ?? ''), self::hash($idempotencyKey));
    }

    private static function sameHash(string $stored, string $expected): bool
    {
        return 64 === strlen($stored) && hash_equals($stored, $expected);
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
