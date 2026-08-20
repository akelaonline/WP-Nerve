<?php

/**
 * Plugin composition root unit tests.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Tests\Unit;

use ReflectionProperty;
use WPNerve\Infrastructure\Activator;
use WPNerve\Plugin;
use WPNerve\Tests\Fixtures\WPState;

final class PluginTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->resetInstance();
    }

    protected function tearDown(): void
    {
        $this->resetInstance();

        parent::tearDown();
    }

    public function testBootIsIdempotent(): void
    {
        Plugin::instance()->boot();
        Plugin::instance()->boot();

        self::assertCount(2, WPState::$actions['rest_api_init']);
        self::assertCount(1, WPState::$actions['wp_scheduled_delete']);
    }

    public function testBootInstallsSchemaOnFirstRun(): void
    {
        Plugin::instance()->boot();

        self::assertCount(6, WPState::$schemaCalls);
        self::assertSame(Activator::SCHEMA_VERSION, WPState::$options['wp_nerve_schema_version']);
        self::assertTrue(
            (bool) array_filter(
                WPState::$schemaCalls,
                static fn (string $sql): bool => str_contains($sql, 'wp_nerve_rate_limits')
            )
        );
    }

    public function testBootMigratesSchemaFromAlpha9(): void
    {
        WPState::$options['wp_nerve_schema_version'] = '5';

        Plugin::instance()->boot();

        self::assertCount(6, WPState::$schemaCalls);
        self::assertSame('6', WPState::$options['wp_nerve_schema_version']);
    }

    public function testBootSkipsSchemaWhenAlreadyInstalled(): void
    {
        WPState::$options['wp_nerve_schema_version'] = Activator::SCHEMA_VERSION;

        Plugin::instance()->boot();

        self::assertCount(0, WPState::$schemaCalls);
    }

    public function testBootRegistersAllWiring(): void
    {
        Plugin::instance()->boot();

        self::assertCount(1, WPState::$actions['wp_abilities_api_categories_init']);
        self::assertCount(1, WPState::$actions['wp_abilities_api_init']);
        self::assertCount(2, WPState::$actions['rest_api_init']);
        self::assertCount(2, WPState::$actions['admin_init']);
        self::assertCount(3, WPState::$actions['admin_menu']);
        self::assertCount(1, WPState::$actions['wp_scheduled_delete']);
        self::assertCount(1, WPState::$filters['rest_allowed_cors_headers']);
    }

    public function testRenderRequirementsNoticeOnlyForCapableUsers(): void
    {
        WPState::$userCan = false;

        ob_start();
        Plugin::instance()->renderRequirementsNotice();
        $output = ob_get_clean();

        self::assertSame('', $output);

        WPState::$userCan = true;

        ob_start();
        Plugin::instance()->renderRequirementsNotice();
        $output = ob_get_clean();

        self::assertStringContainsString('WPNerve requires WordPress 6.9', $output);
        self::assertStringContainsString('notice-error', $output);
    }

    private function resetInstance(): void
    {
        $property = new ReflectionProperty(Plugin::class, 'instance');

        $property->setValue(null, null);
    }
}
