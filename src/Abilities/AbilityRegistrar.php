<?php

/**
 * Registers WPNerve's native WordPress abilities.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Abilities;

final class AbilityRegistrar
{
    public function registerCategory(): void
    {
        wp_register_ability_category(
            'wp-nerve-site',
            array(
                'label'       => __('WPNerve: Site', 'wp-nerve'),
                'description' => __('Safe site information abilities exposed by WPNerve.', 'wp-nerve'),
            )
        );
    }

    public function registerAbilities(): void
    {
        wp_register_ability(
            'wp-nerve/site-status',
            array(
                'label'               => __('Get site status', 'wp-nerve'),
                'description'         => __(
                    'Returns non-sensitive WordPress and WPNerve runtime information for connection diagnostics.',
                    'wp-nerve'
                ),
                'category'            => 'wp-nerve-site',
                'input_schema'        => array(
                    '$schema'              => 'https://json-schema.org/draft/2020-12/schema',
                    'type'                 => 'object',
                    'properties'           => array(),
                    'additionalProperties' => false,
                ),
                'output_schema'       => array(
                    '$schema'              => 'https://json-schema.org/draft/2020-12/schema',
                    'type'                 => 'object',
                    'additionalProperties' => false,
                    'required'             => array(
                        'site_name',
                        'site_url',
                        'wordpress_version',
                        'php_version',
                        'multisite',
                        'mcp_endpoint',
                        'protocol_versions',
                        'wpnerve_version',
                    ),
                    'properties'           => array(
                        'site_name'         => array('type' => 'string'),
                        'site_url'          => array('type' => 'string', 'format' => 'uri'),
                        'wordpress_version' => array('type' => 'string'),
                        'php_version'       => array('type' => 'string'),
                        'multisite'         => array('type' => 'boolean'),
                        'mcp_endpoint'      => array('type' => 'string', 'format' => 'uri'),
                        'protocol_versions' => array(
                            'type'  => 'array',
                            'items' => array('type' => 'string'),
                        ),
                        'wpnerve_version'   => array('type' => 'string'),
                    ),
                ),
                'execute_callback'    => array($this, 'getSiteStatus'),
                'permission_callback' => array($this, 'canReadSiteStatus'),
                'meta'                => array(
                    'public'       => true,
                    'show_in_rest' => false,
                    'annotations'  => array(
                        'readonly'    => true,
                        'destructive' => false,
                        'idempotent'  => true,
                    ),
                    'wp_nerve'     => array(
                        'risk'               => 'read',
                        'capability'         => $this->transportCapability(),
                        'enabled_by_default' => true,
                    ),
                ),
            )
        );
    }

    public function canReadSiteStatus(mixed $input = null): bool
    {
        unset($input);

        return current_user_can($this->transportCapability());
    }

    /** @return array<string, mixed> */
    public function getSiteStatus(mixed $input = null): array
    {
        global $wp_version;

        unset($input);

        return array(
            'site_name'         => (string) get_bloginfo('name'),
            'site_url'          => site_url('/'),
            'wordpress_version' => (string) $wp_version,
            'php_version'       => PHP_VERSION,
            'multisite'         => is_multisite(),
            'mcp_endpoint'      => rest_url('wp-nerve/v1/mcp'),
            'protocol_versions' => array('2026-07-28', '2025-11-25', '2025-06-18'),
            'wpnerve_version'   => WP_NERVE_VERSION,
        );
    }

    private function transportCapability(): string
    {
        /**
         * Filters the minimum capability required to access WPNerve.
         *
         * @param string $capability WordPress capability name.
         */
        $capability = apply_filters('wp_nerve_transport_capability', 'edit_posts');

        return is_string($capability) && '' !== $capability ? $capability : 'do_not_allow';
    }
}
