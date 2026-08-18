<?php

/**
 * Contract for MCP tool registries.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Protocol;

use WP_Error;

interface ToolRegistry
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function tools(): array;

    /**
     * Returns the effective risk of a discoverable tool.
     */
    public function risk(string $toolName): ?string;

    /**
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $context
     * @return array{result: mixed, risk: string}|WP_Error
     */
    public function execute(string $toolName, array $arguments, array $context = array()): array|WP_Error;
}
