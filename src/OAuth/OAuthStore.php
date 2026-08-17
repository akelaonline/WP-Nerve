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

        $wpdb->insert(
            self::clientsTable(),
            array(
                'client_id'     => $clientId,
                'client_name'   => self::text((string) ($client['client_name'] ?? 'MCP client'), 191),
                'redirect_uris' => wp_json_encode($this->redirectUris($client)),
                'created_at'    => current_time('mysql', true),
            ),
            array('%s', '%s', '%s', '%s')
        );

        return $clientId;
    }

    /** @return array<string, mixed>|null */
    public function getClient(string $clientId): ?array
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM %i WHERE client_id = %s', self::clientsTable(), $clientId),
            'ARRAY_A'
        );

        if (! is_array($row)) {
            return null;
        }

        $row['redirect_uris'] = json_decode((string) ($row['redirect_uris'] ?? '[]'), true);

        return is_array($row['redirect_uris']) ? $row : null;
    }

    public function storeAuthorizationCode(
        string $code,
        string $clientId,
        int $userId,
        string $challenge,
        string $redirectUri
    ): void {
        global $wpdb;

        $wpdb->insert(
            self::tokensTable(),
            array(
                'token_hash'          => self::hash($code),
                'token_type'          => 'authorization_code',
                'client_id'           => $clientId,
                'user_id'             => $userId,
                'auth_code_challenge' => $challenge,
                'redirect_uri'        => $redirectUri,
                'expires_at'          => gmdate('Y-m-d H:i:s', time() + 600),
                'created_at'          => current_time('mysql', true),
            ),
            array('%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s')
        );
    }

    /**
     * Issues an access token and refresh token pair for a user.
     *
     * @return array{access_token: string, refresh_token: string, expires_in: int}
     */
    public function issueTokens(string $clientId, int $userId): array
    {
        global $wpdb;

        $accessToken  = self::randomToken(43);
        $refreshToken = self::randomToken(43);
        $expiresAt    = gmdate('Y-m-d H:i:s', time() + self::accessTtl());

        foreach (array(array($accessToken, 'access_token'), array($refreshToken, 'refresh_token')) as $entry) {
            $wpdb->insert(
                self::tokensTable(),
                array(
                    'token_hash' => self::hash($entry[0]),
                    'token_type' => $entry[1],
                    'client_id'  => $clientId,
                    'user_id'    => $userId,
                    'auth_code_challenge' => '',
                    'redirect_uri'        => '',
                    'expires_at'          => $expiresAt,
                    'created_at'          => current_time('mysql', true),
                ),
                array('%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s')
            );
        }

        return array(
            'access_token'  => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_in'    => self::accessTtl(),
        );
    }

    /**
     * Validates an access token and returns the user ID it belongs to.
     */
    public function validateAccessToken(string $token): ?int
    {
        $row = $this->findToken($token, 'access_token');

        if (null === $row || ! $this->isActive($row)) {
            return null;
        }

        return (int) $row['user_id'];
    }

    /** @return array<string, mixed>|null */
    public function consumeAuthorizationCode(string $code): ?array
    {
        global $wpdb;

        $row = $this->findToken($code, 'authorization_code');

        if (null === $row || ! $this->isActive($row)) {
            return null;
        }

        $wpdb->delete(
            self::tokensTable(),
            array('token_hash' => self::hash($code)),
            array('%s')
        );

        return $row;
    }

    /**
     * Rotates a refresh token.
     *
     * @return array<string, mixed>|WP_Error
     */
    public function refreshAccessToken(string $refreshToken, string $clientId): array|WP_Error
    {
        global $wpdb;

        $row = $this->findToken($refreshToken, 'refresh_token');

        if (null === $row || ! $this->isActive($row)) {
            return new WP_Error('wp_nerve_oauth_invalid_grant', 'The refresh token is invalid or expired.');
        }

        if ($row['client_id'] !== $clientId) {
            return new WP_Error('wp_nerve_oauth_invalid_client', 'The refresh token does not belong to this client.');
        }

        $wpdb->delete(
            self::tokensTable(),
            array('token_hash' => self::hash($refreshToken)),
            array('%s')
        );

        return $this->issueTokens($clientId, (int) $row['user_id']);
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
                client_id varchar(64) NOT NULL,
                client_name varchar(191) NOT NULL DEFAULT '',
                redirect_uris text NOT NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY client_id (client_id)
            ) {$charset};"
        );

        dbDelta(
            "CREATE TABLE {$tokens} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                token_hash varchar(64) NOT NULL,
                token_type varchar(20) NOT NULL DEFAULT '',
                client_id varchar(64) NOT NULL DEFAULT '',
                user_id bigint(20) unsigned NOT NULL DEFAULT 0,
                auth_code_challenge varchar(128) NOT NULL DEFAULT '',
                redirect_uri varchar(500) NOT NULL DEFAULT '',
                expires_at datetime NOT NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY token_hash (token_hash),
                KEY token_type (token_type),
                KEY expires_at (expires_at)
            ) {$charset};"
        );
    }

    public static function accessTtl(): int
    {
        /**
         * Filters the access token lifetime in seconds.
         *
         * @param int $seconds Token lifetime.
         */
        $ttl = apply_filters('wp_nerve_oauth_access_ttl', 3600);

        return is_int($ttl) && $ttl > 0 ? $ttl : 3600;
    }

    /** @return array<string, mixed>|null */
    private function findToken(string $token, string $type): ?array
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM %i WHERE token_hash = %s AND token_type = %s',
                self::tokensTable(),
                self::hash($token),
                $type
            ),
            'ARRAY_A'
        );

        return is_array($row) ? $row : null;
    }

    /** @param array<string, mixed> $row */
    private function isActive(array $row): bool
    {
        $expires = strtotime((string) ($row['expires_at'] ?? ''));

        return false !== $expires && $expires > time();
    }

    /**
     * @param array<string, mixed> $client
     * @return array<int, string>
     */
    private function redirectUris(array $client): array
    {
        $uris = $client['redirect_uris'] ?? array();

        if (! is_array($uris)) {
            return array();
        }

        $clean = array();

        foreach ($uris as $uri) {
            if (is_string($uri) && '' !== $uri) {
                $clean[] = $uri;
            }
        }

        return array_values(array_unique($clean));
    }

    private static function hash(string $value): string
    {
        return hash('sha256', $value);
    }

    /**
     * @param int<1, max> $bytes
     */
    private static function randomToken(int $bytes): string
    {
        return bin2hex(random_bytes($bytes));
    }

    private static function text(string $value, int $length): string
    {
        $value = sanitize_text_field($value);

        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $length);
        }

        return wp_check_invalid_utf8(substr($value, 0, $length), true);
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
}
