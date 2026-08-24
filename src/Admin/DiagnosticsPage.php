<?php

/**
 * Runtime diagnostics, ability-surface controls, and operational MCP smoke tests.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Admin;

use WP_Ability;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WPNerve\Abilities\AbilityRegistrar;
use WPNerve\Infrastructure\Activator;
use WPNerve\Policy\PolicyEngine;
use WPNerve\Security\Confirmation\WpdbRepository as ConfirmationRepository;

final class DiagnosticsPage
{
    private const NONCE_ACTION = 'wp_nerve_diagnostics';

    private const SMOKE_TRANSIENT_PREFIX = 'wp_nerve_diagnostics_smoke_';

    public function registerMenu(): void
    {
        if (function_exists('add_submenu_page')) {
            add_submenu_page(
                'wp-nerve',
                __('WPNerve Diagnostics', 'wp-nerve'),
                __('Diagnostics', 'wp-nerve'),
                'manage_options',
                'wp-nerve-diagnostics',
                array($this, 'render')
            );
            return;
        }

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
                    /* translators: %d: number of registered abilities. */
                    __('Full WPNerve test surface enabled for %d registered abilities.', 'wp-nerve'),
                    count($names)
                ),
                'notice-success'
            );
        } elseif ('reset_surface' === $action) {
            delete_option('wp_nerve_enabled_abilities');
            update_option('wp_nerve_enabled_risk_classes', array('read', 'write'), false);
            delete_transient(self::SMOKE_TRANSIENT_PREFIX . get_current_user_id());

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

        $this->maybeRunOperationalSmoke();

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
        $riskClasses       = is_array($riskClasses)
            ? array_values(array_filter($riskClasses, 'is_string'))
            : array();
        $abilityOverrides  = get_option('wp_nerve_enabled_abilities', array());
        $abilityOverrides  = is_array($abilityOverrides)
            ? array_values(array_filter($abilityOverrides, 'is_string'))
            : array();
        $routes            = rest_get_server()->get_routes();
        $routeRegistered   = isset($routes['/wp-nerve/v1/mcp']);
        $smoke             = get_transient(self::SMOKE_TRANSIENT_PREFIX . get_current_user_id());
        $smoke             = is_array($smoke) ? $smoke : array();
        $smokeUrl          = wp_nonce_url(
            admin_url('admin.php?page=wp-nerve-diagnostics&wp_nerve_run_smoke=1'),
            self::NONCE_ACTION,
            'wp_nerve_diag_nonce'
        );
        ?>
        <div class="wrap wpn-admin">
            <header class="wpn-hero">
                <div class="wpn-hero__brand"><span class="wpn-brandmark"><span class="dashicons dashicons-chart-area"></span></span><div><span class="wpn-kicker"><?php echo esc_html__('Runtime diagnostics', 'wp-nerve'); ?></span><h1><?php echo esc_html__('WPNerve Diagnostics', 'wp-nerve'); ?></h1><p><?php echo esc_html__('Live WordPress registry, policy and MCP execution status — no documentation estimates.', 'wp-nerve'); ?></p></div></div>
                <div class="wpn-hero__actions"><span class="wpn-pill"><?php echo esc_html(WP_NERVE_VERSION); ?></span><a class="button wpn-button" href="<?php echo esc_url(admin_url('admin.php?page=wp-nerve')); ?>"><?php echo esc_html__('Dashboard', 'wp-nerve'); ?></a><a class="button wpn-button" href="<?php echo esc_url(admin_url('admin.php?page=wp-nerve-http-smoke')); ?>"><?php echo esc_html__('HTTP Smoke', 'wp-nerve'); ?></a></div>
            </header>

            <?php $this->renderNotice(); ?>

            <div class="wpn-stats">
                <div class="wpn-stat">
                    <span class="wpn-stat__label"><?php echo esc_html__('Ability catalog', 'wp-nerve'); ?></span>
                    <strong class="wpn-stat__value"><?php echo esc_html((string) $registeredCount); ?>/<?php echo esc_html((string) $expectedCount); ?></strong>
                    <span class="wpn-stat__meta"><?php echo esc_html($registeredCount === $expectedCount ? __('Registry contract passed', 'wp-nerve') : __('Registry contract failed', 'wp-nerve')); ?></span>
                </div>
                <div class="wpn-stat">
                    <span class="wpn-stat__label"><?php echo esc_html__('Discoverable', 'wp-nerve'); ?></span>
                    <strong class="wpn-stat__value"><?php echo esc_html((string) $discoverableCount); ?>/<?php echo esc_html((string) $registeredCount); ?></strong>
                    <span class="wpn-stat__meta"><?php echo esc_html__('for this administrator', 'wp-nerve'); ?></span>
                </div>
                <div class="wpn-stat">
                    <span class="wpn-stat__label"><?php echo esc_html__('REST MCP route', 'wp-nerve'); ?></span>
                    <strong class="wpn-stat__value"><?php echo esc_html($routeRegistered ? 'PASS' : 'FAIL'); ?></strong>
                    <span class="wpn-stat__meta"><?php echo esc_html__('public protocol boundary', 'wp-nerve'); ?></span>
                </div>
                <div class="wpn-stat">
                    <span class="wpn-stat__label"><?php echo esc_html__('Database schema', 'wp-nerve'); ?></span>
                    <strong class="wpn-stat__value"><?php echo esc_html($schemaVersion); ?>/<?php echo esc_html(Activator::SCHEMA_VERSION); ?></strong>
                    <span class="wpn-stat__meta"><?php echo esc_html($schemaVersion === Activator::SCHEMA_VERSION ? __('Schema passed', 'wp-nerve') : __('Schema mismatch', 'wp-nerve')); ?></span>
                </div>
            </div>

            <div class="wpn-layout">
                <main class="wpn-main">
                    <section class="wpn-panel">
                        <div class="wpn-panel__head">
                            <div>
                                <h2><?php echo esc_html__('Runtime state', 'wp-nerve'); ?></h2>
                                <p><?php echo esc_html__('Live values read from this WordPress installation.', 'wp-nerve'); ?></p>
                            </div>
                            <span class="wpn-status <?php echo esc_attr($registeredCount === $expectedCount && $routeRegistered && $schemaVersion === Activator::SCHEMA_VERSION ? 'wpn-status--ok' : 'wpn-status--bad'); ?>"><span class="wpn-status__dot"></span><?php echo esc_html($registeredCount === $expectedCount && $routeRegistered && $schemaVersion === Activator::SCHEMA_VERSION ? 'PASS' : 'CHECK'); ?></span>
                        </div>
                        <div class="wpn-panel__body wpn-panel__body--flush">
                            <dl class="wpn-info-grid">
                                <div><dt><?php echo esc_html__('WPNerve version', 'wp-nerve'); ?></dt><dd><code><?php echo esc_html(WP_NERVE_VERSION); ?></code></dd></div>
                                <div><dt><?php echo esc_html__('Enabled risk classes', 'wp-nerve'); ?></dt><dd><code><?php echo esc_html(implode(', ', $riskClasses)); ?></code></dd></div>
                                <div><dt><?php echo esc_html__('Explicit ability overrides', 'wp-nerve'); ?></dt><dd><?php echo esc_html((string) count($abilityOverrides)); ?></dd></div>
                                <div><dt><?php echo esc_html__('Blocked abilities', 'wp-nerve'); ?></dt><dd><?php echo esc_html((string) count($blocked)); ?></dd></div>
                            </dl>
                        </div>
                    </section>

                    <section class="wpn-panel">
                        <div class="wpn-panel__head">
                            <div>
                                <h2><?php echo esc_html__('Operational MCP smoke', 'wp-nerve'); ?></h2>
                                <p><?php echo esc_html__('Runs the real WordPress REST route in-process and removes its temporary content afterwards.', 'wp-nerve'); ?></p>
                            </div>
                        </div>
                        <div class="wpn-panel__body">
                            <p class="wpn-section-note"><?php echo esc_html__('Covers discovery, tools/list, site status, an opt-in tool, draft create/update, destructive confirmation, trash and restore.', 'wp-nerve'); ?></p>
                            <p><a class="button wpn-button wpn-button--primary" href="<?php echo esc_url($smokeUrl); ?>"><?php echo esc_html__('Run operational MCP smoke', 'wp-nerve'); ?></a></p>
                            <?php $this->renderSmoke($smoke); ?>
                        </div>
                    </section>

                    <section class="wpn-panel">
                        <div class="wpn-panel__head"><div><h2><?php echo esc_html__('Blocked abilities', 'wp-nerve'); ?></h2><p><?php echo esc_html__('Abilities registered correctly but hidden by policy or WordPress capabilities.', 'wp-nerve'); ?></p></div></div>
                        <div class="wpn-panel__body">
                            <?php if (array() === $blocked) : ?>
                                <div class="wpn-empty"><strong><?php echo esc_html__('None. The full registered catalog is discoverable.', 'wp-nerve'); ?></strong></div>
                            <?php else : ?>
                                <pre class="wpn-code"><?php echo esc_html(implode("\n", $blocked)); ?></pre>
                            <?php endif; ?>
                        </div>
                    </section>
                </main>

                <aside class="wpn-side">
                    <section class="wpn-card">
                        <span class="wpn-kicker"><?php echo esc_html__('Disposable staging only', 'wp-nerve'); ?></span>
                        <h2><?php echo esc_html__('Operational test mode', 'wp-nerve'); ?></h2>
                        <p><?php echo esc_html__('Expose the complete reviewed catalog for end-to-end testing. WordPress capabilities, idempotency and high-risk confirmation still apply.', 'wp-nerve'); ?></p>
                        <div class="wpn-actions" style="margin-top:16px">
                            <form method="post">
                                <?php wp_nonce_field(self::NONCE_ACTION, 'wp_nerve_diagnostics'); ?>
                                <input type="hidden" name="wp_nerve_diagnostics_action" value="enable_full_surface" />
                                <button type="submit" class="button wpn-button wpn-button--primary"><?php echo esc_html__('Enable full 53-ability surface', 'wp-nerve'); ?></button>
                            </form>
                            <form method="post">
                                <?php wp_nonce_field(self::NONCE_ACTION, 'wp_nerve_diagnostics'); ?>
                                <input type="hidden" name="wp_nerve_diagnostics_action" value="reset_surface" />
                                <button type="submit" class="button wpn-button"><?php echo esc_html__('Reset secure defaults', 'wp-nerve'); ?></button>
                            </form>
                        </div>
                    </section>

                    <section class="wpn-card">
                        <span class="wpn-kicker"><?php echo esc_html__('Interpretation', 'wp-nerve'); ?></span>
                        <h2><?php echo esc_html__('What a green screen means', 'wp-nerve'); ?></h2>
                        <p><?php echo esc_html__('The catalog is registered, the MCP route exists, the database schema is current and the active administrator can discover the expected surface. Use HTTP Smoke for the external HTTPS path.', 'wp-nerve'); ?></p>
                        <div class="wpn-card__links"><a href="<?php echo esc_url(admin_url('admin.php?page=wp-nerve-http-smoke')); ?>"><?php echo esc_html__('Run authenticated HTTP smoke →', 'wp-nerve'); ?></a><a href="<?php echo esc_url(admin_url('admin.php?page=wp-nerve-documentation')); ?>"><?php echo esc_html__('Open operator guide →', 'wp-nerve'); ?></a></div>
                    </section>
                </aside>
            </div>
        </div>
        <?php
    }

    private function maybeRunOperationalSmoke(): void
    {
        if (! isset($_GET['wp_nerve_run_smoke'], $_GET['wp_nerve_diag_nonce'])) {
            return;
        }

        $requested = sanitize_key((string) wp_unslash($_GET['wp_nerve_run_smoke']));
        $nonce     = sanitize_key((string) wp_unslash($_GET['wp_nerve_diag_nonce']));

        if ('1' !== $requested || ! wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            return;
        }

        $result = $this->runOperationalSmoke();
        set_transient(self::SMOKE_TRANSIENT_PREFIX . get_current_user_id(), $result, 15 * MINUTE_IN_SECONDS);

        $passed = true === ($result['passed'] ?? false);
        $this->notice(
            $passed
                ? __('Operational MCP smoke passed.', 'wp-nerve')
                : __('Operational MCP smoke found a failure. Review the step table below.', 'wp-nerve'),
            $passed ? 'notice-success' : 'notice-error'
        );
    }

    /** @return array<string, mixed> */
    private function runOperationalSmoke(): array
    {
        $steps  = array();
        $postId = 0;
        $runId  = substr(hash('sha256', wp_generate_uuid4()), 0, 16);

        try {
            $this->step(
                $steps,
                'registry',
                AbilityRegistrar::CATALOG_COUNT === count($this->registeredAbilities()),
                count($this->registeredAbilities()) . ' registered abilities'
            );

            $policy            = new PolicyEngine();
            $discoverableCount = 0;
            foreach ($this->registeredAbilities() as $ability) {
                if ($policy->isDiscoverable($ability)) {
                    ++$discoverableCount;
                }
            }
            $this->step(
                $steps,
                'policy',
                AbilityRegistrar::CATALOG_COUNT === $discoverableCount,
                $discoverableCount . ' discoverable abilities'
            );

            $discover = $this->dispatchModern('server/discover');
            $this->step(
                $steps,
                'server/discover',
                $this->rpcSuccess($discover),
                $this->rpcDetail($discover)
            );

            $toolsList = $this->dispatchModern('tools/list');
            $tools     = $this->rpcResult($toolsList)['tools'] ?? null;
            $toolCount = is_array($tools) ? count($tools) : 0;
            $this->step(
                $steps,
                'tools/list',
                $this->rpcSuccess($toolsList) && AbilityRegistrar::CATALOG_COUNT === $toolCount,
                $toolCount . ' MCP tools returned'
            );

            $status = $this->dispatchModern(
                'tools/call',
                array('name' => 'wp_nerve_site_status', 'arguments' => array()),
                'wp_nerve_site_status'
            );
            $this->step(
                $steps,
                'site-status',
                $this->toolSuccess($status),
                $this->rpcDetail($status)
            );

            $plugins = $this->dispatchModern(
                'tools/call',
                array('name' => 'wp_nerve_list_plugins', 'arguments' => array()),
                'wp_nerve_list_plugins'
            );
            $this->step(
                $steps,
                'opt-in list-plugins',
                $this->toolSuccess($plugins),
                $this->rpcDetail($plugins)
            );

            $createKey = 'diag-create-' . $runId;
            $created   = $this->dispatchModern(
                'tools/call',
                array(
                    'name'      => 'wp_nerve_create_draft',
                    'arguments' => array(
                        'title'   => 'WPNerve operational smoke ' . $runId,
                        'content' => 'Temporary WPNerve MCP diagnostic content.',
                    ),
                ),
                'wp_nerve_create_draft',
                $createKey
            );
            $createdContent = $this->toolStructuredContent($created);
            $postId         = is_int($createdContent['id'] ?? null) ? $createdContent['id'] : 0;
            $this->step(
                $steps,
                'create-draft',
                $this->toolSuccess($created) && $postId > 0,
                $postId > 0 ? 'created post ' . $postId : $this->rpcDetail($created)
            );

            if ($postId <= 0) {
                throw new \RuntimeException('Draft creation did not return a WordPress post ID.');
            }

            $updated = $this->dispatchModern(
                'tools/call',
                array(
                    'name'      => 'wp_nerve_update_content',
                    'arguments' => array(
                        'id'      => $postId,
                        'excerpt' => 'WPNerve smoke updated through MCP.',
                    ),
                ),
                'wp_nerve_update_content',
                'diag-update-' . $runId
            );
            $this->step(
                $steps,
                'update-content',
                $this->toolSuccess($updated),
                $this->rpcDetail($updated)
            );

            $trashKey = 'diag-trash-' . $runId;
            $trash    = $this->dispatchModern(
                'tools/call',
                array('name' => 'wp_nerve_trash_content', 'arguments' => array('id' => $postId)),
                'wp_nerve_trash_content',
                $trashKey
            );
            $confirmation = $this->confirmationMetadata($trash);
            $token        = is_string($confirmation['token'] ?? null) ? $confirmation['token'] : '';
            $displayCode  = is_string($confirmation['displayCode'] ?? null) ? $confirmation['displayCode'] : '';
            $this->step(
                $steps,
                'destructive confirmation issued',
                '' !== $token && '' !== $displayCode,
                '' !== $displayCode ? 'challenge ' . $displayCode : $this->rpcDetail($trash)
            );

            if ('' === $token || '' === $displayCode) {
                throw new \RuntimeException('Destructive call did not return a confirmation challenge.');
            }

            $confirmationRepository = new ConfirmationRepository();
            $challengeId             = 0;
            foreach ($confirmationRepository->pending() as $challenge) {
                if (
                    ($challenge['display_code'] ?? '') === $displayCode
                    && ($challenge['tool_name'] ?? '') === 'wp_nerve_trash_content'
                ) {
                    $challengeId = (int) ($challenge['id'] ?? 0);
                    break;
                }
            }

            $approved = $challengeId > 0
                && $confirmationRepository->decide($challengeId, get_current_user_id(), true);
            $this->step(
                $steps,
                'admin confirmation approval',
                $approved,
                $approved ? 'approved challenge ' . $displayCode : 'challenge approval failed'
            );

            if (! $approved) {
                throw new \RuntimeException('The destructive confirmation could not be approved.');
            }

            $trashed = $this->dispatchModern(
                'tools/call',
                array('name' => 'wp_nerve_trash_content', 'arguments' => array('id' => $postId)),
                'wp_nerve_trash_content',
                $trashKey,
                $token
            );
            $this->step(
                $steps,
                'trash-content after approval',
                $this->toolSuccess($trashed) && 'trash' === get_post_status($postId),
                $this->rpcDetail($trashed)
            );

            $restored = $this->dispatchModern(
                'tools/call',
                array('name' => 'wp_nerve_restore_content', 'arguments' => array('id' => $postId)),
                'wp_nerve_restore_content',
                'diag-restore-' . $runId
            );
            $this->step(
                $steps,
                'restore-content',
                $this->toolSuccess($restored) && 'trash' !== get_post_status($postId),
                $this->rpcDetail($restored)
            );
        } catch (\Throwable $throwable) {
            $this->step($steps, 'smoke-runner', false, $throwable->getMessage());
        } finally {
            if ($postId > 0) {
                wp_delete_post($postId, true);
            }
        }

        $passed = array() !== $steps;
        foreach ($steps as $step) {
            if (true !== ($step['passed'] ?? false)) {
                $passed = false;
                break;
            }
        }

        return array(
            'passed' => $passed,
            'run_id' => $runId,
            'time'   => current_time('mysql'),
            'steps'  => $steps,
        );
    }

    /**
     * @param array<string, mixed> $params
     * @return array{http: int, body: mixed}
     */
    private function dispatchModern(
        string $method,
        array $params = array(),
        string $name = '',
        string $idempotencyKey = '',
        string $confirmationToken = ''
    ): array {
        $meta = array(
            'io.modelcontextprotocol/protocolVersion'    => '2026-07-28',
            'io.modelcontextprotocol/clientCapabilities' => array(),
            'io.modelcontextprotocol/clientInfo'         => array(
                'name'    => 'wp-nerve-admin-smoke',
                'version' => WP_NERVE_VERSION,
            ),
        );

        if ('' !== $idempotencyKey) {
            $meta['wp-nerve/idempotencyKey'] = $idempotencyKey;
        }

        if ('' !== $confirmationToken) {
            $meta['wp-nerve/confirmationToken'] = $confirmationToken;
        }

        $params['_meta'] = $meta;
        $message         = array(
            'jsonrpc' => '2.0',
            'id'      => 'diagnostic-' . substr(hash('sha256', $method . wp_generate_uuid4()), 0, 12),
            'method'  => $method,
            'params'  => $params,
        );
        $body = wp_json_encode($message);

        if (! is_string($body)) {
            return array('http' => 0, 'body' => array('error' => 'JSON encoding failed.'));
        }

        $request = new WP_REST_Request('POST', '/wp-nerve/v1/mcp');
        $request->set_header('Content-Type', 'application/json');
        $request->set_header('MCP-Protocol-Version', '2026-07-28');
        $request->set_header('Mcp-Method', $method);
        if ('' !== $name) {
            $request->set_header('Mcp-Name', $name);
        }
        $request->set_body($body);

        $response = rest_do_request($request);

        if ($response instanceof WP_Error) {
            return array(
                'http' => (int) ($response->get_error_data()['status'] ?? 500),
                'body' => array('error' => $response->get_error_message()),
            );
        }

        if (! $response instanceof WP_REST_Response) {
            return array('http' => 500, 'body' => array('error' => 'Unexpected REST response type.'));
        }

        return array('http' => $response->get_status(), 'body' => $response->get_data());
    }

    /** @param array{http: int, body: mixed} $response */
    private function rpcSuccess(array $response): bool
    {
        $body = $response['body'];

        return 200 === $response['http']
            && is_array($body)
            && ! isset($body['error'])
            && isset($body['result']);
    }

    /**
     * @param array{http: int, body: mixed} $response
     * @return array<string, mixed>
     */
    private function rpcResult(array $response): array
    {
        $body = $response['body'];

        return is_array($body) && is_array($body['result'] ?? null) ? $body['result'] : array();
    }

    /** @param array{http: int, body: mixed} $response */
    private function toolSuccess(array $response): bool
    {
        $result = $this->rpcResult($response);

        return $this->rpcSuccess($response) && false === ($result['isError'] ?? true);
    }

    /**
     * @param array{http: int, body: mixed} $response
     * @return array<string, mixed>
     */
    private function toolStructuredContent(array $response): array
    {
        $result = $this->rpcResult($response);
        $value  = $result['structuredContent'] ?? null;

        return is_array($value) ? $value : array();
    }

    /**
     * @param array{http: int, body: mixed} $response
     * @return array<string, mixed>
     */
    private function confirmationMetadata(array $response): array
    {
        $result       = $this->rpcResult($response);
        $meta         = is_array($result['_meta'] ?? null) ? $result['_meta'] : array();
        $confirmation = $meta['wp-nerve/confirmation'] ?? null;

        return is_array($confirmation) ? $confirmation : array();
    }

    /** @param array{http: int, body: mixed} $response */
    private function rpcDetail(array $response): string
    {
        $body = $response['body'];

        if (! is_array($body)) {
            return 'HTTP ' . $response['http'] . ' returned a non-object body';
        }

        if (isset($body['error']) && is_array($body['error'])) {
            return 'HTTP ' . $response['http'] . ': ' . (string) ($body['error']['message'] ?? 'protocol error');
        }

        $result = is_array($body['result'] ?? null) ? $body['result'] : array();
        if (true === ($result['isError'] ?? false)) {
            $content = is_array($result['content'] ?? null) ? $result['content'] : array();
            $first   = is_array($content[0] ?? null) ? $content[0] : array();

            return 'HTTP ' . $response['http'] . ': ' . (string) ($first['text'] ?? 'tool error');
        }

        return 'HTTP ' . $response['http'] . ' PASS';
    }

    /**
     * @param array<int, array{name: string, passed: bool, detail: string}> $steps
     */
    private function step(array &$steps, string $name, bool $passed, string $detail): void
    {
        $steps[] = array('name' => $name, 'passed' => $passed, 'detail' => $detail);
    }

    /** @param array<string, mixed> $smoke */
    private function renderSmoke(array $smoke): void
    {
        $steps = is_array($smoke['steps'] ?? null) ? $smoke['steps'] : array();

        if (array() === $steps) {
            return;
        }
        ?>
        <div class="wpn-run-meta">
            <span class="wpn-status <?php echo esc_attr(true === ($smoke['passed'] ?? false) ? 'wpn-status--ok' : 'wpn-status--bad'); ?>"><span class="wpn-status__dot"></span><?php echo esc_html(true === ($smoke['passed'] ?? false) ? 'PASS' : 'FAIL'); ?></span>
            <span class="wpn-section-note"><?php echo esc_html((string) ($smoke['time'] ?? '')); ?></span>
            <code><?php echo esc_html((string) ($smoke['run_id'] ?? '')); ?></code>
        </div>
        <div class="wpn-table-wrap"><table class="wpn-table">
            <thead><tr><th><?php echo esc_html__('Step', 'wp-nerve'); ?></th><th><?php echo esc_html__('Result', 'wp-nerve'); ?></th><th><?php echo esc_html__('Detail', 'wp-nerve'); ?></th></tr></thead>
            <tbody>
                <?php foreach ($steps as $step) : ?>
                    <tr>
                        <td><code><?php echo esc_html((string) ($step['name'] ?? '')); ?></code></td>
                        <td><span class="wpn-status <?php echo esc_attr(true === ($step['passed'] ?? false) ? 'wpn-status--ok' : 'wpn-status--bad'); ?>"><span class="wpn-status__dot"></span><?php echo esc_html(true === ($step['passed'] ?? false) ? 'PASS' : 'FAIL'); ?></span></td>
                        <td><?php echo esc_html((string) ($step['detail'] ?? '')); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php
    }

    /** @return array<int, WP_Ability> */
    private function registeredAbilities(): array
    {
        $abilities = array();

        foreach (wp_get_abilities() as $ability) {
            if ($ability instanceof WP_Ability && str_starts_with($ability->get_name(), 'wp-nerve/')) {
                $abilities[] = $ability;
            }
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
