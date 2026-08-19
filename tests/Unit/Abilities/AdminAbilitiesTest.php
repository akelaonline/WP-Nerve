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
            'wp-nerve/list-options'        => array('privileged', false),
            'wp-nerve/get-transient'       => array('privileged', false),
            'wp-nerve/debug-log'           => array('privileged', false),
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

    public function testCreateAdministratorRequiresSeparateOptInEvenWithPromoteUsers(): void
    {
        $this->enableAdmin(array('read', 'privileged', 'destructive'));

        WPState::$userCan = static fn (string $cap): bool => in_array($cap, array('edit_posts', 'create_users', 'promote_users'), true);

        $result = $this->registry->execute('wp_nerve_create_user', array(
            'username' => 'root',
            'email'    => 'root@example.com',
            'role'     => 'administrator',
        ));

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('wp_nerve_protected_role', $result->get_error_code());
    }

    public function testCreateAdministratorCanBeExplicitlyAllowed(): void
    {
        $this->enableAdmin(array('read', 'privileged', 'destructive'));
        add_filter('wp_nerve_allow_administrator_user_management', static fn (): bool => true);

        WPState::$userCan = static fn (string $cap): bool => in_array($cap, array('edit_posts', 'create_users', 'promote_users'), true);

        $result = $this->registry->execute('wp_nerve_create_user', array(
            'username' => 'root',
            'email'    => 'root@example.com',
            'role'     => 'administrator',
        ));

        self::assertNotInstanceOf(WP_Error::class, $result);
        self::assertSame(array('administrator'), $result['result']['roles']);
    }

    public function testSensitiveSelfUserChangesAreBlocked(): void
    {
        $this->enableAdmin(array('read', 'privileged', 'destructive'));
        WPState::$users[1] = $this->user(1, 'agent', 'editor');

        $result = $this->registry->execute('wp_nerve_update_user', array('id' => 1, 'email' => 'attacker@example.com'));

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('wp_nerve_self_user_change', $result->get_error_code());
    }

    public function testExistingPasswordAndEmailChangesRequireSeparateOptIns(): void
    {
        $this->enableAdmin(array('read', 'privileged', 'destructive'));
        WPState::$users[2] = $this->user(2, 'editor', 'editor');

        $password = $this->registry->execute('wp_nerve_update_user', array('id' => 2, 'password' => 'strong-password-123'));
        $email    = $this->registry->execute('wp_nerve_update_user', array('id' => 2, 'email' => 'new@example.com'));

        self::assertInstanceOf(WP_Error::class, $password);
        self::assertSame('wp_nerve_password_change_disabled', $password->get_error_code());
        self::assertInstanceOf(WP_Error::class, $email);
        self::assertSame('wp_nerve_email_change_disabled', $email->get_error_code());
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

    public function testDeleteAuthenticatedAgentUserIsBlocked(): void
    {
        $this->enableAdmin(array('read', 'privileged', 'destructive'));
        WPState::$users[1] = $this->user(1, 'agent', 'editor');

        $result = $this->registry->execute('wp_nerve_delete_user', array('id' => 1));

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('wp_nerve_self_user_delete', $result->get_error_code());
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

    public function testUploadPluginRequiresChecksumAndUnzipsValidPackage(): void
    {
        $this->requireZipExtension();
        $this->enableAdmin(array('read', 'privileged', 'destructive'));
        $zip = $this->zipPackage(array(
            'plugin/plugin.php' => "<?php\n/* Plugin Name: Fixture Plugin */\n",
        ));

        $result = $this->registry->execute('wp_nerve_upload_plugin', array(
            'filename' => 'plugin.zip',
            'content'  => base64_encode($zip),
            'sha256'   => hash('sha256', $zip),
        ));

        self::assertNotInstanceOf(WP_Error::class, $result);
        self::assertCount(1, WPState::$unzippedFiles);
        self::assertSame('wp-content/plugins', $result['result']['installed_to']);
        self::assertSame(hash('sha256', $zip), $result['result']['sha256']);
        self::assertSame(array(), WPState::$lastUpload, 'Plugin packages are staged privately, not in public uploads.');
    }

    public function testUploadPluginRejectsChecksumMismatch(): void
    {
        $this->enableAdmin(array('read', 'privileged', 'destructive'));
        $zip = "PK\x03\x04fake-plugin-package";

        $result = $this->registry->execute('wp_nerve_upload_plugin', array(
            'filename' => 'plugin.zip',
            'content'  => base64_encode($zip),
            'sha256'   => str_repeat('0', 64),
        ));

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('wp_nerve_checksum_mismatch', $result->get_error_code());
        self::assertCount(0, WPState::$unzippedFiles);
    }

    public function testUploadPluginDoesNotReplaceMatchingInstalledSlug(): void
    {
        $this->enableAdmin(array('read', 'privileged', 'destructive'));
        WPState::$plugins = array('plugin/plugin.php' => array('Name' => 'Plugin', 'Version' => '1.0'));
        $zip = "PK\x03\x04fake-plugin-package";

        $result = $this->registry->execute('wp_nerve_upload_plugin', array(
            'filename' => 'plugin.zip',
            'content'  => base64_encode($zip),
            'sha256'   => hash('sha256', $zip),
        ));

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('wp_nerve_plugin_exists', $result->get_error_code());
    }

    public function testUploadPluginRejectsTraversalInsideArchive(): void
    {
        $this->requireZipExtension();
        $this->enableAdmin(array('read', 'privileged', 'destructive'));
        $zip = $this->zipPackage(array('../escape.php' => '<?php echo "escape";'));

        $result = $this->registry->execute('wp_nerve_upload_plugin', array(
            'filename' => 'innocent.zip',
            'content'  => base64_encode($zip),
            'sha256'   => hash('sha256', $zip),
        ));

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('wp_nerve_unsafe_archive_path', $result->get_error_code());
        self::assertCount(0, WPState::$unzippedFiles);
    }

    public function testUploadPluginRejectsCaseCollidingPaths(): void
    {
        $this->requireZipExtension();
        $this->enableAdmin(array('read', 'privileged', 'destructive'));
        $zip = $this->zipPackage(array(
            'plugin/Foo.php' => '<?php echo 1;',
            'plugin/foo.php' => '<?php echo 2;',
        ));

        $result = $this->registry->execute('wp_nerve_upload_plugin', array(
            'filename' => 'case-test.zip',
            'content'  => base64_encode($zip),
            'sha256'   => hash('sha256', $zip),
        ));

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('wp_nerve_duplicate_archive_path', $result->get_error_code());
        self::assertCount(0, WPState::$unzippedFiles);
    }

    public function testUploadPluginRejectsExistingInternalPluginRootEvenWithDifferentFilename(): void
    {
        $this->requireZipExtension();
        $this->enableAdmin(array('read', 'privileged', 'destructive'));
        WPState::$plugins = array('existing/existing.php' => array('Name' => 'Existing', 'Version' => '1.0'));
        $zip = $this->zipPackage(array('existing/evil.php' => '<?php echo "overwrite";'));

        $result = $this->registry->execute('wp_nerve_upload_plugin', array(
            'filename' => 'innocent.zip',
            'content'  => base64_encode($zip),
            'sha256'   => hash('sha256', $zip),
        ));

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('wp_nerve_plugin_exists', $result->get_error_code());
        self::assertCount(0, WPState::$unzippedFiles);
    }

    public function testGetOptionReturnsAllowlistedValue(): void
    {
        $this->enableAdmin(array('read', 'privileged', 'destructive'));
        WPState::$options['blogname'] = 'Safe title';

        $result = $this->registry->execute('wp_nerve_get_option', array('key' => 'blogname'));

        self::assertNotInstanceOf(WP_Error::class, $result);
        self::assertSame('Safe title', $result['result']['value']);
    }

    public function testProtectedOptionCannotBeReadEvenWhenFilterTriesToAllowIt(): void
    {
        $this->enableAdmin(array('read', 'privileged', 'destructive'));
        WPState::$options['siteurl'] = 'https://example.test';
        add_filter('wp_nerve_allowed_option_keys', static fn (array $keys): array => array_merge($keys, array('siteurl')));

        $result = $this->registry->execute('wp_nerve_get_option', array('key' => 'siteurl'));

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('wp_nerve_protected_option', $result->get_error_code());
    }

    public function testCredentialLikeOptionCannotBeReadEvenWhenAllowlisted(): void
    {
        $this->enableAdmin(array('read', 'privileged', 'destructive'));
        WPState::$options['vendor_api_token'] = 'super-secret';
        add_filter('wp_nerve_allowed_option_keys', static fn (array $keys): array => array_merge($keys, array('vendor_api_token')));

        $result = $this->registry->execute('wp_nerve_get_option', array('key' => 'vendor_api_token'));

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('wp_nerve_protected_option', $result->get_error_code());
    }

    public function testUpdateOptionReturnsPreviousForSafeKey(): void
    {
        $this->enableAdmin(array('read', 'privileged', 'destructive'));
        WPState::$options['blogdescription'] = 'viejo';

        $result = $this->registry->execute('wp_nerve_update_option', array('key' => 'blogdescription', 'value' => 'nuevo'));

        self::assertNotInstanceOf(WP_Error::class, $result);
        self::assertSame('nuevo', $result['result']['value']);
        self::assertSame('viejo', $result['result']['previous']);
        self::assertSame('nuevo', WPState::$options['blogdescription']);
    }

    public function testListOptionsReturnsOnlyAllowlistedKeys(): void
    {
        $this->enableAdmin(array('read', 'privileged', 'destructive'));
        WPState::$options['blogname']        = 'Visible';
        WPState::$options['blogdescription'] = 'Visible too';
        WPState::$options['api_token']       = 'secret-value';
        WPState::$options['siteurl']         = 'https://example.test';

        $result = $this->registry->execute('wp_nerve_list_options', array());

        self::assertNotInstanceOf(WP_Error::class, $result);
        self::assertContains('blogname', $result['result']['options']);
        self::assertContains('blogdescription', $result['result']['options']);
        self::assertNotContains('api_token', $result['result']['options']);
        self::assertNotContains('siteurl', $result['result']['options']);
        self::assertStringNotContainsString('secret', json_encode($result['result']));
    }

    public function testGetTransientRequiresExactAllowlist(): void
    {
        $this->enableAdmin(array('read', 'privileged', 'destructive'));
        WPState::$transients['safe_cache'] = 'yes';

        $denied = $this->registry->execute('wp_nerve_get_transient', array('transient' => 'safe_cache'));
        self::assertInstanceOf(WP_Error::class, $denied);
        self::assertSame('wp_nerve_protected_transient', $denied->get_error_code());

        add_filter('wp_nerve_allowed_transient_keys', static fn (): array => array('safe_cache'));
        $allowed = $this->registry->execute('wp_nerve_get_transient', array('transient' => 'safe_cache'));

        self::assertNotInstanceOf(WP_Error::class, $allowed);
        self::assertSame('yes', $allowed['result']['value']);
    }

    public function testSecretNamedTransientRemainsBlockedWhenAllowlisted(): void
    {
        $this->enableAdmin(array('read', 'privileged', 'destructive'));
        WPState::$transients['oauth_token'] = 'secret';
        add_filter('wp_nerve_allowed_transient_keys', static fn (): array => array('oauth_token'));

        $result = $this->registry->execute('wp_nerve_get_transient', array('transient' => 'oauth_token'));

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('wp_nerve_protected_transient', $result->get_error_code());
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

    private function requireZipExtension(): void
    {
        if (! class_exists(\ZipArchive::class)) {
            self::markTestSkipped('The secure plugin-upload path requires the PHP Zip extension.');
        }
    }

    /**
     * Build a small stored ZIP archive without relying on ZipArchive for fixture creation.
     *
     * @param array<string, string> $files
     */
    private function zipPackage(array $files): string
    {
        $body      = '';
        $directory = '';
        $offset    = 0;
        $count     = 0;

        foreach ($files as $name => $data) {
            $nameLength = strlen($name);
            $dataLength = strlen($data);
            $crc        = crc32($data);

            $local = pack(
                'VvvvvvVVVvv',
                0x04034b50,
                20,
                0,
                0,
                0,
                0,
                $crc,
                $dataLength,
                $dataLength,
                $nameLength,
                0
            ) . $name . $data;

            $central = pack(
                'VvvvvvvVVVvvvvvVV',
                0x02014b50,
                20,
                20,
                0,
                0,
                0,
                0,
                $crc,
                $dataLength,
                $dataLength,
                $nameLength,
                0,
                0,
                0,
                0,
                0,
                $offset
            ) . $name;

            $body .= $local;
            $directory .= $central;
            $offset += strlen($local);
            ++$count;
        }

        $end = pack(
            'VvvvvVVv',
            0x06054b50,
            0,
            0,
            $count,
            $count,
            strlen($directory),
            strlen($body),
            0
        );

        return $body . $directory . $end;
    }
}
