<?php

/**
 * Authenticated real-HTTP MCP smoke diagnostics.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Admin;

use Throwable;
use WP_Error;
use WP_User;

final class HttpSmokePage
{
    private const NONCE_ACTION = 'wp_nerve_http_smoke';
    private const RESULT_PREFIX = 'wp_nerve_http_smoke_';

    public function registerMenu(): void
    {
        if (function_exists('add_submenu_page')) {
            add_submenu_page(
                'wp-nerve',
                __('WPNerve HTTP Smoke', 'wp-nerve'),
                __('HTTP Smoke', 'wp-nerve'),
                'manage_options',
                'wp-nerve-http-smoke',
                array($this, 'render')
            );
            return;
        }

        add_management_page(
            __('WPNerve HTTP Smoke', 'wp-nerve'),
            __('WPNerve HTTP Smoke', 'wp-nerve'),
            'manage_options',
            'wp-nerve-http-smoke',
            array($this, 'render')
        );
    }

    public function render(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $this->maybeRun();
        $result = get_transient(self::RESULT_PREFIX . get_current_user_id());
        $result = is_array($result) ? $result : array();
        $url = wp_nonce_url(
            admin_url('admin.php?page=wp-nerve-http-smoke&wp_nerve_http_smoke=1'),
            self::NONCE_ACTION,
            'wp_nerve_http_nonce'
        );
        ?>
        <div class="wrap wpn-admin">
            <header class="wpn-hero">
                <div class="wpn-hero__brand"><span class="wpn-brandmark"><span class="dashicons dashicons-shield-alt"></span></span><div><span class="wpn-kicker"><?php echo esc_html__('External transport validation', 'wp-nerve'); ?></span><h1><?php echo esc_html__('Authenticated HTTP Smoke', 'wp-nerve'); ?></h1><p><?php echo esc_html__('Validates the public HTTPS MCP endpoint with a temporary credential that is revoked automatically.', 'wp-nerve'); ?></p></div></div>
                <div class="wpn-hero__actions"><span class="wpn-pill"><?php echo esc_html(WP_NERVE_VERSION); ?></span><a class="button wpn-button" href="<?php echo esc_url(admin_url('admin.php?page=wp-nerve')); ?>"><?php echo esc_html__('Dashboard', 'wp-nerve'); ?></a></div>
            </header>
            <section class="wpn-panel"><div class="wpn-panel__head"><div><h2><?php echo esc_html__('Public MCP endpoint', 'wp-nerve'); ?></h2><p><?php echo esc_html__('Modern + legacy protocol checks, authentication failures and mirrored-header validation.', 'wp-nerve'); ?></p></div></div><div class="wpn-panel__body"><p class="wpn-code"><?php echo esc_html(rest_url('wp-nerve/v1/mcp')); ?></p><p><a class="button wpn-button wpn-button--primary" href="<?php echo esc_url($url); ?>"><?php echo esc_html__('Run authenticated HTTP smoke', 'wp-nerve'); ?></a></p></div></section>
            <?php $this->renderResult($result); ?>
        </div>
        <?php
    }

    private function maybeRun(): void
    {
        if (! isset($_GET['wp_nerve_http_smoke'], $_GET['wp_nerve_http_nonce'])) {
            return;
        }

        $requested = sanitize_key((string) wp_unslash($_GET['wp_nerve_http_smoke']));
        $nonce = sanitize_key((string) wp_unslash($_GET['wp_nerve_http_nonce']));
        if ('1' !== $requested || ! wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            return;
        }

        set_transient(self::RESULT_PREFIX . get_current_user_id(), $this->run(), 10 * MINUTE_IN_SECONDS);
    }

    /** @return array<string,mixed> */
    private function run(): array
    {
        $steps = array();
        $runId = substr(hash('sha256', wp_generate_uuid4() . microtime(true)), 0, 16);
        $manager = new ApplicationPasswords();
        $credential = null;

        try {
            $credential = $manager->create(get_current_user_id());
            if ($credential instanceof WP_Error) {
                throw new \RuntimeException($credential->get_error_message());
            }

            $header = $manager->authorizationHeader($credential['user'], $credential['password']);
            $this->step($steps, 'temporary credential', true, 'created');

            $discover = $this->modern($header, 'server/discover');
            $this->step($steps, 'modern server/discover', $this->rpcSuccess($discover), $this->detail($discover));

            $tools = $this->modern($header, 'tools/list');
            $result = $this->rpcResult($tools);
            $count = is_array($result['tools'] ?? null) ? count($result['tools']) : 0;
            $this->step($steps, 'modern tools/list', $this->rpcSuccess($tools) && 53 === $count, $count . ' tools returned');

            $status = $this->modern($header, 'tools/call', array('name' => 'wp_nerve_site_status', 'arguments' => array()), 'wp_nerve_site_status');
            $this->step($steps, 'modern site-status', $this->toolSuccess($status), $this->detail($status));

            foreach (array('2025-11-25', '2025-06-18') as $version) {
                $initialize = $this->legacy($header, $version, 'initialize', array(
                    'protocolVersion' => $version,
                    'capabilities' => array(),
                    'clientInfo' => array('name' => 'wp-nerve-http-smoke', 'version' => WP_NERVE_VERSION),
                ));
                $initialized = $this->rpcResult($initialize);
                $this->step($steps, 'legacy initialize ' . $version, $this->rpcSuccess($initialize) && $version === ($initialized['protocolVersion'] ?? null), $this->detail($initialize));

                $legacyTools = $this->legacy($header, $version, 'tools/list');
                $legacyResult = $this->rpcResult($legacyTools);
                $legacyCount = is_array($legacyResult['tools'] ?? null) ? count($legacyResult['tools']) : 0;
                $this->step($steps, 'legacy tools/list ' . $version, $this->rpcSuccess($legacyTools) && 53 === $legacyCount, $legacyCount . ' tools returned');
            }

            $unauth = $this->raw('', $this->modernMessage('server/discover'), array(
                'MCP-Protocol-Version' => '2026-07-28',
                'Mcp-Method' => 'server/discover',
            ));
            $this->step($steps, 'unauthenticated request', 401 === $unauth['http'], $this->detail($unauth));

            $origin = $this->raw($header, $this->modernMessage('server/discover'), array(
                'MCP-Protocol-Version' => '2026-07-28',
                'Mcp-Method' => 'server/discover',
                'Origin' => 'https://attacker.invalid',
            ));
            $this->step($steps, 'hostile Origin', 403 === $origin['http'], $this->detail($origin));

            $mismatch = $this->raw($header, $this->modernMessage('server/discover'), array(
                'MCP-Protocol-Version' => '2026-07-28',
                'Mcp-Method' => 'tools/list',
            ));
            $this->step($steps, 'mirrored method mismatch', 400 === $mismatch['http'], $this->detail($mismatch));

            $unsupported = $this->raw($header, $this->modernMessage('server/discover', '2099-01-01'), array(
                'MCP-Protocol-Version' => '2099-01-01',
                'Mcp-Method' => 'server/discover',
            ));
            $this->step($steps, 'unsupported protocol', 400 === $unsupported['http'], $this->detail($unsupported));
        } catch (Throwable $throwable) {
            $this->step($steps, 'http-smoke-runner', false, $throwable->getMessage());
        } finally {
            if (is_array($credential) && isset($credential['user'], $credential['uuid']) && $credential['user'] instanceof WP_User) {
                $revoked = $manager->revoke($credential['user']->ID, (string) $credential['uuid']);
                $this->step($steps, 'temporary credential revoked', true === $revoked, true === $revoked ? 'revoked' : ($revoked instanceof WP_Error ? $revoked->get_error_message() : 'revoke failed'));
            }
        }

        $passed = array() !== $steps;
        foreach ($steps as $step) {
            if (true !== $step['passed']) {
                $passed = false;
                break;
            }
        }

        return array('passed' => $passed, 'run_id' => $runId, 'time' => current_time('mysql'), 'steps' => $steps);
    }

    /** @return array{http:int,body:mixed} */
    private function modern(string $authorization, string $method, array $params = array(), string $name = ''): array
    {
        $headers = array('MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => $method);
        if ('' !== $name) {
            $headers['Mcp-Name'] = $name;
        }
        return $this->raw($authorization, $this->modernMessage($method, '2026-07-28', $params), $headers);
    }

    /** @return array{http:int,body:mixed} */
    private function legacy(string $authorization, string $version, string $method, array $params = array()): array
    {
        return $this->raw($authorization, array(
            'jsonrpc' => '2.0',
            'id' => 'http-smoke-' . substr(hash('sha256', $version . $method . wp_generate_uuid4()), 0, 12),
            'method' => $method,
            'params' => $params,
        ), array('MCP-Protocol-Version' => $version));
    }

    /** @return array<string,mixed> */
    private function modernMessage(string $method, string $version = '2026-07-28', array $params = array()): array
    {
        $params['_meta'] = array(
            'io.modelcontextprotocol/protocolVersion' => $version,
            'io.modelcontextprotocol/clientCapabilities' => array(),
            'io.modelcontextprotocol/clientInfo' => array('name' => 'wp-nerve-http-smoke', 'version' => WP_NERVE_VERSION),
        );
        return array(
            'jsonrpc' => '2.0',
            'id' => 'http-smoke-' . substr(hash('sha256', $method . wp_generate_uuid4()), 0, 12),
            'method' => $method,
            'params' => $params,
        );
    }

    /** @return array{http:int,body:mixed} */
    private function raw(string $authorization, array $message, array $headers): array
    {
        $body = wp_json_encode($message);
        if (! is_string($body)) {
            return array('http' => 0, 'body' => array('error' => 'JSON encoding failed.'));
        }

        $requestHeaders = array_merge(array('Content-Type' => 'application/json'), $headers);
        if ('' !== $authorization) {
            $requestHeaders['Authorization'] = $authorization;
        }

        $response = wp_remote_post(rest_url('wp-nerve/v1/mcp'), array(
            'body' => $body,
            'headers' => $requestHeaders,
            'redirection' => 0,
            'sslverify' => true,
            'timeout' => 15,
        ));
        if ($response instanceof WP_Error) {
            return array('http' => 0, 'body' => array('error' => $response->get_error_message()));
        }

        $raw = (string) wp_remote_retrieve_body($response);
        $decoded = json_decode($raw, true);
        return array(
            'http' => (int) wp_remote_retrieve_response_code($response),
            'body' => is_array($decoded) ? $decoded : array('raw' => $raw),
        );
    }

    private function rpcSuccess(array $response): bool
    {
        return 200 === $response['http'] && is_array($response['body']) && ! isset($response['body']['error']) && isset($response['body']['result']);
    }

    /** @return array<string,mixed> */
    private function rpcResult(array $response): array
    {
        return is_array($response['body']) && is_array($response['body']['result'] ?? null) ? $response['body']['result'] : array();
    }

    private function toolSuccess(array $response): bool
    {
        $result = $this->rpcResult($response);
        return $this->rpcSuccess($response) && false === ($result['isError'] ?? true);
    }

    private function detail(array $response): string
    {
        $body = $response['body'];
        if (is_array($body) && isset($body['code']) && is_string($body['code'])) {
            return 'HTTP ' . $response['http'] . ': ' . $body['code'];
        }
        if (is_array($body) && isset($body['error']) && is_array($body['error'])) {
            return 'HTTP ' . $response['http'] . ': ' . (string) ($body['error']['message'] ?? 'protocol error');
        }
        if (is_array($body) && isset($body['error']) && is_string($body['error'])) {
            return 'HTTP ' . $response['http'] . ': ' . $body['error'];
        }
        return 'HTTP ' . $response['http'] . ' PASS';
    }

    private function step(array &$steps, string $name, bool $passed, string $detail): void
    {
        $steps[] = array('name' => $name, 'passed' => $passed, 'detail' => $detail);
    }

    private function renderResult(array $result): void
    {
        $steps = is_array($result['steps'] ?? null) ? $result['steps'] : array();
        if (array() === $steps) {
            return;
        }
        ?>
        <p><strong><?php echo esc_html(true === ($result['passed'] ?? false) ? 'PASS' : 'FAIL'); ?></strong> — <?php echo esc_html((string) ($result['time'] ?? '')); ?> — <code><?php echo esc_html((string) ($result['run_id'] ?? '')); ?></code></p>
        <table class="widefat striped" style="max-width:1000px">
            <thead><tr><th><?php echo esc_html__('Step', 'wp-nerve'); ?></th><th><?php echo esc_html__('Result', 'wp-nerve'); ?></th><th><?php echo esc_html__('Detail', 'wp-nerve'); ?></th></tr></thead>
            <tbody>
            <?php foreach ($steps as $step) : ?>
                <tr><td><code><?php echo esc_html((string) $step['name']); ?></code></td><td><strong><?php echo esc_html(true === $step['passed'] ? 'PASS' : 'FAIL'); ?></strong></td><td><?php echo esc_html((string) $step['detail']); ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }
}
