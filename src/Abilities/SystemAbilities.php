<?php

/**
 * System diagnostic abilities (privileged, opt-in).
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Abilities;

use WP_Error;

final class SystemAbilities extends AbstractAbilityRegistrar
{
    private const MAX_LOG_BYTES = 262144;

    private const CAPABILITY = 'manage_options';

    public function register(): void
    {
        $this->registerGetTransient();
        $this->registerDebugLog();
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|WP_Error
     */
    public function getTransient(mixed $input = null): array|WP_Error
    {
        $input     = is_array($input) ? $input : array();
        $transient = (string) ($input['transient'] ?? '');

        if ('' === $transient) {
            return new WP_Error('wp_nerve_invalid_key', __('The transient parameter is required.', 'wp-nerve'));
        }

        $value = get_transient($transient);

        return array('transient' => $transient, 'value' => $value);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|WP_Error
     */
    public function debugLog(mixed $input = null): array|WP_Error
    {
        $input = is_array($input) ? $input : array();

        $path = (defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : ABSPATH . 'wp-content') . '/debug.log';

        if (! is_readable($path)) {
            return new WP_Error('wp_nerve_log_not_found', __('The debug log is not readable or does not exist.', 'wp-nerve'));
        }

        $bytes = $this->clamp((int) ($input['bytes'] ?? 10000), 1024, self::MAX_LOG_BYTES);

        $handle = fopen($path, 'rb');

        if (false === $handle) {
            return new WP_Error('wp_nerve_log_not_found', __('The debug log could not be opened.', 'wp-nerve'));
        }

        fseek($handle, -$bytes, SEEK_END);
        $content = (string) fread($handle, max(1, $bytes));
        fclose($handle);

        return array(
            'path'    => $path,
            'bytes'   => strlen($content),
            'content' => $content,
        );
    }

    private function registerGetTransient(): void
    {
        $this->registerAbility(
            'wp-nerve/get-transient',
            __('Get transient', 'wp-nerve'),
            __('Returns a named transient value. Requires manage_options.', 'wp-nerve'),
            array(
                '$schema'              => 'https://json-schema.org/draft/2020-12/schema',
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array('transient'),
                'properties'           => array(
                    'transient' => array('type' => 'string', 'minLength' => 1, 'maxLength' => 191),
                ),
            ),
            array(
                '$schema'              => 'https://json-schema.org/draft/2020-12/schema',
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array('transient'),
                'properties'           => array(
                    'transient' => array('type' => 'string'),
                    'value'     => array(),
                ),
            ),
            array($this, 'getTransient'),
            'privileged',
            false,
            self::CAPABILITY
        );
    }

    private function registerDebugLog(): void
    {
        $this->registerAbility(
            'wp-nerve/debug-log',
            __('Read debug log', 'wp-nerve'),
            __('Reads the tail of wp-content/debug.log. Requires manage_options.', 'wp-nerve'),
            array(
                '$schema'              => 'https://json-schema.org/draft/2020-12/schema',
                'type'                 => 'object',
                'additionalProperties' => false,
                'properties'           => array(
                    'bytes' => array('type' => 'integer', 'minimum' => 1024, 'maximum' => 262144, 'default' => 10000),
                ),
            ),
            array(
                '$schema'              => 'https://json-schema.org/draft/2020-12/schema',
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array('path', 'bytes', 'content'),
                'properties'           => array(
                    'path'    => array('type' => 'string'),
                    'bytes'   => array('type' => 'integer'),
                    'content' => array('type' => 'string'),
                ),
            ),
            array($this, 'debugLog'),
            'privileged',
            false,
            self::CAPABILITY
        );
    }
}
