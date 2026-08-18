<?php

/**
 * Tool registry decorator that gates high-risk execution on confirmation.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Security\Confirmation;

use WP_Error;
use WPNerve\Protocol\ToolRegistry;

final class ConfirmedToolRegistry implements ToolRegistry
{
    public function __construct(
        private readonly ToolRegistry $inner,
        private readonly Service $service
    ) {
    }

    /** @return array<int, array<string, mixed>> */
    public function tools(): array
    {
        return $this->inner->tools();
    }

    public function risk(string $toolName): ?string
    {
        return $this->inner->risk($toolName);
    }

    /**
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $context
     * @return array{result: mixed, risk: string}|WP_Error
     */
    public function execute(string $toolName, array $arguments, array $context = array()): array|WP_Error
    {
        $risk = $this->inner->risk($toolName);

        if (null === $risk) {
            return $this->inner->execute($toolName, $arguments, $context);
        }

        $authorization = $this->service->authorize(
            $toolName,
            $risk,
            $arguments,
            $context['idempotency_key'] ?? null,
            is_string($context['credential_id'] ?? null) ? $context['credential_id'] : '',
            $context['confirmation_token'] ?? null
        );

        if (true !== $authorization) {
            return $authorization instanceof WP_Error
                ? $authorization
                : new WP_Error(
                    'wp_nerve_confirmation_unavailable',
                    __('The confirmation service did not authorize this operation.', 'wp-nerve')
                );
        }

        return $this->inner->execute($toolName, $arguments, $context);
    }
}
