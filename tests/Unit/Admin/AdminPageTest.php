<?php

/**
 * AdminPage unit tests.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Tests\Unit\Admin;

use WPNerve\Admin\AdminPage;
use WPNerve\Tests\Fixtures\WPState;
use WPNerve\Tests\Unit\TestCase;

final class AdminPageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $user = new \WP_User(WPState::$currentUserId);
        $user->user_login   = 'agent-editor';
        $user->display_name = 'Agent Editor';
        $user->roles        = array('editor');

        WPState::$users[$user->ID] = $user;
    }

    public function testRegisterMenuAddsManagementPage(): void
    {
        $page = new AdminPage();

        $page->registerMenu();

        self::assertCount(1, WPState::$managementPages);
        self::assertSame('wp-nerve', WPState::$managementPages[0][3]);
        self::assertSame('manage_options', WPState::$managementPages[0][2]);
    }

    public function testRenderRequiresManageOptionsCapability(): void
    {
        WPState::$userCan = false;

        $page = new AdminPage();

        ob_start();
        $page->render();
        $output = ob_get_clean();

        self::assertSame('', $output);
    }

    public function testRenderShowsEndpointAndSecurityNotes(): void
    {
        $page = new AdminPage();

        ob_start();
        $page->render();
        $output = (string) ob_get_clean();

        self::assertStringContainsString('wp-nerve/v1/mcp', $output);
        self::assertStringContainsString('Application Password', $output);
        self::assertStringContainsString('edit_posts', $output);
        self::assertStringContainsString('2026-07-28', $output);
    }

    public function testRenderShowsRiskTogglesAndClientSnippets(): void
    {
        $page = new AdminPage();

        ob_start();
        $page->render();
        $output = (string) ob_get_clean();

        self::assertStringContainsString('Risk classes', $output);
        self::assertStringContainsString('wp_nerve_risk_classes[]', $output);
        self::assertStringContainsString('Claude Code', $output);
        self::assertStringContainsString('BASE64_USERNAME_COLON_APPLICATION_PASSWORD', $output);
        self::assertStringContainsString('Generate WPNerve credential', $output);
        self::assertStringContainsString('Agent Editor', $output);
    }

    public function testHandleActionsSavesRiskClasses(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- test harness.
        $_POST['wp_nerve_admin']         = 'nonce';
        $_POST['wp_nerve_action']        = 'enable_risk_classes';
        $_POST['wp_nerve_risk_classes']  = array('read', 'write', 'privileged', 'destructive');

        $page = new AdminPage();
        $page->handleActions();

        self::assertSame(
            array('read', 'write', 'privileged', 'destructive'),
            WPState::$options['wp_nerve_enabled_risk_classes']
        );
        self::assertIsArray(get_transient('wp_nerve_admin_notice'));

        unset($_POST['wp_nerve_admin'], $_POST['wp_nerve_action'], $_POST['wp_nerve_risk_classes']);
    }

    public function testHandleActionsRejectsInvalidRiskClasses(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- test harness.
        $_POST['wp_nerve_admin']        = 'nonce';
        $_POST['wp_nerve_action']       = 'enable_risk_classes';
        $_POST['wp_nerve_risk_classes'] = array('read', 'dangerous', 'hack');

        $page = new AdminPage();
        $page->handleActions();

        self::assertSame(array('read'), WPState::$options['wp_nerve_enabled_risk_classes']);

        unset($_POST['wp_nerve_admin'], $_POST['wp_nerve_action'], $_POST['wp_nerve_risk_classes']);
    }

    public function testHandleActionsGeneratesApplicationPassword(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- test harness.
        $_POST['wp_nerve_admin']   = 'nonce';
        $_POST['wp_nerve_action']  = 'generate_app_password';
        $_POST['wp_nerve_user_id'] = (string) WPState::$currentUserId;

        $page = new AdminPage();
        $page->handleActions();

        self::assertSame(WPState::$currentUserId, \WP_Application_Passwords::$lastUserId);
        self::assertArrayNotHasKey('wp_nerve_admin_notice', WPState::$transients);
        self::assertNotNull(WPState::$lastRemotePost);
        self::assertStringStartsWith(
            'Basic ',
            (string) WPState::$lastRemotePost['args']['headers']['Authorization']
        );

        ob_start();
        $page->render();
        $output = (string) ob_get_clean();

        self::assertStringContainsString('xxxx xxxx xxxx xxxx xxxx xxxx', $output);
        self::assertStringContainsString('connection test passed', $output);
        self::assertStringContainsString(
            base64_encode('agent-editor:xxxx xxxx xxxx xxxx xxxx xxxx'),
            $output
        );

        unset($_POST['wp_nerve_admin'], $_POST['wp_nerve_action'], $_POST['wp_nerve_user_id']);
    }

    public function testHandleActionsRevokesOnlyManagedCredential(): void
    {
        \WP_Application_Passwords::create_new_application_password(
            WPState::$currentUserId,
            array(
                'app_id' => '6fb8a64d-780a-4fc0-9db4-2c70f73d7939',
                'name'   => 'WPNerve Agent',
            )
        );

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- test harness.
        $_POST['wp_nerve_admin']             = 'nonce';
        $_POST['wp_nerve_action']            = 'revoke_app_password';
        $_POST['wp_nerve_user_id']           = (string) WPState::$currentUserId;
        $_POST['wp_nerve_app_password_uuid'] = '11111111-2222-4333-8444-555555555555';

        $page = new AdminPage();
        $page->handleActions();

        self::assertSame(array(), WPState::$applicationPasswords[WPState::$currentUserId]);

        ob_start();
        $page->render();
        $output = (string) ob_get_clean();

        self::assertStringContainsString('credential revoked', $output);

        unset(
            $_POST['wp_nerve_admin'],
            $_POST['wp_nerve_action'],
            $_POST['wp_nerve_user_id'],
            $_POST['wp_nerve_app_password_uuid']
        );
    }

    public function testReadOnlyUserSelectionShowsExistingCredentialsWithoutCreatingOne(): void
    {
        $other = new \WP_User(22);
        $other->user_login   = 'content-agent';
        $other->display_name = 'Content Agent';
        $other->roles        = array('editor');
        WPState::$users[$other->ID] = $other;

        \WP_Application_Passwords::create_new_application_password(
            $other->ID,
            array(
                'app_id' => '6fb8a64d-780a-4fc0-9db4-2c70f73d7939',
                'name'   => 'WPNerve Agent',
            )
        );

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only test selection.
        $_GET['wp_nerve_user_id'] = (string) $other->ID;

        $page = new AdminPage();

        ob_start();
        $page->render();
        $output = (string) ob_get_clean();

        self::assertStringContainsString('11111111-2222-4333-8444-555555555555', $output);
        self::assertCount(1, WPState::$applicationPasswords[$other->ID]);

        unset($_GET['wp_nerve_user_id']);
    }

    public function testHandleActionsIgnoresInvalidNonce(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- test harness.
        $_POST['wp_nerve_admin']  = 'nonce';
        $_POST['wp_nerve_action'] = 'enable_risk_classes';

        WPState::$nonceValid = false;

        $page = new AdminPage();
        $page->handleActions();

        self::assertArrayNotHasKey('wp_nerve_enabled_risk_classes', WPState::$options);

        unset($_POST['wp_nerve_admin'], $_POST['wp_nerve_action']);
    }
}
