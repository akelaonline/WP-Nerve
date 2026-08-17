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

    /** @return array<string, mixed> */
    public function listPlugins(mixed $input = null): array
    {
        unset($input);

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

        ksort($plugins);

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

        $this->ensureAdminIncludes();

        if ('' === $plugin || ! isset(get_plugins()[$plugin])) {
            return new WP_Error('wp_nerve_plugin_not_found', __('The requested plugin does not exist.', 'wp-nerve'));
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
        $input = is_array($input) ? $input : array();
        $name  = trim((string) ($input['filename'] ?? ''));
        $raw   = (string) ($input['content'] ?? '');

        if ('' === $name || '' === $raw) {
            return new WP_Error('wp_nerve_invalid_upload', __('filename and base64 zip content are required.', 'wp-nerve'));
        }

        if (strlen($raw) > $this->maxUploadBytes()) {
            return new WP_Error('wp_nerve_upload_too_large', __('The uploaded file exceeds the configured size limit.', 'wp-nerve'));
        }

        $bits = base64_decode($raw, true);

        if (false === $bits || '' === $bits) {
            return new WP_Error('wp_nerve_invalid_base64', __('The content parameter must be valid base64.', 'wp-nerve'));
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

        $this->ensureAdminIncludes();

        return array(
            'file'       => (string) $upload['file'],
            'installed_to' => $pluginsDir,
            'recovery'   => array(
                'undo' => 'wp_nerve_delete_plugin',
                'note' => __('Delete the plugin to undo. Verify the extracted folder with plugins/list.', 'wp-nerve'),
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

        $this->ensureAdminIncludes();

        if ('' === $plugin || ! isset(get_plugins()[$plugin])) {
            return new WP_Error('wp_nerve_plugin_not_found', __('The requested plugin does not exist.', 'wp-nerve'));
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
            __('Activates a plugin. Undo by deactivating it.', 'wp-nerve'),
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
            __('Deactivates a plugin. Undo by activating it.', 'wp-nerve'),
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
            __('Uploads a base64-encoded plugin zip and installs it. Undo by deleting the plugin.', 'wp-nerve'),
            array(
                '$schema'              => 'https://json-schema.org/draft/2020-12/schema',
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array('filename', 'content'),
                'properties'           => array(
                    'filename' => array('type' => 'string', 'minLength' => 1, 'maxLength' => 255),
                    'content'  => array('type' => 'string', 'minLength' => 1),
                ),
            ),
            array(
                '$schema'              => 'https://json-schema.org/draft/2020-12/schema',
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array('file', 'installed_to'),
                'properties'           => array(
                    'file'         => array('type' => 'string'),
                    'installed_to' => array('type' => 'string'),
                    'recovery'     => array('type' => 'object'),
                ),
            ),
            array($this, 'uploadPlugin'),
            'destructive',
            false,
            'install_plugins'
        );
    }

    private function registerDeletePlugin(): void
    {
        $this->registerAbility(
            'wp-nerve/delete-plugin',
            __('Delete plugin', 'wp-nerve'),
            __('Deletes an installed plugin. Cannot be undone.', 'wp-nerve'),
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

    private function ensureAdminIncludes(): void
    {
        if (! function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
    }

    private function maxUploadBytes(): int
    {
        /**
         * Filters the maximum accepted plugin zip size in bytes.
         *
         * @param int $bytes Maximum upload size.
         */
        $bytes = apply_filters('wp_nerve_max_plugin_upload_bytes', self::MAX_UPLOAD_BYTES);

        return is_int($bytes) && $bytes > 0 ? $bytes : self::MAX_UPLOAD_BYTES;
    }
}
