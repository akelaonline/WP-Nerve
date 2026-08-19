<?php

/**
 * OAuth 2.1 authorization server for MCP clients.
 *
 * Implements the authorization code grant with PKCE (S256) for public
 * clients, dynamic client registration, and the authorization server
 * metadata document. Used by clients that cannot send Application
 * Passwords (Claude web and mobile connectors).
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\OAuth;

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

        $clientId        = sanitize_text_field((string) $request->get_param('client_id'));
        $redirectUri     = sanitize_text_field((string) $request->get_param('redirect_uri'));
        $state           = sanitize_text_field((string) $request->get_param('state'));
        $responseType    = (string) $request->get_param('response_type');
        $challenge       = sanitize_text_field((string) $request->get_param('code_challenge'));
        $challengeMethod = (string) $request->get_param('code_challenge_method');

        $client = $this->store->getClient($clientId);

        if (null === $client || ! $this->allowsRedirect($client, $redirectUri)) {
            return new WP_REST_Response(
                array('error' => 'invalid_request', 'error_description' => 'Invalid client or redirect URI.'),
                400
            );
        }

        if ('code' !== $responseType || '' === $challenge || 'S256' !== $challengeMethod) {
            return new WP_REST_Response(
                array(
                    'error'             => 'invalid_request',
                    'error_description' => 'PKCE with code_challenge_method S256 is required.',
                ),
                400
            );
        }

        if (! is_user_logged_in()) {
            $authorizeUrl = add_query_arg(
                $request->get_params(),
                rest_url('wp-nerve/v1/oauth/authorize')
            );

            return $this->redirect(wp_login_url($authorizeUrl), $request);
        }

        if ('POST' !== $request->get_method()) {
            return $this->consentPage($client, $redirectUri, $state, $challenge);
        }

        $consent = (string) $request->get_param('wp_nerve_consent');
        $nonce   = (string) $request->get_param('wp_nerve_oauth_nonce');

        if ('allow' !== $consent) {
            return $this->redirectToClient($redirectUri, array('error' => 'access_denied', 'state' => $state));
        }

        if (! wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            return $this->redirectToClient($redirectUri, array('error' => 'access_denied', 'state' => $state));
        }

        $code = bin2hex(random_bytes(24));

        $this->store->storeAuthorizationCode(
            $code,
            $clientId,
            get_current_user_id(),
            $challenge,
            $redirectUri
        );

        return $this->redirectToClient($redirectUri, array('code' => $code, 'state' => $state));
    }

    public function token(WP_REST_Request $request): WP_REST_Response
    {
        $limited = $this->guardRateLimit('oauth_token');

        if (null !== $limited) {
            return $limited;
        }

        $grantType = (string) $request->get_param('grant_type');

        if ('authorization_code' === $grantType) {
            return $this->tokenFromCode($request);
        }

        if ('refresh_token' === $grantType) {
            return $this->tokenFromRefresh($request);
        }

        return new WP_REST_Response(
            array(
                'error'             => 'unsupported_grant_type',
                'error_description' => 'Only authorization_code and refresh_token are supported.',
            ),
            400
        );
    }

    public function registerClient(WP_REST_Request $request): WP_REST_Response
    {
        $limited = $this->guardRateLimit('oauth_register');

        if (null !== $limited) {
            return $limited;
        }

        $body = $request->get_json_params();

        if (! is_array($body) || empty($body['client_name']) || empty($body['redirect_uris'])) {
            return new WP_REST_Response(
                array('error' => 'invalid_client_metadata', 'error_description' => 'client_name and redirect_uris are required.'),
                400
            );
        }

        $clientId = $this->store->createClient(array(
            'client_name'   => (string) $body['client_name'],
            'redirect_uris' => $body['redirect_uris'],
        ));

        $base = rtrim(rest_url('wp-nerve/v1/oauth'), '/');

        return new WP_REST_Response(
            array(
                'client_id'                  => $clientId,
                'client_name'                => (string) $body['client_name'],
                'redirect_uris'              => $body['redirect_uris'],
                'grant_types'                => array('authorization_code', 'refresh_token'),
                'token_endpoint_auth_method' => 'none',
                'authorization_endpoint'     => $base . '/authorize',
                'token_endpoint'             => $base . '/token',
            ),
            201
        );
    }

    public function metadata(): WP_REST_Response
    {
        $base = rtrim(rest_url('wp-nerve/v1/oauth'), '/');

        return new WP_REST_Response(
            array(
                'issuer'                                => site_url('/'),
                'authorization_endpoint'                => $base . '/authorize',
                'token_endpoint'                        => $base . '/token',
                'registration_endpoint'                 => $base . '/register',
                'response_types_supported'              => array('code'),
                'grant_types_supported'                 => array('authorization_code', 'refresh_token'),
                'code_challenge_methods_supported'      => array('S256'),
                'token_endpoint_auth_methods_supported' => array('none'),
                'revocation_endpoint'                   => $base . '/revoke',
            )
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function tokenResponse(array $payload): WP_REST_Response
    {
        $response = new WP_REST_Response($payload, 200);

        $response->header('Cache-Control', 'no-store');
        $response->header('Pragma', 'no-cache');

        return $response;
    }

    private function tokenFromCode(WP_REST_Request $request): WP_REST_Response
    {
        $clientId    = sanitize_text_field((string) $request->get_param('client_id'));
        $code        = (string) $request->get_param('code');
        $verifier    = (string) $request->get_param('code_verifier');
        $redirectUri = sanitize_text_field((string) $request->get_param('redirect_uri'));

        $record = $this->store->consumeAuthorizationCode($code);

        if (null === $record || $record['client_id'] !== $clientId || $record['redirect_uri'] !== $redirectUri) {
            return new WP_REST_Response(
                array('error' => 'invalid_grant', 'error_description' => 'The authorization code is invalid.'),
                400
            );
        }

        if (! hash_equals((string) $record['auth_code_challenge'], $this->pkceChallenge($verifier))) {
            return new WP_REST_Response(
                array('error' => 'invalid_grant', 'error_description' => 'The code verifier is invalid.'),
                400
            );
        }

        $tokens = $this->store->issueTokens($clientId, (int) $record['user_id']);

        return $this->tokenResponse(
            array_merge(
                $tokens,
                array('token_type' => 'Bearer', 'scope' => 'mcp')
            )
        );
    }

    private function tokenFromRefresh(WP_REST_Request $request): WP_REST_Response
    {
        $clientId     = sanitize_text_field((string) $request->get_param('client_id'));
        $refreshToken = (string) $request->get_param('refresh_token');

        $result = $this->store->refreshAccessToken($refreshToken, $clientId);

        if (is_wp_error($result)) {
            return new WP_REST_Response(
                array('error' => 'invalid_grant', 'error_description' => $result->get_error_message()),
                400
            );
        }

        return $this->tokenResponse(
            array_merge($result, array('token_type' => 'Bearer', 'scope' => 'mcp'))
        );
    }

    /**
     * @param array<string, mixed> $client
     */
    private function allowsRedirect(array $client, string $redirectUri): bool
    {
        $uris = is_array($client['redirect_uris'] ?? null) ? $client['redirect_uris'] : array();

        return in_array($redirectUri, $uris, true);
    }

    private function pkceChallenge(string $verifier): string
    {
        // RFC 7636: BASE64URL-ENCODE(SHA256(ASCII(code_verifier))), unpadded.
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    private function redirect(string $url, WP_REST_Request $request): WP_REST_Response
    {
        unset($request);

        $response = new WP_REST_Response(null, 302);
        $response->header('Location', $url);

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

        return new WP_REST_Response($html, 200);
    }

    /**
     * @param array<string, string> $params
     */
    private function redirectToClient(string $redirectUri, array $params): WP_REST_Response
    {
        $separator = str_contains($redirectUri, '?') ? '&' : '?';

        $response = new WP_REST_Response(null, 302);
        $response->header('Location', $redirectUri . $separator . http_build_query($params));

        return $response;
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
        $status = $decision->available ? 429 : 503;
        $error  = $decision->available ? 'slow_down' : 'temporarily_unavailable';
        $description = $decision->available
            ? 'Too many requests. Retry after the current rate-limit window.'
            : 'The authorization server cannot verify its rate-limit budget.';

        $response = new WP_REST_Response(
            array('error' => $error, 'error_description' => $description),
            $status
        );
        $response->header('Cache-Control', 'no-store');
        $response->header('Pragma', 'no-cache');
        $response->header('Retry-After', (string) $decision->retryAfter($this->rateLimiter?->now() ?? time()));
        $response->header('X-RateLimit-Limit', (string) $decision->limit);
        $response->header('X-RateLimit-Remaining', (string) $decision->remaining);
        $response->header('X-RateLimit-Reset', (string) $decision->resetAt);

        return $response;
    }
}
