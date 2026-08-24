<?php

/**
 * Deterministic abuse corpus for the MCP request validator.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Tests\Unit\Protocol;

use JsonException;
use WPNerve\Protocol\RequestValidator;
use WPNerve\Tests\Unit\TestCase;

final class FuzzCorpusTest extends TestCase
{
    /**
     * @dataProvider abuseCorpus
     * @param array<string, mixed>  $message
     * @param array<string, string> $headers
     */
    public function testAbuseCorpusFailsWithStableError(
        string $mode,
        array $message,
        array $headers,
        int $expectedError,
        string $caseName
    ): void {
        $validator = new RequestValidator();
        $error = 'modern' === $mode
            ? $validator->validateModern($message, $headers)
            : $validator->validateCommon($message);

        self::assertNotNull($error, $caseName);
        self::assertSame($expectedError, $error->code, $caseName);
        self::assertSame(400, $error->httpStatus, $caseName);
    }

    public function testEncodedUtf8ToolNameRoundTripsThroughMirroredHeaderValidation(): void
    {
        $validator = new RequestValidator();
        $name      = 'herramienta-ñ';
        $message   = $this->modernCall($name);
        $headers   = array(
            'mcp-protocol-version' => RequestValidator::MODERN_VERSION,
            'mcp-method'           => 'tools/call',
            'mcp-name'             => '=?base64?' . base64_encode($name) . '?=',
        );

        self::assertNull($validator->validateModern($message, $headers));
    }

    public function testRawNonAsciiToolNameHeaderIsRejectedFailClosed(): void
    {
        $validator = new RequestValidator();
        $message   = $this->modernCall('herramienta-ñ');
        $headers   = array(
            'mcp-protocol-version' => RequestValidator::MODERN_VERSION,
            'mcp-method'           => 'tools/call',
            'mcp-name'             => 'herramienta-ñ',
        );

        $error = $validator->validateModern($message, $headers);

        self::assertNotNull($error);
        self::assertSame(-32020, $error->code);
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>, array<string, string>, int, string}>
     * @throws JsonException
     */
    public static function abuseCorpus(): iterable
    {
        $path = dirname(__DIR__, 2) . '/fuzz/request-validator.json';
        $raw  = file_get_contents($path);

        if (! is_string($raw)) {
            throw new JsonException('Unable to read request-validator fuzz corpus.');
        }

        $cases = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);

        if (! is_array($cases)) {
            throw new JsonException('Request-validator fuzz corpus must decode to an array.');
        }

        foreach ($cases as $case) {
            if (! is_array($case)) {
                throw new JsonException('Each fuzz corpus entry must be an object.');
            }

            $name    = (string) ($case['name'] ?? 'unnamed');
            $mode    = (string) ($case['mode'] ?? 'common');
            $message = $case['message'] ?? array();
            $headers = $case['headers'] ?? array();
            $error   = $case['error'] ?? null;

            if (! is_array($message) || ! is_array($headers) || ! is_int($error)) {
                throw new JsonException('Invalid fuzz corpus entry: ' . $name);
            }

            $normalizedHeaders = array();

            foreach ($headers as $header => $value) {
                if (! is_string($header) || ! is_string($value)) {
                    throw new JsonException('Invalid header in fuzz corpus entry: ' . $name);
                }

                $normalizedHeaders[$header] = $value;
            }

            yield $name => array($mode, $message, $normalizedHeaders, $error, $name);
        }
    }

    /** @return array<string, mixed> */
    private function modernCall(string $name): array
    {
        return array(
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => 'tools/call',
            'params'  => array(
                'name'      => $name,
                'arguments' => array(),
                '_meta'     => array(
                    'io.modelcontextprotocol/protocolVersion'    => RequestValidator::MODERN_VERSION,
                    'io.modelcontextprotocol/clientCapabilities' => array(),
                ),
            ),
        );
    }
}
