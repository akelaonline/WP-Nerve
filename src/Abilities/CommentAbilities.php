<?php

/**
 * Comment abilities.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Abilities;

use WP_Comment;
use WP_Error;
use WP_Post;

final class CommentAbilities extends AbstractAbilityRegistrar
{
    public function register(): void
    {
        $this->registerListComments();
        $this->registerGetComment();
        $this->registerCreateComment();
        $this->registerReplyComment();
        $this->registerModerateComment();
        $this->registerDeleteComment();
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|WP_Error
     */
    public function listComments(mixed $input = null): array|WP_Error
    {
        $input  = is_array($input) ? $input : array();
        $status = (string) ($input['status'] ?? 'approve');

        if ('approve' !== $status && ! current_user_can('moderate_comments')) {
            return new WP_Error('wp_nerve_forbidden', __('Listing non-approved comments requires the moderate_comments capability.', 'wp-nerve'));
        }

        $args = array(
            'number' => $this->clamp((int) ($input['number'] ?? 20), 1, 100),
            'status' => $status,
        );

        if (! empty($input['post_id'])) {
            $args['post_id'] = (int) $input['post_id'];
        }

        $comments = get_comments($args);
        $items    = array();

        if (is_array($comments)) {
            foreach ($comments as $comment) {
                if ($comment instanceof WP_Comment) {
                    $items[] = $this->commentItem($comment);
                }
            }
        }

        return array('items' => $items, 'total' => count($items));
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|WP_Error
     */
    public function getComment(mixed $input = null): array|WP_Error
    {
        $input   = is_array($input) ? $input : array();
        $id      = (int) ($input['id'] ?? 0);
        $comment = get_comment($id);

        if (! $comment instanceof WP_Comment) {
            return new WP_Error('wp_nerve_comment_not_found', __('The requested comment does not exist.', 'wp-nerve'));
        }

        if ('1' !== $comment->comment_approved && ! current_user_can('moderate_comments')) {
            return new WP_Error('wp_nerve_forbidden', __('You are not allowed to read this comment.', 'wp-nerve'));
        }

        return $this->commentItem($comment);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|WP_Error
     */
    public function createComment(mixed $input = null): array|WP_Error
    {
        $input = is_array($input) ? $input : array();

        $post = get_post((int) ($input['post_id'] ?? 0));

        if (! $post instanceof WP_Post) {
            return new WP_Error('wp_nerve_post_not_found', __('The requested post does not exist.', 'wp-nerve'));
        }

        $content = trim((string) ($input['content'] ?? ''));

        if ('' === $content) {
            return new WP_Error('wp_nerve_invalid_content', __('The content parameter must be a non-empty string.', 'wp-nerve'));
        }

        if (! current_user_can('moderate_comments')) {
            return new WP_Error('wp_nerve_forbidden', __('Creating comments requires the moderate_comments capability.', 'wp-nerve'));
        }

        $id = wp_insert_comment(
            array(
                'comment_post_ID'      => $post->ID,
                'comment_content'      => $content,
                'comment_author'       => (string) ($input['author_name'] ?? wp_get_current_user()->display_name),
                'comment_author_email' => (string) ($input['author_email'] ?? ''),
                'comment_approved'     => '1',
            )
        );

        return $this->insertedCommentItem($id);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|WP_Error
     */
    public function replyComment(mixed $input = null): array|WP_Error
    {
        $input = is_array($input) ? $input : array();

        $parent = get_comment((int) ($input['comment_id'] ?? 0));

        if (! $parent instanceof WP_Comment) {
            return new WP_Error('wp_nerve_comment_not_found', __('The parent comment does not exist.', 'wp-nerve'));
        }

        $content = trim((string) ($input['content'] ?? ''));

        if ('' === $content) {
            return new WP_Error('wp_nerve_invalid_content', __('The content parameter must be a non-empty string.', 'wp-nerve'));
        }

        if (! current_user_can('moderate_comments')) {
            return new WP_Error('wp_nerve_forbidden', __('Replying to comments requires the moderate_comments capability.', 'wp-nerve'));
        }

        $id = wp_insert_comment(
            array(
                'comment_post_ID'      => (int) $parent->comment_post_ID,
                'comment_parent'       => (int) $parent->comment_ID,
                'comment_content'      => $content,
                'comment_author'       => (string) ($input['author_name'] ?? wp_get_current_user()->display_name),
                'comment_author_email' => (string) ($input['author_email'] ?? ''),
                'comment_approved'     => '1',
            )
        );

        return $this->insertedCommentItem($id);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|WP_Error
     */
    public function moderateComment(mixed $input = null): array|WP_Error
    {
        $input  = is_array($input) ? $input : array();
        $id     = (int) ($input['id'] ?? 0);
        $status = (string) ($input['status'] ?? '');

        if (! in_array($status, array('approve', 'hold', 'spam', 'trash'), true)) {
            return new WP_Error('wp_nerve_invalid_status', __('Status must be approve, hold, spam, or trash.', 'wp-nerve'));
        }

        $comment = get_comment($id);

        if (! $comment instanceof WP_Comment) {
            return new WP_Error('wp_nerve_comment_not_found', __('The requested comment does not exist.', 'wp-nerve'));
        }

        if (! current_user_can('moderate_comments')) {
            return new WP_Error('wp_nerve_forbidden', __('Moderating comments requires the moderate_comments capability.', 'wp-nerve'));
        }

        $previous = $this->commentStatus($comment->comment_approved);

        $result = wp_set_comment_status((int) $comment->comment_ID, $status);

        if (is_wp_error($result)) {
            return $result;
        }

        $updated = get_comment((int) $comment->comment_ID);

        return array(
            'id'       => $comment->comment_ID,
            'status'   => $this->commentStatus($updated instanceof WP_Comment ? $updated->comment_approved : ''),
            'previous' => $previous,
            'recovery' => array(
                'note' => __('Set the comment back to its previous status to undo.', 'wp-nerve'),
                'previous_status' => $previous,
            ),
        );
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|WP_Error
     */
    public function deleteComment(mixed $input = null): array|WP_Error
    {
        $input = is_array($input) ? $input : array();
        $id    = (int) ($input['id'] ?? 0);
        $comment = get_comment($id);

        if (! $comment instanceof WP_Comment) {
            return new WP_Error('wp_nerve_comment_not_found', __('The requested comment does not exist.', 'wp-nerve'));
        }

        if (! current_user_can('moderate_comments')) {
            return new WP_Error('wp_nerve_forbidden', __('Deleting comments requires the moderate_comments capability.', 'wp-nerve'));
        }

        $force = (bool) ($input['force'] ?? false);

        $result = wp_delete_comment((int) $comment->comment_ID, $force);

        if (! $result) {
            return new WP_Error('wp_nerve_delete_failed', __('The comment could not be deleted.', 'wp-nerve'));
        }

        return array(
            'id'       => $comment->comment_ID,
            'deleted'  => true,
            'force'    => $force,
            'recovery' => $force
                ? array('note' => __('Permanent deletion cannot be undone.', 'wp-nerve'))
                : array('undo' => 'restore from trash', 'note' => __('Trashed comments can be restored from the WordPress trash.', 'wp-nerve')),
        );
    }

    private function registerListComments(): void
    {
        $this->registerReadAbility(
            'wp-nerve/list-comments',
            __('List comments', 'wp-nerve'),
            __('Lists approved comments, or any status with the moderate_comments capability.', 'wp-nerve'),
            array(
                '$schema'              => 'https://json-schema.org/draft/2020-12/schema',
                'type'                 => 'object',
                'additionalProperties' => false,
                'properties'           => array(
                    'number'  => array('type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20),
                    'status'  => array('type' => 'string', 'enum' => array('approve', 'hold', 'spam', 'trash', 'all'), 'default' => 'approve'),
                    'post_id' => array('type' => 'integer', 'minimum' => 1),
                ),
            ),
            array(
                '$schema'              => 'https://json-schema.org/draft/2020-12/schema',
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array('items', 'total'),
                'properties'           => array(
                    'items' => array('type' => 'array', 'items' => $this->commentItemSchema()),
                    'total' => array('type' => 'integer'),
                ),
            ),
            array($this, 'listComments')
        );
    }

    private function registerGetComment(): void
    {
        $this->registerReadAbility(
            'wp-nerve/get-comment',
            __('Get comment', 'wp-nerve'),
            __('Returns a single comment.', 'wp-nerve'),
            $this->commentIdSchema(),
            array_merge(
                array('$schema' => 'https://json-schema.org/draft/2020-12/schema'),
                $this->commentItemSchema()
            ),
            array($this, 'getComment')
        );
    }

    private function registerCreateComment(): void
    {
        $this->registerAbility(
            'wp-nerve/create-comment',
            __('Create comment', 'wp-nerve'),
            __('Creates an approved comment on a post. Requires moderate_comments.', 'wp-nerve'),
            array(
                '$schema'              => 'https://json-schema.org/draft/2020-12/schema',
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array('post_id', 'content'),
                'properties'           => array(
                    'post_id'      => array('type' => 'integer', 'minimum' => 1),
                    'content'      => array('type' => 'string', 'minLength' => 1, 'maxLength' => 5000),
                    'author_name'  => array('type' => 'string', 'maxLength' => 100),
                    'author_email' => array('type' => 'string', 'format' => 'email', 'maxLength' => 100),
                ),
            ),
            array_merge(
                array('$schema' => 'https://json-schema.org/draft/2020-12/schema'),
                $this->commentItemSchema()
            ),
            array($this, 'createComment'),
            'write',
            true,
            'moderate_comments'
        );
    }

    private function registerReplyComment(): void
    {
        $this->registerAbility(
            'wp-nerve/reply-comment',
            __('Reply to comment', 'wp-nerve'),
            __('Creates an approved reply to an existing comment. Requires moderate_comments.', 'wp-nerve'),
            array(
                '$schema'              => 'https://json-schema.org/draft/2020-12/schema',
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array('comment_id', 'content'),
                'properties'           => array(
                    'comment_id'   => array('type' => 'integer', 'minimum' => 1),
                    'content'      => array('type' => 'string', 'minLength' => 1, 'maxLength' => 5000),
                    'author_name'  => array('type' => 'string', 'maxLength' => 100),
                    'author_email' => array('type' => 'string', 'format' => 'email', 'maxLength' => 100),
                ),
            ),
            array_merge(
                array('$schema' => 'https://json-schema.org/draft/2020-12/schema'),
                $this->commentItemSchema()
            ),
            array($this, 'replyComment'),
            'write',
            true,
            'moderate_comments'
        );
    }

    private function registerModerateComment(): void
    {
        $this->registerAbility(
            'wp-nerve/moderate-comment',
            __('Moderate comment', 'wp-nerve'),
            __('Changes a comment status to approve, hold, spam, or trash. The previous status is returned for recovery.', 'wp-nerve'),
            array(
                '$schema'              => 'https://json-schema.org/draft/2020-12/schema',
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array('id', 'status'),
                'properties'           => array(
                    'id'     => array('type' => 'integer', 'minimum' => 1),
                    'status' => array('type' => 'string', 'enum' => array('approve', 'hold', 'spam', 'trash')),
                ),
            ),
            array(
                '$schema'              => 'https://json-schema.org/draft/2020-12/schema',
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array('id', 'status', 'previous'),
                'properties'           => array(
                    'id'       => array('type' => 'integer'),
                    'status'   => array('type' => 'string'),
                    'previous' => array('type' => 'string'),
                    'recovery' => array('type' => 'object'),
                ),
            ),
            array($this, 'moderateComment'),
            'write',
            true,
            'moderate_comments'
        );
    }

    private function registerDeleteComment(): void
    {
        $this->registerAbility(
            'wp-nerve/delete-comment',
            __('Delete comment', 'wp-nerve'),
            __('Deletes a comment. Trash by default; permanent deletion with force=true.', 'wp-nerve'),
            array(
                '$schema'              => 'https://json-schema.org/draft/2020-12/schema',
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array('id'),
                'properties'           => array(
                    'id'    => array('type' => 'integer', 'minimum' => 1),
                    'force' => array('type' => 'boolean', 'default' => false),
                ),
            ),
            array(
                '$schema'              => 'https://json-schema.org/draft/2020-12/schema',
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array('id', 'deleted', 'force'),
                'properties'           => array(
                    'id'       => array('type' => 'integer'),
                    'deleted'  => array('type' => 'boolean'),
                    'force'    => array('type' => 'boolean'),
                    'recovery' => array('type' => 'object'),
                ),
            ),
            array($this, 'deleteComment'),
            'destructive',
            false,
            'moderate_comments'
        );
    }

    /**
     * @param int|false $id
     * @return array<string, mixed>|WP_Error
     */
    private function insertedCommentItem(int|false $id): array|WP_Error
    {
        if (false === $id) {
            return new WP_Error('wp_nerve_comment_create_failed', __('The comment could not be created.', 'wp-nerve'));
        }

        $comment = get_comment($id);

        if (! $comment instanceof WP_Comment) {
            return new WP_Error('wp_nerve_comment_create_failed', __('The comment could not be created.', 'wp-nerve'));
        }

        return $this->commentItem($comment);
    }

    /** @return array<string, mixed> */
    private function commentItemSchema(): array
    {
        return array(
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => array('id', 'post_id', 'author', 'content', 'date', 'status', 'parent', 'user_id'),
            'properties'           => array(
                'id'      => array('type' => 'integer'),
                'post_id' => array('type' => 'integer'),
                'author'  => array('type' => 'string'),
                'content' => array('type' => 'string'),
                'date'    => array('type' => 'string'),
                'status'  => array('type' => 'string'),
                'parent'  => array('type' => 'integer'),
                'user_id' => array('type' => 'integer'),
            ),
        );
    }

    /** @return array<string, mixed> */
    private function commentIdSchema(): array
    {
        return array(
            '$schema'              => 'https://json-schema.org/draft/2020-12/schema',
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => array('id'),
            'properties'           => array(
                'id' => array('type' => 'integer', 'minimum' => 1),
            ),
        );
    }

    /** @return array<string, mixed> */
    private function commentItem(WP_Comment $comment): array
    {
        return array(
            'id'      => $comment->comment_ID,
            'post_id' => $comment->comment_post_ID,
            'author'  => $comment->comment_author,
            'content' => $comment->comment_content,
            'date'    => $comment->comment_date,
            'status'  => $this->commentStatus($comment->comment_approved),
            'parent'  => (int) $comment->comment_parent,
            'user_id' => (int) $comment->user_id,
        );
    }

    private function commentStatus(string $approved): string
    {
        return match ($approved) {
            '1' => 'approved',
            '0' => 'pending',
            default => $approved,
        };
    }
}
