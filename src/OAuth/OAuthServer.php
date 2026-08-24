<?php

/**
 * OAuth 2.1 authorization server for MCP clients.
 *
 * Implements authorization code + PKCE for public clients, dynamic client
 * registration, refresh-token rotation and token revocation.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\OAuth;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WPNerve\Security\RateLimit\ClientAddress;
use WPNerve\Security\RateLimit\RateLimiter;
use WPNerve\Security\RateLimit\Result as RateLimitResult;

final class OAuthServer
{
    private const NONCE_ACTION = 'wp_nerve_oauth_consent';

    public function __construct(
        private readonly OAuthStore $store,
        private readonly ?RateLimiter $rateLimiter = null,
        private readonly ?ClientAddress $clientAddress = null
    ) {
    }

    public function registerRoutes(): void
    {
        register_rest_route(
            'wp-nerve/v1',
            '/oauth/authorize',
            array(
                'methods'             => array('GET', 'POST'),
                'callback'            => array($this, 'authorize'),
                'permission_callback' => '__return_true',
            )
        );

        register_rest_route(
            'wp-nerve/v1',
            '/oauth/token',
            array(
                'methods'             => 'POST',
                'callback'            => array($this, 'token'),
                'permission_callback' => '__return_true',
            )
        );

        register_rest_route(
            'wp-nerve/v1',
            '/oauth/revoke',
            array(
                'methods'             => 'POST',
                'callback'            => array($this, 'revoke'),
                'permission_callback' => '__return_true',
            )
        );

        register_rest_route(
            'wp-nerve/v1',
            '/oauth/register',
            array(
                'methods'             => 'POST',
                'callback'            => array($this, 'registerClient'),
                'permission_callback' => '__return_true',
            )
        );

        register_rest_route(
            'wp-nerve/v1',
            '/oauth/.well-known/oauth-authorization-server',
            array(
                'methods'             => 'GET',
                'callback'            => array($this, 'metadata'),
                'permission_callback' => '__return_true',
            )
        );
    }

    public function authorize(WP_REST_Request $request): WP_REST_Response
    {
        $limited = $this->guardRateLimit('oauth_authorize');

        if (null !== $limited) {
            return $limited;
        }

        if (! $this->store->cleanupExpiredTokens()) {
            return $this->oauthError('temporarily_unavailable', 'OAuth storage maintenance is unavailable.', 503);
        }

        $clientId        = sanitize_text_field((string) $request->get_param('client_id'));
        $redirectUri     = sanitize_text_field((string) $request->get_param('redirect_uri'));
        $state           = sanitize_text_field((string) $request->get_param('state'));
        $responseType    = (string) $request->get_param('response_type');
        $challenge       = sanitize_text_field((string) $request->get_param('code_challenge'));
        $challengeMethod = (string) $request->get_param('code_challenge_method');

        $client = $this->store->getClient($clientId);

        if (null === $client || ! $this->allowsRedirect($client, $redirectUri)) {
            return $this->oauthError('invalid_request', 'Invalid client or redirect URI.', 400);
        }

        if ('' === $state || strlen($state) > 512) {
            return $this->oauthError('invalid_request', 'A non-empty state value of at most 512 bytes is required.', 400);
        }

        if ('code' !== $responseType || 'S256' !== $challengeMethod || ! $this->validCodeChallenge($challenge)) {
            return $this->oauthError(
                'invalid_request',
                'PKCE with a valid S256 code_challenge is required.',
                400
            );
        }

        if (! is_user_logged_in()) {
            $authorizeUrl = add_query_arg(
                $request->get_params(),
                rest_url('wp-nerve/v1/oauth/authorize')
            );

            return $this->redirect(wp_login_url($authorizeUrl));
        }

        if ('POST' !== $request->get_method()) {
            return $this->consentPage($client, $redirectUri, $state, $challenge);
        }

        $consent = (string) $request->get_param('wp_nerve_consent');
        $nonce   = (string) $request->get_param('wp_nerve_oauth_nonce');

        if ('allow' !== $consent || ! wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            return $this->redirectToClient($redirectUri, array('error' => 'access_denied', 'state' => $state));
        }

        $code = bin2hex(random_bytes(24));

        if (! $this->store->storeAuthorizationCode(
            $code,
            $clientId,
            get_current_user_id(),
            $challenge,
            $redirectUri
        )) {
            return $this->oauthError('temporarily_unavailable', 'The authorization code could not be stored.', 503);
        }

        return $this->redirectToClient($redirectUri, array('code' => $code, 'state' => $state));
    }

    public function token(WP_REST_Request $request): WP_REST_Response
    {
        $limited = $this->guardRateLimit('oauth_token');

        if (null !== $limited) {
            return $limited;
        }

        if (! $this->store->cleanupExpiredTokens()) {
            return $this->oauthError('temporarily_unavailable', 'OAuth storage maintenance is unavailable.', 503);
        }

        $grantType = (string) $request->get_param('grant_type');

        if ('authorization_code' === $grantType) {
            return $this->tokenFromCode($request);
        }

        if ('refresh_token' === $grantType) {
            return $this->tokenFromRefresh($request);
        }

        return $this->oauthError(
            'unsupported_grant_type',
            'Only authorization_code and refresh_token are supported.',
            400
        );
    }

    public function revoke(WP_REST_Request $request): WP_REST_Response
    {
        $limited = $this->guardRateLimit('oauth_revoke');

        if (null !== $limited) {
            return $limited;
        }

        if (! $this->store->cleanupExpiredTokens()) {
            return $this->oauthError('temporarily_unavailable', 'OAuth storage maintenance is unavailable.', 503);
        }

        $clientId = sanitize_text_field((string) $request->get_param('client_id'));
        $token    = trim((string) $request->get_param('token'));

        if ('' === $clientId || '' === $token || null === $this->store->getClient($clientId)) {
            return $this->oauthError('invalid_request', 'A registered client_id and token are required.', 400);
        }

        if (! $this->store->revokeToken($token, $clientId)) {
            return $this->oauthError('temporarily_unavailable', 'The token could not be revoked.', 503);
        }

        // Do not disclose whether the supplied token existed or belonged to the client.
        return $this->noStoreResponse(array(), 200);
    }

    public function registerClient(WP_REST_Request $request): WP_REST_Response
    {
        $limited = $this->guardRateLimit('oauth_register');

        if (null !== $limited) {
            return $limited;
        }

        if (! $this->store->cleanupExpiredTokens()) {
            return $this->oauthError('temporarily_unavailable', 'OAuth storage maintenance is unavailable.', 503);
        }

        $body = $request->get_json_params();

        if (! is_array($body)) {
            return $this->oauthError('invalid_client_metadata', 'A JSON registration object is required.', 400);
        }

        $clientName   = isset($body['client_name']) && is_string($body['client_name'])
            ? trim($body['client_name'])
            : '';
        $redirectUris = $body['redirect_uris'] ?? null;

        if (
            '' === $clientName
            || strlen($clientName) > 191
            || ! is_array($redirectUris)
            || ! $this->validRedirectUris($redirectUris)
            || ! $this->validRegistrationProfile($body)
        ) {
            return $this->oauthError(
                'invalid_client_metadata',
                'Registration requires a valid client_name, redirect_uris, public-client profile and exact redirect URIs.',
                400
            );
        }

        $count = $this->store->countClients();

        if (null === $count) {
            return $this->oauthError('temporarily_unavailable', 'OAuth client storage is unavailable.', 503);
        }

        if ($count >= $this->clientLimit()) {
            $response = $this->oauthError('temporarily_unavailable', 'OAuth dynamic client capacity has been reached.', 429);
            $response->header('Retry-After', '3600');

            return $response;
        }

        $redirectUris = array_values(array_unique($redirectUris));
        $clientId     = $this->store->createClient(array(
            'client_name'   => $clientName,
            'redirect_uris' => $redirectUris,
        ));

        if ('' === $clientId) {
            return $this->oauthError('temporarily_unavailable', 'The OAuth client could not be stored.', 503);
        }

        $base = rtrim(rest_url('wp-nerve/v1/oauth'), '/');

        return $this->noStoreResponse(
            array(
                'client_id'                  => $clientId,
                'client_name'                => $clientName,
                'redirect_uris'              => $redirectUris,
                'grant_types'                => array('authorization_code', 'refresh_token'),
                'response_types'             => array('code'),
                'token_endpoint_auth_method' => 'none',
                'authorization_endpoint'     => $base . '/authorize',
                'token_endpoint'             => $base . '/token',
                'revocation_endpoint'        => $base . '/revoke',
            ),
            201
        );
    }

    public function metadata(): WP_REST_Response
    {
        $base = rtrim(rest_url('wp-nerve/v1/oauth'), '/');

        return $this->noStoreResponse(
            array(
                'issuer'                                => site_url('/'),
                'authorization_endpoint'                => $base . '/authorize',
                'token_endpoint'                        => $base . '/token',
                'registration_endpoint'                 => $base . '/register',
                'revocation_endpoint'                   => $base . '/revoke',
                'response_types_supported'              => array('code'),
                'grant_types_supported'                 => array('authorization_code', 'refresh_token'),
                'code_challenge_methods_supported'      => array('S256'),
                'token_endpoint_auth_methods_supported' => array('none'),
            ),
            200
        );
    }

    private function tokenFromCode(WP_REST_Request $request): WP_REST_Response
    {
        $clientId    = sanitize_text_field((string) $request->get_param('client_id'));
        $code        = trim((string) $request->get_param('code'));
        $verifier    = (string) $request->get_param('code_verifier');
        $redirectUri = sanitize_text_field((string) $request->get_param('redirect_uri'));

        if (
            '' === $clientId
            || '' === $code
            || '' === $redirectUri
            || ! $this->validCodeVerifier($verifier)
        ) {
            return $this->oauthError('invalid_request', 'client_id, code, redirect_uri and a valid PKCE verifier are required.', 400);
        }

        $record = $this->store->consumeAuthorizationCode($code);

        if (null === $record || $record['client_id'] !== $clientId || $record['redirect_uri'] !== $redirectUri) {
            return $this->oauthError('invalid_grant', 'The authorization code is invalid.', 400);
        }

        if (! hash_equals((string) $record['auth_code_challenge'], $this->pkceChallenge($verifier))) {
            return $this->oauthError('invalid_grant', 'The code verifier is invalid.', 400);
        }

        $tokens = $this->store->issueTokens($clientId, (int) $record['user_id']);

        if (is_wp_error($tokens)) {
            return $this->oauthError('temporarily_unavailable', $tokens->get_error_message(), 503);
        }

        return $this->tokenResponse(array_merge($tokens, array('token_type' => 'Bearer', 'scope' => 'mcp')));
    }

    private function tokenFromRefresh(WP_REST_Request $request): WP_REST_Response
    {
        $clientId     = sanitize_text_field((string) $request->get_param('client_id'));
        $refreshToken = trim((string) $request->get_param('refresh_token'));

        if ('' === $clientId || '' === $refreshToken) {
            return $this->oauthError('invalid_request', 'client_id and refresh_token are required.', 400);
        }

        $result = $this->store->refreshAccessToken($refreshToken, $clientId);

        if (is_wp_error($result)) {
            $storageFailure = 'wp_nerve_oauth_storage_failed' === $result->get_error_code();

            return $this->oauthError(
                $storageFailure ? 'temporarily_unavailable' : 'invalid_grant',
                $result->get_error_message(),
                $storageFailure ? 503 : 400
            );
        }

        return $this->tokenResponse(array_merge($result, array('token_type' => 'Bearer', 'scope' => 'mcp')));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function tokenResponse(array $payload): WP_REST_Response
    {
        return $this->noStoreResponse($payload, 200);
    }

    /**
     * @param array<string, mixed> $client
     */
    private function allowsRedirect(array $client, string $redirectUri): bool
    {
        $uris = is_array($client['redirect_uris'] ?? null) ? $client['redirect_uris'] : array();

        return in_array($redirectUri, $uris, true);
    }

    /** @param array<int, mixed> $uris */
    private function validRedirectUris(array $uris): bool
    {
        if (array() === $uris || count($uris) > 5) {
            return false;
        }

        foreach ($uris as $uri) {
            if (! is_string($uri) || ! $this->validRedirectUri($uri)) {
                return false;
            }
        }

        return count($uris) === count(array_unique($uris));
    }

    private function validRedirectUri(string $uri): bool
    {
        if ('' === $uri || strlen($uri) > 500) {
            return false;
        }

        $parts = wp_parse_url($uri);

        if (
            ! is_array($parts)
            || ! is_string($parts['scheme'] ?? null)
            || ! is_string($parts['host'] ?? null)
            || '' === $parts['host']
            || isset($parts['fragment'])
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            return false;
        }

        $scheme = strtolower($parts['scheme']);
        $host   = strtolower(trim($parts['host'], '[]'));

        if ('https' === $scheme) {
            return true;
        }

        return 'http' === $scheme && in_array($host, array('127.0.0.1', '::1'), true);
    }

    /** @param array<string, mixed> $body */
    private function validRegistrationProfile(array $body): bool
    {
        if (
            isset($body['token_endpoint_auth_method'])
            && 'none' !== $body['token_endpoint_auth_method']
        ) {
            return false;
        }

        if (isset($body['response_types'])) {
            if (! is_array($body['response_types']) || array('code') !== array_values($body['response_types'])) {
                return false;
            }
        }

        if (isset($body['grant_types'])) {
            if (! is_array($body['grant_types'])) {
                return false;
            }

            $grants = array_values(array_unique($body['grant_types']));
            sort($grants);

            if ($grants !== array('authorization_code', 'refresh_token')) {
                return false;
            }
        }

        return true;
    }

    private function validCodeChallenge(string $challenge): bool
    {
        return 1 === preg_match('/^[A-Za-z0-9_-]{43}$/', $challenge);
    }

    private function validCodeVerifier(string $verifier): bool
    {
        $length = strlen($verifier);

        return $length >= 43
            && $length <= 128
            && 1 === preg_match('/^[A-Za-z0-9\-._~]+$/', $verifier);
    }

    private function pkceChallenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    private function redirect(string $url): WP_REST_Response
    {
        $response = new WP_REST_Response(null, 302);
        $response->header('Location', $url);
        $this->applyNoStore($response);

        return $response;
    }

    /**
     * @param array<string, mixed> $client
     */
    private function consentPage(array $client, string $redirectUri, string $state, string $challenge): WP_REST_Response
    {
        $clientName = esc_html((string) ($client['client_name'] ?? 'MCP client'));
        $siteName   = esc_html((string) get_bloginfo('name'));

        $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Authorize MCP client</title></head>'
            . '<body style="font-family: sans-serif; max-width: 560px; margin: 48px auto">'
            . '<h1>' . esc_html__('Authorize', 'wp-nerve') . '</h1>'
            . '<p>' . esc_html__('Allow', 'wp-nerve') . ' <strong>' . $clientName . '</strong> '
            . esc_html__('to access', 'wp-nerve') . ' <strong>' . $siteName . '</strong> '
            . esc_html__('through MCP as', 'wp-nerve') . ' <strong>' . esc_html(wp_get_current_user()->display_name) . '</strong>?</p>'
            . '<form method="post">'
            . '<input type="hidden" name="client_id" value="' . esc_attr($client['client_id'] ?? '') . '">'
            . '<input type="hidden" name="redirect_uri" value="' . esc_attr($redirectUri) . '">'
            . '<input type="hidden" name="state" value="' . esc_attr($state) . '">'
            . '<input type="hidden" name="code_challenge" value="' . esc_attr($challenge) . '">'
            . '<input type="hidden" name="code_challenge_method" value="S256">'
            . '<input type="hidden" name="response_type" value="code">'
            . '<input type="hidden" name="wp_nerve_consent" value="allow">'
            . wp_nonce_field(self::NONCE_ACTION, 'wp_nerve_oauth_nonce', true, false)
            . '<button type="submit" style="padding: 8px 16px">' . esc_html__('Allow access', 'wp-nerve') . '</button>'
            . '</form></body></html>';

        return $this->noStoreResponse($html, 200);
    }

    /**
     * @param array<string, string> $params
     */
    private function redirectToClient(string $redirectUri, array $params): WP_REST_Response
    {
        $separator = str_contains($redirectUri, '?') ? '&' : '?';

        return $this->redirect($redirectUri . $separator . http_build_query($params));
    }

    private function guardRateLimit(string $bucket): ?WP_REST_Response
    {
        if (null === $this->rateLimiter || null === $this->clientAddress) {
            return null;
        }

        $decision = $this->rateLimiter->consume($bucket, $this->clientAddress->resolve());

        if ($decision->available && $decision->allowed) {
            return null;
        }

        return $this->rateLimitResponse($decision);
    }

    private function rateLimitResponse(RateLimitResult $decision): WP_REST_Response
    {
        $status      = $decision->available ? 429 : 503;
        $error       = $decision->available ? 'slow_down' : 'temporarily_unavailable';
        $description = $decision->available
            ? 'Too many requests. Retry after the current rate-limit window.'
            : 'The authorization server cannot verify its rate-limit budget.';

        $response = $this->oauthError($error, $description, $status);
        $response->header('Retry-After', (string) $decision->retryAfter($this->rateLimiter?->now() ?? time()));
        $response->header('X-RateLimit-Limit', (string) $decision->limit);
        $response->header('X-RateLimit-Remaining', (string) $decision->remaining);
        $response->header('X-RateLimit-Reset', (string) $decision->resetAt);

        return $response;
    }

    private function clientLimit(): int
    {
        $limit = apply_filters('wp_nerve_oauth_client_limit', 100);

        return is_int($limit) && $limit >= 1 && $limit <= 1000 ? $limit : 100;
    }

    private function oauthError(string $error, string $description, int $status): WP_REST_Response
    {
        return $this->noStoreResponse(
            array('error' => $error, 'error_description' => $description),
            $status
        );
    }

    private function noStoreResponse(mixed $data, int $status): WP_REST_Response
    {
        $response = new WP_REST_Response($data, $status);
        $this->applyNoStore($response);

        return $response;
    }

    private function applyNoStore(WP_REST_Response $response): void
    {
        $response->header('Cache-Control', 'no-store');
        $response->header('Pragma', 'no-cache');
        $response->header('X-Content-Type-Options', 'nosniff');
    }
}
