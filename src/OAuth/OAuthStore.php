<?php

/**
 * OAuth client and token storage.
 *
 * Access tokens, refresh tokens, and authorization codes are stored as
 * SHA-256 hashes. Plaintext tokens exist only in the response to the client.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\OAuth;

use WP_Error;

final class OAuthStore
{
    public const SCHEMA_VERSION = 2;

    /**
     * @param array<string, mixed> $client
     */
    public function createClient(array $client): string
    {
        global $wpdb;

        $clientId = self::randomToken(32);
        $inserted = $wpdb->insert(
            self::clientsTable(),
            array(
                'client_id'     => $clientId,
                'client_name'   => sanitize_text_field((string) ($client['client_name'] ?? 'MCP client')),
                'redirect_uris' => wp_json_encode($client['redirect_uris'] ?? array()),
                'created_at'    => current_time('mysql', true),
            ),
            array('%s', '%s', '%s', '%s')
        );

        return false === $inserted ? '' : $clientId;
    }

    /** @return array<string, mixed>|null */
    public function getClient(string $clientId): ?array
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT client_id, client_name, redirect_uris FROM %i WHERE client_id = %s LIMIT 1',
                self::clientsTable(),
                $clientId
            ),
            'ARRAY_A'
        );

        if (! is_array($row)) {
            return null;
        }

        $redirects = json_decode((string) ($row['redirect_uris'] ?? '[]'), true);

        return array(
            'client_id'     => (string) $row['client_id'],
            'client_name'   => (string) $row['client_name'],
            'redirect_uris' => is_array($redirects) ? array_values($redirects) : array(),
        );
    }

    public function countClients(): ?int
    {
        global $wpdb;

        $value = $wpdb->get_var(
            $wpdb->prepare('SELECT COUNT(*) FROM %i', self::clientsTable())
        );

        return is_numeric($value) ? max(0, (int) $value) : null;
    }

    public function cleanupExpiredTokens(): bool
    {
        global $wpdb;

        $deleted = $wpdb->query(
            $wpdb->prepare(
                'DELETE FROM %i WHERE expires_at <= %s LIMIT 200',
                self::tokensTable(),
                current_time('mysql', true)
            )
        );

        return false !== $deleted;
    }

    public function storeAuthorizationCode(
        string $code,
        string $clientId,
        int $userId,
        string $challenge,
        string $redirectUri
    ): bool {
        global $wpdb;

        $inserted = $wpdb->insert(
            self::tokensTable(),
            array(
                'token_hash'          => self::hash($code),
                'token_type'          => 'authorization_code',
                'client_id'           => $clientId,
                'user_id'             => $userId,
                'expires_at'          => gmdate('Y-m-d H:i:s', time() + $this->authorizationCodeTtl()),
                'auth_code_challenge' => $challenge,
                'redirect_uri'        => $redirectUri,
                'created_at'          => current_time('mysql', true),
            ),
            array('%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s')
        );

        return false !== $inserted;
    }

    /** @return array<string, mixed>|null */
    public function consumeAuthorizationCode(string $code): ?array
    {
        $hash = self::hash($code);
        $row  = $this->findToken($hash, 'authorization_code');

        if (null === $row || ! $this->deleteTokenHash($hash, 'authorization_code')) {
            return null;
        }

        return $row;
    }

    /**
     * @return array{access_token: string, refresh_token: string, expires_in: int}|WP_Error
     */
    public function issueTokens(string $clientId, int $userId): array|WP_Error
    {
        global $wpdb;

        $access      = self::randomToken(32);
        $refresh     = self::randomToken(48);
        $accessHash  = self::hash($access);
        $refreshHash = self::hash($refresh);
        $accessTtl   = $this->accessTokenTtl();
        $refreshTtl  = $this->refreshTokenTtl();
        $now         = current_time('mysql', true);

        $accessInserted = $wpdb->insert(
            self::tokensTable(),
            array(
                'token_hash' => $accessHash,
                'token_type' => 'access',
                'client_id'  => $clientId,
                'user_id'    => $userId,
                'expires_at' => gmdate('Y-m-d H:i:s', time() + $accessTtl),
                'created_at' => $now,
            ),
            array('%s', '%s', '%s', '%d', '%s', '%s')
        );

        if (false === $accessInserted) {
            return new WP_Error('wp_nerve_oauth_storage_failed', __('OAuth token storage is unavailable.', 'wp-nerve'));
        }

        $refreshInserted = $wpdb->insert(
            self::tokensTable(),
            array(
                'token_hash' => $refreshHash,
                'token_type' => 'refresh',
                'client_id'  => $clientId,
                'user_id'    => $userId,
                'expires_at' => gmdate('Y-m-d H:i:s', time() + $refreshTtl),
                'created_at' => $now,
            ),
            array('%s', '%s', '%s', '%d', '%s', '%s')
        );

        if (false === $refreshInserted) {
            $this->deleteTokenHash($accessHash, 'access');

            return new WP_Error('wp_nerve_oauth_storage_failed', __('OAuth token storage is unavailable.', 'wp-nerve'));
        }

        return array(
            'access_token'  => $access,
            'refresh_token' => $refresh,
            'expires_in'    => $accessTtl,
        );
    }

    /**
     * @return array{access_token: string, refresh_token: string, expires_in: int}|WP_Error
     */
    public function refreshAccessToken(string $refreshToken, string $clientId): array|WP_Error
    {
        $hash = self::hash($refreshToken);
        $row  = $this->findToken($hash, 'refresh');

        if (null === $row || (string) $row['client_id'] !== $clientId) {
            return new WP_Error('wp_nerve_invalid_refresh_token', __('The refresh token is invalid or expired.', 'wp-nerve'));
        }

        if (! $this->deleteTokenHash($hash, 'refresh')) {
            return new WP_Error('wp_nerve_invalid_refresh_token', __('The refresh token is invalid or already consumed.', 'wp-nerve'));
        }

        return $this->issueTokens($clientId, (int) $row['user_id']);
    }

    public function validateAccessToken(string $token): ?int
    {
        $identity = $this->validateAccessTokenIdentity($token);

        return null === $identity ? null : $identity['user_id'];
    }

    /** @return array{user_id: int, client_id: string}|null */
    public function validateAccessTokenIdentity(string $token): ?array
    {
        $row = $this->findToken(self::hash($token), 'access');

        if (null === $row) {
            return null;
        }

        return array(
            'user_id'   => (int) $row['user_id'],
            'client_id' => (string) $row['client_id'],
        );
    }

    public function revokeToken(string $token, string $clientId): bool
    {
        $hash = self::hash($token);

        foreach (array('access', 'refresh') as $type) {
            $row = $this->findToken($hash, $type);

            if (null === $row) {
                continue;
            }

            if ((string) $row['client_id'] !== $clientId) {
                return true;
            }

            return $this->deleteTokenHash($hash, $type);
        }

        // RFC-style revocation responses do not disclose whether a token existed.
        return true;
    }

    public static function installSchema(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $clients = self::clientsTable();
        $tokens  = self::tokensTable();

        dbDelta(
            "CREATE TABLE {$clients} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                client_id varchar(128) NOT NULL,
                client_name varchar(191) NOT NULL DEFAULT '',
                redirect_uris longtext NOT NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY client_id (client_id)
            ) {$charset};"
        );

        dbDelta(
            "CREATE TABLE {$tokens} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                token_hash char(64) NOT NULL,
                token_type varchar(32) NOT NULL,
                client_id varchar(128) NOT NULL,
                user_id bigint(20) unsigned NOT NULL,
                expires_at datetime NOT NULL,
                auth_code_challenge varchar(128) NULL,
                redirect_uri text NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY token_hash (token_hash),
                KEY client_id (client_id),
                KEY user_id (user_id),
                KEY expires_at (expires_at)
            ) {$charset};"
        );
    }

    /** @return array<string, mixed>|null */
    private function findToken(string $hash, string $type): ?array
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM %i WHERE token_hash = %s AND token_type = %s AND expires_at > %s LIMIT 1',
                self::tokensTable(),
                $hash,
                $type,
                current_time('mysql', true)
            ),
            'ARRAY_A'
        );

        return is_array($row) ? $row : null;
    }

    private function deleteTokenHash(string $hash, string $type): bool
    {
        global $wpdb;

        $deleted = $wpdb->delete(
            self::tokensTable(),
            array('token_hash' => $hash, 'token_type' => $type),
            array('%s', '%s')
        );

        return 1 === $deleted;
    }

    private function authorizationCodeTtl(): int
    {
        $ttl = apply_filters('wp_nerve_oauth_authorization_code_ttl', 300);

        return is_int($ttl) && $ttl >= 60 && $ttl <= 600 ? $ttl : 300;
    }

    private function accessTokenTtl(): int
    {
        $ttl = apply_filters('wp_nerve_oauth_access_token_ttl', 3600);

        return is_int($ttl) && $ttl >= 300 && $ttl <= 86400 ? $ttl : 3600;
    }

    private function refreshTokenTtl(): int
    {
        $ttl = apply_filters('wp_nerve_oauth_refresh_token_ttl', 2592000);

        return is_int($ttl) && $ttl >= 3600 && $ttl <= 7776000 ? $ttl : 2592000;
    }

    private static function clientsTable(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'wp_nerve_oauth_clients';
    }

    private static function tokensTable(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'wp_nerve_oauth_tokens';
    }

    private static function hash(string $value): string
    {
        return hash('sha256', $value);
    }

    private static function randomToken(int $bytes): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }
}
