<?php

/**
 * WordPress REST binding for MCP Streamable HTTP.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Transport;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WPNerve\OAuth\OAuthStore;
use WPNerve\Protocol\DispatchResult;
use WPNerve\Protocol\JsonRpcHandler;
use WPNerve\Protocol\ProtocolError;
use WPNerve\Protocol\RequestValidator;
use WPNerve\Security\RateLimit\ClientAddress;
use WPNerve\Security\RateLimit\RateLimiter;
use WPNerve\Security\RateLimit\Result as RateLimitResult;

final class HttpTransport
{
    private const NAMESPACE = 'wp-nerve/v1';
    private const ROUTE     = '/mcp';

    private string $credentialId = '';

    public function __construct(
        private readonly RequestValidator $validator,
        private readonly JsonRpcHandler $handler,
        private readonly ?RateLimiter $rateLimiter = null,
        private readonly ?ClientAddress $clientAddress = null
    ) {
    }

    public function registerRoutes(): void
    {
        register_rest_route(
            self::NAMESPACE,
            self::ROUTE,
            array(
                array(
                    'methods'             => 'POST',
                    'callback'            => array($this, 'handle'),
                    'permission_callback' => array($this, 'checkPermission'),
                ),
                array(
                    'methods'             => array('GET', 'DELETE'),
                    'callback'            => array($this, 'methodNotAllowed'),
                    'permission_callback' => array($this, 'checkPermission'),
                ),
            )
        );
    }

    public function checkPermission(WP_REST_Request $request): bool|WP_Error
    {
        $this->credentialId = '';

        if (null !== $this->rateLimiter && null !== $this->clientAddress) {
            $decision = $this->rateLimiter->consume('mcp', $this->clientAddress->resolve());

            if (! $decision->available || ! $decision->allowed) {
                return $this->rateLimitError($decision);
            }
        }

        $origin = $request->get_header('origin');

        if (is_string($origin) && '' !== $origin && ! $this->isAllowedOrigin($origin)) {
            return new WP_Error(
                'wp_nerve_invalid_origin',
                __('The request Origin is not allowed to access WPNerve.', 'wp-nerve'),
                array('status' => 403)
            );
        }

        $authorization = $request->get_header('authorization');

        if (is_string($authorization) && str_starts_with(strtolower($authorization), 'bearer ')) {
            $token    = trim(substr($authorization, 7));
            $identity = (new OAuthStore())->validateAccessTokenIdentity($token);

            if (null === $identity) {
                return new WP_Error(
                    'wp_nerve_oauth_invalid_token',
                    __('The bearer token is invalid or expired.', 'wp-nerve'),
                    array('status' => 401)
                );
            }

            wp_set_current_user($identity['user_id']);
            $this->credentialId = 'oauth:' . $identity['client_id'];
        }

        if (! is_user_logged_in()) {
            return new WP_Error(
                'wp_nerve_authentication_required',
                __('Authentication is required to access WPNerve.', 'wp-nerve'),
                array('status' => 401)
            );
        }

        if ('' === $this->credentialId) {
            $applicationPassword = rest_get_authenticated_app_password();

            if (is_string($applicationPassword) && '' !== $applicationPassword) {
                $this->credentialId = 'application-password:' . $applicationPassword;
            } else {
                $sessionToken = wp_get_session_token();
                $this->credentialId = '' !== $sessionToken
                    ? 'wordpress-session:' . hash('sha256', $sessionToken)
                    : '';
            }
        }

        if (! current_user_can($this->transportCapability())) {
            return new WP_Error(
                'wp_nerve_forbidden',
                __('The authenticated user cannot access WPNerve.', 'wp-nerve'),
                array('status' => 403)
            );
        }

        if ('production' === wp_get_environment_type() && ! is_ssl()) {
            return new WP_Error(
                'wp_nerve_https_required',
                __('WPNerve requires HTTPS in production.', 'wp-nerve'),
                array('status' => 403)
            );
        }

        return true;
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        if (strlen($request->get_body()) > $this->maxRequestBytes()) {
            return $this->response(
                DispatchResult::error(new ProtocolError(-32600, 'Request body exceeds the configured size limit.', 413), null)
            );
        }

        $contentType = $request->get_header('content-type');

        if (! is_string($contentType) || ! str_contains(strtolower($contentType), 'application/json')) {
            return $this->response(
                DispatchResult::error(new ProtocolError(-32600, 'Content-Type must be application/json.', 415), null)
            );
        }

        $message = $request->get_json_params();

        if (! is_array($message)) {
            return $this->response(
                DispatchResult::error(new ProtocolError(-32700, 'Parse error: request body must contain one JSON-RPC object.'), null)
            );
        }

        $id      = $this->requestId($message);
        $headers = $this->headers($request);
        $common  = $this->validator->validateCommon($message);

        if (null !== $common) {
            return $this->response(DispatchResult::error($common, $id));
        }

        $modern = $this->validator->isModern($message, $headers);

        if ($modern) {
            $error = $this->validator->validateModern($message, $headers);

            if (null !== $error) {
                return $this->response(DispatchResult::error($error, $id));
            }

            $version = RequestValidator::MODERN_VERSION;
        } else {
            $version = $this->legacyVersion($message, $headers);

            if (null === $version) {
                $requested = (string) ($headers['mcp-protocol-version'] ?? 'unknown');
                $error     = new ProtocolError(
                    -32022,
                    'Unsupported protocol version.',
                    400,
                    array('supported' => $this->validator->supportedVersions(), 'requested' => $requested)
                );

                return $this->response(DispatchResult::error($error, $id));
            }
        }

        return $this->response(
            $this->handler->handle($message, $version, $modern, $this->clientContext($message, $request))
        );
    }

    public function methodNotAllowed(): WP_REST_Response
    {
        $response = $this->response(
            DispatchResult::error(new ProtocolError(-32601, 'Method not found: this MCP endpoint accepts POST requests.', 405), null)
        );
        $response->header('Allow', 'POST');

        return $response;
    }

    /**
     * Adds the MCP mirrored headers to WordPress REST CORS preflight responses.
     *
     * @param array<int, string> $headers
     * @return array<int, string>
     */
    public function allowedCorsHeaders(array $headers, WP_REST_Request $request): array
    {
        if (! str_starts_with($request->get_route(), '/' . self::NAMESPACE . self::ROUTE)) {
            return $headers;
        }

        return array_values(
            array_unique(
                array_merge($headers, array('MCP-Protocol-Version', 'Mcp-Method', 'Mcp-Name'))
            )
        );
    }

    private function response(DispatchResult $result): WP_REST_Response
    {
        $response = new WP_REST_Response($result->body, $result->httpStatus);
        $response->header('Cache-Control', 'no-store, private');
        $response->header('X-Content-Type-Options', 'nosniff');
        $response->header('Vary', 'Authorization, MCP-Protocol-Version, Mcp-Method, Mcp-Name');

        return $response;
    }

    private function rateLimitError(RateLimitResult $decision): WP_Error
    {
        $status = $decision->available ? 429 : 503;
        $code   = $decision->available ? 'wp_nerve_rate_limited' : 'wp_nerve_rate_limit_unavailable';
        $message = $decision->available
            ? __('Too many WPNerve requests. Retry after the current rate-limit window.', 'wp-nerve')
            : __('WPNerve cannot verify the rate-limit budget right now.', 'wp-nerve');

        return new WP_Error(
            $code,
            $message,
            array(
                'status'      => $status,
                'retry_after' => $decision->retryAfter($this->rateLimiter?->now() ?? time()),
                'limit'       => $decision->limit,
                'remaining'   => $decision->remaining,
                'reset_at'    => $decision->resetAt,
            )
        );
    }

    /**
     * @return array<string, string>
     */
    private function headers(WP_REST_Request $request): array
    {
        $headers = array();

        foreach ($request->get_headers() as $name => $values) {
            // WP_REST_Request canonicalizes dashes to underscores internally.
            $name = str_replace('_', '-', strtolower((string) $name));

            if (is_array($values) && isset($values[0]) && is_string($values[0])) {
                $headers[$name] = $values[0];
            } elseif (is_string($values)) {
                $headers[$name] = $values;
            }
        }

        return $headers;
    }

    /**
     * @param array<string, mixed>  $message
     * @param array<string, string> $headers
     */
    private function legacyVersion(array $message, array $headers): ?string
    {
        $headerVersion = $headers['mcp-protocol-version'] ?? null;

        if (is_string($headerVersion) && in_array($headerVersion, RequestValidator::LEGACY_VERSIONS, true)) {
            return $headerVersion;
        }

        $params           = $message['params'] ?? array();
        $requestedVersion = is_array($params) ? ($params['protocolVersion'] ?? null) : null;

        if (
            'initialize' === ($message['method'] ?? null)
            && is_string($requestedVersion)
            && in_array($requestedVersion, RequestValidator::LEGACY_VERSIONS, true)
        ) {
            return $requestedVersion;
        }

        if (null === $headerVersion && 'initialize' === ($message['method'] ?? null)) {
            return RequestValidator::LEGACY_VERSIONS[0];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $message
     * @return array<string, string>
     */
    private function clientContext(array $message, WP_REST_Request $request): array
    {
        $params     = $message['params'] ?? array();
        $meta       = is_array($params) ? ($params['_meta'] ?? array()) : array();
        $clientInfo = is_array($meta) ? ($meta['io.modelcontextprotocol/clientInfo'] ?? array()) : array();

        if ((! is_array($clientInfo) || array() === $clientInfo) && is_array($params)) {
            $clientInfo = $params['clientInfo'] ?? array();
        }

        return array(
            'client_name'    => is_array($clientInfo) && is_string($clientInfo['name'] ?? null)
                ? $clientInfo['name']
                : (string) $request->get_header('user-agent'),
            'client_version' => is_array($clientInfo) && is_string($clientInfo['version'] ?? null)
                ? $clientInfo['version']
                : '',
            'credential_id'  => $this->credentialId,
        );
    }

    /** @param array<string, mixed> $message */
    private function requestId(array $message): int|string|null
    {
        $id = $message['id'] ?? null;

        return is_int($id) || is_string($id) ? $id : null;
    }

    private function transportCapability(): string
    {
        $capability = apply_filters('wp_nerve_transport_capability', 'edit_posts');

        return is_string($capability) && '' !== $capability ? $capability : 'do_not_allow';
    }

    private function isAllowedOrigin(string $origin): bool
    {
        $candidate = $this->normalizeOrigin($origin, false);
        $site      = $this->normalizeOrigin(site_url('/'), true);

        if (null === $candidate || null === $site) {
            return false;
        }

        /**
         * Filters browser origins allowed to call the WPNerve MCP endpoint.
         *
         * Origin values must include a scheme and host, and may include a port.
         * Paths, credentials, query strings, and fragments are rejected.
         *
         * @param array<int, string> $allowedOrigins Allowed origins.
         * @param string             $candidate      Normalized request origin.
         */
        $allowedOrigins = apply_filters('wp_nerve_allowed_origins', array($site), $candidate);

        if (! is_array($allowedOrigins)) {
            return false;
        }

        foreach ($allowedOrigins as $allowedOrigin) {
            if (! is_string($allowedOrigin)) {
                continue;
            }

            if ($candidate === $this->normalizeOrigin($allowedOrigin, false)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeOrigin(string $url, bool $allowPath): ?string
    {
        $parts = wp_parse_url($url);

        if (
            ! is_array($parts)
            || ! is_string($parts['scheme'] ?? null)
            || ! is_string($parts['host'] ?? null)
            || '' === $parts['host']
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || (! $allowPath && isset($parts['path']))
        ) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);

        if (! in_array($scheme, array('http', 'https'), true)) {
            return null;
        }

        $host = strtolower($parts['host']);
        $host = str_contains($host, ':') ? '[' . trim($host, '[]') . ']' : $host;
        $port = isset($parts['port']) ? (int) $parts['port'] : null;

        if ((80 === $port && 'http' === $scheme) || (443 === $port && 'https' === $scheme)) {
            $port = null;
        }

        return $scheme . '://' . $host . (null === $port ? '' : ':' . $port);
    }

    private function maxRequestBytes(): int
    {
        /**
         * Filters the maximum accepted MCP JSON request body size in bytes.
         *
         * @param int $bytes Maximum request size.
         */
        $bytes = apply_filters('wp_nerve_max_request_bytes', 1048576);

        return is_int($bytes) && $bytes > 0 ? $bytes : 1048576;
    }
}
