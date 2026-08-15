<?php

/**
 * MCP request and mirrored-header validation.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Protocol;

final class RequestValidator
{
    public const MODERN_VERSION = '2026-07-28';

    /** @var array<int, string> */
    public const LEGACY_VERSIONS = array('2025-11-25', '2025-06-18');

    /**
     * @param array<string, mixed>  $message
     * @param array<string, string> $headers Lowercase header names.
     */
    public function isModern(array $message, array $headers): bool
    {
        $headerVersion = $headers['mcp-protocol-version'] ?? null;

        if (is_string($headerVersion) && ! in_array($headerVersion, self::LEGACY_VERSIONS, true)) {
            return true;
        }

        $params = $message['params'] ?? null;
        $meta   = is_array($params) ? ($params['_meta'] ?? null) : null;

        return is_array($meta)
            && is_string($meta['io.modelcontextprotocol/protocolVersion'] ?? null);
    }

    /** @param array<string, mixed> $message */
    public function validateCommon(array $message): ?ProtocolError
    {
        if ('2.0' !== ($message['jsonrpc'] ?? null)) {
            return new ProtocolError(-32600, 'Invalid Request: jsonrpc must be "2.0".');
        }

        if (! isset($message['method']) || ! is_string($message['method']) || '' === $message['method']) {
            return new ProtocolError(-32600, 'Invalid Request: method must be a non-empty string.');
        }

        if (array_key_exists('id', $message) && ! is_int($message['id']) && ! is_string($message['id']) && null !== $message['id']) {
            return new ProtocolError(-32600, 'Invalid Request: id must be a string, integer, or null.');
        }

        if (isset($message['params']) && ! is_array($message['params'])) {
            return new ProtocolError(-32602, 'Invalid params: params must be an object.');
        }

        return null;
    }

    /**
     * @param array<string, mixed>  $message
     * @param array<string, string> $headers Lowercase header names.
     */
    public function validateModern(array $message, array $headers): ?ProtocolError
    {
        $common = $this->validateCommon($message);

        if (null !== $common) {
            return $common;
        }

        $method = (string) $message['method'];
        $params = $message['params'] ?? array();
        $meta   = is_array($params) ? ($params['_meta'] ?? null) : null;

        if (! is_array($meta)) {
            return $this->headerMismatch('Missing required request _meta.');
        }

        $bodyVersion   = $meta['io.modelcontextprotocol/protocolVersion'] ?? null;
        $headerVersion = $headers['mcp-protocol-version'] ?? null;

        if (! is_string($bodyVersion) || ! is_string($headerVersion) || $bodyVersion !== $headerVersion) {
            return $this->headerMismatch('MCP-Protocol-Version does not match request metadata.');
        }

        if (self::MODERN_VERSION !== $bodyVersion) {
            return new ProtocolError(
                -32022,
                'Unsupported protocol version.',
                400,
                array(
                    'supported' => $this->supportedVersions(),
                    'requested' => $bodyVersion,
                )
            );
        }

        if (
            ! array_key_exists('io.modelcontextprotocol/clientCapabilities', $meta)
            || ! is_array($meta['io.modelcontextprotocol/clientCapabilities'])
        ) {
            return new ProtocolError(-32602, 'Invalid params: client capabilities are required in request metadata.');
        }

        $headerMethod = $headers['mcp-method'] ?? null;

        if (! is_string($headerMethod) || $method !== $headerMethod) {
            return $this->headerMismatch('Mcp-Method does not match the JSON-RPC method.');
        }

        if (in_array($method, array('tools/call', 'resources/read', 'prompts/get'), true)) {
            $bodyName   = 'resources/read' === $method ? ($params['uri'] ?? null) : ($params['name'] ?? null);
            $headerName = $this->decodeHeaderValue($headers['mcp-name'] ?? null);

            if (! is_string($bodyName) || null === $headerName || $bodyName !== $headerName) {
                return $this->headerMismatch('Mcp-Name does not match the requested tool, resource, or prompt.');
            }
        }

        return null;
    }

    /** @return array<int, string> */
    public function supportedVersions(): array
    {
        return array_merge(array(self::MODERN_VERSION), self::LEGACY_VERSIONS);
    }

    private function decodeHeaderValue(mixed $value): ?string
    {
        if (! is_string($value) || '' === $value) {
            return null;
        }

        if (! str_starts_with($value, '=?base64?') || ! str_ends_with($value, '?=')) {
            return preg_match('/^[\x20-\x7E]+$/', $value) ? $value : null;
        }

        $encoded = substr($value, 9, -2);
        $decoded = base64_decode($encoded, true);

        if (false === $decoded || 1 !== preg_match('//u', $decoded)) {
            return null;
        }

        return $decoded;
    }

    private function headerMismatch(string $message): ProtocolError
    {
        return new ProtocolError(-32020, 'Header mismatch: ' . $message, 400);
    }
}
