<?php

/**
 * Persistence contract for atomic idempotency claims.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Security\Idempotency;

interface Repository
{
    public function claim(
        int $userId,
        string $credentialId,
        string $toolName,
        string $key,
        string $requestHash
    ): Claim;

    /**
     * @param array<string, mixed> $outcome
     */
    public function complete(
        int $userId,
        string $credentialId,
        string $toolName,
        string $key,
        string $requestHash,
        array $outcome
    ): bool;
}
