<?php

/**
 * Activator unit tests.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Tests\Unit\Infrastructure;

use WPNerve\Infrastructure\Activator;
use WPNerve\Tests\Fixtures\WPState;
use WPNerve\Tests\Unit\TestCase;

final class ActivatorTest extends TestCase
{
    public function testActivateInstallsSchemaAndVersion(): void
    {
        Activator::activate();

        self::assertCount(3, WPState::$schemaCalls);
        self::assertSame('2', WPState::$options['wp_nerve_schema_version']);
        self::assertSame(array(), WPState::$deactivatedPlugins);
    }

    public function testActivateRejectsOldWordPress(): void
    {
        WPState::$wpVersion = '6.5';
        $GLOBALS['wp_version'] = '6.5';

        Activator::activate();

        self::assertCount(1, WPState::$deactivatedPlugins);
        self::assertStringContainsString('wp-nerve.php', WPState::$deactivatedPlugins[0]);
        self::assertCount(1, WPState::$wpDieCalls);
        self::assertStringContainsString('WordPress 6.9', WPState::$wpDieCalls[0]['message']);
    }
}
