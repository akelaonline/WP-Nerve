<?php

/**
 * Deterministic mutation sweep for JSON-RPC/MCP request validation.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Tests\Unit\Protocol;

use WPNerve\Protocol\ProtocolError;
use WPNerve\Protocol\RequestValidator;
use WPNerve\Tests\Unit\TestCase;

final class MutationFuzzTest extends TestCase
{
    public function testCommonValidatorSurvivesDeterministicScalarAndShapeMutations(): void
    {
        $validator = new RequestValidator();
        $values = array(
            null,
            '',
            '2.0',
            'tools/list',
            0,
            1,
            1.5,
            true,
            false,
            array(),
            array('nested' => array('value' => true)),
            str_repeat('x', 4096),
        );
        $iterations = 0;

        foreach ($values as $jsonrpc) {
            foreach ($values as $method) {
                $message = array(
                    'jsonrpc' => $jsonrpc,
                    'id'      => $values[$iterations % count($values)],
                    'method'  => $method,
                    'params'  => $values[($iterations * 5 + 3) % count($values)],
                );

                $result = $validator->validateCommon($message);

                self::assertTrue(null === $result || $result instanceof ProtocolError);
                ++$iterations;
            }
        }

        self::assertSame(144, $iterations);
    }

    public function testModernValidatorSurvivesMetadataAndMirroredHeaderMutations(): void
    {
        $validator = new RequestValidator();
        $mutations = array(
            null,
            '',
            0,
            1.5,
            true,
            false,
            array(),
            array('x'),
            array('nested' => array('x' => 'y')),
            str_repeat('z', 2048),
        );
        $iterations = 0;

        foreach ($mutations as $version) {
            foreach ($mutations as $capabilities) {
                $message = array(
                    'jsonrpc' => '2.0',
                    'id'      => $iterations,
                    'method'  => 'tools/call',
                    'params'  => array(
                        'name'      => 'wp_nerve_site_status',
                        'arguments' => array(),
                        '_meta'     => array(
                            'io.modelcontextprotocol/protocolVersion'    => $version,
                            'io.modelcontextprotocol/clientCapabilities' => $capabilities,
                        ),
                    ),
                );
                $headers = array(
                    'mcp-protocol-version' => is_string($version) ? $version : RequestValidator::MODERN_VERSION,
                    'mcp-method'           => 0 === $iterations % 7 ? 'tools/list' : 'tools/call',
                    'mcp-name'             => 0 === $iterations % 11 ? 'wp_nerve_get_option' : 'wp_nerve_site_status',
                );

                $result = $validator->validateModern($message, $headers);

                self::assertTrue(null === $result || $result instanceof ProtocolError);
                ++$iterations;
            }
        }

        self::assertSame(100, $iterations);
    }

    public function testDeepButBoundedClientCapabilityShapeDoesNotCrashValidator(): void
    {
        $capabilities = array();
        $cursor       = &$capabilities;

        for ($depth = 0; $depth < 64; ++$depth) {
            $cursor['next'] = array();
            $cursor = &$cursor['next'];
        }
        unset($cursor);

        $message = array(
            'jsonrpc' => '2.0',
            'id'      => 'deep-shape',
            'method'  => 'tools/list',
            'params'  => array(
                '_meta' => array(
                    'io.modelcontextprotocol/protocolVersion'    => RequestValidator::MODERN_VERSION,
                    'io.modelcontextprotocol/clientCapabilities' => $capabilities,
                ),
            ),
        );
        $headers = array(
            'mcp-protocol-version' => RequestValidator::MODERN_VERSION,
            'mcp-method'           => 'tools/list',
        );

        self::assertNull((new RequestValidator())->validateModern($message, $headers));
    }
}
