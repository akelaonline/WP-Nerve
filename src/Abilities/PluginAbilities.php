<?php

/**
 * Plugin management abilities (privileged and destructive, opt-in).
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Abilities;

use WP_Error;

final class PluginAbilities extends AbstractAbilityRegistrar
{
    private const MAX_UPLOAD_BYTES = 52428800;

    public function register(): void
    {
        $this->registerListPlugins();
        $this->registerActivatePlugin();
        $this->registerDeactivatePlugin();
        $this->registerUploadPlugin();
        $this->registerDeletePlugin();
    }

    /** @return array<string, mixed>|WP_Error */
    public function listPlugins(mixed $input = null): array|WP_Error
    {
        unset($input);

        if (! current_user_can('activate_plugins')) {
            return new WP_Error('wp_nerve_forbidden', __('You are not allowed to inspect plugins.', 'wp-nerve'));
        }

        $this->ensureAdminIncludes();
        $plugins = array();

        foreach (get_plugins() as $file => $data) {
            $plugins[] = array(
                'file'    => $file,
                'name'    => (string) ($data['Name'] ?? $file),
                'version' => (string) ($data['Version'] ?? ''),
                'active'  => is_plugin_active($file),
            );
        }

        usort($plugins, static fn (array $a, array $b): int => strcmp((string) $a['file'], (string) $b['file']));

        return array('plugins' => $plugins, 'total' => count($plugins));
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|WP_Error
     */
    public function activatePlugin(mixed $input = null): array|WP_Error
    {
        $input  = is_array($input) ? $input : array();
        $plugin = (string) ($input['plugin'] ?? '');

        if (! current_user_can('activate_plugins')) {
            return new WP_Error('wp_nerve_forbidden', __('You are not allowed to activate plugins.', 'wp-nerve'));
        }

        $this->ensureAdminIncludes();

        if ('' === $plugin || ! isset(get_plugins()[$plugin])) {
            return new WP_Error('wp_nerve_plugin_not_found', __('The requested plugin does not exist.', 'wp-nerve'));
        }

        $result = activate_plugin($plugin);

        if (is_wp_error($result)) {
            return $result;
        }

        return array(
            'file'   => $plugin,
            'active' => true,
            'recovery' => array(
                'undo' => 'wp_nerve_deactivate_plugin',
                'note' => __('Deactivate the plugin to undo.', 'wp-nerve'),
            ),
        );
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|WP_Error
     */
    public function deactivatePlugin(mixed $input = null): array|WP_Error
    {
        $input  = is_array($input) ? $input : array();
        $plugin = (string) ($input['plugin'] ?? '');

        if (! current_user_can('activate_plugins')) {
            return new WP_Error('wp_nerve_forbidden', __('You are not allowed to deactivate plugins.', 'wp-nerve'));
        }

        $this->ensureAdminIncludes();

        if ('' === $plugin || ! isset(get_plugins()[$plugin])) {
            return new WP_Error('wp_nerve_plugin_not_found', __('The requested plugin does not exist.', 'wp-nerve'));
        }

        if ($this->isProtectedPlugin($plugin)) {
            return new WP_Error(
                'wp_nerve_protected_plugin',
                __('This plugin is protected from WPNerve deactivation.', 'wp-nerve')
            );
        }

        deactivate_plugins(array($plugin));

        return array(
            'file'   => $plugin,
            'active' => false,
            'recovery' => array(
                'undo' => 'wp_nerve_activate_plugin',
                'note' => __('Activate the plugin to undo.', 'wp-nerve'),
            ),
        );
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|WP_Error
     */
    public function uploadPlugin(mixed $input = null): array|WP_Error
    {
        $input    = is_array($input) ? $input : array();
        $name     = trim((string) ($input['filename'] ?? ''));
        $raw      = (string) ($input['content'] ?? '');
        $expected = strtolower(trim((string) ($input['sha256'] ?? '')));

        if (! current_user_can('install_plugins') || ! current_user_can('upload_plugins')) {
            return new WP_Error('wp_nerve_forbidden', __('You are not allowed to upload plugin archives.', 'wp-nerve'));
        }

        if (
            '' === $name
            || '' === $raw
            || 1 !== preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*\.zip$/i', $name)
            || basename($name) !== $name
        ) {
            return new WP_Error(
                'wp_nerve_invalid_upload',
                __('A simple .zip filename and base64 archive content are required.', 'wp-nerve')
            );
        }

        if (1 !== preg_match('/^[a-f0-9]{64}$/', $expected)) {
            return new WP_Error('wp_nerve_invalid_checksum', __('A lowercase SHA-256 checksum is required.', 'wp-nerve'));
        }

        $encodedLimit = (int) ceil($this->maxUploadBytes() * 4 / 3) + 4;

        if (strlen($raw) > $encodedLimit) {
            return new WP_Error('wp_nerve_upload_too_large', __('The uploaded file exceeds the configured size limit.', 'wp-nerve'));
        }

        $bits = base64_decode($raw, true);

        if (false === $bits || '' === $bits || strlen($bits) > $this->maxUploadBytes()) {
            return new WP_Error('wp_nerve_invalid_base64', __('The content parameter must be valid base64 within the size limit.', 'wp-nerve'));
        }

        if (! str_starts_with($bits, 'PK')) {
            return new WP_Error('wp_nerve_invalid_archive', __('The uploaded package is not a ZIP archive.', 'wp-nerve'));
        }

        $actual = hash('sha256', $bits);

        if (! hash_equals($expected, $actual)) {
            return new WP_Error('wp_nerve_checksum_mismatch', __('The plugin archive checksum does not match.', 'wp-nerve'));
        }

        $this->ensureAdminIncludes();
        $slug = strtolower(substr($name, 0, -4));

        foreach (array_keys(get_plugins()) as $installedPlugin) {
            if (str_starts_with(strtolower((string) $installedPlugin), $slug . '/')) {
                return new WP_Error(
                    'wp_nerve_plugin_exists',
                    __('A plugin matching this archive slug is already installed; WPNerve will not replace it.', 'wp-nerve')
                );
            }
        }

        $upload = wp_upload_bits($name, null, $bits);

        if (false !== $upload['error']) {
            return new WP_Error('wp_nerve_upload_failed', (string) $upload['error']);
        }

        $pluginsDir = defined('WP_PLUGIN_DIR') ? WP_PLUGIN_DIR : WP_CONTENT_DIR . '/plugins';
        $result     = unzip_file((string) $upload['file'], $pluginsDir);

        if (is_wp_error($result)) {
            return $result;
        }

        return array(
            'file'         => $name,
            'installed_to' => 'wp-content/plugins',
            'sha256'       => $actual,
            'recovery'     => array(
                'undo' => 'wp_nerve_delete_plugin',
                'note' => __('Delete the newly installed plugin to undo after verifying its plugin file with list-plugins.', 'wp-nerve'),
            ),
        );
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|WP_Error
     */
    public function deletePlugin(mixed $input = null): array|WP_Error
    {
        $input  = is_array($input) ? $input : array();
        $plugin = (string) ($input['plugin'] ?? '');

        if (! current_user_can('delete_plugins')) {
            return new WP_Error('wp_nerve_forbidden', __('You are not allowed to delete plugins.', 'wp-nerve'));
        }

        $this->ensureAdminIncludes();

        if ('' === $plugin || ! isset(get_plugins()[$plugin])) {
            return new WP_Error('wp_nerve_plugin_not_found', __('The requested plugin does not exist.', 'wp-nerve'));
        }

        if ($this->isProtectedPlugin($plugin)) {
            return new WP_Error(
                'wp_nerve_protected_plugin',
                __('This plugin is protected from WPNerve deletion.', 'wp-nerve')
            );
        }

        if (is_plugin_active($plugin)) {
            deactivate_plugins(array($plugin));
        }

        $failed = delete_plugins(array($plugin));

        if (is_wp_error($failed)) {
            return $failed;
        }

        if (is_array($failed) && in_array($plugin, $failed, true)) {
            return new WP_Error('wp_nerve_delete_failed', __('The plugin could not be deleted.', 'wp-nerve'));
        }

        return array(
            'file'     => $plugin,
            'deleted'  => true,
            'recovery' => array(
                'note' => __('Reinstall the plugin to undo.', 'wp-nerve'),
            ),
        );
    }

    private function registerListPlugins(): void
    {
        $this->registerAbility(
            'wp-nerve/list-plugins',
            __('List plugins', 'wp-nerve'),
            __('Lists installed plugins with their active state. Requires activate_plugins.', 'wp-nerve'),
            $this->emptyInputSchema(),
            array(
                '$schema'              => 'https://json-schema.org/draft/2020-12/schema',
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array('plugins', 'total'),
                'properties'           => array(
                    'plugins' => array(
                        'type'  => 'array',
                        'items' => array(
                            'type'                 => 'object',
                            'additionalProperties' => false,
                            'required'             => array('file', 'name', 'version', 'active'),
                            'properties'           => array(
                                'file'    => array('type' => 'string'),
                                'name'    => array('type' => 'string'),
                                'version' => array('type' => 'string'),
                                'active'  => array('type' => 'boolean'),
                            ),
                        ),
                    ),
                    'total' => array('type' => 'integer'),
                ),
            ),
            array($this, 'listPlugins'),
            'read',
            false,
            'activate_plugins'
        );
    }

    private function registerActivatePlugin(): void
    {
        $this->registerAbility(
            'wp-nerve/activate-plugin',
            __('Activate plugin', 'wp-nerve'),
            __('Activates an installed plugin. Undo by deactivating it.', 'wp-nerve'),
            $this->pluginSchema(),
            $this->pluginResultSchema(),
            array($this, 'activatePlugin'),
            'privileged',
            false,
            'activate_plugins'
        );
    }

    private function registerDeactivatePlugin(): void
    {
        $this->registerAbility(
            'wp-nerve/deactivate-plugin',
            __('Deactivate plugin', 'wp-nerve'),
            __('Deactivates a non-protected plugin. WPNerve itself is always protected.', 'wp-nerve'),
            $this->pluginSchema(),
            $this->pluginResultSchema(),
            array($this, 'deactivatePlugin'),
            'privileged',
            false,
            'activate_plugins'
        );
    }

    private function registerUploadPlugin(): void
    {
        $this->registerAbility(
            'wp-nerve/upload-plugin',
            __('Upload plugin', 'wp-nerve'),
            __('Installs a checksummed base64 ZIP without replacing a matching installed plugin slug.', 'wp-nerve'),
            array(
                '$schema'              => 'https://json-schema.org/draft/2020-12/schema',
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array('filename', 'content', 'sha256'),
                'properties'           => array(
                    'filename' => array('type' => 'string', 'minLength' => 5, 'maxLength' => 255, 'pattern' => '^[A-Za-z0-9][A-Za-z0-9._-]*\\.zip$'),
                    'content'  => array('type' => 'string', 'minLength' => 1),
                    'sha256'   => array('type' => 'string', 'pattern' => '^[a-f0-9]{64}$'),
                ),
            ),
            array(
                '$schema'              => 'https://json-schema.org/draft/2020-12/schema',
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array('file', 'installed_to', 'sha256'),
                'properties'           => array(
                    'file'         => array('type' => 'string'),
                    'installed_to' => array('type' => 'string'),
                    'sha256'       => array('type' => 'string'),
                    'recovery'     => array('type' => 'object'),
                ),
            ),
            array($this, 'uploadPlugin'),
            'destructive',
            false,
            'install_plugins',
            array('upload_plugins')
        );
    }

    private function registerDeletePlugin(): void
    {
        $this->registerAbility(
            'wp-nerve/delete-plugin',
            __('Delete plugin', 'wp-nerve'),
            __('Deletes a non-protected installed plugin. WPNerve itself is always protected.', 'wp-nerve'),
            $this->pluginSchema(),
            array(
                '$schema'              => 'https://json-schema.org/draft/2020-12/schema',
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array('file', 'deleted'),
                'properties'           => array(
                    'file'     => array('type' => 'string'),
                    'deleted'  => array('type' => 'boolean'),
                    'recovery' => array('type' => 'object'),
                ),
            ),
            array($this, 'deletePlugin'),
            'destructive',
            false,
            'delete_plugins'
        );
    }

    /** @return array<string, mixed> */
    private function pluginSchema(): array
    {
        return array(
            '$schema'              => 'https://json-schema.org/draft/2020-12/schema',
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => array('plugin'),
            'properties'           => array(
                'plugin' => array('type' => 'string', 'minLength' => 1, 'maxLength' => 300),
            ),
        );
    }

    /** @return array<string, mixed> */
    private function pluginResultSchema(): array
    {
        return array(
            '$schema'              => 'https://json-schema.org/draft/2020-12/schema',
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => array('file', 'active'),
            'properties'           => array(
                'file'     => array('type' => 'string'),
                'active'   => array('type' => 'boolean'),
                'recovery' => array('type' => 'object'),
            ),
        );
    }

    private function isProtectedPlugin(string $plugin): bool
    {
        $self = defined('WP_NERVE_FILE') ? plugin_basename(WP_NERVE_FILE) : 'wp-nerve/wp-nerve.php';

        if ($plugin === $self) {
            return true;
        }

        if (function_exists('is_plugin_active_for_network') && is_plugin_active_for_network($plugin)) {
            return true;
        }

        /**
         * Filters additional plugin files WPNerve must never deactivate or delete.
         *
         * WPNerve itself and network-active plugins remain protected regardless
         * of this filter.
         *
         * @param array<int, string> $plugins Protected plugin files.
         */
        $protected = apply_filters('wp_nerve_protected_plugins', array());

        return is_array($protected) && in_array($plugin, $protected, true);
    }

    private function ensureAdminIncludes(): void
    {
        if (! function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
    }

    private function maxUploadBytes(): int
    {
        /**
         * Filters the maximum accepted decoded plugin ZIP size in bytes.
         *
         * @param int $bytes Maximum upload size.
         */
        $bytes = apply_filters('wp_nerve_max_plugin_upload_bytes', self::MAX_UPLOAD_BYTES);

        return is_int($bytes) && $bytes > 0 ? min($bytes, self::MAX_UPLOAD_BYTES) : self::MAX_UPLOAD_BYTES;
    }
}
