<?php

/**
 * Media ability tests.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Tests\Unit\Abilities;

use WP_Error;
use WP_Post;
use WPNerve\Abilities\AbilityRegistrar;
use WPNerve\Policy\PolicyEngine;
use WPNerve\Protocol\AbilityToolRegistry;
use WPNerve\Tests\Fixtures\WPState;
use WPNerve\Tests\Unit\TestCase;

final class MediaTest extends TestCase
{
    private AbilityToolRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $registrar = new AbilityRegistrar();
        $registrar->registerAbilities();

        $this->registry = new AbilityToolRegistry(new PolicyEngine());
    }

    public function testRegistersMediaAbilitiesWithRiskMetadata(): void
    {
        $expected = array(
            'wp-nerve/list-media'    => array('read', true),
            'wp-nerve/get-media'     => array('read', true),
            'wp-nerve/upload-media'  => array('write', false),
            'wp-nerve/update-media'  => array('write', true),
            'wp-nerve/delete-media'  => array('destructive', false),
        );

        $actual = array();

        foreach (WPState::$registeredAbilities as $ability) {
            $name = $ability->get_name();

            if (array_key_exists($name, $expected)) {
                $meta = $ability->get_meta_item('wp_nerve', array());
                $actual[$name] = array($meta['risk'], $meta['enabled_by_default']);
            }
        }

        self::assertSame($expected, $actual);
    }

    public function testListMediaReturnsItems(): void
    {
        WPState::$queryResults = array(
            $this->attachment(11, 'logo.png', 'image/png'),
            $this->attachment(12, 'hero.jpg', 'image/jpeg'),
        );

        $result = $this->registry->execute('wp_nerve_list_media', array());

        self::assertNotInstanceOf(WP_Error::class, $result);
        self::assertSame(2, $result['result']['total']);
        self::assertSame('attachment', WPState::$lastQueryArgs['post_type']);
        self::assertSame('inherit', WPState::$lastQueryArgs['post_status']);
        self::assertSame(11, $result['result']['items'][0]['id']);
        self::assertSame('image/png', $result['result']['items'][0]['mime']);
    }

    public function testListMediaPassesMimeAndSearchFilters(): void
    {
        WPState::$queryResults = array();

        $this->registry->execute('wp_nerve_list_media', array('mime_type' => 'image/png', 'search' => 'logo'));

        self::assertSame('image/png', WPState::$lastQueryArgs['post_mime_type']);
        self::assertSame('logo', WPState::$lastQueryArgs['s']);
    }

    public function testGetMediaReturnsItemWithMetadata(): void
    {
        WPState::$posts[11] = $this->attachment(11, 'logo.png', 'image/png');
        WPState::$attachedFiles[11] = '/uploads/logo.png';
        WPState::$attachmentMeta[11] = array('width' => 800, 'height' => 600, 'file' => 'logo.png');
        WPState::$postMeta[11]['_wp_attachment_image_alt'] = 'Company logo';

        $result = $this->registry->execute('wp_nerve_get_media', array('id' => 11));

        self::assertNotInstanceOf(WP_Error::class, $result);

        $item = $result['result'];

        self::assertSame('logo.png', $item['filename']);
        self::assertSame(800, $item['width']);
        self::assertSame(600, $item['height']);
        self::assertSame('Company logo', $item['alt']);
    }

    public function testGetMediaRejectsNonAttachment(): void
    {
        WPState::$posts[7] = $this->attachment(7, 'page', 'post');
        $post = WPState::$posts[7];
        $post->post_type = 'post';

        $result = $this->registry->execute('wp_nerve_get_media', array('id' => 7));

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('wp_nerve_media_not_found', $result->get_error_code());
    }

    public function testUploadMediaCreatesAttachment(): void
    {
        $this->enableAbility('wp-nerve/upload-media');

        WPState::$userCan = static fn (string $cap): bool => in_array($cap, array('edit_posts', 'upload_files'), true);

        $result = $this->registry->execute('wp_nerve_upload_media', array(
            'filename'  => 'foto.png',
            'mime_type' => 'image/png',
            'content'   => base64_encode('fake-png-bytes'),
            'title'     => 'Mi foto',
            'alt'       => 'Foto de prueba',
        ));

        self::assertNotInstanceOf(WP_Error::class, $result);

        $item = $result['result'];

        self::assertSame('foto.png', $item['filename']);
        self::assertSame('image/png', $item['mime']);
        self::assertSame('Mi foto', $item['title']);
        self::assertSame('Foto de prueba', $item['alt']);
        self::assertSame('wp_nerve_delete_media', $item['recovery']['undo']);

        // The attachment is registered with metadata and file.
        self::assertSame('inherit', WPState::$posts[$item['id']]->post_status);
        self::assertArrayHasKey($item['id'], WPState::$attachmentMeta);
    }

    public function testUploadMediaHiddenByDefault(): void
    {
        WPState::$userCan = static fn (string $cap): bool => in_array($cap, array('edit_posts', 'upload_files'), true);

        $result = $this->registry->execute('wp_nerve_upload_media', array(
            'filename' => 'x.png',
            'content'  => base64_encode('x'),
        ));

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('wp_nerve_tool_not_found', $result->get_error_code());
    }

    public function testUploadMediaRejectsInvalidBase64(): void
    {
        $this->enableAbility('wp-nerve/upload-media');

        WPState::$userCan = static fn (string $cap): bool => in_array($cap, array('edit_posts', 'upload_files'), true);

        $result = $this->registry->execute('wp_nerve_upload_media', array(
            'filename' => 'x.png',
            'content'  => 'not-base64!!!',
        ));

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('wp_nerve_invalid_base64', $result->get_error_code());
    }

    public function testUploadMediaRequiresUploadFilesCapability(): void
    {
        $this->enableAbility('wp-nerve/upload-media');

        WPState::$userCan = static fn (string $cap): bool => 'edit_posts' === $cap;

        // The tool is not revealed to users without upload_files.
        $result = $this->registry->execute('wp_nerve_upload_media', array(
            'filename' => 'x.png',
            'content'  => base64_encode('x'),
        ));

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('wp_nerve_tool_not_found', $result->get_error_code());
    }

    public function testUpdateMediaChangesFields(): void
    {
        WPState::$posts[11] = $this->attachment(11, 'logo.png', 'image/png');
        WPState::$userCan   = static fn (string $cap, mixed $id = null): bool =>
            'edit_posts' === $cap || ('edit_post' === $cap && 11 === $id);

        $result = $this->registry->execute('wp_nerve_update_media', array(
            'id'    => 11,
            'title' => 'Nuevo logo',
            'alt'   => 'Logo actualizado',
        ));

        self::assertNotInstanceOf(WP_Error::class, $result);
        self::assertSame('Nuevo logo', $result['result']['title']);
        self::assertSame('Logo actualizado', WPState::$postMeta[11]['_wp_attachment_image_alt']);
    }

    public function testUpdateMediaRejectsWithoutEditCapability(): void
    {
        WPState::$posts[11] = $this->attachment(11, 'logo.png', 'image/png');
        WPState::$userCan   = static fn (string $cap): bool => 'edit_posts' === $cap;

        $result = $this->registry->execute('wp_nerve_update_media', array('id' => 11, 'title' => 'X'));

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('wp_nerve_forbidden', $result->get_error_code());
    }

    public function testDeleteMediaHiddenByDefault(): void
    {
        WPState::$posts[11] = $this->attachment(11, 'logo.png', 'image/png');

        $result = $this->registry->execute('wp_nerve_delete_media', array('id' => 11));

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('wp_nerve_tool_not_found', $result->get_error_code());
    }

    public function testDeleteMediaTrashesByDefault(): void
    {
        $this->enableDestructive('wp-nerve/delete-media');

        WPState::$posts[11] = $this->attachment(11, 'logo.png', 'image/png');
        WPState::$userCan   = static fn (string $cap, mixed $id = null): bool =>
            'edit_posts' === $cap || ('delete_post' === $cap && 11 === $id);

        $result = $this->registry->execute('wp_nerve_delete_media', array('id' => 11));

        self::assertNotInstanceOf(WP_Error::class, $result);
        self::assertSame(true, $result['result']['deleted']);
        self::assertSame(false, $result['result']['force']);
        self::assertSame(array(11), WPState::$deletedAttachmentIds);
    }

    public function testDeleteMediaForceRequiresDeleteOthersPosts(): void
    {
        $this->enableDestructive('wp-nerve/delete-media');

        WPState::$posts[11] = $this->attachment(11, 'logo.png', 'image/png');
        WPState::$userCan   = static fn (string $cap, mixed $id = null): bool =>
            'edit_posts' === $cap || ('delete_post' === $cap && 11 === $id);

        $result = $this->registry->execute('wp_nerve_delete_media', array('id' => 11, 'force' => true));

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('wp_nerve_forbidden', $result->get_error_code());
    }

    private function enableAbility(string $name): void
    {
        add_filter('wp_nerve_ability_is_enabled', static function (bool $enabled, $ability) use ($name): bool {
            return $enabled || $name === $ability->get_name();
        }, 10, 2);
    }

    private function enableDestructive(string $name): void
    {
        WPState::$options['wp_nerve_enabled_risk_classes'] = array('read', 'write', 'destructive');

        add_filter('wp_nerve_ability_is_enabled', static function (bool $enabled, $ability) use ($name): bool {
            return $enabled || $name === $ability->get_name();
        }, 10, 2);
    }

    private function attachment(int $id, string $title, string $mime): WP_Post
    {
        $post = new WP_Post($id);

        $post->post_title     = $title;
        $post->post_type      = 'attachment';
        $post->post_status    = 'inherit';
        $post->post_mime_type = $mime;
        $post->post_author    = 2;

        return $post;
    }
}
