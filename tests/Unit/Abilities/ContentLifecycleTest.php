<?php

/**
 * Content lifecycle ability tests.
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

final class ContentLifecycleTest extends TestCase
{
    private AbilityToolRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $registrar = new AbilityRegistrar();
        $registrar->registerAbilities();

        $this->registry = new AbilityToolRegistry(new PolicyEngine());
    }

    public function testRegistersContentLifecycleAbilitiesWithRiskMetadata(): void
    {
        $expected = array(
            'wp-nerve/create-draft'      => array('write', true),
            'wp-nerve/update-content'    => array('write', true),
            'wp-nerve/list-revisions'    => array('read', true),
            'wp-nerve/get-revision'      => array('read', true),
            'wp-nerve/trash-content'     => array('destructive', false),
            'wp-nerve/restore-content'   => array('write', true),
            'wp-nerve/publish-content'   => array('destructive', false),
            'wp-nerve/restore-revision'  => array('destructive', false),
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

    public function testCreateDraftCreatesPost(): void
    {
        $result = $this->registry->execute('wp_nerve_create_draft', array(
            'title'   => 'New draft',
            'content' => 'Body text',
            'excerpt' => 'Teaser',
        ));

        self::assertNotInstanceOf(WP_Error::class, $result);

        self::assertSame('draft', WPState::$lastInsertedPost['post_status']);
        self::assertSame('post', WPState::$lastInsertedPost['post_type']);

        $draft = $result['result'];

        self::assertSame(100, $draft['id']);
        self::assertSame('New draft', $draft['title']);
        self::assertSame('Body text', $draft['content']);
        self::assertSame('draft', $draft['status']);
    }

    public function testCreateDraftAllowsPendingStatus(): void
    {
        $this->registry->execute('wp_nerve_create_draft', array('title' => 'T', 'status' => 'pending'));

        self::assertSame('pending', WPState::$lastInsertedPost['post_status']);
    }

    public function testCreateDraftRejectsUnknownPostType(): void
    {
        $result = $this->registry->execute('wp_nerve_create_draft', array('title' => 'T', 'post_type' => 'product'));

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('wp_nerve_invalid_post_type', $result->get_error_code());
    }

    public function testCreatePageRequiresEditPages(): void
    {
        WPState::$userCan = static fn (string $cap): bool => 'edit_posts' === $cap;

        $result = $this->registry->execute('wp_nerve_create_draft', array('title' => 'T', 'post_type' => 'page'));

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('wp_nerve_forbidden', $result->get_error_code());
    }

    public function testUpdateContentChangesFields(): void
    {
        WPState::$posts[7] = $this->post(7, 'Original', 'publish');
        WPState::$userCan  = static fn (string $cap, mixed $id = null): bool =>
            'edit_posts' === $cap || ('edit_post' === $cap && 7 === $id);

        $result = $this->registry->execute('wp_nerve_update_content', array(
            'id'      => 7,
            'title'   => 'Updated title',
            'content' => 'New body',
        ));

        self::assertNotInstanceOf(WP_Error::class, $result);
        self::assertSame('Updated title', $result['result']['title']);
        self::assertSame('New body', $result['result']['content']);
        self::assertSame(7, WPState::$lastUpdatedPost['ID']);
    }

    public function testUpdateContentRejectsWithoutEditCapability(): void
    {
        WPState::$posts[7] = $this->post(7, 'Original', 'publish');
        WPState::$userCan  = static fn (string $cap): bool => 'edit_posts' === $cap;

        $result = $this->registry->execute('wp_nerve_update_content', array('id' => 7, 'title' => 'X'));

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('wp_nerve_forbidden', $result->get_error_code());
    }

    public function testUpdateContentRejectsMissingPost(): void
    {
        $result = $this->registry->execute('wp_nerve_update_content', array('id' => 999, 'title' => 'X'));

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('wp_nerve_post_not_found', $result->get_error_code());
    }

    public function testListRevisionsReturnsMappedRevisions(): void
    {
        WPState::$posts[7]      = $this->post(7, 'Parent', 'publish');
        WPState::$revisions[50] = $this->revision(50, 7, 'Rev 2');
        WPState::$revisions[49] = $this->revision(49, 7, 'Rev 1');
        WPState::$userCan       = static fn (string $cap, mixed $id = null): bool =>
            'edit_posts' === $cap || ('edit_post' === $cap && 7 === $id);

        $result = $this->registry->execute('wp_nerve_list_revisions', array('id' => 7));

        self::assertNotInstanceOf(WP_Error::class, $result);
        self::assertSame(2, $result['result']['total']);
        self::assertSame(50, $result['result']['items'][0]['id']);
        self::assertSame(7, $result['result']['items'][0]['parent']);
    }

    public function testListRevisionsRequiresEditCapability(): void
    {
        WPState::$posts[7] = $this->post(7, 'Parent', 'publish');
        WPState::$userCan  = static fn (string $cap): bool => 'edit_posts' === $cap;

        $result = $this->registry->execute('wp_nerve_list_revisions', array('id' => 7));

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('wp_nerve_forbidden', $result->get_error_code());
    }

    public function testGetRevisionReturnsFullContent(): void
    {
        WPState::$posts[7]      = $this->post(7, 'Parent', 'publish');
        WPState::$revisions[50] = $this->revision(50, 7, 'Rev 2', 'Revision body');
        WPState::$userCan       = static fn (string $cap, mixed $id = null): bool =>
            'edit_posts' === $cap || ('edit_post' === $cap && 7 === $id);

        $result = $this->registry->execute('wp_nerve_get_revision', array('id' => 50));

        self::assertNotInstanceOf(WP_Error::class, $result);
        self::assertSame('Revision body', $result['result']['content']);
        self::assertSame('Rev 2', $result['result']['title']);
    }

    public function testGetRevisionRejectsMissingRevision(): void
    {
        $result = $this->registry->execute('wp_nerve_get_revision', array('id' => 404));

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('wp_nerve_revision_not_found', $result->get_error_code());
    }

    public function testTrashContentMovesToTrash(): void
    {
        $this->enableDestructive('wp-nerve/trash-content');

        WPState::$posts[7] = $this->post(7, 'Doomed', 'publish');
        WPState::$userCan  = static fn (string $cap, mixed $id = null): bool =>
            'edit_posts' === $cap || ('delete_post' === $cap && 7 === $id);

        $result = $this->registry->execute('wp_nerve_trash_content', array('id' => 7));

        self::assertNotInstanceOf(WP_Error::class, $result);
        self::assertSame('trash', $result['result']['status']);
        self::assertSame(array(7), WPState::$trashedPostIds);
    }

    public function testTrashContentHiddenWithoutDestructiveOptIn(): void
    {
        WPState::$posts[7] = $this->post(7, 'Doomed', 'publish');

        $result = $this->registry->execute('wp_nerve_trash_content', array('id' => 7));

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('wp_nerve_tool_not_found', $result->get_error_code());
    }

    public function testRestoreContentUntrashes(): void
    {
        WPState::$posts[7] = $this->post(7, 'Back', 'trash');
        WPState::$userCan  = static fn (string $cap, mixed $id = null): bool =>
            'edit_posts' === $cap || ('delete_post' === $cap && 7 === $id);

        $result = $this->registry->execute('wp_nerve_restore_content', array('id' => 7));

        self::assertNotInstanceOf(WP_Error::class, $result);
        self::assertSame('publish', $result['result']['status']);
        self::assertSame(array(7), WPState::$untrashedPostIds);
    }

    public function testRestoreContentRejectsNonTrashedPost(): void
    {
        WPState::$posts[7] = $this->post(7, 'Live', 'publish');

        $result = $this->registry->execute('wp_nerve_restore_content', array('id' => 7));

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('wp_nerve_not_trashed', $result->get_error_code());
    }

    public function testPublishContentPublishes(): void
    {
        $this->enableDestructive('wp-nerve/publish-content');

        WPState::$posts[7] = $this->post(7, 'Ready', 'draft');
        WPState::$userCan  = static fn (string $cap): bool => in_array($cap, array('edit_posts', 'publish_posts'), true);

        $result = $this->registry->execute('wp_nerve_publish_content', array('id' => 7));

        self::assertNotInstanceOf(WP_Error::class, $result);
        self::assertSame('publish', $result['result']['status']);
        self::assertSame(array(7), WPState::$publishedPostIds);
    }

    public function testPublishPageRequiresPublishPagesCapability(): void
    {
        $this->enableDestructive('wp-nerve/publish-content');

        WPState::$posts[8] = $this->post(8, 'Page', 'draft');
        $post = WPState::$posts[8];
        $post->post_type = 'page';
        WPState::$userCan  = static fn (string $cap, mixed $id = null): bool =>
            'edit_posts' === $cap || ('publish_posts' === $cap && 8 === $id);

        $result = $this->registry->execute('wp_nerve_publish_content', array('id' => 8));

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('wp_nerve_forbidden', $result->get_error_code());
    }

    public function testRestoreRevisionRestoresParentContent(): void
    {
        $this->enableDestructive('wp-nerve/restore-revision');

        WPState::$posts[7]      = $this->post(7, 'Current', 'publish');
        WPState::$revisions[50] = $this->revision(50, 7, 'Older title', 'Older body');
        WPState::$userCan       = static fn (string $cap, mixed $id = null): bool =>
            'edit_posts' === $cap || ('edit_post' === $cap && 7 === $id);

        $result = $this->registry->execute('wp_nerve_restore_revision', array('id' => 50));

        self::assertNotInstanceOf(WP_Error::class, $result);
        self::assertSame('Older title', $result['result']['title']);
        self::assertSame('Older body', $result['result']['content']);
        self::assertSame(array(50), WPState::$restoredRevisionIds);
    }

    private function enableDestructive(string $name): void
    {
        WPState::$options['wp_nerve_enabled_risk_classes'] = array('read', 'write', 'destructive');

        add_filter('wp_nerve_ability_is_enabled', static function (bool $enabled, $ability) use ($name): bool {
            return $enabled || $name === $ability->get_name();
        }, 10, 2);
    }

    private function post(int $id, string $title, string $status, string $content = ''): WP_Post
    {
        $post = new WP_Post($id);

        $post->post_title   = $title;
        $post->post_status  = $status;
        $post->post_content = $content;
        $post->post_author  = 2;

        return $post;
    }

    private function revision(int $id, int $parent, string $title, string $content = ''): WP_Post
    {
        $revision = new WP_Post($id);

        $revision->post_title   = $title;
        $revision->post_content = $content;
        $revision->post_parent  = $parent;
        $revision->post_type    = 'revision';
        $revision->post_status  = 'inherit';
        $revision->post_author  = 2;

        return $revision;
    }
}
