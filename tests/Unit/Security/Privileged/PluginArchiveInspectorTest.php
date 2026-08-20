<?php

/**
 * Plugin archive preflight tests.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Tests\Unit\Security\Privileged;

use ZipArchive;
use WPNerve\Security\Privileged\PluginArchiveInspector;
use WPNerve\Tests\Unit\TestCase;

final class PluginArchiveInspectorTest extends TestCase
{
    /** @var array<int, string> */
    private array $temporaryPaths = array();

    protected function tearDown(): void
    {
        foreach (array_reverse($this->temporaryPaths) as $path) {
            if (is_file($path) || is_link($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                rmdir($path);
            }
        }

        parent::tearDown();
    }

    public function testValidArchiveReportsNormalizedRootAndExpansionSize(): void
    {
        $this->requireZip();
        $pluginsDir = $this->directory('plugins');
        $archive = $this->archive(array(
            'fixture/fixture.php' => '<?php /* Plugin Name: Fixture */',
            'fixture/readme.txt'  => 'fixture',
        ));

        $result = (new PluginArchiveInspector())->inspect($archive, $pluginsDir);

        self::assertIsArray($result);
        self::assertSame(array('fixture'), $result['roots']);
        self::assertSame(array('fixture/fixture.php', 'fixture/readme.txt'), $result['entries']);
        self::assertGreaterThan(0, $result['uncompressed_bytes']);
    }

    public function testSingleFilePluginArchiveIsAccepted(): void
    {
        $this->requireZip();
        $pluginsDir = $this->directory('plugins');
        $archive = $this->archive(array(
            'hello-custom.php' => '<?php /* Plugin Name: Hello Custom */',
        ));

        $result = (new PluginArchiveInspector())->inspect($archive, $pluginsDir);

        self::assertIsArray($result);
        self::assertSame(array('hello-custom.php'), $result['roots']);
    }

    public function testArchiveCannotWriteIntoExistingFilesystemRoot(): void
    {
        $this->requireZip();
        $pluginsDir = $this->directory('plugins');
        $existing   = $pluginsDir . '/existing';
        mkdir($existing, 0700, true);
        $this->temporaryPaths[] = $existing;
        $archive = $this->archive(array('existing/evil.php' => '<?php'));

        $result = (new PluginArchiveInspector())->inspect($archive, $pluginsDir);

        self::assertTrue(is_wp_error($result));
        self::assertSame('wp_nerve_plugin_target_exists', $result->get_error_code());
    }

    public function testArchiveSymlinkEntryIsRejected(): void
    {
        $this->requireZip();
        $pluginsDir = $this->directory('plugins');
        $archive    = $this->path('symlink.zip');
        $zip        = new ZipArchive();

        self::assertTrue($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        self::assertTrue($zip->addFromString('fixture/fixture.php', '<?php /* Plugin Name: Fixture */'));
        self::assertTrue($zip->addFromString('fixture/link', '../wp-config.php'));

        $unixSymlinkMode = 0120000 | 0777;
        self::assertTrue(
            $zip->setExternalAttributesName(
                'fixture/link',
                ZipArchive::OPSYS_UNIX,
                $unixSymlinkMode << 16
            )
        );
        self::assertTrue($zip->close());

        $result = (new PluginArchiveInspector())->inspect($archive, $pluginsDir);

        self::assertTrue(is_wp_error($result));
        self::assertSame('wp_nerve_archive_symlink', $result->get_error_code());
    }

    public function testUnixSpecialFileEntryIsRejected(): void
    {
        $this->requireZip();
        $pluginsDir = $this->directory('plugins');
        $archive    = $this->path('fifo.zip');
        $zip        = new ZipArchive();

        self::assertTrue($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        self::assertTrue($zip->addFromString('fixture/fixture.php', '<?php /* Plugin Name: Fixture */'));
        self::assertTrue($zip->addFromString('fixture/pipe', ''));

        $unixFifoMode = 0010000 | 0600;
        self::assertTrue(
            $zip->setExternalAttributesName(
                'fixture/pipe',
                ZipArchive::OPSYS_UNIX,
                $unixFifoMode << 16
            )
        );
        self::assertTrue($zip->close());

        $result = (new PluginArchiveInspector())->inspect($archive, $pluginsDir);

        self::assertTrue(is_wp_error($result));
        self::assertSame('wp_nerve_archive_special_file', $result->get_error_code());
    }

    public function testBackslashTraversalIsRejectedAfterNormalization(): void
    {
        $this->requireZip();
        $pluginsDir = $this->directory('plugins');
        $archive = $this->archive(array('fixture\\..\\escape.php' => '<?php'));

        $result = (new PluginArchiveInspector())->inspect($archive, $pluginsDir);

        self::assertTrue(is_wp_error($result));
        self::assertSame('wp_nerve_unsafe_archive_path', $result->get_error_code());
    }

    public function testAbsoluteAndDriveQualifiedPathsAreRejected(): void
    {
        $this->requireZip();
        $pluginsDir = $this->directory('plugins');

        foreach (array('/escape.php', 'C:/escape.php') as $name) {
            $archive = $this->archive(array($name => '<?php'));
            $result = (new PluginArchiveInspector())->inspect($archive, $pluginsDir);

            self::assertTrue(is_wp_error($result), $name);
            self::assertSame('wp_nerve_unsafe_archive_path', $result->get_error_code(), $name);
        }
    }

    public function testEmptyAndDotPathSegmentsAreRejected(): void
    {
        $this->requireZip();
        $pluginsDir = $this->directory('plugins');

        foreach (array('fixture//evil.php', 'fixture/./evil.php') as $name) {
            $archive = $this->archive(array($name => '<?php'));
            $result = (new PluginArchiveInspector())->inspect($archive, $pluginsDir);

            self::assertTrue(is_wp_error($result), $name);
            self::assertSame('wp_nerve_unsafe_archive_path', $result->get_error_code(), $name);
        }
    }

    public function testArchiveWithMultipleTopLevelRootsIsRejected(): void
    {
        $this->requireZip();
        $pluginsDir = $this->directory('plugins');
        $archive = $this->archive(array(
            'plugin-a/plugin-a.php' => '<?php /* Plugin Name: A */',
            'plugin-b/plugin-b.php' => '<?php /* Plugin Name: B */',
        ));

        $result = (new PluginArchiveInspector())->inspect($archive, $pluginsDir);

        self::assertTrue(is_wp_error($result));
        self::assertSame('wp_nerve_archive_root_count', $result->get_error_code());
    }

    public function testArchiveWithoutPhpPluginFileIsRejected(): void
    {
        $this->requireZip();
        $pluginsDir = $this->directory('plugins');
        $archive = $this->archive(array(
            'fixture/readme.txt' => 'not a plugin',
        ));

        $result = (new PluginArchiveInspector())->inspect($archive, $pluginsDir);

        self::assertTrue(is_wp_error($result));
        self::assertSame('wp_nerve_archive_no_plugin_file', $result->get_error_code());
    }

    public function testUnicodeFilenameInsideSinglePluginRootIsAccepted(): void
    {
        $this->requireZip();
        $pluginsDir = $this->directory('plugins');
        $archive = $this->archive(array(
            'fixture/fixture.php'      => '<?php /* Plugin Name: Fixture */',
            'fixture/documentación.md' => 'ok',
        ));

        $result = (new PluginArchiveInspector())->inspect($archive, $pluginsDir);

        self::assertIsArray($result);
        self::assertSame(array('fixture'), $result['roots']);
    }

    /** @param array<string, string> $files */
    private function archive(array $files): string
    {
        $archive = $this->path('fixture-' . count($this->temporaryPaths) . '.zip');
        $zip     = new ZipArchive();

        self::assertTrue($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE));

        foreach ($files as $name => $content) {
            self::assertTrue($zip->addFromString($name, $content));
        }

        self::assertTrue($zip->close());

        return $archive;
    }

    private function directory(string $name): string
    {
        $path = $this->path($name);
        mkdir($path, 0700, true);

        return $path;
    }

    private function path(string $suffix): string
    {
        $path = sys_get_temp_dir() . '/wpnerve-' . bin2hex(random_bytes(8)) . '-' . $suffix;
        $this->temporaryPaths[] = $path;

        return $path;
    }

    private function requireZip(): void
    {
        if (! class_exists(ZipArchive::class)) {
            self::markTestSkipped('Plugin archive preflight requires the PHP Zip extension.');
        }
    }
}
