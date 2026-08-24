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
    private const REDIRECT = 'https://claude.ai/callback';

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
        self::assertStringContainsString('UNIQUE KEY token_hash', WPState::$schemaCalls[1]);
    }

    public function testRegisterRoutesRegistersOauthEndpoints(): void
    {
        $this->server->registerRoutes();

        $routes = array_column(WPState::$restRoutes, 'route');

        self::assertContains('/oauth/authorize', $routes);
        self::assertContains('/oauth/token', $routes);
        self::assertContains('/oauth/revoke', $routes);
        self::assertContains('/oauth/register', $routes);
        self::assertContains('/oauth/.well-known/oauth-authorization-server', $routes);
    }

    public function testRegisterClientCreatesPublicClient(): void
    {
        $response = $this->registerClient(array(
            'client_name'   => 'Claude Web',
            'redirect_uris' => array(self::REDIRECT),
        ));

        self::assertSame(201, $response->get_status());

        $data = $response->get_data();

        self::assertNotEmpty($data['client_id']);
        self::assertSame('none', $data['token_endpoint_auth_method']);
        self::assertSame(array('code'), $data['response_types']);
        self::assertContains('authorization_code', $data['grant_types']);
        self::assertStringContainsString('/oauth/revoke', $data['revocation_endpoint']);
        self::assertNoStoreHeaders($response->get_headers());

        $stored = $this->store->getClient($data['client_id']);

        self::assertNotNull($stored);
        self::assertSame('Claude Web', $stored['client_name']);
        self::assertSame(array(self::REDIRECT), $stored['redirect_uris']);
    }

    public function testRegisterClientRejectsMissingMetadata(): void
    {
        $response = $this->registerClient(array('client_name' => 'x'));

        self::assertSame(400, $response->get_status());
        self::assertSame('invalid_client_metadata', $response->get_data()['error']);
    }

    public function testRegisterClientRejectsUnsafeRedirects(): void
    {
        foreach (
            array(
                'http://example.com/callback',
                'https://user:pass@example.com/callback',
                'https://example.com/callback#fragment',
            ) as $redirect
        ) {
            $response = $this->registerClient(array(
                'client_name'   => 'Unsafe client',
                'redirect_uris' => array($redirect),
            ));

            self::assertSame(400, $response->get_status(), $redirect);
            self::assertSame('invalid_client_metadata', $response->get_data()['error']);
        }
    }

    public function testRegisterClientAllowsLoopbackHttp(): void
    {
        $response = $this->registerClient(array(
            'client_name'   => 'Native client',
            'redirect_uris' => array('http://127.0.0.1:54321/callback'),
        ));

        self::assertSame(201, $response->get_status());
    }

    public function testRegisterClientRejectsUnsupportedPublicClientProfile(): void
    {
        $response = $this->registerClient(array(
            'client_name'                => 'Confidential client',
            'redirect_uris'              => array(self::REDIRECT),
            'token_endpoint_auth_method' => 'client_secret_basic',
        ));

        self::assertSame(400, $response->get_status());
        self::assertSame('invalid_client_metadata', $response->get_data()['error']);
    }

    public function testRegisterClientHonorsCapacityLimit(): void
    {
        WPState::$wpdb->varResults = array(1);
        add_filter(
            'wp_nerve_oauth_client_limit',
            static fn (): int => 1
        );

        $response = $this->registerClient(array(
            'client_name'   => 'Capacity test',
            'redirect_uris' => array(self::REDIRECT),
        ));

        self::assertSame(429, $response->get_status());
        self::assertSame('temporarily_unavailable', $response->get_data()['error']);
        self::assertSame('3600', $response->get_headers()['retry-after']);
    }

    public function testAuthorizeShowsConsentForValidClient(): void
    {
        $clientId = $this->client();
        $verifier = $this->verifier('A');

        $request = $this->authorizationRequest(
            'GET',
            $clientId,
            $this->challengeFor($verifier),
            'state-consent'
        );

        $response = $this->server->authorize($request);

        self::assertSame(200, $response->get_status());
        self::assertStringContainsString('Allow access', (string) $response->get_data());
        self::assertStringContainsString('Claude', (string) $response->get_data());
        self::assertStringContainsString('wp_nerve_oauth_nonce', (string) $response->get_data());
        self::assertNoStoreHeaders($response->get_headers());
    }

    public function testAuthorizeRejectsInvalidClient(): void
    {
        $request = $this->authorizationRequest(
            'GET',
            'nope',
            $this->challengeFor($this->verifier('B')),
            'state-invalid-client'
        );

        $response = $this->server->authorize($request);

        self::assertSame(400, $response->get_status());
        self::assertSame('invalid_request', $response->get_data()['error']);
    }

    public function testAuthorizeRequiresStateAndStrictPkce(): void
    {
        $clientId = $this->client();

        $missingState = $this->withParams(new WP_REST_Request('GET', '/oauth/authorize'), array(
            'client_id'             => $clientId,
            'redirect_uri'          => self::REDIRECT,
            'response_type'         => 'code',
            'code_challenge'        => $this->challengeFor($this->verifier('C')),
            'code_challenge_method' => 'S256',
        ));

        $response = $this->server->authorize($missingState);

        self::assertSame(400, $response->get_status());
        self::assertStringContainsString('state', $response->get_data()['error_description']);

        $badPkce = $this->authorizationRequest('GET', $clientId, 'too-short', 'state-bad-pkce');
        $response = $this->server->authorize($badPkce);

        self::assertSame(400, $response->get_status());
        self::assertStringContainsString('PKCE', $response->get_data()['error_description']);
    }

    public function testAuthorizeRedirectsToLoginWhenAnonymous(): void
    {
        WPState::$isLoggedIn = false;

        $clientId = $this->client();
        $request  = $this->authorizationRequest(
            'GET',
            $clientId,
            $this->challengeFor($this->verifier('D')),
            'state-login'
        );

        $response = $this->server->authorize($request);

        self::assertSame(302, $response->get_status());
        self::assertStringContainsString('wp-login.php', $response->get_headers()['location']);
        self::assertNoStoreHeaders($response->get_headers());
    }

    public function testConsentIssuesCodeAndTokenFlowSucceeds(): void
    {
        $clientId = $this->client();
        $verifier = $this->verifier('E');
        $code     = $this->authorizeCode($clientId, $verifier, 'state-flow');

        $tokenResponse = $this->exchangeCode($clientId, $code, $verifier);

        self::assertSame(200, $tokenResponse->get_status());

        $tokens = $tokenResponse->get_data();

        self::assertNotEmpty($tokens['access_token']);
        self::assertNotEmpty($tokens['refresh_token']);
        self::assertSame('Bearer', $tokens['token_type']);
        self::assertSame('mcp', $tokens['scope']);
        self::assertSame(3600, $tokens['expires_in']);
        self::assertNoStoreHeaders($tokenResponse->get_headers());

        self::assertSame(WPState::$currentUserId, $this->store->validateAccessToken($tokens['access_token']));
        self::assertSame(
            array('user_id' => WPState::$currentUserId, 'client_id' => $clientId),
            $this->store->validateAccessTokenIdentity($tokens['access_token'])
        );
    }

    public function testAuthorizationCodeIsSingleUse(): void
    {
        $clientId = $this->client();
        $verifier = $this->verifier('F');
        $code     = $this->authorizeCode($clientId, $verifier, 'state-single-code');

        self::assertSame(200, $this->exchangeCode($clientId, $code, $verifier)->get_status());

        $replay = $this->exchangeCode($clientId, $code, $verifier);

        self::assertSame(400, $replay->get_status());
        self::assertSame('invalid_grant', $replay->get_data()['error']);
    }

    public function testTokenRejectsWrongValidCodeVerifierAndConsumesCode(): void
    {
        $clientId      = $this->client();
        $rightVerifier = $this->verifier('G');
        $wrongVerifier = $this->verifier('H');
        $code          = $this->authorizeCode($clientId, $rightVerifier, 'state-wrong-verifier');

        $wrong = $this->exchangeCode($clientId, $code, $wrongVerifier);

        self::assertSame(400, $wrong->get_status());
        self::assertSame('invalid_grant', $wrong->get_data()['error']);

        $replay = $this->exchangeCode($clientId, $code, $rightVerifier);

        self::assertSame(400, $replay->get_status());
        self::assertSame('invalid_grant', $replay->get_data()['error']);
    }

    public function testRefreshTokenRotatesAndReplayFails(): void
    {
        $clientId = $this->client();
        $tokens   = $this->store->issueTokens($clientId, 1);

        self::assertIsArray($tokens);

        $first = $this->refresh($clientId, $tokens['refresh_token']);

        self::assertSame(200, $first->get_status());
        self::assertNotSame($tokens['refresh_token'], $first->get_data()['refresh_token']);

        $replay = $this->refresh($clientId, $tokens['refresh_token']);

        self::assertSame(400, $replay->get_status());
        self::assertSame('invalid_grant', $replay->get_data()['error']);
    }

    public function testRefreshWithWrongClientDoesNotConsumeToken(): void
    {
        $clientId = $this->client();
        $tokens   = $this->store->issueTokens($clientId, 1);

        self::assertIsArray($tokens);

        $wrong = $this->refresh('another-client', $tokens['refresh_token']);

        self::assertSame(400, $wrong->get_status());
        self::assertSame('invalid_grant', $wrong->get_data()['error']);

        $correct = $this->refresh($clientId, $tokens['refresh_token']);

        self::assertSame(200, $correct->get_status());
    }

    public function testRevocationInvalidatesOwnedTokensWithoutDisclosure(): void
    {
        $clientId = $this->client();
        $tokens   = $this->store->issueTokens($clientId, 1);

        self::assertIsArray($tokens);
        self::assertSame(1, $this->store->validateAccessToken($tokens['access_token']));

        $accessRevocation = $this->revoke($clientId, $tokens['access_token']);

        self::assertSame(200, $accessRevocation->get_status());
        self::assertSame(array(), $accessRevocation->get_data());
        self::assertNull($this->store->validateAccessToken($tokens['access_token']));
        self::assertNoStoreHeaders($accessRevocation->get_headers());

        $refreshRevocation = $this->revoke($clientId, $tokens['refresh_token']);

        self::assertSame(200, $refreshRevocation->get_status());
        self::assertSame(400, $this->refresh($clientId, $tokens['refresh_token'])->get_status());
    }

    public function testRevocationCannotDeleteAnotherClientsToken(): void
    {
        $clientId = $this->client();
        $otherId  = $this->store->createClient(array(
            'client_name'   => 'Other',
            'redirect_uris' => array('https://other.example/callback'),
        ));
        $tokens = $this->store->issueTokens($clientId, 1);

        self::assertIsArray($tokens);
        self::assertSame(200, $this->revoke($otherId, $tokens['access_token'])->get_status());
        self::assertSame(1, $this->store->validateAccessToken($tokens['access_token']));
    }

    public function testMetadataDescribesEndpointsAndDisablesCaching(): void
    {
        $response = $this->server->metadata();
        $data     = $response->get_data();

        self::assertContains('S256', $data['code_challenge_methods_supported']);
        self::assertStringContainsString('oauth/authorize', $data['authorization_endpoint']);
        self::assertStringContainsString('oauth/token', $data['token_endpoint']);
        self::assertStringContainsString('oauth/revoke', $data['revocation_endpoint']);
        self::assertSame(array('none'), $data['token_endpoint_auth_methods_supported']);
        self::assertNoStoreHeaders($response->get_headers());
    }

    public function testValidateAccessTokenReturnsNullForUnknownToken(): void
    {
        self::assertNull($this->store->validateAccessToken('not-a-token'));
    }

    public function testConsentRejectsInvalidNonce(): void
    {
        $clientId = $this->client();
        $verifier = $this->verifier('I');
        $consent  = $this->authorizationRequest(
            'POST',
            $clientId,
            $this->challengeFor($verifier),
            'state-invalid-nonce'
        );
        $consent->set_param('wp_nerve_consent', 'allow');
        $consent->set_param('wp_nerve_oauth_nonce', 'nonce');

        WPState::$nonceValid = false;

        $response = $this->server->authorize($consent);

        self::assertSame(302, $response->get_status());
        self::assertStringContainsString('access_denied', $response->get_headers()['location']);
        self::assertStringContainsString('state=state-invalid-nonce', $response->get_headers()['location']);
        self::assertNoStoreHeaders($response->get_headers());
    }

    public function testOAuthStorageFailuresFailClosed(): void
    {
        WPState::$wpdb->insertResults = array(false);

        $response = $this->registerClient(array(
            'client_name'   => 'Storage failure',
            'redirect_uris' => array(self::REDIRECT),
        ));

        self::assertSame(503, $response->get_status());
        self::assertSame('temporarily_unavailable', $response->get_data()['error']);

        WPState::reset();
        $this->store  = new OAuthStore();
        $this->server = new OAuthServer($this->store);

        WPState::$wpdb->queryResults = array(false);

        $metadataIndependent = $this->server->metadata();
        self::assertSame(200, $metadataIndependent->get_status());

        $token = new WP_REST_Request('POST', '/oauth/token');
        $token->set_param('grant_type', 'refresh_token');

        $response = $this->server->token($token);

        self::assertSame(503, $response->get_status());
        self::assertSame('temporarily_unavailable', $response->get_data()['error']);
    }

    private function client(): string
    {
        $clientId = $this->store->createClient(array(
            'client_name'   => 'Claude',
            'redirect_uris' => array(self::REDIRECT),
        ));

        self::assertNotSame('', $clientId);

        return $clientId;
    }

    /** @param array<string, mixed> $body */
    private function registerClient(array $body): \WP_REST_Response
    {
        $request = new WP_REST_Request('POST', '/wp-nerve/v1/oauth/register');
        $request->set_body(wp_json_encode($body));

        return $this->server->registerClient($request);
    }

    private function authorizationRequest(
        string $method,
        string $clientId,
        string $challenge,
        string $state
    ): WP_REST_Request {
        return $this->withParams(new WP_REST_Request($method, '/oauth/authorize'), array(
            'client_id'             => $clientId,
            'redirect_uri'          => self::REDIRECT,
            'response_type'         => 'code',
            'code_challenge'        => $challenge,
            'code_challenge_method' => 'S256',
            'state'                 => $state,
        ));
    }

    private function authorizeCode(string $clientId, string $verifier, string $state): string
    {
        $request = $this->authorizationRequest('POST', $clientId, $this->challengeFor($verifier), $state);
        $request->set_param('wp_nerve_consent', 'allow');
        $request->set_param('wp_nerve_oauth_nonce', 'nonce');

        $response = $this->server->authorize($request);

        self::assertSame(302, $response->get_status());

        parse_str((string) parse_url($response->get_headers()['location'], PHP_URL_QUERY), $parts);
        self::assertArrayHasKey('code', $parts);

        return (string) $parts['code'];
    }

    private function exchangeCode(string $clientId, string $code, string $verifier): \WP_REST_Response
    {
        $request = $this->withParams(new WP_REST_Request('POST', '/oauth/token'), array(
            'grant_type'    => 'authorization_code',
            'client_id'     => $clientId,
            'code'          => $code,
            'code_verifier' => $verifier,
            'redirect_uri'  => self::REDIRECT,
        ));

        return $this->server->token($request);
    }

    private function refresh(string $clientId, string $refreshToken): \WP_REST_Response
    {
        $request = $this->withParams(new WP_REST_Request('POST', '/oauth/token'), array(
            'grant_type'    => 'refresh_token',
            'client_id'     => $clientId,
            'refresh_token' => $refreshToken,
        ));

        return $this->server->token($request);
    }

    private function revoke(string $clientId, string $token): \WP_REST_Response
    {
        $request = $this->withParams(new WP_REST_Request('POST', '/oauth/revoke'), array(
            'client_id' => $clientId,
            'token'     => $token,
        ));

        return $this->server->revoke($request);
    }

    private function verifier(string $seed): string
    {
        return str_repeat($seed, 43);
    }

    private function challengeFor(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    /** @param array<string, mixed> $headers */
    private static function assertNoStoreHeaders(array $headers): void
    {
        self::assertSame('no-store', $headers['cache-control']);
        self::assertSame('no-cache', $headers['pragma']);
        self::assertSame('nosniff', $headers['x-content-type-options']);
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
