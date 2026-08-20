<?php

/**
 * Runtime diagnostics and explicit ability-surface controls.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Admin;

use WP_Ability;
use WPNerve\Abilities\AbilityRegistrar;
use WPNerve\Infrastructure\Activator;
use WPNerve\Policy\PolicyEngine;

final class DiagnosticsPage
{
    private const NONCE_ACTION = 'wp_nerve_diagnostics';

    public function registerMenu(): void
    {
        add_management_page(
            __('WPNerve Diagnostics', 'wp-nerve'),
            __('WPNerve Diagnostics', 'wp-nerve'),
            'manage_options',
            'wp-nerve-diagnostics',
            array($this, 'render')
        );
    }

    public function handleActions(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        if (! isset($_POST['wp_nerve_diagnostics'], $_POST['wp_nerve_diagnostics_action'])) {
            return;
        }

        $nonce = sanitize_key((string) wp_unslash($_POST['wp_nerve_diagnostics']));

        if (! wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            return;
        }

        $action = sanitize_key((string) wp_unslash($_POST['wp_nerve_diagnostics_action']));

        if ('enable_full_surface' === $action) {
            $names = $this->registeredAbilityNames();

            update_option(
                'wp_nerve_enabled_risk_classes',
                array('read', 'write', 'destructive', 'privileged'),
                false
            );
            update_option('wp_nerve_enabled_abilities', $names, false);

            $this->notice(
                sprintf(
                    /* translators: %d: number of explicitly enabled WPNerve abilities. */
                    __('Full WPNerve test surface enabled for %d registered abilities.', 'wp-nerve'),
                    count($names)
                ),
                'notice-success'
            );
        } elseif ('reset_surface' === $action) {
            delete_option('wp_nerve_enabled_abilities');
            update_option('wp_nerve_enabled_risk_classes', array('read', 'write'), false);

            $this->notice(
                __('WPNerve ability overrides reset to secure defaults.', 'wp-nerve'),
                'notice-success'
            );
        }
    }

    public function render(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $abilities    = $this->registeredAbilities();
        $policy       = new PolicyEngine();
        $discoverable = array();
        $blocked      = array();

        foreach ($abilities as $ability) {
            if ($policy->isDiscoverable($ability)) {
                $discoverable[] = $ability->get_name();
            } else {
                $blocked[] = $ability->get_name();
            }
        }

        $registeredCount   = count($abilities);
        $discoverableCount = count($discoverable);
        $expectedCount     = AbilityRegistrar::CATALOG_COUNT;
        $schemaVersion     = (string) get_option('wp_nerve_schema_version', '');
        $riskClasses       = get_option('wp_nerve_enabled_risk_classes', array('read', 'write'));
        $riskClasses       = is_array($riskClasses) ? array_values(array_filter($riskClasses, 'is_string')) : array();
        $abilityOverrides  = get_option('wp_nerve_enabled_abilities', array());
        $abilityOverrides  = is_array($abilityOverrides)
            ? array_values(array_filter($abilityOverrides, 'is_string'))
            : array();

        $routes = rest_get_server()->get_routes();
        $routeRegistered = isset($routes['/wp-nerve/v1/mcp']);
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('WPNerve Diagnostics', 'wp-nerve'); ?></h1>
            <p>
                <?php
                echo esc_html__(
                    'This page shows the live WordPress registry, not a documentation estimate.',
                    'wp-nerve'
                );
                ?>
            </p>

            <?php $this->renderNotice(); ?>

            <table class="widefat striped" style="max-width: 900px">
                <tbody>
                    <tr>
                        <th scope="row"><?php echo esc_html__('WPNerve version', 'wp-nerve'); ?></th>
                        <td><code><?php echo esc_html(WP_NERVE_VERSION); ?></code></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Registered abilities', 'wp-nerve'); ?></th>
                        <td>
                            <strong><?php echo esc_html((string) $registeredCount); ?></strong>
                            / <?php echo esc_html((string) $expectedCount); ?>
                            <?php if ($registeredCount === $expectedCount) : ?>
                                — <span style="color: #008a20"><?php echo esc_html__('PASS', 'wp-nerve'); ?></span>
                            <?php else : ?>
                                — <span style="color: #b32d2e"><?php echo esc_html__('FAIL', 'wp-nerve'); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Discoverable for this administrator', 'wp-nerve'); ?></th>
                        <td>
                            <strong><?php echo esc_html((string) $discoverableCount); ?></strong>
                            / <?php echo esc_html((string) $registeredCount); ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('REST MCP route', 'wp-nerve'); ?></th>
                        <td><?php echo esc_html($routeRegistered ? 'PASS' : 'FAIL'); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Database schema', 'wp-nerve'); ?></th>
                        <td>
                            <code><?php echo esc_html($schemaVersion); ?></code>
                            / <code><?php echo esc_html(Activator::SCHEMA_VERSION); ?></code>
                            — <?php echo esc_html($schemaVersion === Activator::SCHEMA_VERSION ? 'PASS' : 'FAIL'); ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Enabled risk classes', 'wp-nerve'); ?></th>
                        <td><code><?php echo esc_html(implode(', ', $riskClasses)); ?></code></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Explicit ability overrides', 'wp-nerve'); ?></th>
                        <td><?php echo esc_html((string) count($abilityOverrides)); ?></td>
                    </tr>
                </tbody>
            </table>

            <h2><?php echo esc_html__('Operational test mode', 'wp-nerve'); ?></h2>
            <p>
                <?php
                echo esc_html__(
                    'On a disposable staging site, enable the complete reviewed catalog in one step. WordPress capabilities, idempotency and high-risk confirmation still apply.',
                    'wp-nerve'
                );
                ?>
            </p>
            <form method="post" style="display:inline-block; margin-right:8px">
                <?php wp_nonce_field(self::NONCE_ACTION, 'wp_nerve_diagnostics'); ?>
                <input type="hidden" name="wp_nerve_diagnostics_action" value="enable_full_surface" />
                <button type="submit" class="button button-primary">
                    <?php echo esc_html__('Enable full 53-ability test surface', 'wp-nerve'); ?>
                </button>
            </form>
            <form method="post" style="display:inline-block">
                <?php wp_nonce_field(self::NONCE_ACTION, 'wp_nerve_diagnostics'); ?>
                <input type="hidden" name="wp_nerve_diagnostics_action" value="reset_surface" />
                <button type="submit" class="button button-secondary">
                    <?php echo esc_html__('Reset secure defaults', 'wp-nerve'); ?>
                </button>
            </form>

            <h2><?php echo esc_html__('Blocked abilities for this administrator', 'wp-nerve'); ?></h2>
            <?php if (array() === $blocked) : ?>
                <p><strong><?php echo esc_html__('None. The full registered catalog is discoverable.', 'wp-nerve'); ?></strong></p>
            <?php else : ?>
                <p class="description">
                    <?php
                    echo esc_html__(
                        'These abilities are registered correctly but are currently hidden by an ability flag, risk class, or WordPress capability.',
                        'wp-nerve'
                    );
                    ?>
                </p>
                <pre style="max-width:900px; white-space:pre-wrap"><?php echo esc_html(implode("\n", $blocked)); ?></pre>
            <?php endif; ?>
        </div>
        <?php
    }

    /** @return array<int, WP_Ability> */
    private function registeredAbilities(): array
    {
        $abilities = array();

        foreach (wp_get_abilities() as $ability) {
            if (! $ability instanceof WP_Ability || ! str_starts_with($ability->get_name(), 'wp-nerve/')) {
                continue;
            }

            $abilities[] = $ability;
        }

        usort(
            $abilities,
            static fn (WP_Ability $left, WP_Ability $right): int => strcmp($left->get_name(), $right->get_name())
        );

        return $abilities;
    }

    /** @return array<int, string> */
    private function registeredAbilityNames(): array
    {
        return array_map(
            static fn (WP_Ability $ability): string => $ability->get_name(),
            $this->registeredAbilities()
        );
    }

    private function notice(string $message, string $type): void
    {
        set_transient(
            'wp_nerve_diagnostics_notice_' . get_current_user_id(),
            array('message' => $message, 'type' => $type),
            60
        );
    }

    private function renderNotice(): void
    {
        $key    = 'wp_nerve_diagnostics_notice_' . get_current_user_id();
        $notice = get_transient($key);

        if (! is_array($notice)) {
            return;
        }

        delete_transient($key);
        ?>
        <div class="notice <?php echo esc_attr((string) ($notice['type'] ?? 'notice-info')); ?> inline">
            <p><?php echo esc_html((string) ($notice['message'] ?? '')); ?></p>
        </div>
        <?php
    }
}
