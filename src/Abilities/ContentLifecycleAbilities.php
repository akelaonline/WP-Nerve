<?php

/**
 * Content lifecycle abilities: drafts, updates, revisions, trash, and publish.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Abilities;

use WP_Error;
use WP_Post;

final class ContentLifecycleAbilities extends AbstractAbilityRegistrar
{
    public function register(): void
    {
        $this->registerCreateDraft();
        $this->registerUpdateContent();
        $this->registerListRevisions();
        $this->registerGetRevision();
        $this->registerTrashContent();
        $this->registerRestoreContent();
        $this->registerPublishContent();
        $this->registerRestoreRevision();
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|WP_Error
     */
    public function createDraft(mixed $input = null): array|WP_Error
    {
        $input    = is_array($input) ? $input : array();
        $postType = (string) ($input['post_type'] ?? 'post');
        $status   = (string) ($input['status'] ?? 'draft');

        if (! in_array($postType, array('post', 'page'), true)) {
            return new WP_Error('wp_nerve_invalid_post_type', __('Only post and page drafts are supported.', 'wp-nerve'));
        }

        if ('page' === $postType && ! current_user_can('edit_pages')) {
            return new WP_Error('wp_nerve_forbidden', __('You are not allowed to create pages.', 'wp-nerve'));
        }

        if (! in_array($status, array('draft', 'pending'), true)) {
            return new WP_Error('wp_nerve_invalid_status', __('Draft status must be draft or pending.', 'wp-nerve'));
        }

        $id = wp_insert_post(
            array(
                'post_title'   => (string) ($input['title'] ?? ''),
                'post_content' => (string) ($input['content'] ?? ''),
                'post_excerpt' => (string) ($input['excerpt'] ?? ''),
                'post_status'  => $status,
                'post_type'    => $postType,
            ),
            true
        );

        if (is_wp_error($id)) {
            return $id;
        }

        $post = get_post($id);

        if (! $post instanceof WP_Post) {
            return new WP_Error('wp_nerve_create_failed', __('The draft could not be created.', 'wp-nerve'));
        }

        return $this->contentItem($post, true);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|WP_Error
     */
    public function updateContent(mixed $input = null): array|WP_Error
    {
        $input = is_array($input) ? $input : array();
        $id    = (int) ($input['id'] ?? 0);

        $post = $this->editablePost($id);

        if ($post instanceof WP_Error) {
            return $post;
        }

        $update = array('ID' => $post->ID);

        if (array_key_exists('title', $input)) {
            $update['post_title'] = (string) $input['title'];
        }

        if (array_key_exists('content', $input)) {
            $update['post_content'] = (string) $input['content'];
        }

        if (array_key_exists('excerpt', $input)) {
            $update['post_excerpt'] = (string) $input['excerpt'];
        }

        $result = wp_update_post($update, true);

        if (is_wp_error($result)) {
            return $result;
        }

        $updated = get_post($post->ID);

        if (! $updated instanceof WP_Post) {
            return new WP_Error('wp_nerve_update_failed', __('The content could not be updated.', 'wp-nerve'));
        }

        return $this->contentItem($updated, true);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|WP_Error
     */
    public function listRevisions(mixed $input = null): array|WP_Error
    {
        $input = is_array($input) ? $input : array();
        $id    = (int) ($input['id'] ?? 0);
        $post  = get_post($id);

        if (! $post instanceof WP_Post) {
            return new WP_Error('wp_nerve_post_not_found', __('The requested post does not exist.', 'wp-nerve'));
        }

        if (! current_user_can('edit_post', $post->ID)) {
            return new WP_Error('wp_nerve_forbidden', __('You are not allowed to read revisions for this content.', 'wp-nerve'));
        }

        $number   = $this->clamp((int) ($input['number'] ?? 20), 1, 100);
        $revision = wp_get_post_revisions($post->ID, array('numberposts' => $number));
        $items    = array();

        foreach ($revision as $entry) {
            if ($entry instanceof WP_Post) {
                $items[] = $this->revisionItem($entry);
            }
        }

        return array('items' => $items, 'total' => count($items));
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|WP_Error
     */
    public function getRevision(mixed $input = null): array|WP_Error
    {
        $input = is_array($input) ? $input : array();
        $id    = (int) ($input['id'] ?? 0);
        $revision = wp_get_post_revision($id);

        if (! $revision instanceof WP_Post) {
            return new WP_Error('wp_nerve_revision_not_found', __('The requested revision does not exist.', 'wp-nerve'));
        }

        if (! current_user_can('edit_post', (int) $revision->post_parent)) {
            return new WP_Error('wp_nerve_forbidden', __('You are not allowed to read this revision.', 'wp-nerve'));
        }

        $item = $this->revisionItem($revision);
        $item['content'] = $revision->post_content;

        return $item;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|WP_Error
     */
    public function trashContent(mixed $input = null): array|WP_Error
    {
        $input = is_array($input) ? $input : array();
        $id    = (int) ($input['id'] ?? 0);
        $post  = get_post($id);

        if (! $post instanceof WP_Post) {
            return new WP_Error('wp_nerve_post_not_found', __('The requested post does not exist.', 'wp-nerve'));
        }

        if (! current_user_can('delete_post', $post->ID)) {
            return new WP_Error('wp_nerve_forbidden', __('You are not allowed to trash this content.', 'wp-nerve'));
        }

        $result = wp_trash_post($post->ID);

        if (false === $result || null === $result) {
            return new WP_Error('wp_nerve_trash_failed', __('The content could not be trashed.', 'wp-nerve'));
        }

        $trashed = get_post($post->ID);

        return $trashed instanceof WP_Post ? $this->contentItem($trashed, false) : array('id' => $post->ID);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|WP_Error
     */
    public function restoreContent(mixed $input = null): array|WP_Error
    {
        $input = is_array($input) ? $input : array();
        $id    = (int) ($input['id'] ?? 0);
        $post  = get_post($id);

        if (! $post instanceof WP_Post) {
            return new WP_Error('wp_nerve_post_not_found', __('The requested post does not exist.', 'wp-nerve'));
        }

        if ('trash' !== $post->post_status) {
            return new WP_Error('wp_nerve_not_trashed', __('Only trashed content can be restored.', 'wp-nerve'));
        }

        if (! current_user_can('delete_post', $post->ID)) {
            return new WP_Error('wp_nerve_forbidden', __('You are not allowed to restore this content.', 'wp-nerve'));
        }

        wp_untrash_post($post->ID);

        $restored = get_post($post->ID);

        return $restored instanceof WP_Post ? $this->contentItem($restored, false) : array('id' => $post->ID);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|WP_Error
     */
    public function publishContent(mixed $input = null): array|WP_Error
    {
        $input = is_array($input) ? $input : array();
        $id    = (int) ($input['id'] ?? 0);
        $post  = get_post($id);

        if (! $post instanceof WP_Post) {
            return new WP_Error('wp_nerve_post_not_found', __('The requested post does not exist.', 'wp-nerve'));
        }

        $publishCapability = 'page' === $post->post_type ? 'publish_pages' : 'publish_posts';

        if (! current_user_can($publishCapability)) {
            return new WP_Error('wp_nerve_forbidden', __('You are not allowed to publish this content.', 'wp-nerve'));
        }

        wp_publish_post($post->ID);

        $published = get_post($post->ID);

        return $published instanceof WP_Post ? $this->contentItem($published, false) : array('id' => $post->ID);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|WP_Error
     */
    public function restoreRevision(mixed $input = null): array|WP_Error
    {
        $input = is_array($input) ? $input : array();
        $id    = (int) ($input['id'] ?? 0);
        $revision = wp_get_post_revision($id);

        if (! $revision instanceof WP_Post) {
            return new WP_Error('wp_nerve_revision_not_found', __('The requested revision does not exist.', 'wp-nerve'));
        }

        if (! current_user_can('edit_post', (int) $revision->post_parent)) {
            return new WP_Error('wp_nerve_forbidden', __('You are not allowed to restore this revision.', 'wp-nerve'));
        }

        $parentId = wp_restore_post_revision($revision->ID);

        $post = get_post((int) $parentId);

        if (! $post instanceof WP_Post) {
            return new WP_Error('wp_nerve_restore_failed', __('The revision could not be restored.', 'wp-nerve'));
        }

        return $this->contentItem($post, true);
    }

    private function registerCreateDraft(): void
    {
        $this->registerAbility(
            'wp-nerve/create-draft',
            __('Create draft', 'wp-nerve'),
            __('Creates a new draft post or page. Undo by trashing the draft.', 'wp-nerve'),
            array(
                '$schema'              => 'https://json-schema.org/draft/2020-12/schema',
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array('title'),
                'properties'           => array(
                    'title'     => array('type' => 'string', 'minLength' => 1, 'maxLength' => 500),
                    'content'   => array('type' => 'string'),
                    'excerpt'   => array('type' => 'string'),
                    'post_type' => array('type' => 'string', 'enum' => array('post', 'page'), 'default' => 'post'),
                    'status'    => array('type' => 'string', 'enum' => array('draft', 'pending'), 'default' => 'draft'),
                ),
            ),
            array_merge(
                array('$schema' => 'https://json-schema.org/draft/2020-12/schema'),
                $this->contentItemSchema(true)
            ),
            array($this, 'createDraft'),
            'write'
        );
    }

    private function registerUpdateContent(): void
    {
        $this->registerAbility(
            'wp-nerve/update-content',
            __('Update content', 'wp-nerve'),
            __('Updates the title, content, or excerpt of an existing post. A WordPress revision is kept for recovery.', 'wp-nerve'),
            array(
                '$schema'              => 'https://json-schema.org/draft/2020-12/schema',
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array('id'),
                'properties'           => array(
                    'id'      => array('type' => 'integer', 'minimum' => 1),
                    'title'   => array('type' => 'string', 'minLength' => 1, 'maxLength' => 500),
                    'content' => array('type' => 'string'),
                    'excerpt' => array('type' => 'string'),
                ),
            ),
            array_merge(
                array('$schema' => 'https://json-schema.org/draft/2020-12/schema'),
                $this->contentItemSchema(true)
            ),
            array($this, 'updateContent'),
            'write'
        );
    }

    private function registerListRevisions(): void
    {
        $this->registerReadAbility(
            'wp-nerve/list-revisions',
            __('List revisions', 'wp-nerve'),
            __('Lists the revisions of a post, most recent first.', 'wp-nerve'),
            array(
                '$schema'              => 'https://json-schema.org/draft/2020-12/schema',
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array('id'),
                'properties'           => array(
                    'id'     => array('type' => 'integer', 'minimum' => 1),
                    'number' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20),
                ),
            ),
            array(
                '$schema'              => 'https://json-schema.org/draft/2020-12/schema',
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array('items', 'total'),
                'properties'           => array(
                    'items' => array(
                        'type'  => 'array',
                        'items' => array(
                            'type'                 => 'object',
                            'additionalProperties' => false,
                            'required'             => array('id', 'parent', 'author', 'date', 'modified', 'title'),
                            'properties'           => array(
                                'id'       => array('type' => 'integer'),
                                'parent'   => array('type' => 'integer'),
                                'author'   => array('type' => 'integer'),
                                'date'     => array('type' => 'string'),
                                'modified' => array('type' => 'string'),
                                'title'    => array('type' => 'string'),
                            ),
                        ),
                    ),
                    'total' => array('type' => 'integer'),
                ),
            ),
            array($this, 'listRevisions')
        );
    }

    private function registerGetRevision(): void
    {
        $this->registerReadAbility(
            'wp-nerve/get-revision',
            __('Get revision', 'wp-nerve'),
            __('Returns a single revision with its full content.', 'wp-nerve'),
            array(
                '$schema'              => 'https://json-schema.org/draft/2020-12/schema',
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array('id'),
                'properties'           => array(
                    'id' => array('type' => 'integer', 'minimum' => 1),
                ),
            ),
            array(
                '$schema'              => 'https://json-schema.org/draft/2020-12/schema',
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array('id', 'parent', 'author', 'date', 'modified', 'title', 'content'),
                'properties'           => array(
                    'id'       => array('type' => 'integer'),
                    'parent'   => array('type' => 'integer'),
                    'author'   => array('type' => 'integer'),
                    'date'     => array('type' => 'string'),
                    'modified' => array('type' => 'string'),
                    'title'    => array('type' => 'string'),
                    'content'  => array('type' => 'string'),
                ),
            ),
            array($this, 'getRevision')
        );
    }

    private function registerTrashContent(): void
    {
        $this->registerAbility(
            'wp-nerve/trash-content',
            __('Trash content', 'wp-nerve'),
            __('Moves content to the trash. Undo with restore-content.', 'wp-nerve'),
            $this->idInputSchema(),
            array_merge(
                array('$schema' => 'https://json-schema.org/draft/2020-12/schema'),
                $this->contentItemSchema(false)
            ),
            array($this, 'trashContent'),
            'destructive',
            false
        );
    }

    private function registerRestoreContent(): void
    {
        $this->registerAbility(
            'wp-nerve/restore-content',
            __('Restore content', 'wp-nerve'),
            __('Restores trashed content to its previous status.', 'wp-nerve'),
            $this->idInputSchema(),
            array_merge(
                array('$schema' => 'https://json-schema.org/draft/2020-12/schema'),
                $this->contentItemSchema(false)
            ),
            array($this, 'restoreContent'),
            'write'
        );
    }

    private function registerPublishContent(): void
    {
        $this->registerAbility(
            'wp-nerve/publish-content',
            __('Publish content', 'wp-nerve'),
            __('Publishes a post or page. The previous status and a revision are kept for recovery.', 'wp-nerve'),
            $this->idInputSchema(),
            array_merge(
                array('$schema' => 'https://json-schema.org/draft/2020-12/schema'),
                $this->contentItemSchema(false)
            ),
            array($this, 'publishContent'),
            'destructive',
            false
        );
    }

    private function registerRestoreRevision(): void
    {
        $this->registerAbility(
            'wp-nerve/restore-revision',
            __('Restore revision', 'wp-nerve'),
            __('Restores a post from one of its revisions. A new revision is created before restoring.', 'wp-nerve'),
            $this->idInputSchema(),
            array_merge(
                array('$schema' => 'https://json-schema.org/draft/2020-12/schema'),
                $this->contentItemSchema(true)
            ),
            array($this, 'restoreRevision'),
            'destructive',
            false
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function idInputSchema(): array
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
    private function revisionItem(WP_Post $revision): array
    {
        return array(
            'id'       => $revision->ID,
            'parent'   => (int) $revision->post_parent,
            'author'   => (int) $revision->post_author,
            'date'     => $revision->post_date,
            'modified' => $revision->post_modified,
            'title'    => $revision->post_title,
        );
    }

    /** @return WP_Post|WP_Error */
    private function editablePost(int $id): WP_Post|WP_Error
    {
        $post = get_post($id);

        if (! $post instanceof WP_Post) {
            return new WP_Error('wp_nerve_post_not_found', __('The requested post does not exist.', 'wp-nerve'));
        }

        if (! current_user_can('edit_post', $post->ID)) {
            return new WP_Error('wp_nerve_forbidden', __('You are not allowed to edit this content.', 'wp-nerve'));
        }

        return $post;
    }
}
