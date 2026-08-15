<?php

/**
 * MCP method dispatcher for modern and legacy protocol eras.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Protocol;

use WPNerve\Audit\AuditRepository;

final class JsonRpcHandler
{
    public function __construct(
        private readonly AbilityToolRegistry $tools,
        private readonly AuditRepository $audit
    ) {
    }

    /**
     * @param array<string, mixed> $message
     * @param array<string, mixed> $context
     */
    public function handle(array $message, string $protocolVersion, bool $modern, array $context = array()): DispatchResult
    {
        $id     = $this->requestId($message);
        $method = (string) ($message['method'] ?? '');

        if (! array_key_exists('id', $message) && str_starts_with($method, 'notifications/')) {
            return DispatchResult::noContent();
        }

        $result = match ($method) {
            'server/discover' => $modern
                ? $this->result($id, $this->discoverResult())
                : $this->methodNotFound($id, false),
            'initialize' => ! $modern
                ? $this->result($id, $this->initializeResult($message, $protocolVersion))
                : $this->methodNotFound($id, true),
            'ping' => ! $modern
                ? $this->result($id, array())
                : $this->methodNotFound($id, true),
            'tools/list' => $this->result($id, $this->toolsListResult($modern)),
            'tools/call' => $this->callTool($message, $protocolVersion, $modern, $context),
            default => $this->methodNotFound($id, $modern),
        };

        return array_key_exists('id', $message) ? $result : DispatchResult::noContent();
    }

    /** @return array<string, mixed> */
    private function discoverResult(): array
    {
        return array(
            'resultType'       => 'complete',
            'supportedVersions' => array_merge(
                array(RequestValidator::MODERN_VERSION),
                RequestValidator::LEGACY_VERSIONS
            ),
            'capabilities'     => array('tools' => array('listChanged' => false)),
            'instructions'     => 'Use only the tools exposed for the authenticated WordPress user. WPNerve denies destructive actions by default.',
            'ttlMs'            => 60000,
            'cacheScope'       => 'private',
            '_meta'            => $this->resultMeta(),
        );
    }

    /**
     * @param array<string, mixed> $message
     * @return array<string, mixed>
     */
    private function initializeResult(array $message, string $fallbackVersion): array
    {
        $params    = $message['params'] ?? array();
        $requested = is_array($params) ? ($params['protocolVersion'] ?? null) : null;
        $version   = is_string($requested) && in_array($requested, RequestValidator::LEGACY_VERSIONS, true)
            ? $requested
            : $fallbackVersion;

        if (! in_array($version, RequestValidator::LEGACY_VERSIONS, true)) {
            $version = RequestValidator::LEGACY_VERSIONS[0];
        }

        return array(
            'protocolVersion' => $version,
            'capabilities'    => array('tools' => array('listChanged' => false)),
            'serverInfo'      => $this->serverInfo(),
            'instructions'    => 'WPNerve exposes only tools authorized for the authenticated WordPress user.',
        );
    }

    /** @return array<string, mixed> */
    private function toolsListResult(bool $modern): array
    {
        $result = array('tools' => $this->tools->tools());

        if ($modern) {
            $result = array_merge(
                array('resultType' => 'complete'),
                $result,
                array(
                    'ttlMs'      => 60000,
                    'cacheScope' => 'private',
                    '_meta'      => $this->resultMeta(),
                )
            );
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $message
     * @param array<string, mixed> $context
     */
    private function callTool(array $message, string $protocolVersion, bool $modern, array $context): DispatchResult
    {
        $id        = $this->requestId($message);
        $params    = $message['params'] ?? array();
        $name      = is_array($params) ? ($params['name'] ?? null) : null;
        $arguments = is_array($params) ? ($params['arguments'] ?? array()) : array();

        if (! is_string($name) || '' === $name || ! is_array($arguments)) {
            return DispatchResult::error(new ProtocolError(-32602, 'Invalid params for tools/call.'), $id);
        }

        $started = hrtime(true);
        $result  = $this->tools->execute($name, $arguments);
        $elapsed = (int) round((hrtime(true) - $started) / 1_000_000);

        if (is_wp_error($result)) {
            $this->audit(
                $message,
                $protocolVersion,
                $context,
                $name,
                '',
                'error',
                $elapsed,
                (string) $result->get_error_code()
            );

            if ('wp_nerve_tool_not_found' === $result->get_error_code()) {
                return DispatchResult::error(new ProtocolError(-32602, $result->get_error_message()), $id);
            }

            $toolResult = array(
                'content' => array(array('type' => 'text', 'text' => $result->get_error_message())),
                'isError' => true,
            );
        } else {
            $this->audit($message, $protocolVersion, $context, $name, (string) $result['risk'], 'success', $elapsed, '');

            $toolResult = array(
                'content'           => array(array('type' => 'text', 'text' => $this->stringify($result['result']))),
                'structuredContent' => $result['result'],
                'isError'           => false,
            );
        }

        if ($modern) {
            $toolResult = array_merge(
                array('resultType' => 'complete'),
                $toolResult,
                array('_meta' => $this->resultMeta())
            );
        }

        return $this->result($id, $toolResult);
    }

    /**
     * @param array<string, mixed> $message
     * @param array<string, mixed> $context
     */
    private function audit(
        array $message,
        string $protocolVersion,
        array $context,
        string $tool,
        string $risk,
        string $outcome,
        int $duration,
        string $errorCode
    ): void {
        $this->audit->record(
            array(
                'request_id'       => (string) ($message['id'] ?? ''),
                'protocol_version' => $protocolVersion,
                'client_name'      => (string) ($context['client_name'] ?? ''),
                'client_version'   => (string) ($context['client_version'] ?? ''),
                'rpc_method'       => 'tools/call',
                'tool_name'        => $tool,
                'risk'             => $risk,
                'outcome'          => $outcome,
                'duration_ms'      => $duration,
                'error_code'       => $errorCode,
            )
        );
    }

    /**
     * @param int|string|null     $id
     * @param array<string, mixed> $result
     */
    private function result(int|string|null $id, array $result): DispatchResult
    {
        return new DispatchResult(
            array(
                'jsonrpc' => '2.0',
                'id'      => $id,
                'result'  => $result,
            )
        );
    }

    /** @param int|string|null $id */
    private function methodNotFound(int|string|null $id, bool $modern): DispatchResult
    {
        return DispatchResult::error(
            new ProtocolError(-32601, 'Method not found.', $modern ? 404 : 200),
            $id
        );
    }

    /** @param array<string, mixed> $message */
    private function requestId(array $message): int|string|null
    {
        $id = $message['id'] ?? null;

        return is_int($id) || is_string($id) ? $id : null;
    }

    /** @return array<string, string> */
    private function serverInfo(): array
    {
        return array('name' => 'WPNerve', 'version' => WP_NERVE_VERSION);
    }

    /** @return array<string, array<string, string>> */
    private function resultMeta(): array
    {
        return array('io.modelcontextprotocol/serverInfo' => $this->serverInfo());
    }

    private function stringify(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        $encoded = wp_json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return false === $encoded ? '' : $encoded;
    }
}
