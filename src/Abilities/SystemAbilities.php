<?php

/**
 * System diagnostic abilities (privileged, opt-in).
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Abilities;

use WP_Error;
use WPNerve\Security\Privileged\SurfaceGuard;

final class SystemAbilities extends AbstractAbilityRegistrar
{
    private const MAX_LOG_BYTES = 65536;

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
        $transient = strtolower(trim((string) ($input['transient'] ?? '')));
        $guard     = new SurfaceGuard();

        if ('' === $transient) {
            return new WP_Error('wp_nerve_invalid_key', __('The transient parameter is required.', 'wp-nerve'));
        }

        if (! $guard->canReadTransient($transient)) {
            return new WP_Error(
                'wp_nerve_protected_transient',
                __('This transient is not allowlisted for WPNerve disclosure.', 'wp-nerve')
            );
        }

        $value = get_transient($transient);

        if (! $guard->valueIsSafe($value)) {
            return new WP_Error(
                'wp_nerve_unsafe_transient_value',
                __('The transient value cannot be safely represented through WPNerve.', 'wp-nerve')
            );
        }

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

        $size   = filesize($path);
        $offset = is_int($size) ? max(0, $size - $bytes) : 0;

        if (0 !== fseek($handle, $offset, SEEK_SET)) {
            fclose($handle);

            return new WP_Error('wp_nerve_log_read_failed', __('The debug log could not be positioned for reading.', 'wp-nerve'));
        }

        $content = (string) fread($handle, max(1, $bytes));
        fclose($handle);

        $content = (new SurfaceGuard())->redactLog($content);

        return array(
            'path'     => 'wp-content/debug.log',
            'bytes'    => strlen($content),
            'content'  => $content,
            'redacted' => true,
        );
    }

    private function registerGetTransient(): void
    {
        $this->registerAbility(
            'wp-nerve/get-transient',
            __('Get transient', 'wp-nerve'),
            __('Returns an explicitly allowlisted transient value. The default allowlist is empty.', 'wp-nerve'),
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
            __('Reads a redacted tail of wp-content/debug.log. Requires manage_options.', 'wp-nerve'),
            array(
                '$schema'              => 'https://json-schema.org/draft/2020-12/schema',
                'type'                 => 'object',
                'additionalProperties' => false,
                'properties'           => array(
                    'bytes' => array('type' => 'integer', 'minimum' => 1024, 'maximum' => 65536, 'default' => 10000),
                ),
            ),
            array(
                '$schema'              => 'https://json-schema.org/draft/2020-12/schema',
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array('path', 'bytes', 'content', 'redacted'),
                'properties'           => array(
                    'path'     => array('type' => 'string'),
                    'bytes'    => array('type' => 'integer'),
                    'content'  => array('type' => 'string'),
                    'redacted' => array('type' => 'boolean'),
                ),
            ),
            array($this, 'debugLog'),
            'privileged',
            false,
            self::CAPABILITY
        );
    }
}
