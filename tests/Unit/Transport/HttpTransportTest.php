<?php

/**
 * HttpTransport unit tests.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Tests\Unit\Transport;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WPNerve\Audit\AuditRecorder;
use WPNerve\Protocol\JsonRpcHandler;
use WPNerve\Protocol\RequestValidator;
use WPNerve\Protocol\ToolRegistry;
use WPNerve\Tests\Fixtures\WPState;
use WPNerve\Tests\Unit\TestCase;
use WPNerve\Transport\HttpTransport;

final class HttpTransportTest extends TestCase
{
    private HttpTransport $transport;

    private ToolRegistry $tools;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tools      = $this->createMock(ToolRegistry::class);
        $this->transport  = new HttpTransport(
            new RequestValidator(),
            new JsonRpcHandler($this->tools, $this->createMock(AuditRecorder::class))
        );
    }

    public function testRegisterRoutesRegistersPostAndGetDelete(): void
    {
        $this->transport->registerRoutes();

        self::assertCount(1, WPState::$restRoutes);

        $route = WPState::$restRoutes[0];

        self::assertSame('wp-nerve/v1', $route['namespace']);
        self::assertSame('/mcp', $route['route']);
        self::assertCount(2, $route['args']);

        $methods = array();
        foreach ($route['args'] as $definition) {
            $methods[] = $definition['methods'];
        }

        self::assertContains('POST', $methods);
        self::assertContains(array('GET', 'DELETE'), $methods);
    }

    public function testCheckPermissionAllowsValidRequest(): void
    {
        $request = $this->request('POST');

        self::assertTrue($this->transport->checkPermission($request));
    }

    public function testCheckPermissionRejectsDisallowedOrigin(): void
    {
        $request = $this->request('POST');
        $request->set_header('Origin', 'https://evil.example');

        $result = $this->transport->checkPermission($request);

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('wp_nerve_invalid_origin', $result->get_error_code());
        self::assertSame(403, $result->get_error_data()['status']);
    }

    public function testCheckPermissionAcceptsSameSiteOrigin(): void
    {
        $request = $this->request('POST');
        $request->set_header('Origin', WPState::$siteUrl);

        self::assertTrue($this->transport->checkPermission($request));
    }

    public function testCheckPermissionAcceptsFilteredOrigin(): void
    {
        add_filter('wp_nerve_allowed_origins', static fn (): array => array('https://client.example'));

        $request = $this->request('POST');
        $request->set_header('Origin', 'https://client.example');

        self::assertTrue($this->transport->checkPermission($request));
    }

    public function testCheckPermissionRejectsAnonymous(): void
    {
        WPState::$isLoggedIn = false;

        $result = $this->transport->checkPermission($this->request('POST'));

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('wp_nerve_authentication_required', $result->get_error_code());
        self::assertSame(401, $result->get_error_data()['status']);
    }

    public function testCheckPermissionAcceptsValidBearerToken(): void
    {
        WPState::$isLoggedIn = false;

        $store  = new \WPNerve\OAuth\OAuthStore();
        $tokens = $store->issueTokens('client-1', 42);

        $request = $this->request('POST');
        $request->set_header('Authorization', 'Bearer ' . $tokens['access_token']);

        $result = $this->transport->checkPermission($request);

        self::assertTrue($result);
        self::assertSame(42, WPState::$currentUserId);
    }

    public function testCheckPermissionRejectsInvalidBearerToken(): void
    {
        WPState::$isLoggedIn = false;

        $request = $this->request('POST');
        $request->set_header('Authorization', 'Bearer not-a-real-token');

        $result = $this->transport->checkPermission($request);

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('wp_nerve_oauth_invalid_token', $result->get_error_code());
        self::assertSame(401, $result->get_error_data()['status']);
    }

    public function testCheckPermissionRejectsWithoutCapability(): void
    {
        WPState::$userCan = false;

        $result = $this->transport->checkPermission($this->request('POST'));

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('wp_nerve_forbidden', $result->get_error_code());
        self::assertSame(403, $result->get_error_data()['status']);
    }

    public function testCheckPermissionRejectsHttpInProduction(): void
    {
        WPState::$environmentType = 'production';
        WPState::$isSsl           = false;

        $result = $this->transport->checkPermission($this->request('POST'));

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('wp_nerve_https_required', $result->get_error_code());
        self::assertSame(403, $result->get_error_data()['status']);
    }

    public function testCheckPermissionAllowsHttpOutsideProduction(): void
    {
        WPState::$environmentType = 'development';
        WPState::$isSsl           = false;

        self::assertTrue($this->transport->checkPermission($this->request('POST')));
    }

    public function testAllowedCorsHeadersOnlyForMcpRoute(): void
    {
        $request = $this->request('POST', '/wp-nerve/v1/mcp');

        $headers = $this->transport->allowedCorsHeaders(array('X-Custom'), $request);

        self::assertContains('MCP-Protocol-Version', $headers);
        self::assertContains('Mcp-Method', $headers);
        self::assertContains('Mcp-Name', $headers);
        self::assertContains('X-Custom', $headers);

        $other = $this->request('POST', '/wp/v2/posts');
        $otherHeaders = $this->transport->allowedCorsHeaders(array('X-Custom'), $other);

        self::assertNotContains('MCP-Protocol-Version', $otherHeaders);
    }

    public function testHandleRejectsOversizedBody(): void
    {
        add_filter('wp_nerve_max_request_bytes', static fn (): int => 10);

        $request = $this->request('POST', '/wp-nerve/v1/mcp');
        $request->set_body(str_repeat('a', 11));

        $response = $this->transport->handle($request);

        self::assertSame(413, $response->get_status());
        self::assertSame(-32600, $response->get_data()['error']['code']);
    }

    public function testHandleRejectsNonJsonContentType(): void
    {
        $request = $this->request('POST', '/wp-nerve/v1/mcp');
        $request->set_header('Content-Type', 'text/plain');
        $request->set_body('{}');

        $response = $this->transport->handle($request);

        self::assertSame(415, $response->get_status());
    }

    public function testHandleRejectsMalformedJson(): void
    {
        $request = $this->request('POST', '/wp-nerve/v1/mcp');
        $request->set_body('{not json');

        $response = $this->transport->handle($request);

        self::assertSame(-32700, $response->get_data()['error']['code']);
    }

    public function testHandleRejectsInvalidJsonRpcEnvelope(): void
    {
        $request = $this->request('POST', '/wp-nerve/v1/mcp');
        $request->set_body(wp_json_encode(array('jsonrpc' => '1.0', 'method' => 'tools/list')));

        $response = $this->transport->handle($request);

        self::assertSame(-32600, $response->get_data()['error']['code']);
    }

    public function testHandleRejectsModernHeaderMismatch(): void
    {
        $request = $this->modernRequest('tools/list', array('mcp-method' => 'tools/call'));

        $response = $this->transport->handle($request);

        self::assertSame(-32020, $response->get_data()['error']['code']);
        self::assertSame(400, $response->get_status());
    }

    public function testHandleRejectsUnsupportedModernVersion(): void
    {
        $message = $this->modernMessage('server/discover');
        $message['params']['_meta']['io.modelcontextprotocol/protocolVersion'] = '2099-01-01';

        $request = $this->request('POST', '/wp-nerve/v1/mcp');
        $request->set_header('MCP-Protocol-Version', '2099-01-01');
        $request->set_header('Mcp-Method', 'server/discover');
        $request->set_body(wp_json_encode($message));

        $response = $this->transport->handle($request);

        self::assertSame(-32022, $response->get_data()['error']['code']);
        self::assertContains(RequestValidator::MODERN_VERSION, $response->get_data()['error']['data']['supported']);
    }

    public function testHandleDispatchesModernToolsList(): void
    {
        $this->tools->method('tools')->willReturn(array(array('name' => 'wp_nerve_site_status')));

        $request = $this->modernRequest('tools/list');

        $response = $this->transport->handle($request);

        self::assertSame(200, $response->get_status());
        self::assertSame('complete', $response->get_data()['result']['resultType']);
        self::assertSame('wp_nerve_site_status', $response->get_data()['result']['tools'][0]['name']);
    }

    public function testHandleDispatchesLegacyInitialize(): void
    {
        $request = $this->request('POST', '/wp-nerve/v1/mcp');
        $request->set_body(wp_json_encode(array(
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => 'initialize',
            'params'  => array('protocolVersion' => '2025-06-18'),
        )));

        $response = $this->transport->handle($request);

        self::assertSame(200, $response->get_status());
        self::assertSame('2025-06-18', $response->get_data()['result']['protocolVersion']);
    }

    public function testHandleRejectsUnsupportedLegacyVersion(): void
    {
        // A legacy-era request without a recognizable protocol version and
        // without modern metadata cannot be negotiated and is rejected.
        $request = $this->request('POST', '/wp-nerve/v1/mcp');
        $request->set_body(wp_json_encode(array(
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => 'tools/list',
            'params'  => array(),
        )));

        $response = $this->transport->handle($request);

        self::assertSame(-32022, $response->get_data()['error']['code']);
    }

    public function testHandleMethodNotAllowed(): void
    {
        $request = $this->request('GET', '/wp-nerve/v1/mcp');

        $response = $this->transport->methodNotAllowed();

        self::assertSame(405, $response->get_status());
        self::assertSame('POST', $response->get_headers()['allow']);
    }

    public function testHandleSetsSecurityResponseHeaders(): void
    {
        $request = $this->modernRequest('tools/list');

        $response = $this->transport->handle($request);
        $headers  = $response->get_headers();

        self::assertSame('no-store, private', $headers['cache-control']);
        self::assertSame('nosniff', $headers['x-content-type-options']);
        self::assertStringContainsString('Authorization', $headers['vary']);
    }

    public function testHandleRejectsToolsCallWithoutMcpNameHeader(): void
    {
        // The modern validation layer requires the mirrored Mcp-Name header to
        // match the body, so a missing header is rejected before dispatch.
        $request = $this->request('POST', '/wp-nerve/v1/mcp');
        $request->set_header('MCP-Protocol-Version', RequestValidator::MODERN_VERSION);
        $request->set_header('Mcp-Method', 'tools/call');
        $request->set_body(wp_json_encode(array(
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => 'tools/call',
            'params'  => array('name' => 'wp_nerve_site_status', 'arguments' => array()),
        )));

        $response = $this->transport->handle($request);

        self::assertSame(-32020, $response->get_data()['error']['code']);
    }

    public function testAuthenticatedApplicationPasswordIdentityReachesToolExecution(): void
    {
        $this->tools->expects(self::once())->method('execute')->with(
            'wp_nerve_create_draft',
            array('title' => 'A'),
            array(
                'idempotency_key' => 'request-123',
                'credential_id'   => 'application-password:test-application-password',
            )
        )->willReturn(array('result' => array('id' => 41), 'risk' => 'write'));

        $message = $this->modernMessage('tools/call');
        $message['params']['name'] = 'wp_nerve_create_draft';
        $message['params']['arguments'] = array('title' => 'A');
        $message['params']['_meta']['wp-nerve/idempotencyKey'] = 'request-123';

        $request = $this->modernRequest(
            'tools/call',
            array('mcp-name' => 'wp_nerve_create_draft')
        );
        $request->set_body(wp_json_encode($message));

        self::assertTrue($this->transport->checkPermission($request));

        $response = $this->transport->handle($request);

        self::assertSame(41, $response->get_data()['result']['structuredContent']['id']);
    }

    private function request(string $method, string $route = '/wp-nerve/v1/mcp'): WP_REST_Request
    {
        $request = new WP_REST_Request($method, $route);
        $request->set_header('Content-Type', 'application/json');

        return $request;
    }

    private function modernRequest(string $method, array $headerOverrides = array()): WP_REST_Request
    {
        $request = $this->request('POST');

        $headers = array_merge(
            array(
                'mcp-protocol-version' => RequestValidator::MODERN_VERSION,
                'mcp-method'           => $method,
            ),
            $headerOverrides
        );

        foreach ($headers as $name => $value) {
            $request->set_header($name, $value);
        }

        $request->set_body(wp_json_encode($this->modernMessage($method)));

        return $request;
    }

    /** @return array<string, mixed> */
    private function modernMessage(string $method): array
    {
        $params = array(
            '_meta' => array(
                'io.modelcontextprotocol/protocolVersion'    => RequestValidator::MODERN_VERSION,
                'io.modelcontextprotocol/clientCapabilities' => array(),
                'io.modelcontextprotocol/clientInfo'         => array('name' => 'wp-nerve-tests', 'version' => '1.0.0'),
            ),
        );

        if ('tools/call' === $method) {
            $params['name']      = 'wp_nerve_site_status';
            $params['arguments'] = array();
        }

        return array('jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params);
    }
}
