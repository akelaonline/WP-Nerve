<?php

/**
 * Connection, risk, and client configuration screen.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Admin;

use WP_Error;

final class AdminPage
{
    private const NONCE_ACTION = 'wp_nerve_admin';

    public function registerMenu(): void
    {
        add_management_page(
            __('WPNerve', 'wp-nerve'),
            __('WPNerve', 'wp-nerve'),
            'manage_options',
            'wp-nerve',
            array($this, 'render')
        );

        add_action('admin_init', array($this, 'handleActions'));
    }

    public function handleActions(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        if (! isset($_POST['wp_nerve_admin'], $_POST['wp_nerve_action'])) {
            return;
        }

        $nonce  = isset($_POST['wp_nerve_admin']) ? sanitize_key((string) $_POST['wp_nerve_admin']) : '';
        $action = isset($_POST['wp_nerve_action']) ? sanitize_key((string) $_POST['wp_nerve_action']) : '';

        if (! wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            return;
        }

        if ('enable_risk_classes' === $action) {
            $requested = isset($_POST['wp_nerve_risk_classes']) && is_array($_POST['wp_nerve_risk_classes'])
                ? wp_unslash($_POST['wp_nerve_risk_classes']) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each value is sanitized below.
                : array();

            $this->saveRiskClasses($requested);
        } elseif ('generate_app_password' === $action) {
            $this->generateApplicationPassword();
        }
    }

    public function render(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $endpoint = rest_url('wp-nerve/v1/mcp');
        $enabled  = $this->enabledRiskClasses();
        $notice   = get_transient('wp_nerve_admin_notice');

        if (false !== $notice) {
            delete_transient('wp_nerve_admin_notice');
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('WPNerve', 'wp-nerve'); ?></h1>
            <p><?php echo esc_html__('Secure MCP access to the native WordPress Abilities API.', 'wp-nerve'); ?></p>

            <?php if (is_array($notice)) : ?>
                <div class="notice <?php echo esc_attr($notice['type'] ?? 'notice-info'); ?> is-dismissible">
                    <p><?php echo esc_html($notice['message'] ?? ''); ?></p>
                    <?php if (! empty($notice['password'])) : ?>
                        <p><code><?php echo esc_html($notice['password']); ?></code></p>
                        <p class="description">
                            <?php echo esc_html__('Copy this password now — it will not be shown again.', 'wp-nerve'); ?>
                        </p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <h2><?php echo esc_html__('Connection', 'wp-nerve'); ?></h2>
            <table class="widefat striped" style="max-width: 900px">
                <tbody>
                    <tr>
                        <th scope="row"><?php echo esc_html__('MCP endpoint', 'wp-nerve'); ?></th>
                        <td><code><?php echo esc_html($endpoint); ?></code></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Authentication', 'wp-nerve'); ?></th>
                        <td><?php echo esc_html__('WordPress Application Password over HTTPS', 'wp-nerve'); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Protocol versions', 'wp-nerve'); ?></th>
                        <td><code>2026-07-28</code>, <code>2025-11-25</code>, <code>2025-06-18</code></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Default access', 'wp-nerve'); ?></th>
                        <td>
                            <?php echo esc_html__('Authenticated users with the edit_posts capability', 'wp-nerve'); ?>
                        </td>
                    </tr>
                </tbody>
            </table>

            <h2><?php echo esc_html__('Application password', 'wp-nerve'); ?></h2>
            <p class="description">
                <?php
                echo esc_html__(
                    'Create a dedicated password for the WordPress user the agent will act as. It is stored by WordPress, never by WPNerve.',
                    'wp-nerve'
                );
                ?>
            </p>
            <form method="post">
                <?php wp_nonce_field(self::NONCE_ACTION, 'wp_nerve_admin'); ?>
                <input type="hidden" name="wp_nerve_action" value="generate_app_password" />
                <button type="submit" class="button button-primary">
                    <?php echo esc_html__('Generate Application Password for me', 'wp-nerve'); ?>
                </button>
            </form>

            <h2><?php echo esc_html__('Risk classes', 'wp-nerve'); ?></h2>
            <p class="description">
                <?php
                echo esc_html__(
                    'Enabled risk classes are exposed to MCP clients. Destructive and privileged operations are hidden unless you enable them here.',
                    'wp-nerve'
                );
                ?>
            </p>
            <form method="post">
                <?php wp_nonce_field(self::NONCE_ACTION, 'wp_nerve_admin'); ?>
                <input type="hidden" name="wp_nerve_action" value="enable_risk_classes" />
                <fieldset>
                    <legend class="screen-reader-text"><?php echo esc_html__('Risk classes', 'wp-nerve'); ?></legend>
                    <?php foreach ($this->riskClassOptions() as $value => $label) : ?>
                        <label style="display:block; margin-bottom: 6px">
                            <input type="checkbox" name="wp_nerve_risk_classes[]" value="<?php echo esc_attr($value); ?>"
                                <?php checked(in_array($value, $enabled, true)); ?> />
                            <?php echo esc_html($label); ?>
                        </label>
                    <?php endforeach; ?>
                </fieldset>
                <button type="submit" class="button button-primary">
                    <?php echo esc_html__('Save risk classes', 'wp-nerve'); ?>
                </button>
            </form>

            <h2><?php echo esc_html__('Client configuration', 'wp-nerve'); ?></h2>
            <p class="description">
                <?php
                echo esc_html__(
                    'Replace USERNAME and APPLICATION_PASSWORD with the user and the password generated above.',
                    'wp-nerve'
                );
                ?>
            </p>
            <h3>Claude Code</h3>
            <pre style="max-width: 900px; overflow: auto">{
  "mcpServers": {
    "wp-nerve": {
      "type": "http",
      "url": "<?php echo esc_html($endpoint); ?>",
      "headers": {
        "Authorization": "Basic <?php echo esc_html(base64_encode('USERNAME:APPLICATION_PASSWORD')); ?>"
      }
    }
  }
}</pre>
            <h3>Any MCP client (curl)</h3>
            <pre style="max-width: 900px; overflow: auto">curl --user 'USERNAME:APPLICATION_PASSWORD' \
  --header 'Content-Type: application/json' \
  --header 'MCP-Protocol-Version: 2026-07-28' \
  --header 'Mcp-Method: server/discover' \
  --data '{
    "jsonrpc": "2.0", "id": 1, "method": "server/discover",
    "params": {
      "_meta": {
        "io.modelcontextprotocol/protocolVersion": "2026-07-28",
        "io.modelcontextprotocol/clientCapabilities": {},
        "io.modelcontextprotocol/clientInfo": {"name": "agent", "version": "1.0.0"}
      }
    }
  }' \
  '<?php echo esc_html($endpoint); ?>'</pre>

            <h2><?php echo esc_html__('Secure setup', 'wp-nerve'); ?></h2>
            <ol>
                <li><?php echo esc_html__('Generate or create a dedicated Application Password named WPNerve.', 'wp-nerve'); ?></li>
                <li>
                    <?php
                    echo esc_html__(
                        'Configure the MCP client with the endpoint, username, and generated Application Password.',
                        'wp-nerve'
                    );
                    ?>
                </li>
                <li>
                    <?php
                    echo esc_html__(
                        'Revoke that Application Password immediately if the client or device is lost.',
                        'wp-nerve'
                    );
                    ?>
                </li>
            </ol>

            <p class="description">
                <?php
                echo esc_html__(
                    'WPNerve never displays or stores the Application Password. Tool arguments and credentials are excluded from the audit log.',
                    'wp-nerve'
                );
                ?>
            </p>
        </div>
        <?php
    }

    /**
     * @param array<int, mixed> $requested
     */
    private function saveRiskClasses(array $requested): void
    {
        $allowed = array('read', 'write', 'privileged', 'destructive');
        $classes = array();

        foreach ($requested as $class) {
            $class = sanitize_key((string) $class);

            if (in_array($class, $allowed, true)) {
                $classes[] = $class;
            }
        }

        update_option('wp_nerve_enabled_risk_classes', array_values(array_unique($classes)));

        set_transient(
            'wp_nerve_admin_notice',
            array('type' => 'notice-success', 'message' => __('Risk classes updated.', 'wp-nerve')),
            30
        );
    }

    private function generateApplicationPassword(): void
    {
        if (! class_exists('WP_Application_Passwords')) {
            set_transient(
                'wp_nerve_admin_notice',
                array(
                    'type'    => 'notice-error',
                    'message' => __('Application Passwords are not available on this WordPress version.', 'wp-nerve'),
                ),
                30
            );

            return;
        }

        $result = \WP_Application_Passwords::create_new_application_password(
            get_current_user_id(),
            array('name' => 'WPNerve Agent')
        );

        if (is_wp_error($result)) {
            set_transient(
                'wp_nerve_admin_notice',
                array('type' => 'notice-error', 'message' => $result->get_error_message()),
                30
            );

            return;
        }

        set_transient(
            'wp_nerve_admin_notice',
            array(
                'type'     => 'notice-success',
                'message'  => __('Application Password created:', 'wp-nerve'),
                'password' => (string) ($result['password'] ?? ''),
            ),
            60
        );
    }

    /** @return array<int, string> */
    private function enabledRiskClasses(): array
    {
        $defaults = array('read', 'write');
        $option   = get_option('wp_nerve_enabled_risk_classes', null);

        return is_array($option) ? $option : $defaults;
    }

    /** @return array<string, string> */
    private function riskClassOptions(): array
    {
        return array(
            'read'        => __('Read — safe information abilities', 'wp-nerve'),
            'write'       => __('Write — recoverable mutations', 'wp-nerve'),
            'privileged'  => __('Privileged — administration (users, plugins, options)', 'wp-nerve'),
            'destructive' => __('Destructive — delete, publish, restore', 'wp-nerve'),
        );
    }
}
