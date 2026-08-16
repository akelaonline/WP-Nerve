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
     * @param array<string, mixed> $arguments
     * @return array{result: mixed, risk: string}|WP_Error
     */
    public function execute(string $toolName, array $arguments): array|WP_Error;
}
