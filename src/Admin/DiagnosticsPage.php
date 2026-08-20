<?php

/**
 * Runtime diagnostics, ability-surface controls, and operational MCP smoke tests.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Admin;

use Throwable;
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
            admin_url('tools.php?page=wp-nerve-diagnostics&wp_nerve_run_smoke=1'),
            self::NONCE_ACTION,
            'wp_nerve_diag_nonce'
        );
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('WPNerve Diagnostics', 'wp-nerve'); ?></h1>
            <p><?php echo esc_html__('Live WordPress registry and policy status — no documentation estimates.', 'wp-nerve'); ?></p>

            <?php $this->renderNotice(); ?>

            <table class="widefat striped" style="max-width:900px">
                <tbody>
                    <tr>
                        <th><?php echo esc_html__('WPNerve version', 'wp-nerve'); ?></th>
                        <td><code><?php echo esc_html(WP_NERVE_VERSION); ?></code></td>
                    </tr>
                    <tr>
                        <th><?php echo esc_html__('Registered abilities', 'wp-nerve'); ?></th>
                        <td>
                            <strong><?php echo esc_html((string) $registeredCount); ?></strong>
                            / <?php echo esc_html((string) $expectedCount); ?> —
                            <strong><?php echo esc_html($registeredCount === $expectedCount ? 'PASS' : 'FAIL'); ?></strong>
                        </td>
                    </tr>
                    <tr>
                        <th><?php echo esc_html__('Discoverable for this administrator', 'wp-nerve'); ?></th>
                        <td>
                            <strong><?php echo esc_html((string) $discoverableCount); ?></strong>
                            / <?php echo esc_html((string) $registeredCount); ?>
                        </td>
                    </tr>
                    <tr>
                        <th><?php echo esc_html__('REST MCP route', 'wp-nerve'); ?></th>
                        <td><strong><?php echo esc_html($routeRegistered ? 'PASS' : 'FAIL'); ?></strong></td>
                    </tr>
                    <tr>
                        <th><?php echo esc_html__('Database schema', 'wp-nerve'); ?></th>
                        <td>
                            <code><?php echo esc_html($schemaVersion); ?></code> /
                            <code><?php echo esc_html(Activator::SCHEMA_VERSION); ?></code> —
                            <strong><?php echo esc_html($schemaVersion === Activator::SCHEMA_VERSION ? 'PASS' : 'FAIL'); ?></strong>
                        </td>
                    </tr>
                    <tr>
                        <th><?php echo esc_html__('Enabled risk classes', 'wp-nerve'); ?></th>
                        <td><code><?php echo esc_html(implode(', ', $riskClasses)); ?></code></td>
                    </tr>
                    <tr>
                        <th><?php echo esc_html__('Explicit ability overrides', 'wp-nerve'); ?></th>
                        <td><?php echo esc_html((string) count($abilityOverrides)); ?></td>
                    </tr>
                </tbody>
            </table>

            <h2><?php echo esc_html__('Operational MCP smoke test', 'wp-nerve'); ?></h2>
            <p>
                <?php
                echo esc_html__(
                    'Runs the real WordPress REST route in-process: discovery, tools/list, site status, an opt-in tool, draft create/update, destructive confirmation, trash and restore. Test content is removed afterwards.',
                    'wp-nerve'
                );
                ?>
            </p>
            <p>
                <a class="button button-primary" href="<?php echo esc_url($smokeUrl); ?>">
                    <?php echo esc_html__('Run operational MCP smoke', 'wp-nerve'); ?>
                </a>
            </p>
            <?php $this->renderSmoke($smoke); ?>

            <h2><?php echo esc_html__('Operational test mode', 'wp-nerve'); ?></h2>
            <p>
                <?php
                echo esc_html__(
                    'For disposable staging: expose the complete reviewed catalog. WordPress capabilities, idempotency and high-risk confirmation still apply.',
                    'wp-nerve'
                );
                ?>
            </p>
            <form method="post" style="display:inline-block;margin-right:8px">
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
                        'Registered correctly but hidden by an ability flag, risk class, or WordPress capability.',
                        'wp-nerve'
                    );
                    ?>
                </p>
                <pre style="max-width:900px;white-space:pre-wrap"><?php echo esc_html(implode("\n", $blocked)); ?></pre>
            <?php endif; ?>
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
        set_transient(self::SMOKE_TRANSIENT_PREFIX . get_current_user_id(), $result, 10 * MINUTE_IN_SECONDS);

        $this->notice(
            true === ($result['passed'] ?? false)
                ? __('Operational MCP smoke passed.', 'wp-nerve')
                : __('Operational MCP smoke found a failure. Review the step table below.', 'wp-nerve'),
            true === ($result['passed'] ?? false) ? 'notice-success' : 'notice-error'
        );
    }

    /** @return array<string, mixed> */
    private function runOperationalSmoke(): array
    {
        $runId  = substr(hash('sha256', wp_generate_uuid4() . microtime(true)), 0, 16);
        $steps  = array();
        $postId = 0;

        try {
            $abilities = $this->registeredAbilities();
            $policy    = new PolicyEngine();
            $available = array_values(
                array_filter($abilities, static fn (WP_Ability $ability): bool => $policy->isDiscoverable($ability))
            );

            $this->step($steps, 'registry', AbilityRegistrar::CATALOG_COUNT === count($abilities), count($abilities) . ' registered abilities');
            $this->step($steps, 'policy', AbilityRegistrar::CATALOG_COUNT === count($available), count($available) . ' discoverable abilities');

            $discover = $this->dispatchModern('server/discover');
            $this->step($steps, 'server/discover', $this->rpcSuccess($discover), $this->rpcDetail($discover));

            $list      = $this->dispatchModern('tools/list');
            $listBody  = $this->rpcResult($list);
            $toolCount = is_array($listBody['tools'] ?? null) ? count($listBody['tools']) : 0;
            $this->step($steps, 'tools/list', $this->rpcSuccess($list) && AbilityRegistrar::CATALOG_COUNT === $toolCount, $toolCount . ' MCP tools returned');

            $status = $this->dispatchModern('tools/call', array('name' => 'wp_nerve_site_status', 'arguments' => array()), 'wp_nerve_site_status');
            $this->step($steps, 'site-status', $this->toolSuccess($status), $this->rpcDetail($status));

            $plugins = $this->dispatchModern('tools/call', array('name' => 'wp_nerve_list_plugins', 'arguments' => array()), 'wp_nerve_list_plugins');
            $this->step($steps, 'opt-in list-plugins', $this->toolSuccess($plugins), $this->rpcDetail($plugins));

            $create = $this->dispatchModern(
                'tools/call',
                array(
                    'name'      => 'wp_nerve_create_draft',
                    'arguments' => array(
                        'title'   => 'WPNerve diagnostic ' . $runId,
                        'content' => 'Temporary WPNerve operational smoke content.',
                    ),
                ),
                'wp_nerve_create_draft',
                'diag-create-' . $runId
            );
            $created = $this->toolStructuredContent($create);
            $postId  = (int) ($created['id'] ?? 0);
            $this->step($steps, 'create-draft', $this->toolSuccess($create) && $postId > 0, $postId > 0 ? 'created post ' . $postId : $this->rpcDetail($create));

            if ($postId <= 0) {
                throw new \RuntimeException('Draft creation did not return a post ID.');
            }

            $update = $this->dispatchModern(
                'tools/call',
                array(
                    'name'      => 'wp_nerve_update_content',
                    'arguments' => array(
                        'id'      => $postId,
                        'excerpt' => 'WPNerve operational smoke updated.',
                    ),
                ),
                'wp_nerve_update_content',
                'diag-update-' . $runId
            );
            $this->step($steps, 'update-content', $this->toolSuccess($update), $this->rpcDetail($update));

            $trashKey = 'diag-trash-' . $runId;
            $pending  = $this->dispatchModern(
                'tools/call',
                array('name' => 'wp_nerve_trash_content', 'arguments' => array('id' => $postId)),
                'wp_nerve_trash_content',
                $trashKey
            );
            $confirmation = $this->confirmationMetadata($pending);
            $token        = is_string($confirmation['token'] ?? null) ? $confirmation['token'] : '';
            $displayCode  = is_string($confirmation['displayCode'] ?? null) ? $confirmation['displayCode'] : '';
            $this->step($steps, 'destructive confirmation issued', '' !== $token && '' !== $displayCode, '' !== $displayCode ? 'challenge ' . $displayCode : $this->rpcDetail($pending));

            if ('' === $token || '' === $displayCode) {
                throw new \RuntimeException('Destructive confirmation metadata was not returned.');
            }

            $repository  = new ConfirmationRepository();
            $challengeId = 0;
            foreach ($repository->pending() as $challenge) {
                if ($displayCode === ($challenge['display_code'] ?? null)) {
                    $challengeId = (int) ($challenge['id'] ?? 0);
                    break;
                }
            }

            $approved = $challengeId > 0 && $repository->decide($challengeId, get_current_user_id(), true);
            $this->step($steps, 'admin confirmation approval', $approved, $approved ? 'approved challenge ' . $displayCode : 'approval failed');

            if (! $approved) {
                throw new \RuntimeException('Destructive confirmation could not be approved.');
            }

            $trashed = $this->dispatchModern(
                'tools/call',
                array('name' => 'wp_nerve_trash_content', 'arguments' => array('id' => $postId)),
                'wp_nerve_trash_content',
                $trashKey,
                $token
            );
            $this->step($steps, 'trash-content after approval', $this->toolSuccess($trashed) && 'trash' === get_post_status($postId), $this->rpcDetail($trashed));

            $restored = $this->dispatchModern(
                'tools/call',
                array('name' => 'wp_nerve_restore_content', 'arguments' => array('id' => $postId)),
                'wp_nerve_restore_content',
                'diag-restore-' . $runId
            );
            $this->step($steps, 'restore-content', $this->toolSuccess($restored) && 'trash' !== get_post_status($postId), $this->rpcDetail($restored));
        } catch (Throwable $throwable) {
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
            $data = $response->get_error_data();
            return array(
                'http' => is_array($data) ? (int) ($data['status'] ?? 500) : 500,
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
        <p>
            <strong><?php echo esc_html(true === ($smoke['passed'] ?? false) ? 'PASS' : 'FAIL'); ?></strong>
            — <?php echo esc_html((string) ($smoke['time'] ?? '')); ?>
            — <code><?php echo esc_html((string) ($smoke['run_id'] ?? '')); ?></code>
        </p>
        <table class="widefat striped" style="max-width:1000px">
            <thead>
                <tr>
                    <th><?php echo esc_html__('Step', 'wp-nerve'); ?></th>
                    <th><?php echo esc_html__('Result', 'wp-nerve'); ?></th>
                    <th><?php echo esc_html__('Detail', 'wp-nerve'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($steps as $step) : ?>
                    <tr>
                        <td><code><?php echo esc_html((string) ($step['name'] ?? '')); ?></code></td>
                        <td><strong><?php echo esc_html(true === ($step['passed'] ?? false) ? 'PASS' : 'FAIL'); ?></strong></td>
                        <td><?php echo esc_html((string) ($step['detail'] ?? '')); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
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
