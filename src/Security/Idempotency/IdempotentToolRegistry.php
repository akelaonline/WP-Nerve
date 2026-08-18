<?php

/**
 * Tool registry decorator that protects all non-read executions from replay.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Security\Idempotency;

use WP_Error;
use WPNerve\Protocol\ToolRegistry;

final class IdempotentToolRegistry implements ToolRegistry
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

        $result = $this->service->execute(
            $toolName,
            $risk,
            $arguments,
            $context['idempotency_key'] ?? null,
            is_string($context['credential_id'] ?? null) ? $context['credential_id'] : '',
            fn (): array|WP_Error => $this->inner->execute($toolName, $arguments, $context)
        );

        if ($result instanceof WP_Error) {
            return $result;
        }

        if (! array_key_exists('result', $result) || $risk !== ($result['risk'] ?? null)) {
            return new WP_Error(
                'wp_nerve_idempotency_corrupt',
                __('The stored idempotency outcome is invalid.', 'wp-nerve')
            );
        }

        return array(
            'result' => $result['result'],
            'risk'   => $risk,
        );
    }
}
