<?php

/**
 * Safe wrapper around WordPress Application Password management.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Admin;

use WP_Error;
use WP_User;

final class ApplicationPasswords
{
    private const APP_ID = '6fb8a64d-780a-4fc0-9db4-2c70f73d7939';

    /** @return array<int, WP_User> */
    public function editableUsers(): array
    {
        $users = array();

        foreach (get_users(array('orderby' => 'display_name', 'order' => 'ASC')) as $user) {
            if ($user instanceof WP_User && $this->canManage($user)) {
                $users[] = $user;
            }
        }

        return $users;
    }

    /**
     * @return array{password: string, uuid: string, user: WP_User}|WP_Error
     */
    public function create(int $userId): array|WP_Error
    {
        $user = $this->managedUser($userId);

        if ($user instanceof WP_Error) {
            return $user;
        }

        if (! $this->isAvailable($user)) {
            return new WP_Error(
                'wp_nerve_application_passwords_unavailable',
                __('Application Passwords are not available for the selected user.', 'wp-nerve')
            );
        }

        $result = \WP_Application_Passwords::create_new_application_password(
            $user->ID,
            array(
                'app_id' => self::APP_ID,
                'name'   => 'WPNerve Agent',
            )
        );

        if ($result instanceof WP_Error) {
            return $result;
        }

        $password = is_string($result[0] ?? null) ? $result[0] : '';
        $item     = is_array($result[1] ?? null) ? $result[1] : array();
        $uuid     = is_string($item['uuid'] ?? null) ? $item['uuid'] : '';

        if ('' === $password || '' === $uuid) {
            return new WP_Error(
                'wp_nerve_application_password_invalid_result',
                __('WordPress created an invalid Application Password response.', 'wp-nerve')
            );
        }

        return array(
            'password' => $password,
            'uuid'     => $uuid,
            'user'     => $user,
        );
    }

    /**
     * @return array<int, array{uuid: string, name: string, created: int, last_used: int|null, last_ip: string}>
     */
    public function credentials(int $userId): array
    {
        $user = $this->managedUser($userId);

        if ($user instanceof WP_Error || ! class_exists('WP_Application_Passwords')) {
            return array();
        }

        $credentials = array();

        foreach (\WP_Application_Passwords::get_user_application_passwords($user->ID) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $appId = is_string($item['app_id'] ?? null) ? $item['app_id'] : '';
            $name  = is_string($item['name'] ?? null) ? $item['name'] : '';

            if (self::APP_ID !== $appId && ! ('' === $appId && 'WPNerve Agent' === $name)) {
                continue;
            }

            $uuid = is_string($item['uuid'] ?? null) ? $item['uuid'] : '';

            if ('' === $uuid) {
                continue;
            }

            $credentials[] = array(
                'uuid'      => $uuid,
                'name'      => '' !== $name ? $name : 'WPNerve Agent',
                'created'   => is_int($item['created'] ?? null) ? $item['created'] : 0,
                'last_used' => is_int($item['last_used'] ?? null) ? $item['last_used'] : null,
                'last_ip'   => is_string($item['last_ip'] ?? null) ? $item['last_ip'] : '',
            );
        }

        return $credentials;
    }

    public function revoke(int $userId, string $uuid): bool|WP_Error
    {
        $user = $this->managedUser($userId);

        if ($user instanceof WP_Error) {
            return $user;
        }

        $owned = false;

        foreach ($this->credentials($user->ID) as $credential) {
            if ($uuid === $credential['uuid']) {
                $owned = true;
                break;
            }
        }

        if (! $owned) {
            return new WP_Error(
                'wp_nerve_application_password_not_found',
                __('The selected WPNerve credential does not exist.', 'wp-nerve')
            );
        }

        $deleted = \WP_Application_Passwords::delete_application_password($user->ID, $uuid);

        if (true === $deleted || $deleted instanceof WP_Error) {
            return $deleted;
        }

        return new WP_Error(
            'wp_nerve_application_password_revoke_failed',
            __('WordPress could not revoke the selected WPNerve credential.', 'wp-nerve')
        );
    }

    /** @return true|WP_Error */
    public function test(WP_User $user, string $password): bool|WP_Error
    {
        $body = wp_json_encode(
            array(
                'jsonrpc' => '2.0',
                'id'      => 'credential-test',
                'method'  => 'server/discover',
                'params'  => array(
                    '_meta' => array(
                        'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                        'io.modelcontextprotocol/clientCapabilities' => array(),
                        'io.modelcontextprotocol/clientInfo' => array(
                            'name'    => 'wp-nerve-admin-test',
                            'version' => WP_NERVE_VERSION,
                        ),
                    ),
                ),
            )
        );

        if (! is_string($body)) {
            return new WP_Error(
                'wp_nerve_connection_test_encoding_failed',
                __('The connection test request could not be encoded.', 'wp-nerve')
            );
        }

        $response = wp_remote_post(
            rest_url('wp-nerve/v1/mcp'),
            array(
                'body'        => $body,
                'headers'     => array(
                    'Authorization'        => $this->authorizationHeader($user, $password),
                    'Content-Type'         => 'application/json',
                    'MCP-Protocol-Version' => '2026-07-28',
                    'Mcp-Method'           => 'server/discover',
                ),
                'redirection' => 0,
                'sslverify'   => true,
                'timeout'     => 10,
            )
        );

        if ($response instanceof WP_Error) {
            return new WP_Error(
                'wp_nerve_connection_test_unavailable',
                __('The credential was created, but the local connection test could not reach the MCP endpoint.', 'wp-nerve')
            );
        }

        $status  = wp_remote_retrieve_response_code($response);
        $payload = json_decode(wp_remote_retrieve_body($response), true);

        if (200 !== $status || ! is_array($payload) || isset($payload['error']) || ! isset($payload['result'])) {
            return new WP_Error(
                'wp_nerve_connection_test_failed',
                __('The credential was created, but the MCP endpoint rejected the connection test.', 'wp-nerve')
            );
        }

        return true;
    }

    public function authorizationHeader(WP_User $user, string $password): string
    {
        return 'Basic ' . base64_encode($user->user_login . ':' . $password);
    }

    private function managedUser(int $userId): WP_User|WP_Error
    {
        $user = get_userdata($userId);

        if (! $user instanceof WP_User || ! $this->canManage($user)) {
            return new WP_Error(
                'wp_nerve_application_password_user_forbidden',
                __('You cannot manage Application Passwords for the selected user.', 'wp-nerve')
            );
        }

        return $user;
    }

    private function canManage(WP_User $user): bool
    {
        return user_can($user, 'edit_posts') && current_user_can('edit_user', $user->ID);
    }

    private function isAvailable(WP_User $user): bool
    {
        return class_exists('WP_Application_Passwords')
            && function_exists('wp_is_application_passwords_available_for_user')
            && wp_is_application_passwords_available_for_user($user);
    }
}
