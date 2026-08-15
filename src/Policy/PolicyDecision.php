<?php

/**
 * Immutable policy evaluation result.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Policy;

final class PolicyDecision
{
    private function __construct(
        public readonly bool $allowed,
        public readonly string $code,
        public readonly string $message
    ) {
    }

    public static function allow(): self
    {
        return new self(true, 'allowed', 'Allowed');
    }

    public static function deny(string $code, string $message): self
    {
        return new self(false, $code, $message);
    }
}
