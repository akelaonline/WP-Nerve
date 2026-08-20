<?php

/**
 * WPNerve product dashboard.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Admin;

use WPNerve\Security\Confirmation\Repository as ConfirmationRepository;
use WPNerve\Security\Confirmation\WpdbRepository as ConfirmationWpdbRepository;

final class AdminPage
{
    private const NONCE_ACTION = 'wp_nerve_admin';

    private ApplicationPasswords $applicationPasswords;
    private ConfirmationRepository $confirmations;

    /** @var array<string, mixed>|null */
    private ?array $requestNotice = null;
    private ?int $selectedUserId = null;

    public function __construct(
        ?ApplicationPasswords $applicationPasswords = null,
        ?ConfirmationRepository $confirmations = null
    ) {
        $this->applicationPasswords = $applicationPasswords ?? new ApplicationPasswords();
        $this->confirmations = $confirmations ?? new ConfirmationWpdbRepository();
    }

    public function registerMenu(): void
    {
        if (function_exists('add_menu_page') && function_exists('add_submenu_page')) {
            add_menu_page(
                __('WPNerve', 'wp-nerve'),
                __('WPNerve', 'wp-nerve'),
                'manage_options',
                'wp-nerve',
                array($this, 'render'),
                'dashicons-networking',
                58
            );
            add_submenu_page(
                'wp-nerve',
                __('WPNerve Dashboard', 'wp-nerve'),
                __('Dashboard', 'wp-nerve'),
                'manage_options',
                'wp-nerve',
                array($this, 'render')
            );
            return;
        }

        add_management_page(
            __('WPNerve', 'wp-nerve'),
            __('WPNerve', 'wp-nerve'),
            'manage_options',
            'wp-nerve',
            array($this, 'render')
        );
    }

    public function enqueueAssets(string $hookSuffix): void
    {
        if (! str_contains($hookSuffix, 'wp-nerve')) {
            return;
        }

        wp_enqueue_style('wp-nerve-admin', WP_NERVE_URL . 'assets/admin.css', array(), WP_NERVE_VERSION);
    }

    public function handleActions(): void
    {
        if (! current_user_can('manage_options') || ! isset($_POST['wp_nerve_admin'], $_POST['wp_nerve_action'])) {
            return;
        }

        $nonce = sanitize_key((string) $_POST['wp_nerve_admin']);
        $action = sanitize_key((string) $_POST['wp_nerve_action']);

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
            return;
        }

        if ('generate_app_password' === $action) {
            $userId = isset($_POST['wp_nerve_user_id']) ? absint(wp_unslash($_POST['wp_nerve_user_id'])) : 0;
            nocache_headers();
            $this->selectedUserId = $userId;
            $this->generateApplicationPassword($userId);
            return;
        }

        if ('revoke_app_password' === $action) {
            $userId = isset($_POST['wp_nerve_user_id']) ? absint(wp_unslash($_POST['wp_nerve_user_id'])) : 0;
            $uuid = isset($_POST['wp_nerve_app_password_uuid'])
                ? sanitize_text_field(wp_unslash((string) $_POST['wp_nerve_app_password_uuid']))
                : '';
            $this->selectedUserId = $userId;
            $this->revokeApplicationPassword($userId, $uuid);
            return;
        }

        if (in_array($action, array('approve_confirmation', 'deny_confirmation'), true)) {
            $challengeId = isset($_POST['wp_nerve_confirmation_id'])
                ? absint(wp_unslash($_POST['wp_nerve_confirmation_id']))
                : 0;
            $this->decideConfirmation($challengeId, 'approve_confirmation' === $action);
        }
    }

    public function render(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $endpoint = rest_url('wp-nerve/v1/mcp');
        $enabled = $this->enabledRiskClasses();
        $notice = $this->requestNotice ?? get_transient('wp_nerve_admin_notice');
        $users = $this->applicationPasswords->editableUsers();
        $selected = $this->selectedUser($users);
        $credentials = null === $selected ? array() : $this->applicationPasswords->credentials($selected->ID);
        $confirmations = $this->confirmations->pending();

        if (null === $this->requestNotice && false !== $notice) {
            delete_transient('wp_nerve_admin_notice');
        }
        ?>
        <div class="wrap wpn-admin">
            <header class="wpn-hero">
                <div class="wpn-hero__brand">
                    <span class="wpn-brandmark"><span class="dashicons dashicons-networking"></span></span>
                    <div>
                        <span class="wpn-kicker"><?php echo esc_html__('Native MCP gateway for WordPress', 'wp-nerve'); ?></span>
                        <h1><?php echo esc_html__('WPNerve', 'wp-nerve'); ?></h1>
                        <p><?php echo esc_html__('Secure agent access built on the native WordPress Abilities API.', 'wp-nerve'); ?></p>
                    </div>
                </div>
                <div class="wpn-hero__actions">
                    <span class="wpn-pill"><?php echo esc_html(WP_NERVE_VERSION); ?></span>
                    <span class="wpn-pill wpn-pill--ok"><span class="wpn-pill__dot"></span><?php echo esc_html__('MCP ready', 'wp-nerve'); ?></span>
                    <a class="button wpn-button" href="<?php echo esc_url(admin_url('admin.php?page=wp-nerve-diagnostics')); ?>"><?php echo esc_html__('Diagnostics', 'wp-nerve'); ?></a>
                </div>
            </header>

            <?php $this->renderNotice($notice); ?>

            <div class="wpn-stats">
                <?php $this->stat(__('Ability catalog', 'wp-nerve'), '53', __('reviewed native abilities', 'wp-nerve')); ?>
                <?php $this->stat(__('Risk classes', 'wp-nerve'), count($enabled) . '/4', __('enabled on this site', 'wp-nerve')); ?>
                <?php $this->stat(__('Credentials', 'wp-nerve'), (string) count($credentials), __('for selected user', 'wp-nerve')); ?>
                <?php $this->stat(__('Approvals', 'wp-nerve'), (string) count($confirmations), __('high-risk operations pending', 'wp-nerve')); ?>
            </div>

            <div class="wpn-layout">
                <main class="wpn-main">
                    <section class="wpn-panel">
                        <div class="wpn-panel__head">
                            <div><h2><?php echo esc_html__('Connection', 'wp-nerve'); ?></h2><p><?php echo esc_html__('Everything a client needs to reach this WordPress installation.', 'wp-nerve'); ?></p></div>
                            <span class="wpn-status wpn-status--ok"><span class="wpn-status__dot"></span>HTTPS</span>
                        </div>
                        <div class="wpn-panel__body wpn-panel__body--flush">
                            <dl class="wpn-info-grid">
                                <div><dt><?php echo esc_html__('MCP endpoint', 'wp-nerve'); ?></dt><dd><code><?php echo esc_html($endpoint); ?></code></dd></div>
                                <div><dt><?php echo esc_html__('Authentication', 'wp-nerve'); ?></dt><dd><?php echo esc_html__('WordPress Application Password or constrained OAuth over HTTPS', 'wp-nerve'); ?></dd></div>
                                <div><dt><?php echo esc_html__('Protocol versions', 'wp-nerve'); ?></dt><dd><code>2026-07-28</code>, <code>2025-11-25</code>, <code>2025-06-18</code></dd></div>
                                <div><dt><?php echo esc_html__('Default access', 'wp-nerve'); ?></dt><dd><?php echo esc_html__('Authenticated users with the edit_posts capability', 'wp-nerve'); ?></dd></div>
                            </dl>
                        </div>
                    </section>

                    <section class="wpn-panel">
                        <div class="wpn-panel__head"><div><h2><?php echo esc_html__('Pending high-risk confirmations', 'wp-nerve'); ?></h2><p><?php echo esc_html__('Approve only when the tool, user, risk and displayed code match the operation you expect.', 'wp-nerve'); ?></p></div></div>
                        <div class="wpn-panel__body">
                            <?php if (array() === $confirmations) : ?>
                                <div class="wpn-empty"><?php echo esc_html__('No high-risk operations are waiting for approval.', 'wp-nerve'); ?></div>
                            <?php else : ?>
                                <div class="wpn-table-wrap"><table class="wpn-table"><thead><tr><th><?php echo esc_html__('Code', 'wp-nerve'); ?></th><th><?php echo esc_html__('Agent user', 'wp-nerve'); ?></th><th><?php echo esc_html__('Tool', 'wp-nerve'); ?></th><th><?php echo esc_html__('Risk', 'wp-nerve'); ?></th><th><?php echo esc_html__('Decision', 'wp-nerve'); ?></th></tr></thead><tbody>
                                <?php foreach ($confirmations as $confirmation) : ?><tr>
                                    <td><code><?php echo esc_html($confirmation['display_code']); ?></code></td>
                                    <td><?php echo esc_html($this->confirmationActor((int) $confirmation['user_id'])); ?></td>
                                    <td><code><?php echo esc_html($confirmation['tool_name']); ?></code></td>
                                    <td><?php echo esc_html($confirmation['risk']); ?></td>
                                    <td class="wpn-actions"><?php $this->confirmationButton($confirmation, true); ?><?php $this->confirmationButton($confirmation, false); ?></td>
                                </tr><?php endforeach; ?>
                                </tbody></table></div>
                            <?php endif; ?>
                        </div>
                    </section>

                    <section class="wpn-panel">
                        <div class="wpn-panel__head"><div><h2><?php echo esc_html__('Application Password', 'wp-nerve'); ?></h2><p><?php echo esc_html__('Create a dedicated credential for the WordPress user the agent will act as.', 'wp-nerve'); ?></p></div></div>
                        <div class="wpn-panel__body">
                            <?php if (array() === $users) : ?>
                                <div class="wpn-empty"><?php echo esc_html__('No editable user with edit_posts can use Application Passwords.', 'wp-nerve'); ?></div>
                            <?php else : ?>
                                <form method="get" class="wpn-field"><input type="hidden" name="page" value="wp-nerve"><div class="wpn-field__label"><strong><?php echo esc_html__('Agent user', 'wp-nerve'); ?></strong><span><?php echo esc_html__('Use a dedicated least-privilege account when possible.', 'wp-nerve'); ?></span></div><div class="wpn-actions"><select name="wp_nerve_user_id"><?php foreach ($users as $user) : ?><option value="<?php echo esc_attr((string) $user->ID); ?>" <?php selected(null !== $selected && $selected->ID === $user->ID); ?>><?php echo esc_html(sprintf(__('%1$s (%2$s)', 'wp-nerve'), $user->display_name, $user->user_login)); ?></option><?php endforeach; ?></select><button class="button wpn-button" type="submit"><?php echo esc_html__('View credentials', 'wp-nerve'); ?></button></div></form>
                                <?php if (null !== $selected) : ?><form method="post" class="wpn-actions"><?php wp_nonce_field(self::NONCE_ACTION, 'wp_nerve_admin'); ?><input type="hidden" name="wp_nerve_action" value="generate_app_password"><input type="hidden" name="wp_nerve_user_id" value="<?php echo esc_attr((string) $selected->ID); ?>"><button class="button wpn-button wpn-button--primary" type="submit"><?php echo esc_html__('Generate WPNerve credential', 'wp-nerve'); ?></button></form><?php endif; ?>
                            <?php endif; ?>

                            <?php if (! empty($notice['authorization']) && ! empty($notice['username'])) : ?>
                                <h3><?php echo esc_html__('Copy-ready client configuration', 'wp-nerve'); ?></h3>
                                <pre class="wpn-code">{
  "mcpServers": {
    "wp-nerve": {
      "type": "http",
      "url": "<?php echo esc_html($endpoint); ?>",
      "headers": {"Authorization": "<?php echo esc_html($notice['authorization']); ?>"}
    }
  }
}</pre>
                            <?php endif; ?>

                            <?php if (null !== $selected) : ?>
                                <h3><?php echo esc_html__('Managed WPNerve credentials', 'wp-nerve'); ?></h3>
                                <?php if (array() === $credentials) : ?><p class="wpn-section-note"><?php echo esc_html__('No WPNerve credentials exist for this user.', 'wp-nerve'); ?></p><?php else : ?><table class="wpn-table"><thead><tr><th><?php echo esc_html__('Name', 'wp-nerve'); ?></th><th><?php echo esc_html__('Last used', 'wp-nerve'); ?></th><th><?php echo esc_html__('Last IP', 'wp-nerve'); ?></th><th><?php echo esc_html__('Action', 'wp-nerve'); ?></th></tr></thead><tbody><?php foreach ($credentials as $credential) : ?><tr><td><?php echo esc_html($credential['name']); ?><br><code><?php echo esc_html($credential['uuid']); ?></code></td><td><?php echo esc_html(null === $credential['last_used'] ? __('Never', 'wp-nerve') : $this->formatTimestamp((int) $credential['last_used'])); ?></td><td><?php echo esc_html('' === $credential['last_ip'] ? '—' : $credential['last_ip']); ?></td><td><?php $this->revokeButton($selected->ID, $credential['uuid']); ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </section>

                    <section class="wpn-panel">
                        <div class="wpn-panel__head"><div><h2><?php echo esc_html__('Risk classes', 'wp-nerve'); ?></h2><p><?php echo esc_html__('Control what clients can discover. Privileged and Destructive remain confirmation-gated.', 'wp-nerve'); ?></p></div></div>
                        <div class="wpn-panel__body"><form method="post"><?php wp_nonce_field(self::NONCE_ACTION, 'wp_nerve_admin'); ?><input type="hidden" name="wp_nerve_action" value="enable_risk_classes"><div class="wpn-checks"><?php foreach ($this->riskClassOptions() as $value => $label) : ?><label class="wpn-check"><input type="checkbox" name="wp_nerve_risk_classes[]" value="<?php echo esc_attr($value); ?>" <?php checked(in_array($value, $enabled, true)); ?>><span><strong><?php echo esc_html(ucfirst($value)); ?></strong><small><?php echo esc_html($label); ?></small></span></label><?php endforeach; ?></div><p><button class="button wpn-button wpn-button--primary" type="submit"><?php echo esc_html__('Save risk classes', 'wp-nerve'); ?></button></p></form></div>
                    </section>
                </main>

                <aside class="wpn-side">
                    <section class="wpn-card"><span class="wpn-kicker"><?php echo esc_html__('Security posture', 'wp-nerve'); ?></span><h2><?php echo esc_html__('Fail-closed by design', 'wp-nerve'); ?></h2><p><?php echo esc_html__('Mutations require idempotency. High-risk operations require approval. Credentials and tool arguments stay out of normal audit rows.', 'wp-nerve'); ?></p><div class="wpn-card__links"><a href="<?php echo esc_url(admin_url('admin.php?page=wp-nerve-diagnostics')); ?>"><?php echo esc_html__('Runtime diagnostics →', 'wp-nerve'); ?></a><a href="<?php echo esc_url(admin_url('admin.php?page=wp-nerve-http-smoke')); ?>"><?php echo esc_html__('Authenticated HTTP smoke →', 'wp-nerve'); ?></a></div></section>
                    <section class="wpn-card"><span class="wpn-kicker"><?php echo esc_html__('Client setup', 'wp-nerve'); ?></span><h2><?php echo esc_html__('Claude Code', 'wp-nerve'); ?></h2><p><?php echo esc_html__('Generate a credential for a copy-ready configuration, or build a Basic Authorization header from USERNAME:APPLICATION_PASSWORD.', 'wp-nerve'); ?></p><pre class="wpn-code">{
  "type": "http",
  "url": "<?php echo esc_html($endpoint); ?>",
  "headers": {"Authorization": "Basic BASE64_USERNAME_COLON_APPLICATION_PASSWORD"}
}</pre></section>
                    <section class="wpn-card"><span class="wpn-kicker"><?php echo esc_html__('Built by Akela', 'wp-nerve'); ?></span><h2><?php echo esc_html__('WordPress infrastructure for agents', 'wp-nerve'); ?></h2><p><?php echo esc_html__('No relay, no SaaS control plane, no Firebase.', 'wp-nerve'); ?></p><div class="wpn-card__links"><a href="<?php echo esc_url(admin_url('admin.php?page=wp-nerve-documentation')); ?>"><?php echo esc_html__('Documentation →', 'wp-nerve'); ?></a></div></section>
                </aside>
            </div>
        </div>
        <?php
    }

    /** @param array<string, mixed>|false $notice */
    private function renderNotice(array|false $notice): void
    {
        if (! is_array($notice)) {
            return;
        }

        $class = 'notice-success' === ($notice['type'] ?? '') ? 'wpn-notice--success' : ('notice-error' === ($notice['type'] ?? '') ? 'wpn-notice--error' : 'wpn-notice--warning');
        ?>
        <div class="wpn-notice <?php echo esc_attr($class); ?>"><p><strong><?php echo esc_html($notice['message'] ?? ''); ?></strong></p>
            <?php if (! empty($notice['password'])) : ?><?php if (! empty($notice['username'])) : ?><p><?php echo esc_html__('Username:', 'wp-nerve'); ?> <code><?php echo esc_html($notice['username']); ?></code></p><?php endif; ?><p class="wpn-code"><?php echo esc_html($notice['password']); ?></p><p class="wpn-section-note"><?php echo esc_html__('Copy this password now — WPNerve keeps it only for this response and it will not be shown again.', 'wp-nerve'); ?></p><?php endif; ?>
        </div>
        <?php
    }

    private function stat(string $label, string $value, string $meta): void
    {
        echo '<div class="wpn-stat"><span class="wpn-stat__label">' . esc_html($label) . '</span><strong class="wpn-stat__value">' . esc_html($value) . '</strong><span class="wpn-stat__meta">' . esc_html($meta) . '</span></div>';
    }

    /** @param array<string, mixed> $confirmation */
    private function confirmationButton(array $confirmation, bool $approve): void
    {
        ?><form method="post"><?php wp_nonce_field(self::NONCE_ACTION, 'wp_nerve_admin'); ?><input type="hidden" name="wp_nerve_action" value="<?php echo esc_attr($approve ? 'approve_confirmation' : 'deny_confirmation'); ?>"><input type="hidden" name="wp_nerve_confirmation_id" value="<?php echo esc_attr((string) $confirmation['id']); ?>"><button class="button <?php echo $approve ? 'button-primary' : ''; ?>" type="submit"><?php echo esc_html($approve ? __('Approve', 'wp-nerve') : __('Deny', 'wp-nerve')); ?></button></form><?php
    }

    private function revokeButton(int $userId, string $uuid): void
    {
        ?><form method="post"><?php wp_nonce_field(self::NONCE_ACTION, 'wp_nerve_admin'); ?><input type="hidden" name="wp_nerve_action" value="revoke_app_password"><input type="hidden" name="wp_nerve_user_id" value="<?php echo esc_attr((string) $userId); ?>"><input type="hidden" name="wp_nerve_app_password_uuid" value="<?php echo esc_attr($uuid); ?>"><button class="button" type="submit"><?php echo esc_html__('Revoke', 'wp-nerve'); ?></button></form><?php
    }

    /** @param array<int, mixed> $requested */
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
        set_transient('wp_nerve_admin_notice', array('type' => 'notice-success', 'message' => __('Risk classes updated.', 'wp-nerve')), 30);
    }

    private function generateApplicationPassword(int $userId): void
    {
        $result = $this->applicationPasswords->create($userId);
        if (is_wp_error($result)) {
            $this->requestNotice = array('type' => 'notice-error', 'message' => $result->get_error_message());
            return;
        }
        $test = $this->applicationPasswords->test($result['user'], $result['password']);
        $this->requestNotice = array(
            'type' => true === $test ? 'notice-success' : 'notice-warning',
            'message' => true === $test ? __('Application Password created and the MCP connection test passed.', 'wp-nerve') : $test->get_error_message(),
            'password' => $result['password'],
            'username' => $result['user']->user_login,
            'authorization' => $this->applicationPasswords->authorizationHeader($result['user'], $result['password']),
        );
    }

    private function revokeApplicationPassword(int $userId, string $uuid): void
    {
        $result = $this->applicationPasswords->revoke($userId, $uuid);
        $message = $result instanceof \WP_Error ? $result->get_error_message() : __('WordPress could not revoke the selected WPNerve credential.', 'wp-nerve');
        $this->requestNotice = array('type' => true === $result ? 'notice-success' : 'notice-error', 'message' => true === $result ? __('WPNerve credential revoked.', 'wp-nerve') : $message);
    }

    private function decideConfirmation(int $challengeId, bool $approved): void
    {
        $decided = $this->confirmations->decide($challengeId, get_current_user_id(), $approved);
        $this->requestNotice = array(
            'type' => $decided ? 'notice-success' : 'notice-error',
            'message' => $decided
                ? ($approved ? __('High-risk operation approved. The MCP client may now retry the exact call.', 'wp-nerve') : __('High-risk operation denied.', 'wp-nerve'))
                : __('The confirmation could not be changed because it is missing, expired, or already decided.', 'wp-nerve'),
        );
    }

    private function confirmationActor(int $userId): string
    {
        $user = get_userdata($userId);
        return $user instanceof \WP_User
            ? sprintf(__('%1$s (%2$s)', 'wp-nerve'), $user->display_name, $user->user_login)
            : sprintf(__('User #%d', 'wp-nerve'), $userId);
    }

    /** @param array<int, \WP_User> $users */
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
        return $timestamp > 0 ? wp_date('Y-m-d H:i:s T', $timestamp) : '—';
    }

    /** @return array<int, string> */
    private function enabledRiskClasses(): array
    {
        $option = get_option('wp_nerve_enabled_risk_classes', null);
        return is_array($option) ? $option : array('read', 'write');
    }

    /** @return array<string, string> */
    private function riskClassOptions(): array
    {
        return array(
            'read' => __('Read — safe information abilities', 'wp-nerve'),
            'write' => __('Write — recoverable mutations', 'wp-nerve'),
            'privileged' => __('Privileged — administration (users, plugins, options)', 'wp-nerve'),
            'destructive' => __('Destructive — delete, publish, restore', 'wp-nerve'),
        );
    }
}
