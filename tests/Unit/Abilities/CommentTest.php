<?php

/**
 * Comment ability tests.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Tests\Unit\Abilities;

use WP_Comment;
use WP_Error;
use WP_Post;
use WPNerve\Abilities\AbilityRegistrar;
use WPNerve\Policy\PolicyEngine;
use WPNerve\Protocol\AbilityToolRegistry;
use WPNerve\Tests\Fixtures\WPState;
use WPNerve\Tests\Unit\TestCase;

final class CommentTest extends TestCase
{
    private AbilityToolRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $registrar = new AbilityRegistrar();
        $registrar->registerAbilities();

        $this->registry = new AbilityToolRegistry(new PolicyEngine());
    }

    public function testRegistersCommentAbilitiesWithRiskMetadata(): void
    {
        $expected = array(
            'wp-nerve/list-comments'     => array('read', true),
            'wp-nerve/get-comment'       => array('read', true),
            'wp-nerve/create-comment'    => array('write', true),
            'wp-nerve/reply-comment'     => array('write', true),
            'wp-nerve/moderate-comment'  => array('write', true),
            'wp-nerve/delete-comment'    => array('destructive', false),
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

    public function testListCommentsReturnsApprovedByDefault(): void
    {
        WPState::$comments[1] = $this->comment(1, 'Nice post', '1');
        WPState::$comments[2] = $this->comment(2, 'Spam', 'spam');
        WPState::$comments[3] = $this->comment(3, 'Pending', '0');

        $result = $this->registry->execute('wp_nerve_list_comments', array());

        self::assertNotInstanceOf(WP_Error::class, $result);
        self::assertSame(1, $result['result']['total']);
        self::assertSame('Nice post', $result['result']['items'][0]['content']);
        self::assertSame('approved', $result['result']['items'][0]['status']);
    }

    public function testListCommentsAllStatusRequiresModeration(): void
    {
        WPState::$userCan = static fn (string $cap): bool => 'edit_posts' === $cap;

        $result = $this->registry->execute('wp_nerve_list_comments', array('status' => 'all'));

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('wp_nerve_forbidden', $result->get_error_code());
    }

    public function testListCommentsAllStatusWithModerationCapability(): void
    {
        WPState::$comments[1] = $this->comment(1, 'A', '1');
        WPState::$comments[2] = $this->comment(2, 'B', '0');
        WPState::$userCan = static fn (string $cap): bool => in_array($cap, array('edit_posts', 'moderate_comments'), true);

        $result = $this->registry->execute('wp_nerve_list_comments', array('status' => 'all'));

        self::assertNotInstanceOf(WP_Error::class, $result);
        self::assertSame(2, $result['result']['total']);
    }

    public function testGetCommentReturnsItem(): void
    {
        WPState::$comments[5] = $this->comment(5, 'Hello', '1');

        $result = $this->registry->execute('wp_nerve_get_comment', array('id' => 5));

        self::assertNotInstanceOf(WP_Error::class, $result);
        self::assertSame('Hello', $result['result']['content']);
    }

    public function testGetCommentRejectsMissing(): void
    {
        $result = $this->registry->execute('wp_nerve_get_comment', array('id' => 999));

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('wp_nerve_comment_not_found', $result->get_error_code());
    }

    public function testCreateCommentInsertsApproved(): void
    {
        WPState::$posts[7] = new WP_Post(7);
        WPState::$userCan  = static fn (string $cap): bool => in_array($cap, array('edit_posts', 'moderate_comments'), true);

        $result = $this->registry->execute('wp_nerve_create_comment', array(
            'post_id' => 7,
            'content' => 'Un comentario',
            'author_name' => 'Akela',
        ));

        self::assertNotInstanceOf(WP_Error::class, $result);
        self::assertSame(500, $result['result']['id']);
        self::assertSame('Un comentario', $result['result']['content']);
        self::assertSame(7, $result['result']['post_id']);
        self::assertSame('approved', $result['result']['status']);
    }

    public function testCreateCommentRequiresModerationCapability(): void
    {
        WPState::$posts[7] = new WP_Post(7);
        WPState::$userCan  = static fn (string $cap): bool => 'edit_posts' === $cap;

        // The tool is not revealed to users without moderate_comments.
        $result = $this->registry->execute('wp_nerve_create_comment', array('post_id' => 7, 'content' => 'X'));

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('wp_nerve_tool_not_found', $result->get_error_code());
    }

    public function testReplyCommentSetsParent(): void
    {
        WPState::$posts[7]  = new WP_Post(7);
        WPState::$comments[5] = $this->comment(5, 'Parent', '1');
        WPState::$comments[5]->comment_post_ID = 7;
        WPState::$userCan   = static fn (string $cap): bool => in_array($cap, array('edit_posts', 'moderate_comments'), true);

        $result = $this->registry->execute('wp_nerve_reply_comment', array(
            'comment_id' => 5,
            'content'    => 'Mi respuesta',
        ));

        self::assertNotInstanceOf(WP_Error::class, $result);
        self::assertSame(5, $result['result']['parent']);
        self::assertSame(7, $result['result']['post_id']);
    }

    public function testModerateCommentReturnsPreviousStatus(): void
    {
        WPState::$comments[5] = $this->comment(5, 'Spammy', '0');
        WPState::$userCan     = static fn (string $cap): bool => in_array($cap, array('edit_posts', 'moderate_comments'), true);

        $result = $this->registry->execute('wp_nerve_moderate_comment', array('id' => 5, 'status' => 'spam'));

        self::assertNotInstanceOf(WP_Error::class, $result);
        self::assertSame('spam', $result['result']['status']);
        self::assertSame('pending', $result['result']['previous']);
        self::assertSame('pending', $result['result']['recovery']['previous_status']);
    }

    public function testModerateCommentRejectsInvalidStatus(): void
    {
        $result = $this->registry->execute('wp_nerve_moderate_comment', array('id' => 5, 'status' => 'delete'));

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('wp_nerve_invalid_status', $result->get_error_code());
    }

    public function testDeleteCommentHiddenByDefault(): void
    {
        WPState::$comments[5] = $this->comment(5, 'X', '1');

        $result = $this->registry->execute('wp_nerve_delete_comment', array('id' => 5));

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('wp_nerve_tool_not_found', $result->get_error_code());
    }

    public function testDeleteCommentWithOptIn(): void
    {
        WPState::$options['wp_nerve_enabled_risk_classes'] = array('read', 'write', 'destructive');
        add_filter('wp_nerve_ability_is_enabled', static fn (bool $enabled, $ability): bool =>
            $enabled || 'wp-nerve/delete-comment' === $ability->get_name(), 10, 2);

        WPState::$comments[5] = $this->comment(5, 'X', '1');
        WPState::$userCan     = static fn (string $cap): bool => in_array($cap, array('edit_posts', 'moderate_comments'), true);

        $result = $this->registry->execute('wp_nerve_delete_comment', array('id' => 5));

        self::assertNotInstanceOf(WP_Error::class, $result);
        self::assertSame(true, $result['result']['deleted']);
        self::assertSame(array(5), WPState::$deletedCommentIds);
    }

    private function comment(int $id, string $content, string $approved): WP_Comment
    {
        $comment = new WP_Comment($id);

        $comment->comment_content = $content;
        $comment->comment_approved = $approved;

        return $comment;
    }
}
