<?php

/**
 * Central policy gate for ability discovery and execution.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Policy;

use WP_Ability;

final class PolicyEngine
{
    public function isDiscoverable(WP_Ability $ability): bool
    {
        if (! str_starts_with($ability->get_name(), 'wp-nerve/')) {
            return false;
        }

        $configuration = $this->configuration($ability);
        $enabled       = true === ($configuration['enabled_by_default'] ?? false);
        $capability    = $configuration['capability'] ?? 'do_not_allow';

        if (! is_string($capability) || '' === $capability) {
            return false;
        }

        $discoverable = $enabled && current_user_can($capability);

        /**
         * Filters whether an ability is exposed as an MCP tool.
         *
         * @param bool       $discoverable Whether the ability is discoverable.
         * @param WP_Ability $ability     Ability under evaluation.
         */
        return (bool) apply_filters('wp_nerve_ability_is_discoverable', $discoverable, $ability);
    }

    /**
     * @param mixed $input Validated by WP_Ability during execution.
     */
    public function authorize(WP_Ability $ability, mixed $input = null): PolicyDecision
    {
        if (! $this->isDiscoverable($ability)) {
            return PolicyDecision::deny('ability_not_exposed', 'This ability is not exposed to the current user.');
        }

        $risk = $this->risk($ability);

        if (in_array($risk, array(RiskLevel::Destructive, RiskLevel::Privileged), true)) {
            return PolicyDecision::deny(
                'confirmation_required',
                'This ability requires an explicit WPNerve confirmation token.'
            );
        }

        /**
         * Filters the final policy decision before an ability executes.
         *
         * @param PolicyDecision $decision Current decision.
         * @param WP_Ability     $ability  Ability under evaluation.
         * @param mixed          $input    Tool input.
         */
        $decision = apply_filters('wp_nerve_policy_decision', PolicyDecision::allow(), $ability, $input);

        return $decision instanceof PolicyDecision
            ? $decision
            : PolicyDecision::deny('invalid_policy_decision', 'A policy filter returned an invalid decision.');
    }

    public function risk(WP_Ability $ability): RiskLevel
    {
        $value = $this->configuration($ability)['risk'] ?? RiskLevel::Privileged->value;

        return is_string($value)
            ? (RiskLevel::tryFrom($value) ?? RiskLevel::Privileged)
            : RiskLevel::Privileged;
    }

    /** @return array<string, mixed> */
    private function configuration(WP_Ability $ability): array
    {
        $configuration = $ability->get_meta_item('wp_nerve', array());

        return is_array($configuration) ? $configuration : array();
    }
}
