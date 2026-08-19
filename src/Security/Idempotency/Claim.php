<?php

/**
 * Immutable result of an idempotency claim.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Security\Idempotency;

final class Claim
{
    /**
     * @param array<string, mixed>|null $outcome Serialized previous outcome for replay.
     */
    public function __construct(
        public readonly ClaimState $state,
        public readonly ?array $outcome = null
    ) {
    }

    public static function acquired(): self
    {
        return new self(ClaimState::Acquired);
    }

    /** @param array<string, mixed> $outcome */
    public static function replay(array $outcome): self
    {
        return new self(ClaimState::Replay, $outcome);
    }
}
