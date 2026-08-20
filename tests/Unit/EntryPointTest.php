<?php

/**
 * Main plugin entry point tests.
 *
 * Loading wp-nerve.php must register the activation hook, boot the plugin, and
 * define the runtime constants. The entry file is loaded once per process and
 * re-booted for every test so assertions do not depend on execution order.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Tests\Unit;

use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use ReflectionProperty;
use WPNerve\Infrastructure\Activator;
use WPNerve\Plugin;
use WPNerve\Tests\Fixtures\WPState;

final class EntryPointTest extends PHPUnitTestCase
{
    private static bool $entryLoaded = false;

    protected function setUp(): void
    {
        parent::setUp();

        WPState::reset();

        if (! self::$entryLoaded) {
            require dirname(__DIR__, 2) . '/wp-nerve.php';
            self::$entryLoaded = true;
            return;
        }

        $property = new ReflectionProperty(Plugin::class, 'instance');
        $property->setValue(null, null);
        Plugin::instance()->boot();
    }

    public function testLoadingEntryPointBootsThePlugin(): void
    {
        self::assertSame('0.1.0-alpha.14', WP_NERVE_VERSION);

        self::assertCount(1, WPState::$activationHooks);
        self::assertSame(array(Activator::class, 'activate'), WPState::$activationHooks[0]['callback']);
        self::assertCount(2, WPState::$actions['rest_api_init']);
        self::assertCount(1, WPState::$actions['wp_abilities_api_init']);
        self::assertCount(2, WPState::$actions['admin_init']);
        self::assertCount(2, WPState::$actions['admin_menu']);
        self::assertCount(6, WPState::$schemaCalls);
        self::assertSame('6', WPState::$options['wp_nerve_schema_version']);
    }

    public function testEntryPointHeaderMatchesVersionConstant(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/wp-nerve.php');
        self::assertIsString($source);

        preg_match('/^\s*\* Version:\s*(.+)$/m', $source, $matches);
        self::assertSame(WP_NERVE_VERSION, trim($matches[1] ?? ''));
    }

    public function testEntryPointDeclaresRuntimeConstants(): void
    {
        self::assertTrue(defined('WP_NERVE_FILE'));
        self::assertTrue(defined('WP_NERVE_PATH'));
        self::assertTrue(defined('WP_NERVE_URL'));
    }
}
