<?php

/**
 * Maps native WordPress abilities to MCP tools.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Protocol;

use WP_Ability;
use WP_Error;
use WPNerve\Policy\PolicyEngine;

final class AbilityToolRegistry implements ToolRegistry
{
    public function __construct(private readonly PolicyEngine $policy)
    {
    }

    /** @return array<int, array<string, mixed>> */
    public function tools(): array
    {
        $tools = array();

        foreach ($this->abilities() as $ability) {
            $tools[] = $this->descriptor($ability);
        }

        usort(
            $tools,
            static fn (array $left, array $right): int => strcmp((string) $left['name'], (string) $right['name'])
        );

        return $tools;
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array{result: mixed, risk: string}|WP_Error
     */
    public function execute(string $toolName, array $arguments, array $context = array()): array|WP_Error
    {
        unset($context);

        $ability = $this->find($toolName);

        if (null === $ability) {
            return new WP_Error('wp_nerve_tool_not_found', __('The requested MCP tool does not exist or is not available.', 'wp-nerve'));
        }

        $decision = $this->policy->authorize($ability, $arguments);

        if (! $decision->allowed) {
            return new WP_Error($decision->code, $decision->message);
        }

        $result = $ability->execute($arguments);

        if (is_wp_error($result)) {
            return $result;
        }

        return array(
            'result' => $result,
            'risk'   => $this->policy->risk($ability)->value,
        );
    }

    public function risk(string $toolName): ?string
    {
        $ability = $this->find($toolName);

        return null === $ability ? null : $this->policy->risk($ability)->value;
    }

    private function find(string $toolName): ?WP_Ability
    {
        foreach ($this->abilities() as $ability) {
            if ($toolName === $this->toolName($ability)) {
                return $ability;
            }
        }

        return null;
    }

    /** @return array<int, WP_Ability> */
    private function abilities(): array
    {
        $abilities = array();

        foreach (wp_get_abilities() as $ability) {
            if ($ability instanceof WP_Ability && $this->policy->isDiscoverable($ability)) {
                $abilities[] = $ability;
            }
        }

        return $abilities;
    }

    /** @return array<string, mixed> */
    private function descriptor(WP_Ability $ability): array
    {
        $annotations = $ability->get_meta_item('annotations', array());
        $annotations = is_array($annotations) ? $annotations : array();
        $descriptor  = array(
            'name'         => $this->toolName($ability),
            'title'        => $ability->get_label(),
            'description'  => $ability->get_description(),
            'inputSchema'  => $this->inputSchema($ability),
            'annotations'  => array(
                'readOnlyHint'    => true === ($annotations['readonly'] ?? null),
                'destructiveHint' => true === ($annotations['destructive'] ?? null),
                'idempotentHint'  => true === ($annotations['idempotent'] ?? null),
                'openWorldHint'   => false,
            ),
            '_meta'        => array(
                'wp-nerve/ability' => $ability->get_name(),
                'wp-nerve/risk'    => $this->policy->risk($ability)->value,
                'wp-nerve/idempotencyRequired' => 'read' !== $this->policy->risk($ability)->value,
            ),
        );

        if (array() !== $ability->get_output_schema()) {
            $descriptor['outputSchema'] = $ability->get_output_schema();
        }

        return $descriptor;
    }

    /** @return array<string, mixed> */
    private function inputSchema(WP_Ability $ability): array
    {
        $schema = $ability->get_input_schema();

        return array() !== $schema
            ? $schema
            : array(
                '$schema'              => 'https://json-schema.org/draft/2020-12/schema',
                'type'                 => 'object',
                'properties'           => array(),
                'additionalProperties' => false,
            );
    }

    private function toolName(WP_Ability $ability): string
    {
        $name = str_replace(array('/', '-'), '_', $ability->get_name());

        return sanitize_key($name);
    }
}
