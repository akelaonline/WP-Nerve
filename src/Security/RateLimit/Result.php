<?php

/**
 * Rate-limit decision returned to transport boundaries.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Security\RateLimit;

final class Result
{
    public function __construct(
        public readonly bool $allowed,
        public readonly bool $available,
        public readonly int $limit,
        public readonly int $remaining,
        public readonly int $resetAt
    ) {
    }

    public static function unavailable(int $limit, int $resetAt): self
    {
        return new self(false, false, $limit, 0, $resetAt);
    }

    public function retryAfter(int $now): int
    {
        return max(1, $this->resetAt - $now);
    }
}
