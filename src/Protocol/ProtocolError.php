<?php

/**
 * JSON-RPC/MCP protocol error value object.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Protocol;

final class ProtocolError
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public readonly int $code,
        public readonly string $message,
        public readonly int $httpStatus = 400,
        public readonly array $data = array()
    ) {
    }

    /**
     * @param int|string|null $id
     * @return array<string, mixed>
     */
    public function response(int|string|null $id): array
    {
        $error = array(
            'code'    => $this->code,
            'message' => $this->message,
        );

        if (array() !== $this->data) {
            $error['data'] = $this->data;
        }

        return array(
            'jsonrpc' => '2.0',
            'id'      => $id,
            'error'   => $error,
        );
    }
}
