<?php

/**
 * Widget sidebar abilities (read-only).
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Abilities;

use WP_Error;

final class WidgetAbilities extends AbstractAbilityRegistrar
{
    public function register(): void
    {
        $this->registerListSidebars();
        $this->registerGetSidebar();
        $this->registerListAvailable();
    }

    /** @return array<string, mixed> */
    public function listSidebars(mixed $input = null): array
    {
        unset($input);

        $sidebars = array();

        foreach ($this->registeredSidebars() as $sidebar) {
            $sidebars[] = array(
                'id'          => (string) ($sidebar['id'] ?? ''),
                'name'        => (string) ($sidebar['name'] ?? ''),
                'description' => (string) ($sidebar['description'] ?? ''),
            );
        }

        return array('sidebars' => $sidebars);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|WP_Error
     */
    public function getSidebar(mixed $input = null): array|WP_Error
    {
        $input     = is_array($input) ? $input : array();
        $sidebarId = (string) ($input['sidebar_id'] ?? '');
        $all       = wp_get_sidebars_widgets();

        if (! isset($all[$sidebarId]) || ! is_array($all[$sidebarId])) {
            return new WP_Error('wp_nerve_sidebar_not_found', __('The requested sidebar does not exist.', 'wp-nerve'));
        }

        $registered = $this->registeredSidebars();
        $info       = array('id' => $sidebarId, 'name' => $sidebarId, 'description' => '');

        foreach ($registered as $sidebar) {
            if (($sidebar['id'] ?? '') === $sidebarId) {
                $info = array(
                    'id'          => $sidebarId,
                    'name'        => (string) ($sidebar['name'] ?? $sidebarId),
                    'description' => (string) ($sidebar['description'] ?? ''),
                );
                break;
            }
        }

        return array(
            'sidebar' => $info,
            'widgets' => array_values($all[$sidebarId]),
        );
    }

    /** @return array<string, mixed> */
    public function listAvailable(mixed $input = null): array
    {
        unset($input);

        $widgets = array();

        foreach ($this->registeredWidgets() as $idBase => $widget) {
            $widgets[] = array(
                'id_base' => (string) $idBase,
                'name'    => (string) ($widget->name ?? $idBase),
            );
        }

        ksort($widgets);

        return array('widgets' => $widgets);
    }

    private function registerListSidebars(): void
    {
        $this->registerReadAbility(
            'wp-nerve/widgets/list-sidebars',
            __('List sidebars', 'wp-nerve'),
            __('Lists the registered widget sidebars.', 'wp-nerve'),
            $this->emptyInputSchema(),
            array(
                '$schema'              => 'https://json-schema.org/draft/2020-12/schema',
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array('sidebars'),
                'properties'           => array(
                    'sidebars' => array(
                        'type'  => 'array',
                        'items' => array(
                            'type'                 => 'object',
                            'additionalProperties' => false,
                            'required'             => array('id', 'name'),
                            'properties'           => array(
                                'id'          => array('type' => 'string'),
                                'name'        => array('type' => 'string'),
                                'description' => array('type' => 'string'),
                            ),
                        ),
                    ),
                ),
            ),
            array($this, 'listSidebars')
        );
    }

    private function registerGetSidebar(): void
    {
        $this->registerReadAbility(
            'wp-nerve/widgets/get-sidebar',
            __('Get sidebar widgets', 'wp-nerve'),
            __('Returns the widgets assigned to a sidebar.', 'wp-nerve'),
            array(
                '$schema'              => 'https://json-schema.org/draft/2020-12/schema',
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array('sidebar_id'),
                'properties'           => array(
                    'sidebar_id' => array('type' => 'string', 'minLength' => 1),
                ),
            ),
            array(
                '$schema'              => 'https://json-schema.org/draft/2020-12/schema',
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array('sidebar', 'widgets'),
                'properties'           => array(
                    'sidebar' => array('type' => 'object'),
                    'widgets' => array('type' => 'array', 'items' => array('type' => 'string')),
                ),
            ),
            array($this, 'getSidebar')
        );
    }

    private function registerListAvailable(): void
    {
        $this->registerReadAbility(
            'wp-nerve/widgets/list-available',
            __('List available widgets', 'wp-nerve'),
            __('Lists the widget types registered by the active theme and plugins.', 'wp-nerve'),
            $this->emptyInputSchema(),
            array(
                '$schema'              => 'https://json-schema.org/draft/2020-12/schema',
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array('widgets'),
                'properties'           => array(
                    'widgets' => array(
                        'type'  => 'array',
                        'items' => array(
                            'type'                 => 'object',
                            'additionalProperties' => false,
                            'required'             => array('id_base', 'name'),
                            'properties'           => array(
                                'id_base' => array('type' => 'string'),
                                'name'    => array('type' => 'string'),
                            ),
                        ),
                    ),
                ),
            ),
            array($this, 'listAvailable')
        );
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function registeredSidebars(): array
    {
        $sidebars = $GLOBALS['wp_registered_sidebars'] ?? array();

        return is_array($sidebars) ? array_values($sidebars) : array();
    }

    /**
     * @return array<string, object>
     */
    private function registeredWidgets(): array
    {
        $factory = $GLOBALS['wp_widget_factory'] ?? null;

        if (! is_object($factory) || ! isset($factory->widgets) || ! is_array($factory->widgets)) {
            return array();
        }

        return $factory->widgets;
    }
}
