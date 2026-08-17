<?php

/**
 * User, plugin, option, and system ability tests (privileged, opt-in).
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Tests\Unit\Abilities;

use WP_Error;
use WP_User;
use WPNerve\Abilities\AbilityRegistrar;
use WPNerve\Policy\PolicyEngine;
use WPNerve\Protocol\AbilityToolRegistry;
use WPNerve\Tests\Fixtures\WPState;
use WPNerve\Tests\Unit\TestCase;

final class AdminAbilitiesTest extends TestCase
{
    private AbilityToolRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $registrar = new AbilityRegistrar();
        $registrar->registerAbilities();

        $this->registry = new AbilityToolRegistry(new PolicyEngine());
    }

    public function testRegistersAdminAbilitiesWithRiskMetadata(): void
    {
        $expected = array(
            'wp-nerve/list-users'           => array('read', false),
            'wp-nerve/get-user'            => array('read', false),
            'wp-nerve/create-user'         => array('privileged', false),
            'wp-nerve/update-user'         => array('privileged', false),
            'wp-nerve/delete-user'         => array('destructive', false),
            'wp-nerve/list-plugins'         => array('read', false),
            'wp-nerve/activate-plugin'     => array('privileged', false),
            'wp-nerve/deactivate-plugin'   => array('privileged', false),
            'wp-nerve/upload-plugin'       => array('destructive', false),
            'wp-nerve/delete-plugin'       => array('destructive', false),
            'wp-nerve/get-option'          => array('privileged', false),
            'wp-nerve/update-option'       => array('privileged', false),
            'wp-nerve/list-options'         => array('privileged', false),
            'wp-nerve/get-transient' => array('privileged', false),
            'wp-nerve/debug-log'     => array('privileged', false),
        );

        $actual = array();

        foreach (WPState::$registeredAbilities as $ability) {
            $name = $ability->get_name();

            if (array_key_exists($name, $expected)) {
                $meta = $ability->get_meta_item('wp_nerve', array());
                $actual[$name] = array($meta['risk'], $meta['enabled_by_default']);
            }
        }

        self::assertSame($expected, $actual);
    }

    public function testAllAdminAbilitiesHiddenWithoutOptIn(): void
    {
        foreach (array('wp_nerve_list_users', 'wp_nerve_list_plugins', 'wp_nerve_get_option', 'wp_nerve_get_transient') as $tool) {
            $result = $this->registry->execute($tool, array());

            self::assertInstanceOf(WP_Error::class, $result);
            self::assertSame('wp_nerve_tool_not_found', $result->get_error_code(), $tool . ' should be hidden');
        }
    }

    public function testListUsersReturnsUsers(): void
    {
        $this->enableAdmin(array('read', 'privileged', 'destructive'));

        WPState::$users[1] = $this->user(1, 'admin', 'administrator');
        WPState::$users[2] = $this->user(2, 'editor', 'editor');

        $result = $this->registry->execute('wp_nerve_list_users', array());

        self::assertNotInstanceOf(WP_Error::class, $result);
        self::assertSame(2, $result['result']['total']);
        self::assertSame('admin', $result['result']['items'][0]['username']);
        self::assertArrayNotHasKey('email', $result['result']['items'][0]);
    }

    public function testGetUserIncludesEmail(): void
    {
        $this->enableAdmin(array('read', 'privileged', 'destructive'));

        WPState::$users[2] = $this->user(2, 'editor', 'editor');
        WPState::$users[2]->user_email = 'editor@example.com';

        $result = $this->registry->execute('wp_nerve_get_user', array('id' => 2));

        self::assertNotInstanceOf(WP_Error::class, $result);
        self::assertSame('editor@example.com', $result['result']['email']);
    }

    public function testCreateUserInsertsUser(): void
    {
        $this->enableAdmin(array('read', 'privileged', 'destructive'));

        WPState::$userCan = static fn (string $cap): bool => in_array($cap, array('edit_posts', 'create_users'), true);

        $result = $this->registry->execute('wp_nerve_create_user', array(
            'username' => 'nuevo',
            'email'    => 'nuevo@example.com',
            'role'     => 'editor',
        ));

        self::assertNotInstanceOf(WP_Error::class, $result);
        self::assertSame(900, $result['result']['id']);
        self::assertSame('nuevo', $result['result']['username']);
        self::assertSame(array('editor'), $result['result']['roles']);
        self::assertArrayHasKey('recovery', $result['result']);
    }

    public function testCreateAdministratorRequiresPromoteUsers(): void
    {
        $this->enableAdmin(array('read', 'privileged', 'destructive'));

        WPState::$userCan = static fn (string $cap): bool => in_array($cap, array('edit_posts', 'create_users'), true);

        $result = $this->registry->execute('wp_nerve_create_user', array(
            'username' => 'root',
            'email'    => 'root@example.com',
            'role'     => 'administrator',
        ));

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('wp_nerve_forbidden', $result->get_error_code());
    }

    public function testDeleteUserRemovesUser(): void
    {
        $this->enableAdmin(array('read', 'privileged', 'destructive'));

        WPState::$users[3] = $this->user(3, 'ghost', 'subscriber');
        WPState::$userCan  = static fn (string $cap, mixed $id = null): bool =>
            'edit_posts' === $cap || 'delete_users' === $cap || ('delete_user' === $cap && 3 === $id);

        $result = $this->registry->execute('wp_nerve_delete_user', array('id' => 3));

        self::assertNotInstanceOf(WP_Error::class, $result);
        self::assertSame(true, $result['result']['deleted']);
        self::assertArrayNotHasKey(3, WPState::$users);
    }

    public function testListPluginsReturnsActiveState(): void
    {
        $this->enableAdmin(array('read', 'privileged', 'destructive'));

        WPState::$plugins = array(
            'akela-seo/akela-seo.php' => array('Name' => 'Akela SEO', 'Version' => '0.7.43'),
            'hello.php'               => array('Name' => 'Hello Dolly', 'Version' => '1.7'),
        );
        WPState::$activePlugins = array('akela-seo/akela-seo.php');

        $result = $this->registry->execute('wp_nerve_list_plugins', array());

        self::assertNotInstanceOf(WP_Error::class, $result);
        self::assertSame(2, $result['result']['total']);
        self::assertTrue($result['result']['plugins'][0]['active']);
    }

    public function testActivatePluginActivates(): void
    {
        $this->enableAdmin(array('read', 'privileged', 'destructive'));

        WPState::$plugins = array('hello.php' => array('Name' => 'Hello', 'Version' => '1.0'));

        $result = $this->registry->execute('wp_nerve_activate_plugin', array('plugin' => 'hello.php'));

        self::assertNotInstanceOf(WP_Error::class, $result);
        self::assertSame(true, $result['result']['active']);
        self::assertSame(array('hello.php'), WPState::$activePlugins);
        self::assertSame('wp_nerve_deactivate_plugin', $result['result']['recovery']['undo']);
    }

    public function testDeactivatePluginDeactivates(): void
    {
        $this->enableAdmin(array('read', 'privileged', 'destructive'));

        WPState::$plugins = array('hello.php' => array('Name' => 'Hello', 'Version' => '1.0'));
        WPState::$activePlugins = array('hello.php');

        $result = $this->registry->execute('wp_nerve_deactivate_plugin', array('plugin' => 'hello.php'));

        self::assertNotInstanceOf(WP_Error::class, $result);
        self::assertSame(false, $result['result']['active']);
        self::assertSame(array(), WPState::$activePlugins);
    }

    public function testDeletePluginDeletes(): void
    {
        $this->enableAdmin(array('read', 'privileged', 'destructive'));

        WPState::$plugins = array('hello.php' => array('Name' => 'Hello', 'Version' => '1.0'));

        $result = $this->registry->execute('wp_nerve_delete_plugin', array('plugin' => 'hello.php'));

        self::assertNotInstanceOf(WP_Error::class, $result);
        self::assertSame(true, $result['result']['deleted']);
        self::assertSame(array('hello.php'), WPState::$deletedPlugins);
    }

    public function testUploadPluginUnzips(): void
    {
        $this->enableAdmin(array('read', 'privileged', 'destructive'));

        $result = $this->registry->execute('wp_nerve_upload_plugin', array(
            'filename' => 'plugin.zip',
            'content'  => base64_encode('fake-zip'),
        ));

        self::assertNotInstanceOf(WP_Error::class, $result);
        self::assertCount(1, WPState::$unzippedFiles);
        self::assertStringContainsString('plugins', WPState::$unzippedFiles[0]['to']);
    }

    public function testGetOptionReturnsValue(): void
    {
        $this->enableAdmin(array('read', 'privileged', 'destructive'));

        WPState::$options['siteurl'] = 'https://example.test';

        $result = $this->registry->execute('wp_nerve_get_option', array('key' => 'siteurl'));

        self::assertNotInstanceOf(WP_Error::class, $result);
        self::assertSame('https://example.test', $result['result']['value']);
    }

    public function testUpdateOptionReturnsPrevious(): void
    {
        $this->enableAdmin(array('read', 'privileged', 'destructive'));

        WPState::$options['wp_nerve_test'] = 'viejo';

        $result = $this->registry->execute('wp_nerve_update_option', array('key' => 'wp_nerve_test', 'value' => 'nuevo'));

        self::assertNotInstanceOf(WP_Error::class, $result);
        self::assertSame('nuevo', $result['result']['value']);
        self::assertSame('viejo', $result['result']['previous']);
        self::assertSame('nuevo', WPState::$options['wp_nerve_test']);
    }

    public function testListOptionsReturnsKeysOnly(): void
    {
        $this->enableAdmin(array('read', 'privileged', 'destructive'));

        WPState::$options['a'] = 'secret-value';
        WPState::$options['b'] = array('nested');

        $result = $this->registry->execute('wp_nerve_list_options', array());

        self::assertNotInstanceOf(WP_Error::class, $result);
        self::assertContains('a', $result['result']['options']);
        self::assertContains('b', $result['result']['options']);
        self::assertStringNotContainsString('secret', json_encode($result['result']));
    }

    public function testGetTransientReturnsValue(): void
    {
        $this->enableAdmin(array('read', 'privileged', 'destructive'));

        WPState::$transients['wp_nerve_flush'] = 'yes';

        $result = $this->registry->execute('wp_nerve_get_transient', array('transient' => 'wp_nerve_flush'));

        self::assertNotInstanceOf(WP_Error::class, $result);
        self::assertSame('yes', $result['result']['value']);
    }

    private function enableAdmin(array $classes): void
    {
        WPState::$options['wp_nerve_enabled_risk_classes'] = $classes;

        add_filter('wp_nerve_ability_is_enabled', static function (bool $enabled, $ability): bool {
            $name = $ability->get_name();

            return $enabled
                || str_contains($name, 'user')
                || str_contains($name, 'plugin')
                || str_contains($name, 'option')
                || str_contains($name, 'transient')
                || str_contains($name, 'debug-log');
        }, 10, 2);
    }

    private function user(int $id, string $login, string $role): WP_User
    {
        $user = new WP_User($id);

        $user->user_login   = $login;
        $user->display_name = ucfirst($login);
        $user->roles        = array($role);

        return $user;
    }
}
