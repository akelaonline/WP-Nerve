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
}
