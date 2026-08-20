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

    private const RESULT_TRANSIENT_PREFIX = 'wp_nerve_http_smoke_';

    public function registerMenu(): void
    {
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

        $key    = self::RESULT_TRANSIENT_PREFIX . get_current_user_id();
        $result = get_transient($key);
        $result = is_array($result) ? $result : array();
        $url    = wp_nonce_url(
            admin_url('tools.php?page=wp-nerve-http-smoke&wp_nerve_http_smoke=1'),
            self::NONCE_ACTION,
            'wp_nerve_http_nonce'
        );
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('WPNerve authenticated HTTP smoke', 'wp-nerve'); ?></h1>
            <p>
                <?php
                echo esc_html__(
                    'Creates a temporary WordPress Application Password, calls the public HTTPS MCP endpoint through WordPress HTTP, validates modern and legacy wire contracts plus security failures, and revokes the credential in the same run.',
                    'wp-nerve'
                );
                ?>
            </p>
            <p><code><?php echo esc_html(rest_url('wp-nerve/v1/mcp')); ?></code></p>
            <p>
                <a class="button button-primary" href="<?php echo esc_url($url); ?>">
                    <?php echo esc_html__('Run authenticated HTTP smoke', 'wp-nerve'); ?>
                </a>
            </p>
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
        $nonce     = sanitize_key((string) wp_unslash($_GET['wp_nerve_http_nonce']));

        if ('1' !== $requested || ! wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            return;
        }

        $result = $this->run();
        set_transient(
            self::RESULT_TRANSIENT_PREFIX . get_current_user_id(),
            $result,
            10 * MINUTE_IN_SECONDS
        );
    }

    /** @return array<string, mixed> */
    private function run(): array
    {
        $steps       = array();
        $runId       = substr(hash('sha256', wp_generate_uuid4() . microtime(true)), 0, 16);
        $credentials = new ApplicationPasswords();
        $created     = null;

        try {
            $created = $credentials->create(get_current_user_id());

            if ($created instanceof WP_Error) {
                throw new \RuntimeException($created->get_error_message());
            }

            $user     = $created['user'];
            $password = $created['password'];
            $header   = $credentials->authorizationHeader($user, $password);

            $this->step($steps, 'temporary credential', true, 'created for HTTP smoke');

            $discover = $this->modernRequest($header, 'server/discover');
            $this->step($steps, 'modern server/discover', $this->rpcSuccess($discover), $this->detail($discover));

            $tools      = $this->modernRequest($header, 'tools/list');
            $toolsBody  = $this->rpcResult($tools);
            $toolsCount = is_array($toolsBody['tools'] ?? null) ? count($toolsBody['tools']) : 0;
            $this->step($steps, 'modern tools/list', $this->rpcSuccess($tools) && 53 === $toolsCount, $toolsCount . ' tools returned');

            $status = $this->modernRequest(
                $header,
                'tools/call',
                array('name' => 'wp_nerve_site_status', 'arguments' => array()),
                'wp_nerve_site_status'
            );
            $this->step($steps, 'modern site-status', $this->toolSuccess($status), $this->detail($status));

            foreach (array('2025-11-25', '2025-06-18') as $version) {
                $initialize = $this->legacyRequest(
                    $header,
                    $version,
                    'initialize',
                    array(
                        'protocolVersion' => $version,
                        'capabilities'    => array(),
                        'clientInfo'      => array(
                            'name'    => 'wp-nerve-http-smoke',
                            'version' => WP_NERVE_VERSION,
                        ),
                    )
                );
                $initialized = $this->rpcResult($initialize);
                $this->step(
                    $steps,
                    'legacy initialize ' . $version,
                    $this->rpcSuccess($initialize) && $version === ($initialized['protocolVersion'] ?? null),
                    $this->detail($initialize)
                );

                $legacyTools = $this->legacyRequest($header, $version, 'tools/list');
                $legacyBody  = $this->rpcResult($legacyTools);
                $legacyCount = is_array($legacyBody['tools'] ?? null) ? count($legacyBody['tools']) : 0;
                $this->step(
                    $steps,
                    'legacy tools/list ' . $version,
                    $this->rpcSuccess($legacyTools) && 53 === $legacyCount,
                    $legacyCount . ' tools returned'
                );
            }

            $unauthenticated = $this->rawRequest('', $this->modernMessage('server/discover'), array(
                'MCP-Protocol-Version' => '2026-07-28',
                'Mcp-Method'           => 'server/discover',
            ));
            $this->step($steps, 'unauthenticated request', 401 === $unauthenticated['http'], $this->detail($unauthenticated));

            $badOrigin = $this->rawRequest($header, $this->modernMessage('server/discover'), array(
                'MCP-Protocol-Version' => '2026-07-28',
                'Mcp-Method'           => 'server/discover',
                'Origin'               => 'https://attacker.invalid',
            ));
            $this->step($steps, 'hostile Origin', 403 === $badOrigin['http'], $this->detail($badOrigin));

            $methodMismatch = $this->rawRequest($header, $this->modernMessage('server/discover'), array(
                'MCP-Protocol-Version' => '2026-07-28',
                'Mcp-Method'           => 'tools/list',
            ));
            $this->step($steps, 'mirrored method mismatch', 400 === $methodMismatch['http'], $this->detail($methodMismatch));

            $unsupported = $this->rawRequest($header, $this->modernMessage('server/discover', '2099-01-01'), array(
                'MCP-Protocol-Version' => '2099-01-01',
                'Mcp-Method'           => 'server/discover',
            ));
            $this->step($steps, 'unsupported protocol', 400 === $unsupported['http'], $this->detail($unsupported));
        } catch (Throwable $throwable) {
            $this->step($steps, 'http-smoke-runner', false, $throwable->getMessage());
        } finally {
            if (is_array($created) && isset($created['user'], $created['uuid']) && $created['user'] instanceof WP_User) {
                $revoked = $credentials->revoke($created['user']->ID, (string) $created['uuid']);
                $this->step(
                    $steps,
                    'temporary credential revoked',
                    true === $revoked,
                    true === $revoked ? 'revoked' : ($revoked instanceof WP_Error ? $revoked->get_error_message() : 'revoke failed')
                );
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
    private function modernRequest(string $authorization, string $method, array $params = array(), string $name = ''): array
    {
        $headers = array(
            'MCP-Protocol-Version' => '2026-07-28',
            'Mcp-Method'           => $method,
        );

        if ('' !== $name) {
            $headers['Mcp-Name'] = $name;
        }

        return $this->rawRequest($authorization, $this->modernMessage($method, '2026-07-28', $params), $headers);
    }

    /**
     * @param array<string, mixed> $params
     * @return array{http: int, body: mixed}
     */
    private function legacyRequest(string $authorization, string $version, string $method, array $params = array()): array
    {
        $message = array(
            'jsonrpc' => '2.0',
            'id'      => 'http-smoke-' . substr(hash('sha256', $version . $method . wp_generate_uuid4()), 0, 12),
            'method'  => $method,
            'params'  => $params,
        );

        return $this->rawRequest(
            $authorization,
            $message,
            array('MCP-Protocol-Version' => $version)
        );
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function modernMessage(string $method, string $version = '2026-07-28', array $params = array()): array
    {
        $params['_meta'] = array(
            'io.modelcontextprotocol/protocolVersion'    => $version,
            'io.modelcontextprotocol/clientCapabilities' => array(),
            'io.modelcontextprotocol/clientInfo'         => array(
                'name'    => 'wp-nerve-http-smoke',
                'version' => WP_NERVE_VERSION,
            ),
        );

        return array(
            'jsonrpc' => '2.0',
            'id'      => 'http-smoke-' . substr(hash('sha256', $method . wp_generate_uuid4()), 0, 12),
            'method'  => $method,
            'params'  => $params,
        );
    }

    /**
     * @param array<string, mixed>  $message
     * @param array<string, string> $headers
     * @return array{http: int, body: mixed}
     */
    private function rawRequest(string $authorization, array $message, array $headers): array
    {
        $body = wp_json_encode($message);

        if (! is_string($body)) {
            return array('http' => 0, 'body' => array('error' => 'JSON encoding failed.'));
        }

        $requestHeaders = array_merge(array('Content-Type' => 'application/json'), $headers);
        if ('' !== $authorization) {
            $requestHeaders['Authorization'] = $authorization;
        }

        $response = wp_remote_post(
            rest_url('wp-nerve/v1/mcp'),
            array(
                'body'        => $body,
                'headers'     => $requestHeaders,
                'redirection' => 0,
                'sslverify'   => true,
                'timeout'     => 15,
            )
        );

        if ($response instanceof WP_Error) {
            return array('http' => 0, 'body' => array('error' => $response->get_error_message()));
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $raw    = (string) wp_remote_retrieve_body($response);
        $data   = json_decode($raw, true);

        return array('http' => $status, 'body' => is_array($data) ? $data : array('raw' => $raw));
    }

    /** @param array{http: int, body: mixed} $response */
    private function rpcSuccess(array $response): bool
    {
        return 200 === $response['http']
            && is_array($response['body'])
            && ! isset($response['body']['error'])
            && isset($response['body']['result']);
    }

    /** @return array<string, mixed> */
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

    /** @param array{http: int, body: mixed} $response */
    private function detail(array $response): string
    {
        $body = $response['body'];

        if (! is_array($body)) {
            return 'HTTP ' . $response['http'];
        }
        if (isset($body['code']) && is_string($body['code'])) {
            return 'HTTP ' . $response['http'] . ': ' . $body['code'];
        }
        if (isset($body['error']) && is_array($body['error'])) {
            return 'HTTP ' . $response['http'] . ': ' . (string) ($body['error']['message'] ?? 'protocol error');
        }
        if (isset($body['error']) && is_string($body['error'])) {
            return 'HTTP ' . $response['http'] . ': ' . $body['error'];
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

    /** @param array<string, mixed> $result */
    private function renderResult(array $result): void
    {
        $steps = is_array($result['steps'] ?? null) ? $result['steps'] : array();

        if (array() === $steps) {
            return;
        }
        ?>
        <p>
            <strong><?php echo esc_html(true === ($result['passed'] ?? false) ? 'PASS' : 'FAIL'); ?></strong>
            — <?php echo esc_html((string) ($result['time'] ?? '')); ?>
            — <code><?php echo esc_html((string) ($result['run_id'] ?? '')); ?></code>
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
}
