<?php

/**
 * Uninstall routine unit tests.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Tests\Unit;

use WPNerve\Tests\Fixtures\WPState;

final class UninstallTest extends TestCase
{
    public function testPreservesDataByDefault(): void
    {
        WPState::$options['wp_nerve_schema_version'] = '5';

        $this->runUninstall();

        self::assertSame('', WPState::$wpdb->lastQuery);
        self::assertArrayHasKey('wp_nerve_schema_version', WPState::$options);
    }

    public function testDeletesDataWhenOptedIn(): void
    {
        WPState::$options['wp_nerve_schema_version']           = '5';
        WPState::$options['wp_nerve_delete_data_on_uninstall'] = true;

        $this->runUninstall();

        self::assertStringContainsString('DROP TABLE IF EXISTS', WPState::$wpdb->lastPrepared);
        self::assertStringContainsString('wp_wp_nerve_oauth_tokens', WPState::$wpdb->lastQuery);
        self::assertTrue(
            (bool) array_filter(
                WPState::$wpdb->queries,
                static fn (string $query): bool => str_contains($query, 'wp_wp_nerve_confirmations')
            )
        );
        self::assertTrue(
            (bool) array_filter(
                WPState::$wpdb->queries,
                static fn (string $query): bool => str_contains($query, 'wp_wp_nerve_rate_limits')
            )
        );
        self::assertArrayNotHasKey('wp_nerve_schema_version', WPState::$options);
        self::assertArrayNotHasKey('wp_nerve_delete_data_on_uninstall', WPState::$options);
    }

    private function runUninstall(): void
    {
        defined('WP_UNINSTALL_PLUGIN') || define('WP_UNINSTALL_PLUGIN', 'wp-nerve/wp-nerve.php');

        require dirname(__DIR__, 2) . '/uninstall.php';
    }
}
