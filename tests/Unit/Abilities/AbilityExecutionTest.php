<?php

/**
 * Read ability execution tests through the full registry pipeline.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Tests\Unit\Abilities;

use WP_Error;
use WP_Post;
use WP_Post_Type;
use WPNerve\Abilities\AbilityRegistrar;
use WPNerve\Policy\PolicyEngine;
use WPNerve\Protocol\AbilityToolRegistry;
use WPNerve\Tests\Fixtures\WPState;
use WPNerve\Tests\Unit\TestCase;

final class AbilityExecutionTest extends TestCase
{
    private AbilityToolRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $registrar = new AbilityRegistrar();
        $registrar->registerAbilities();

        $this->registry = new AbilityToolRegistry(new PolicyEngine());
    }

    public function testGetSiteStatusReturnsDiagnostics(): void
    {
        $result = $this->registry->execute('wp_nerve_site_status', array());

        self::assertNotInstanceOf(WP_Error::class, $result);
        self::assertSame('read', $result['risk']);

        $status = $result['result'];

        self::assertSame('Example Test Site', $status['site_name']);
        self::assertSame('https://example.test/', $status['site_url']);
        self::assertSame('6.9', $status['wordpress_version']);
        self::assertFalse($status['multisite']);
        self::assertSame('https://example.test/wp-json/wp-nerve/v1/mcp', $status['mcp_endpoint']);
        self::assertContains('2026-07-28', $status['protocol_versions']);
        self::assertSame('0.1.0-alpha.6', $status['wpnerve_version']);
    }

    public function testListContentTypesReturnsPublicTypes(): void
    {
        WPState::$postTypes = array(
            'post' => new WP_Post_Type('post', array('label' => 'Posts', 'rest_base' => 'posts', 'supports' => array('title', 'editor'))),
            'page' => new WP_Post_Type('page', array('label' => 'Pages', 'hierarchical' => true)),
            'draft' => new WP_Post_Type('draft', array('public' => false)),
        );

        $result = $this->registry->execute('wp_nerve_list_content_types', array());

        self::assertNotInstanceOf(WP_Error::class, $result);

        $types = $result['result']['types'];

        self::assertCount(2, $types);
        self::assertSame('post', $types[0]['name']);
        self::assertSame('Posts', $types[0]['label']);
        self::assertSame('posts', $types[0]['rest_base']);
        self::assertSame(array('title', 'editor'), $types[0]['supports']);
        self::assertSame('page', $types[1]['name']);
        self::assertTrue($types[1]['hierarchical']);
        self::assertSame('page', $types[1]['rest_base']);
    }

    public function testSearchContentPassesQueryArguments(): void
    {
        WPState::$queryResults = array(
            $this->post(11, 'Hello world', 'publish'),
            $this->post(12, 'Hello again', 'publish'),
        );

        $result = $this->registry->execute('wp_nerve_search_content', array('query' => 'hello', 'number' => 50));

        self::assertNotInstanceOf(WP_Error::class, $result);

        self::assertSame('hello', WPState::$lastQueryArgs['s']);
        self::assertSame(50, WPState::$lastQueryArgs['posts_per_page']);
        self::assertSame('publish', WPState::$lastQueryArgs['post_status']);
        self::assertSame('relevance', WPState::$lastQueryArgs['orderby']);
        self::assertSame('DESC', WPState::$lastQueryArgs['order']);
        self::assertArrayNotHasKey('post_type', WPState::$lastQueryArgs);

        self::assertSame(2, $result['result']['total']);
        self::assertSame(11, $result['result']['items'][0]['id']);
        self::assertSame('Hello world', $result['result']['items'][0]['title']);
    }

    public function testSearchContentClampsNumber(): void
    {
        WPState::$queryResults = array();

        $this->registry->execute('wp_nerve_search_content', array('query' => 'x', 'number' => 999));

        self::assertSame(50, WPState::$lastQueryArgs['posts_per_page']);
    }

    public function testSearchContentSupportsPostTypeFilter(): void
    {
        WPState::$queryResults = array();

        $this->registry->execute('wp_nerve_search_content', array('query' => 'x', 'post_type' => array('post', 'page')));

        self::assertSame(array('post', 'page'), WPState::$lastQueryArgs['post_type']);
    }

    public function testSearchContentRejectsEmptyQuery(): void
    {
        $result = $this->registry->execute('wp_nerve_search_content', array('query' => '   '));

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('wp_nerve_invalid_query', $result->get_error_code());
    }

    public function testSearchContentRestrictsNonPublicStatus(): void
    {
        WPState::$userCan = static fn (string $cap): bool => 'edit_posts' === $cap;

        $result = $this->registry->execute('wp_nerve_search_content', array('query' => 'x', 'status' => 'private'));

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('wp_nerve_forbidden_status', $result->get_error_code());
    }

    public function testSearchContentAllowsPrivateStatusWithCapability(): void
    {
        WPState::$userCan = static fn (string $cap): bool => in_array($cap, array('edit_posts', 'read_private_posts'), true);
        WPState::$queryResults = array($this->post(5, 'Private one', 'private'));

        $result = $this->registry->execute('wp_nerve_search_content', array('query' => 'x', 'status' => 'private'));

        self::assertNotInstanceOf(WP_Error::class, $result);
        self::assertSame('private', WPState::$lastQueryArgs['post_status']);
    }

    public function testGetContentReturnsFullItem(): void
    {
        WPState::$posts[7] = $this->post(7, 'A full post', 'publish', 'Lots of body content here.');

        $result = $this->registry->execute('wp_nerve_get_content', array('id' => 7));

        self::assertNotInstanceOf(WP_Error::class, $result);

        $item = $result['result'];

        self::assertSame(7, $item['id']);
        self::assertSame('A full post', $item['title']);
        self::assertSame('Lots of body content here.', $item['content']);
        self::assertSame('publish', $item['status']);
        self::assertSame('https://example.test/?p=7', $item['link']);
    }

    public function testGetContentRejectsMissingPost(): void
    {
        $result = $this->registry->execute('wp_nerve_get_content', array('id' => 404));

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('wp_nerve_post_not_found', $result->get_error_code());
    }

    public function testGetContentRejectsInvalidId(): void
    {
        $result = $this->registry->execute('wp_nerve_get_content', array('id' => 0));

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('wp_nerve_invalid_id', $result->get_error_code());
    }

    public function testGetContentRejectsDraftWithoutEditCapability(): void
    {
        WPState::$posts[3] = $this->post(3, 'Draft', 'draft');
        WPState::$userCan  = static fn (string $cap): bool => 'edit_posts' === $cap;

        $result = $this->registry->execute('wp_nerve_get_content', array('id' => 3));

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('wp_nerve_forbidden', $result->get_error_code());
    }

    public function testGetContentAllowsDraftWithEditCapability(): void
    {
        WPState::$posts[3] = $this->post(3, 'Draft', 'draft');
        WPState::$userCan  = static fn (string $cap, mixed $id = null): bool =>
            'edit_posts' === $cap || ('edit_post' === $cap && 3 === $id);

        $result = $this->registry->execute('wp_nerve_get_content', array('id' => 3));

        self::assertNotInstanceOf(WP_Error::class, $result);
        self::assertSame('draft', $result['result']['status']);
    }

    public function testGetContentRejectsPrivateWithoutCapability(): void
    {
        WPState::$posts[4] = $this->post(4, 'Private', 'private');
        WPState::$userCan  = static fn (string $cap): bool => 'edit_posts' === $cap;

        $result = $this->registry->execute('wp_nerve_get_content', array('id' => 4));

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('wp_nerve_forbidden', $result->get_error_code());
    }

    public function testGetContentAllowsPrivateWithCapability(): void
    {
        WPState::$posts[4] = $this->post(4, 'Private', 'private');
        WPState::$userCan  = static fn (string $cap): bool => in_array($cap, array('edit_posts', 'read_private_posts'), true);

        $result = $this->registry->execute('wp_nerve_get_content', array('id' => 4));

        self::assertNotInstanceOf(WP_Error::class, $result);
        self::assertSame('Private', $result['result']['title']);
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
}
