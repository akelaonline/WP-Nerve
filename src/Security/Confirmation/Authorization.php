<?php

/**
 * Immutable result of an atomic confirmation authorization.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Security\Confirmation;

final class Authorization
{
    public function __construct(
        public readonly AuthorizationState $state,
        public readonly string $displayCode = '',
        public readonly int $expiresAt = 0
    ) {
    }
}
