<?php

/**
 * JsonRpcHandler unit tests.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Tests\Unit\Protocol;

use WP_Error;
use WPNerve\Audit\AuditRecorder;
use WPNerve\Protocol\JsonRpcHandler;
use WPNerve\Protocol\RequestValidator;
use WPNerve\Protocol\ToolRegistry;
use WPNerve\Tests\Unit\TestCase;

final class JsonRpcHandlerTest extends TestCase
{
    private ToolRegistry $tools;

    private AuditRecorder $audit;

    private JsonRpcHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tools   = $this->createMock(ToolRegistry::class);
        $this->audit   = $this->createMock(AuditRecorder::class);
        $this->handler = new JsonRpcHandler($this->tools, $this->audit);
    }

    public function testModernDiscoverReturnsSupportedVersions(): void
    {
        $result = $this->handler->handle(
            array('jsonrpc' => '2.0', 'id' => 1, 'method' => 'server/discover', 'params' => array()),
            RequestValidator::MODERN_VERSION,
            true
        );

        self::assertSame(200, $result->httpStatus);
        self::assertSame('complete', $result->body['result']['resultType']);
        self::assertContains(RequestValidator::MODERN_VERSION, $result->body['result']['supportedVersions']);
        self::assertContains('2025-11-25', $result->body['result']['supportedVersions']);
        self::assertSame('WPNerve', $result->body['result']['_meta']['io.modelcontextprotocol/serverInfo']['name']);
    }

    public function testLegacyDiscoverReturnsMethodNotFound(): void
    {
        $result = $this->handler->handle(
            array('jsonrpc' => '2.0', 'id' => 1, 'method' => 'server/discover', 'params' => array()),
            '2025-06-18',
            false
        );

        self::assertSame(-32601, $result->body['error']['code']);
    }

    public function testLegacyInitializeEchoesRequestedVersion(): void
    {
        $result = $this->handler->handle(
            array(
                'jsonrpc' => '2.0',
                'id'      => 1,
                'method'  => 'initialize',
                'params'  => array('protocolVersion' => '2025-11-25'),
            ),
            '2025-06-18',
            false
        );

        self::assertSame('2025-11-25', $result->body['result']['protocolVersion']);
        self::assertSame('WPNerve', $result->body['result']['serverInfo']['name']);
    }

    public function testLegacyInitializeFallsBackToFirstLegacyVersion(): void
    {
        $result = $this->handler->handle(
            array('jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => array()),
            '2025-11-25',
            false
        );

        self::assertSame('2025-11-25', $result->body['result']['protocolVersion']);
    }

    public function testLegacyPingReturnsEmptyResult(): void
    {
        $result = $this->handler->handle(
            array('jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping', 'params' => array()),
            '2025-06-18',
            false
        );

        self::assertSame(array(), $result->body['result']);
    }

    public function testModernPingIsNotSupported(): void
    {
        $result = $this->handler->handle(
            array('jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping', 'params' => array()),
            RequestValidator::MODERN_VERSION,
            true
        );

        self::assertSame(-32601, $result->body['error']['code']);
        self::assertSame(404, $result->httpStatus);
    }

    public function testModernToolsListIncludesResultType(): void
    {
        $this->tools->method('tools')->willReturn(array(array('name' => 'wp_nerve_site_status')));

        $result = $this->handler->handle(
            array('jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => array()),
            RequestValidator::MODERN_VERSION,
            true
        );

        self::assertSame('complete', $result->body['result']['resultType']);
        self::assertSame('wp_nerve_site_status', $result->body['result']['tools'][0]['name']);
    }

    public function testLegacyToolsListOmitsResultType(): void
    {
        $this->tools->method('tools')->willReturn(array());

        $result = $this->handler->handle(
            array('jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => array()),
            '2025-06-18',
            false
        );

        self::assertArrayNotHasKey('resultType', $result->body['result']);
    }

    public function testToolsCallSuccessRecordsAudit(): void
    {
        $this->tools->method('execute')->willReturn(array('result' => array('site_name' => 'Test'), 'risk' => 'read'));

        $this->audit->expects(self::once())->method('record')->with(
            self::callback(function (array $event): bool {
                return 'wp_nerve_site_status' === $event['tool_name']
                    && 'success' === $event['outcome']
                    && 'read' === $event['risk']
                    && '' === $event['error_code'];
            })
        );

        $result = $this->handler->handle(
            array(
                'jsonrpc' => '2.0',
                'id'      => 7,
                'method'  => 'tools/call',
                'params'  => array('name' => 'wp_nerve_site_status', 'arguments' => array()),
            ),
            RequestValidator::MODERN_VERSION,
            true,
            array('client_name' => 'test-client', 'client_version' => '1.0.0')
        );

        self::assertFalse($result->body['result']['isError']);
        self::assertSame('Test', $result->body['result']['structuredContent']['site_name']);
        self::assertStringContainsString('site_name', (string) $result->body['result']['content'][0]['text']);
    }

    public function testToolsCallErrorMarksIsErrorAndAudits(): void
    {
        $error = new WP_Error('wp_nerve_forbidden', 'Denied.');

        $this->tools->method('execute')->willReturn($error);

        $this->audit->expects(self::once())->method('record')->with(
            self::callback(function (array $event): bool {
                return 'error' === $event['outcome'] && 'wp_nerve_forbidden' === $event['error_code'];
            })
        );

        $result = $this->handler->handle(
            array('jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => array('name' => 'x', 'arguments' => array())),
            RequestValidator::MODERN_VERSION,
            true
        );

        self::assertTrue($result->body['result']['isError']);
        self::assertSame('Denied.', $result->body['result']['content'][0]['text']);
    }

    public function testToolsCallPassesIdempotencyKeyOutsideAbilityArguments(): void
    {
        $this->tools->expects(self::once())->method('execute')->with(
            'wp_nerve_create_draft',
            array('title' => 'A'),
            array(
                'idempotency_key' => 'request-123',
                'credential_id'   => 'application-password:test',
            )
        )->willReturn(array('result' => array('id' => 41), 'risk' => 'write'));

        $result = $this->handler->handle(
            array(
                'jsonrpc' => '2.0',
                'id'      => 7,
                'method'  => 'tools/call',
                'params'  => array(
                    'name'      => 'wp_nerve_create_draft',
                    'arguments' => array('title' => 'A'),
                    '_meta'     => array('wp-nerve/idempotencyKey' => 'request-123'),
                ),
            ),
            RequestValidator::MODERN_VERSION,
            true,
            array('credential_id' => 'application-password:test')
        );

        self::assertFalse($result->body['result']['isError']);
    }

    public function testToolsCallUnknownToolReturnsProtocolError(): void
    {
        $this->tools->method('execute')->willReturn(new WP_Error('wp_nerve_tool_not_found', 'No such tool.'));

        $result = $this->handler->handle(
            array('jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => array('name' => 'nope', 'arguments' => array())),
            RequestValidator::MODERN_VERSION,
            true
        );

        self::assertSame(-32602, $result->body['error']['code']);
        self::assertSame('No such tool.', $result->body['error']['message']);
    }

    public function testToolsCallRejectsInvalidParams(): void
    {
        $result = $this->handler->handle(
            array('jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => array('name' => 42)),
            RequestValidator::MODERN_VERSION,
            true
        );

        self::assertSame(-32602, $result->body['error']['code']);
    }

    public function testNotificationWithoutIdReturnsNoContent(): void
    {
        $result = $this->handler->handle(
            array('jsonrpc' => '2.0', 'method' => 'notifications/initialized', 'params' => array()),
            RequestValidator::MODERN_VERSION,
            true
        );

        self::assertNull($result->body);
        self::assertSame(202, $result->httpStatus);
    }

    public function testUnknownMethodReturnsMethodNotFound(): void
    {
        $result = $this->handler->handle(
            array('jsonrpc' => '2.0', 'id' => 1, 'method' => 'resources/list', 'params' => array()),
            RequestValidator::MODERN_VERSION,
            true
        );

        self::assertSame(-32601, $result->body['error']['code']);
        self::assertSame(404, $result->httpStatus);
    }

    public function testNotificationWithIdIsTreatedAsUnknownMethod(): void
    {
        // A notification carrying an id is not a valid notification; the handler
        // must not swallow it silently and instead answers with an error.
        $result = $this->handler->handle(
            array('jsonrpc' => '2.0', 'id' => 5, 'method' => 'notifications/x', 'params' => array()),
            RequestValidator::MODERN_VERSION,
            true
        );

        self::assertSame(-32601, $result->body['error']['code']);
    }
}
