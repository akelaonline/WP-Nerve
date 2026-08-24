<?php

/**
 * Plugin archive expansion-limit tests.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Tests\Unit\Security\Privileged;

use ZipArchive;
use WPNerve\Security\Privileged\PluginArchiveInspector;
use WPNerve\Tests\Unit\TestCase;

final class PluginArchiveExpansionTest extends TestCase
{
    public function testExpansionLimitCanOnlyBeReducedForDeterministicTesting(): void
    {
        if (! class_exists(ZipArchive::class)) {
            self::markTestSkipped('Plugin archive preflight requires the PHP Zip extension.');
        }

        $base = sys_get_temp_dir() . '/wpnerve-expansion-' . bin2hex(random_bytes(8));
        $pluginsDir = $base . '/plugins';
        $archive = $base . '/fixture.zip';
        mkdir($pluginsDir, 0700, true);

        $zip = new ZipArchive();
        self::assertTrue($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        self::assertTrue(
            $zip->addFromString(
                'fixture/fixture.php',
                '<?php /* Plugin Name: Expansion Fixture */' . str_repeat('A', 4096)
            )
        );
        self::assertTrue($zip->close());

        add_filter('wp_nerve_max_plugin_uncompressed_bytes', static fn (): int => 1024);

        try {
            $result = (new PluginArchiveInspector())->inspect($archive, $pluginsDir);

            self::assertTrue(is_wp_error($result));
            self::assertSame('wp_nerve_archive_expansion_limit', $result->get_error_code());
        } finally {
            if (is_file($archive)) {
                unlink($archive);
            }
            if (is_dir($pluginsDir)) {
                rmdir($pluginsDir);
            }
            if (is_dir($base)) {
                rmdir($base);
            }
        }
    }
}
