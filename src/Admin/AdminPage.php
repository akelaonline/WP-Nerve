<?php

/**
 * Connection, risk, and client configuration screen.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Admin;

final class AdminPage
{
    private const NONCE_ACTION = 'wp_nerve_admin';

    private ApplicationPasswords $applicationPasswords;

    /** @var array<string, mixed>|null */
    private ?array $requestNotice = null;

    private ?int $selectedUserId = null;

    public function __construct(?ApplicationPasswords $applicationPasswords = null)
    {
        $this->applicationPasswords = $applicationPasswords ?? new ApplicationPasswords();
    }

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
            // phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each value is sanitized below.
            $requested = isset($_POST['wp_nerve_risk_classes']) && is_array($_POST['wp_nerve_risk_classes'])
                ? wp_unslash($_POST['wp_nerve_risk_classes'])
                : array();
            // phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

            $this->saveRiskClasses($requested);
        } elseif ('generate_app_password' === $action) {
            $userId = isset($_POST['wp_nerve_user_id']) ? absint(wp_unslash($_POST['wp_nerve_user_id'])) : 0;

            nocache_headers();
            $this->selectedUserId = $userId;
            $this->generateApplicationPassword($userId);
        } elseif ('revoke_app_password' === $action) {
            $userId = isset($_POST['wp_nerve_user_id']) ? absint(wp_unslash($_POST['wp_nerve_user_id'])) : 0;
            $uuid   = isset($_POST['wp_nerve_app_password_uuid'])
                ? sanitize_text_field(wp_unslash((string) $_POST['wp_nerve_app_password_uuid']))
                : '';

            $this->selectedUserId = $userId;
            $this->revokeApplicationPassword($userId, $uuid);
        }
    }

    public function render(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $endpoint    = rest_url('wp-nerve/v1/mcp');
        $enabled     = $this->enabledRiskClasses();
        $notice      = $this->requestNotice ?? get_transient('wp_nerve_admin_notice');
        $users       = $this->applicationPasswords->editableUsers();
        $selected    = $this->selectedUser($users);
        $credentials = null === $selected
            ? array()
            : $this->applicationPasswords->credentials($selected->ID);

        if (null === $this->requestNotice && false !== $notice) {
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
                        <?php if (! empty($notice['username'])) : ?>
                            <p>
                                <?php echo esc_html__('Username:', 'wp-nerve'); ?>
                                <code><?php echo esc_html($notice['username']); ?></code>
                            </p>
                        <?php endif; ?>
                        <p><code><?php echo esc_html($notice['password']); ?></code></p>
                        <p class="description">
                            <?php // phpcs:ignore Generic.Files.LineLength.TooLong -- translatable sentence remains intact. ?>
                            <?php echo esc_html__('Copy this password now — WPNerve keeps it only for this response and it will not be shown again.', 'wp-nerve'); ?>
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
                    // phpcs:ignore Generic.Files.LineLength.TooLong -- translatable sentence remains intact.
                    'Create a dedicated credential for the WordPress user the agent will act as. Prefer a separate user with only the capabilities the agent needs.',
                    'wp-nerve'
                );
                ?>
            </p>
            <?php if (array() === $users) : ?>
                <div class="notice notice-warning inline">
                    <p>
                        <?php echo esc_html__('No editable user with the edit_posts capability can use Application Passwords.', 'wp-nerve'); ?>
                    </p>
                </div>
            <?php else : ?>
                <form method="get">
                    <input type="hidden" name="page" value="wp-nerve" />
                    <label for="wp-nerve-user"><strong><?php echo esc_html__('Agent user', 'wp-nerve'); ?></strong></label>
                    <select id="wp-nerve-user" name="wp_nerve_user_id">
                        <?php foreach ($users as $user) : ?>
                            <option value="<?php echo esc_attr((string) $user->ID); ?>"
                                <?php selected(null !== $selected && $selected->ID === $user->ID); ?>>
                                <?php
                                echo esc_html(
                                    sprintf(
                                        /* translators: 1: display name, 2: username. */
                                        __('%1$s (%2$s)', 'wp-nerve'),
                                        $user->display_name,
                                        $user->user_login
                                    )
                                );
                                ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="button button-secondary">
                        <?php echo esc_html__('View credentials', 'wp-nerve'); ?>
                    </button>
                </form>
                <?php if (null !== $selected) : ?>
                    <form method="post" style="margin-top: 8px">
                        <?php wp_nonce_field(self::NONCE_ACTION, 'wp_nerve_admin'); ?>
                        <input type="hidden" name="wp_nerve_action" value="generate_app_password" />
                        <input type="hidden" name="wp_nerve_user_id" value="<?php echo esc_attr((string) $selected->ID); ?>" />
                        <button type="submit" class="button button-primary">
                            <?php echo esc_html__('Generate WPNerve credential', 'wp-nerve'); ?>
                        </button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>

            <?php if (! empty($notice['authorization']) && ! empty($notice['username'])) : ?>
                <h3><?php echo esc_html__('Copy-ready client configuration', 'wp-nerve'); ?></h3>
                <pre style="max-width: 900px; overflow: auto">{
  "mcpServers": {
    "wp-nerve": {
      "type": "http",
      "url": "<?php echo esc_html($endpoint); ?>",
      "headers": {
        "Authorization": "<?php echo esc_html($notice['authorization']); ?>"
      }
    }
  }
}</pre>
            <?php endif; ?>

            <?php if (null !== $selected) : ?>
                <h3><?php echo esc_html__('Managed WPNerve credentials', 'wp-nerve'); ?></h3>
                <?php if (array() === $credentials) : ?>
                    <p><?php echo esc_html__('No WPNerve credentials exist for this user.', 'wp-nerve'); ?></p>
                <?php else : ?>
                    <table class="widefat striped" style="max-width: 900px">
                        <thead>
                            <tr>
                                <th><?php echo esc_html__('Name', 'wp-nerve'); ?></th>
                                <th><?php echo esc_html__('Created', 'wp-nerve'); ?></th>
                                <th><?php echo esc_html__('Last used', 'wp-nerve'); ?></th>
                                <th><?php echo esc_html__('Last IP', 'wp-nerve'); ?></th>
                                <th><?php echo esc_html__('Action', 'wp-nerve'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($credentials as $credential) : ?>
                                <tr>
                                    <td>
                                        <?php echo esc_html($credential['name']); ?><br />
                                        <code><?php echo esc_html($credential['uuid']); ?></code>
                                    </td>
                                    <td><?php echo esc_html($this->formatTimestamp($credential['created'])); ?></td>
                                    <td>
                                        <?php
                                        echo esc_html(
                                            null === $credential['last_used']
                                                ? __('Never', 'wp-nerve')
                                                : $this->formatTimestamp($credential['last_used'])
                                        );
                                        ?>
                                    </td>
                                    <td><?php echo esc_html('' === $credential['last_ip'] ? '—' : $credential['last_ip']); ?></td>
                                    <td>
                                        <form method="post">
                                            <?php wp_nonce_field(self::NONCE_ACTION, 'wp_nerve_admin'); ?>
                                            <input type="hidden" name="wp_nerve_action" value="revoke_app_password" />
                                            <input type="hidden" name="wp_nerve_user_id" value="<?php echo esc_attr((string) $selected->ID); ?>" />
                                            <input type="hidden" name="wp_nerve_app_password_uuid" value="<?php echo esc_attr($credential['uuid']); ?>" />
                                            <button type="submit" class="button button-secondary">
                                                <?php echo esc_html__('Revoke', 'wp-nerve'); ?>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            <?php endif; ?>

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
                    // phpcs:ignore Generic.Files.LineLength.TooLong -- translatable sentence remains intact.
                    'Generate a credential above for a copy-ready configuration. Otherwise, base64-encode USERNAME:APPLICATION_PASSWORD and replace the placeholder below.',
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
        "Authorization": "Basic BASE64_USERNAME_COLON_APPLICATION_PASSWORD"
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
                    'WPNerve displays a newly generated secret once and never persists it. Tool arguments and credentials are excluded from the audit log.',
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

    private function generateApplicationPassword(int $userId): void
    {
        $result = $this->applicationPasswords->create($userId);

        if (is_wp_error($result)) {
            $this->requestNotice = array(
                'type'    => 'notice-error',
                'message' => $result->get_error_message(),
            );

            return;
        }

        $test    = $this->applicationPasswords->test($result['user'], $result['password']);
        $message = true === $test
            ? __('Application Password created and the MCP connection test passed.', 'wp-nerve')
            : $test->get_error_message();

        $this->requestNotice = array(
            'type'          => true === $test ? 'notice-success' : 'notice-warning',
            'message'       => $message,
            'password'      => $result['password'],
            'username'      => $result['user']->user_login,
            'authorization' => $this->applicationPasswords->authorizationHeader(
                $result['user'],
                $result['password']
            ),
        );
    }

    private function revokeApplicationPassword(int $userId, string $uuid): void
    {
        $result = $this->applicationPasswords->revoke($userId, $uuid);

        $message = $result instanceof \WP_Error
            ? $result->get_error_message()
            : __('WordPress could not revoke the selected WPNerve credential.', 'wp-nerve');

        $this->requestNotice = array(
            'type'    => true === $result ? 'notice-success' : 'notice-error',
            'message' => true === $result
                ? __('WPNerve credential revoked.', 'wp-nerve')
                : $message,
        );
    }

    /**
     * @param array<int, \WP_User> $users
     */
    private function selectedUser(array $users): ?\WP_User
    {
        $requested = $this->selectedUserId;

        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only user selection.
        if (null === $requested && isset($_GET['wp_nerve_user_id'])) {
            $requested = absint(wp_unslash($_GET['wp_nerve_user_id']));
        }
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        $requested ??= get_current_user_id();

        foreach ($users as $user) {
            if ($requested === $user->ID) {
                return $user;
            }
        }

        return $users[0] ?? null;
    }

    private function formatTimestamp(int $timestamp): string
    {
        if ($timestamp <= 0) {
            return '—';
        }

        $formatted = wp_date('Y-m-d H:i:s T', $timestamp);

        return is_string($formatted) ? $formatted : '—';
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
