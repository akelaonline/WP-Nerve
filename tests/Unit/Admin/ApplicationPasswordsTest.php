<?php

/**
 * Application Password management tests.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Tests\Unit\Admin;

use WP_Error;
use WPNerve\Admin\ApplicationPasswords;
use WPNerve\Tests\Fixtures\WPState;
use WPNerve\Tests\Unit\TestCase;

final class ApplicationPasswordsTest extends TestCase
{
    private \WP_User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = new \WP_User(12);
        $this->user->user_login   = 'agent-user';
        $this->user->display_name = 'Agent User';
        $this->user->roles        = array('editor');

        WPState::$users[$this->user->ID] = $this->user;
    }

    public function testCreateUsesWordPressTupleAndWPNerveApplicationId(): void
    {
        $manager = new ApplicationPasswords();
        $result  = $manager->create($this->user->ID);

        self::assertIsArray($result);
        self::assertSame('xxxx xxxx xxxx xxxx xxxx xxxx', $result['password']);
        self::assertSame($this->user, $result['user']);
        self::assertSame(
            '6fb8a64d-780a-4fc0-9db4-2c70f73d7939',
            WPState::$applicationPasswords[$this->user->ID][0]['app_id']
        );
    }

    public function testCreateRejectsUserWhenApplicationPasswordsAreUnavailable(): void
    {
        WPState::$applicationPasswordsAvailable = false;

        $result = (new ApplicationPasswords())->create($this->user->ID);

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('wp_nerve_application_passwords_unavailable', $result->get_error_code());
        self::assertArrayNotHasKey($this->user->ID, WPState::$applicationPasswords);
    }

    public function testCredentialsExcludeApplicationPasswordsOwnedByOtherApps(): void
    {
        WPState::$applicationPasswords[$this->user->ID] = array(
            array(
                'uuid'      => 'foreign',
                'app_id'    => 'another-app',
                'name'      => 'Another app',
                'created'   => 1,
                'last_used' => null,
                'last_ip'   => '',
            ),
            array(
                'uuid'      => 'managed',
                'app_id'    => '6fb8a64d-780a-4fc0-9db4-2c70f73d7939',
                'name'      => 'WPNerve Agent',
                'created'   => 2,
                'last_used' => 3,
                'last_ip'   => '203.0.113.8',
            ),
        );

        $credentials = (new ApplicationPasswords())->credentials($this->user->ID);

        self::assertCount(1, $credentials);
        self::assertSame('managed', $credentials[0]['uuid']);
    }

    public function testCredentialsIncludeLegacyWPNerveCredentialWithoutApplicationId(): void
    {
        WPState::$applicationPasswords[$this->user->ID] = array(
            array(
                'uuid'      => 'legacy',
                'app_id'    => '',
                'name'      => 'WPNerve Agent',
                'created'   => 1,
                'last_used' => null,
                'last_ip'   => '',
            ),
        );

        $credentials = (new ApplicationPasswords())->credentials($this->user->ID);

        self::assertCount(1, $credentials);
        self::assertSame('legacy', $credentials[0]['uuid']);
    }

    public function testRevokeRefusesCredentialOwnedByAnotherApplication(): void
    {
        WPState::$applicationPasswords[$this->user->ID] = array(
            array('uuid' => 'foreign', 'app_id' => 'another-app', 'name' => 'Another app'),
        );

        $result = (new ApplicationPasswords())->revoke($this->user->ID, 'foreign');

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('wp_nerve_application_password_not_found', $result->get_error_code());
        self::assertCount(1, WPState::$applicationPasswords[$this->user->ID]);
    }

    public function testConnectionTestUsesBasicAuthenticationAndMcpDiscovery(): void
    {
        $result = (new ApplicationPasswords())->test($this->user, 'secret value');

        self::assertTrue($result);
        self::assertNotNull(WPState::$lastRemotePost);
        self::assertSame(
            'Basic ' . base64_encode('agent-user:secret value'),
            WPState::$lastRemotePost['args']['headers']['Authorization']
        );
        $body = json_decode((string) WPState::$lastRemotePost['args']['body'], true);

        self::assertIsArray($body);
        self::assertSame('server/discover', $body['method']);
    }

    public function testConnectionTestReturnsSpecificFailureWithoutLosingCredential(): void
    {
        WPState::$remotePostResponse = new WP_Error('http_request_failed', 'Loopback blocked.');

        $result = (new ApplicationPasswords())->test($this->user, 'secret value');

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('wp_nerve_connection_test_unavailable', $result->get_error_code());
    }
}
