<?php

/**
 * Persistence contract for confirmation challenges.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Security\Confirmation;

interface Repository
{
    public function issue(
        int $userId,
        string $credentialId,
        string $toolName,
        string $risk,
        string $requestHash,
        string $idempotencyKey,
        string $tokenHash,
        string $displayCode,
        int $expiresAt
    ): bool;

    public function authorize(
        int $userId,
        string $credentialId,
        string $toolName,
        string $requestHash,
        string $idempotencyKey,
        string $tokenHash
    ): Authorization;

    /**
     * @return array<int, array{id: int, user_id: int, tool_name: string, risk: string, display_code: string, created_at: string, expires_at: string}>
     */
    public function pending(): array;

    public function decide(int $challengeId, int $adminUserId, bool $approved): bool;
}
