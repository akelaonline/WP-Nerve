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
        $capability    = $configuration['capability'] ?? 'do_not_allow';

        if (! is_string($capability) || '' === $capability) {
            return false;
        }

        $discoverable = $this->isEnabled($configuration, $ability)
            && $this->isRiskClassEnabled($this->risk($ability))
            && current_user_can($capability);

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

    /**
     * Whether the ability's risk class is enabled on this site.
     *
     * Read and write classes are enabled by default. Destructive and privileged
     * classes require an explicit opt-in via the wp_nerve_enabled_risk_classes
     * option or filter.
     */
    public function isRiskClassEnabled(RiskLevel $risk): bool
    {
        $defaults = array(RiskLevel::Read->value, RiskLevel::Write->value);
        $option   = get_option('wp_nerve_enabled_risk_classes', null);
        $classes  = is_array($option) ? $option : $defaults;

        /**
         * Filters the risk classes enabled on this site.
         *
         * @param array<int, string> $classes Enabled risk class values.
         */
        $classes = apply_filters('wp_nerve_enabled_risk_classes', $classes);

        return is_array($classes) && in_array($risk->value, $classes, true);
    }

    /**
     * @param array<string, mixed> $configuration
     */
    private function isEnabled(array $configuration, WP_Ability $ability): bool
    {
        $enabled = true === ($configuration['enabled_by_default'] ?? false);

        /**
         * Filters whether an ability is enabled regardless of its default flag.
         *
         * @param bool       $enabled Whether the ability is enabled.
         * @param WP_Ability $ability Ability under evaluation.
         */
        return (bool) apply_filters('wp_nerve_ability_is_enabled', $enabled, $ability);
    }

    /** @return array<string, mixed> */
    private function configuration(WP_Ability $ability): array
    {
        $configuration = $ability->get_meta_item('wp_nerve', array());

        return is_array($configuration) ? $configuration : array();
    }
}
