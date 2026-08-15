<?php

/**
 * RequestValidator unit tests.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Tests\Unit\Protocol;

use PHPUnit\Framework\TestCase;
use WPNerve\Protocol\RequestValidator;

final class RequestValidatorTest extends TestCase
{
    private RequestValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new RequestValidator();
    }

    public function testAcceptsAValidModernToolsListRequest(): void
    {
        $message = $this->modernMessage('tools/list');
        $headers = array(
            'mcp-protocol-version' => RequestValidator::MODERN_VERSION,
            'mcp-method'           => 'tools/list',
        );

        self::assertNull($this->validator->validateModern($message, $headers));
    }

    public function testRejectsAMethodHeaderMismatch(): void
    {
        $message = $this->modernMessage('tools/list');
        $headers = array(
            'mcp-protocol-version' => RequestValidator::MODERN_VERSION,
            'mcp-method'           => 'tools/call',
        );

        $error = $this->validator->validateModern($message, $headers);

        self::assertNotNull($error);
        self::assertSame(-32020, $error->code);
        self::assertSame(400, $error->httpStatus);
    }

    public function testAcceptsABase64EncodedToolNameHeader(): void
    {
        $message            = $this->modernMessage('tools/call');
        $message['params']   = array_merge(
            $message['params'],
            array('name' => 'wp_nerve_site_status', 'arguments' => array())
        );
        $encoded            = base64_encode('wp_nerve_site_status');
        $headers            = array(
            'mcp-protocol-version' => RequestValidator::MODERN_VERSION,
            'mcp-method'           => 'tools/call',
            'mcp-name'             => '=?base64?' . $encoded . '?=',
        );

        self::assertNull($this->validator->validateModern($message, $headers));
    }

    public function testRejectsAnUnsupportedModernVersion(): void
    {
        $message = $this->modernMessage('server/discover');
        $message['params']['_meta']['io.modelcontextprotocol/protocolVersion'] = '2099-01-01';
        $headers = array(
            'mcp-protocol-version' => '2099-01-01',
            'mcp-method'           => 'server/discover',
        );

        $error = $this->validator->validateModern($message, $headers);

        self::assertNotNull($error);
        self::assertSame(-32022, $error->code);
        self::assertContains(RequestValidator::MODERN_VERSION, $error->data['supported']);
    }

    public function testRejectsAnInvalidJsonRpcEnvelope(): void
    {
        $error = $this->validator->validateCommon(array('jsonrpc' => '1.0', 'method' => 'tools/list'));

        self::assertNotNull($error);
        self::assertSame(-32600, $error->code);
    }

    /** @return array<string, mixed> */
    private function modernMessage(string $method): array
    {
        return array(
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => $method,
            'params'  => array(
                '_meta' => array(
                    'io.modelcontextprotocol/protocolVersion'    => RequestValidator::MODERN_VERSION,
                    'io.modelcontextprotocol/clientCapabilities' => array(),
                    'io.modelcontextprotocol/clientInfo'         => array(
                        'name'    => 'WPNerve Tests',
                        'version' => '1.0.0',
                    ),
                ),
            ),
        );
    }
}
