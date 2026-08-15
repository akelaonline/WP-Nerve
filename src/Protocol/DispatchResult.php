<?php

/**
 * Protocol dispatch result, independent from WordPress HTTP classes.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Protocol;

final class DispatchResult
{
    /** @param array<string, mixed>|null $body */
    public function __construct(
        public readonly ?array $body,
        public readonly int $httpStatus = 200
    ) {
    }

    /** @param int|string|null $id */
    public static function error(ProtocolError $error, int|string|null $id): self
    {
        return new self($error->response($id), $error->httpStatus);
    }

    public static function noContent(): self
    {
        return new self(null, 202);
    }
}
