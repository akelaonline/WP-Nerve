<?php

/**
 * OAuth 2.1 authorization server tests.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Tests\Unit\OAuth;

use WP_REST_Request;
use WPNerve\OAuth\OAuthServer;
use WPNerve\OAuth\OAuthStore;
use WPNerve\Tests\Fixtures\WPState;
use WPNerve\Tests\Unit\TestCase;

final class OAuthTest extends TestCase
{
    private OAuthStore $store;

    private OAuthServer $server;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store  = new OAuthStore();
        $this->server = new OAuthServer($this->store);
    }

    public function testInstallSchemaRunsDbDelta(): void
    {
        OAuthStore::installSchema();

        self::assertCount(2, WPState::$schemaCalls);
        self::assertStringContainsString('wp_nerve_oauth_clients', WPState::$schemaCalls[0]);
        self::assertStringContainsString('wp_nerve_oauth_tokens', WPState::$schemaCalls[1]);
    }

    public function testRegisterRoutesRegistersOauthEndpoints(): void
    {
        $this->server->registerRoutes();

        $routes = array_column(WPState::$restRoutes, 'route');

        self::assertContains('/oauth/authorize', $routes);
        self::assertContains('/oauth/token', $routes);
        self::assertContains('/oauth/register', $routes);
        self::assertContains('/oauth/.well-known/oauth-authorization-server', $routes);
    }

    public function testRegisterClientCreatesPublicClient(): void
    {
        $request = new WP_REST_Request('POST', '/wp-nerve/v1/oauth/register');
        $request->set_body(wp_json_encode(array(
            'client_name'   => 'Claude Web',
            'redirect_uris' => array('https://claude.ai/callback'),
        )));

        $response = $this->server->registerClient($request);

        self::assertSame(201, $response->get_status());

        $data = $response->get_data();

        self::assertNotEmpty($data['client_id']);
        self::assertSame('none', $data['token_endpoint_auth_method']);
        self::assertContains('authorization_code', $data['grant_types']);

        $stored = $this->store->getClient($data['client_id']);

        self::assertNotNull($stored);
        self::assertSame('Claude Web', $stored['client_name']);
    }

    public function testRegisterClientRejectsMissingMetadata(): void
    {
        $request = new WP_REST_Request('POST', '/wp-nerve/v1/oauth/register');
        $request->set_body(wp_json_encode(array('client_name' => 'x')));

        $response = $this->server->registerClient($request);

        self::assertSame(400, $response->get_status());
        self::assertSame('invalid_client_metadata', $response->get_data()['error']);
    }

    public function testAuthorizeShowsConsentForValidClient(): void
    {
        $clientId = $this->store->createClient(array(
            'client_name'   => 'Claude',
            'redirect_uris' => array('https://claude.ai/callback'),
        ));

        $request = new WP_REST_Request('GET', '/wp-nerve/v1/oauth/authorize');
        $request->set_body(wp_json_encode(array()));

        $request = $this->withParams($request, array(
            'client_id'             => $clientId,
            'redirect_uri'          => 'https://claude.ai/callback',
            'response_type'         => 'code',
            'code_challenge'        => 'challenge-value',
            'code_challenge_method' => 'S256',
            'state'                 => 'xyz',
        ));

        $response = $this->server->authorize($request);

        self::assertSame(200, $response->get_status());
        self::assertStringContainsString('Allow access', (string) $response->get_data());
        self::assertStringContainsString('Claude', (string) $response->get_data());
    }

    public function testAuthorizeRejectsInvalidClient(): void
    {
        $request = $this->withParams(new WP_REST_Request('GET', '/oauth/authorize'), array(
            'client_id'             => 'nope',
            'redirect_uri'          => 'https://claude.ai/callback',
            'response_type'         => 'code',
            'code_challenge'        => 'x',
            'code_challenge_method' => 'S256',
        ));

        $response = $this->server->authorize($request);

        self::assertSame(400, $response->get_status());
        self::assertSame('invalid_request', $response->get_data()['error']);
    }

    public function testAuthorizeRequiresPkceS256(): void
    {
        $clientId = $this->store->createClient(array(
            'client_name'   => 'Claude',
            'redirect_uris' => array('https://claude.ai/callback'),
        ));

        $request = $this->withParams(new WP_REST_Request('GET', '/oauth/authorize'), array(
            'client_id'     => $clientId,
            'redirect_uri'  => 'https://claude.ai/callback',
            'response_type' => 'code',
        ));

        $response = $this->server->authorize($request);

        self::assertSame(400, $response->get_status());
        self::assertStringContainsString('PKCE', $response->get_data()['error_description']);
    }

    public function testAuthorizeRedirectsToLoginWhenAnonymous(): void
    {
        WPState::$isLoggedIn = false;

        $clientId = $this->store->createClient(array(
            'client_name'   => 'Claude',
            'redirect_uris' => array('https://claude.ai/callback'),
        ));

        $request = $this->withParams(new WP_REST_Request('GET', '/oauth/authorize'), array(
            'client_id'             => $clientId,
            'redirect_uri'          => 'https://claude.ai/callback',
            'response_type'         => 'code',
            'code_challenge'        => 'x',
            'code_challenge_method' => 'S256',
        ));

        $response = $this->server->authorize($request);

        self::assertSame(302, $response->get_status());
        self::assertStringContainsString('wp-login.php', $response->get_headers()['location']);
    }

    public function testConsentIssuesCodeAndTokenFlowSucceeds(): void
    {
        $clientId = $this->store->createClient(array(
            'client_name'   => 'Claude',
            'redirect_uris' => array('https://claude.ai/callback'),
        ));

        $verifier = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789-._~';
        $challenge = base64_encode(hash('sha256', $verifier, true));

        // Consent (POST).
        $consent = $this->withParams(new WP_REST_Request('POST', '/oauth/authorize'), array(
            'client_id'             => $clientId,
            'redirect_uri'          => 'https://claude.ai/callback',
            'response_type'         => 'code',
            'code_challenge'        => $challenge,
            'code_challenge_method' => 'S256',
            'state'                 => 'state-1',
            'wp_nerve_consent'      => 'allow',
        ));

        $response = $this->server->authorize($consent);

        self::assertSame(302, $response->get_status());

        $location = $response->get_headers()['location'];

        self::assertStringContainsString('code=', $location);
        self::assertStringContainsString('state=state-1', $location);

        parse_str((string) parse_url($location, PHP_URL_QUERY), $parts);
        $code = $parts['code'];

        // Token request with correct verifier.
        $tokenRequest = $this->withParams(new WP_REST_Request('POST', '/oauth/token'), array(
            'grant_type'    => 'authorization_code',
            'client_id'     => $clientId,
            'code'          => $code,
            'code_verifier' => $verifier,
            'redirect_uri'  => 'https://claude.ai/callback',
        ));

        $tokenResponse = $this->server->token($tokenRequest);

        self::assertSame(200, $tokenResponse->get_status());

        $tokens = $tokenResponse->get_data();

        self::assertNotEmpty($tokens['access_token']);
        self::assertNotEmpty($tokens['refresh_token']);
        self::assertSame('Bearer', $tokens['token_type']);

        // The access token validates to the consenting user.
        self::assertSame(WPState::$currentUserId, $this->store->validateAccessToken($tokens['access_token']));

        // Refresh token rotates.
        $refreshRequest = $this->withParams(new WP_REST_Request('POST', '/oauth/token'), array(
            'grant_type'    => 'refresh_token',
            'client_id'     => $clientId,
            'refresh_token' => $tokens['refresh_token'],
        ));

        $refreshResponse = $this->server->token($refreshRequest);

        self::assertSame(200, $refreshResponse->get_status());
        self::assertNotEmpty($refreshResponse->get_data()['access_token']);
    }

    public function testTokenRejectsWrongCodeVerifier(): void
    {
        $clientId = $this->store->createClient(array(
            'client_name'   => 'Claude',
            'redirect_uris' => array('https://claude.ai/callback'),
        ));

        $challenge = base64_encode(hash('sha256', 'right-verifier', true));

        $consent = $this->withParams(new WP_REST_Request('POST', '/oauth/authorize'), array(
            'client_id'             => $clientId,
            'redirect_uri'          => 'https://claude.ai/callback',
            'response_type'         => 'code',
            'code_challenge'        => $challenge,
            'code_challenge_method' => 'S256',
            'wp_nerve_consent'      => 'allow',
        ));

        $response = $this->server->authorize($consent);

        parse_str((string) parse_url($response->get_headers()['location'], PHP_URL_QUERY), $parts);

        $tokenRequest = $this->withParams(new WP_REST_Request('POST', '/oauth/token'), array(
            'grant_type'    => 'authorization_code',
            'client_id'     => $clientId,
            'code'          => $parts['code'],
            'code_verifier' => 'wrong-verifier',
            'redirect_uri'  => 'https://claude.ai/callback',
        ));

        $tokenResponse = $this->server->token($tokenRequest);

        self::assertSame(400, $tokenResponse->get_status());
        self::assertSame('invalid_grant', $tokenResponse->get_data()['error']);
    }

    public function testRefreshWithWrongClientFails(): void
    {
        $clientId = $this->store->createClient(array(
            'client_name'   => 'Claude',
            'redirect_uris' => array('https://claude.ai/callback'),
        ));

        $tokens = $this->store->issueTokens($clientId, 1);

        $request = $this->withParams(new WP_REST_Request('POST', '/oauth/token'), array(
            'grant_type'    => 'refresh_token',
            'client_id'     => 'another-client',
            'refresh_token' => $tokens['refresh_token'],
        ));

        $response = $this->server->token($request);

        self::assertSame(400, $response->get_status());
        self::assertSame('invalid_grant', $response->get_data()['error']);
    }

    public function testMetadataDescribesEndpoints(): void
    {
        $response = $this->server->metadata();

        $data = $response->get_data();

        self::assertContains('S256', $data['code_challenge_methods_supported']);
        self::assertStringContainsString('oauth/authorize', $data['authorization_endpoint']);
        self::assertStringContainsString('oauth/token', $data['token_endpoint']);
    }

    public function testValidateAccessTokenReturnsNullForUnknownToken(): void
    {
        self::assertNull($this->store->validateAccessToken('not-a-token'));
    }

    /**
     * @param array<string, mixed> $params
     */
    private function withParams(WP_REST_Request $request, array $params): WP_REST_Request
    {
        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }

        return $request;
    }
}
