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
        self::assertStringContainsString('USERNAME:APPLICATION_PASSWORD', $output);
        self::assertStringContainsString('Generate Application Password for me', $output);
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
        $_POST['wp_nerve_admin']  = 'nonce';
        $_POST['wp_nerve_action'] = 'generate_app_password';

        $page = new AdminPage();
        $page->handleActions();

        self::assertSame(WPState::$currentUserId, \WP_Application_Passwords::$lastUserId);

        $notice = get_transient('wp_nerve_admin_notice');

        self::assertIsArray($notice);
        self::assertSame('xxxx xxxx xxxx xxxx xxxx xxxx', $notice['password']);

        unset($_POST['wp_nerve_admin'], $_POST['wp_nerve_action']);
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
