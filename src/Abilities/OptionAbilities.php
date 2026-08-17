<?php

/**
 * Option abilities (privileged, opt-in).
 *
 * Listing options returns keys only; values are never exposed in bulk.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Abilities;

use WP_Error;

final class OptionAbilities extends AbstractAbilityRegistrar
{
    private const CAPABILITY = 'manage_options';

    public function register(): void
    {
        $this->registerGetOption();
        $this->registerUpdateOption();
        $this->registerListOptions();
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|WP_Error
     */
    public function getOption(mixed $input = null): array|WP_Error
    {
        $input = is_array($input) ? $input : array();
        $key   = (string) ($input['key'] ?? '');

        if ('' === $key) {
            return new WP_Error('wp_nerve_invalid_key', __('The key parameter is required.', 'wp-nerve'));
        }

        $value = get_option($key, '__wp_nerve_missing__');

        if ('__wp_nerve_missing__' === $value) {
            return new WP_Error('wp_nerve_option_not_found', __('The requested option does not exist.', 'wp-nerve'));
        }

        return array('key' => $key, 'value' => $value);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|WP_Error
     */
    public function updateOption(mixed $input = null): array|WP_Error
    {
        $input = is_array($input) ? $input : array();
        $key   = (string) ($input['key'] ?? '');

        if ('' === $key || ! array_key_exists('value', $input)) {
            return new WP_Error('wp_nerve_invalid_key', __('The key and value parameters are required.', 'wp-nerve'));
        }

        $previous = get_option($key, '__wp_nerve_missing__');

        update_option($key, $input['value']);

        return array(
            'key'      => $key,
            'value'    => $input['value'],
            'previous' => '__wp_nerve_missing__' === $previous ? null : $previous,
            'recovery' => array(
                'note' => __('Restore the previous value to undo.', 'wp-nerve'),
            ),
        );
    }

    /** @return array<string, mixed> */
    public function listOptions(mixed $input = null): array
    {
        unset($input);

        $keys = array_keys(wp_load_alloptions());
        sort($keys);

        return array('options' => $keys, 'total' => count($keys));
    }

    private function registerGetOption(): void
    {
        $this->registerAbility(
            'wp-nerve/get-option',
            __('Get option', 'wp-nerve'),
            __('Returns a named option and its value. Requires manage_options.', 'wp-nerve'),
            $this->optionKeySchema(),
            array(
                '$schema'              => 'https://json-schema.org/draft/2020-12/schema',
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array('key'),
                'properties'           => array(
                    'key'   => array('type' => 'string'),
                    'value' => array(),
                ),
            ),
            array($this, 'getOption'),
            'privileged',
            false,
            self::CAPABILITY
        );
    }

    private function registerUpdateOption(): void
    {
        $this->registerAbility(
            'wp-nerve/update-option',
            __('Update option', 'wp-nerve'),
            __('Updates a named option. The previous value is returned for recovery. Requires manage_options.', 'wp-nerve'),
            array(
                '$schema'              => 'https://json-schema.org/draft/2020-12/schema',
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array('key', 'value'),
                'properties'           => array(
                    'key'   => array('type' => 'string', 'minLength' => 1, 'maxLength' => 191),
                    'value' => array(),
                ),
            ),
            array(
                '$schema'              => 'https://json-schema.org/draft/2020-12/schema',
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array('key'),
                'properties'           => array(
                    'key'      => array('type' => 'string'),
                    'value'    => array(),
                    'previous' => array(),
                    'recovery' => array('type' => 'object'),
                ),
            ),
            array($this, 'updateOption'),
            'privileged',
            false,
            self::CAPABILITY
        );
    }

    private function registerListOptions(): void
    {
        $this->registerAbility(
            'wp-nerve/list-options',
            __('List options', 'wp-nerve'),
            __('Lists option keys only, without values. Requires manage_options.', 'wp-nerve'),
            $this->emptyInputSchema(),
            array(
                '$schema'              => 'https://json-schema.org/draft/2020-12/schema',
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array('options', 'total'),
                'properties'           => array(
                    'options' => array('type' => 'array', 'items' => array('type' => 'string')),
                    'total'   => array('type' => 'integer'),
                ),
            ),
            array($this, 'listOptions'),
            'privileged',
            false,
            self::CAPABILITY
        );
    }

    /** @return array<string, mixed> */
    private function optionKeySchema(): array
    {
        return array(
            '$schema'              => 'https://json-schema.org/draft/2020-12/schema',
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => array('key'),
            'properties'           => array(
                'key' => array('type' => 'string', 'minLength' => 1, 'maxLength' => 191),
            ),
        );
    }
}
