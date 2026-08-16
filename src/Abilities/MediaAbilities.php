<?php

/**
 * Media library abilities.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Abilities;

use WP_Error;
use WP_Post;
use WP_Query;

final class MediaAbilities extends AbstractAbilityRegistrar
{
    private const MAX_UPLOAD_BYTES = 26214400;

    public function register(): void
    {
        $this->registerListMedia();
        $this->registerGetMedia();
        $this->registerUploadMedia();
        $this->registerUpdateMedia();
        $this->registerDeleteMedia();
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function listMedia(mixed $input = null): array
    {
        $input = is_array($input) ? $input : array();

        $args = array(
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => $this->clamp((int) ($input['number'] ?? 20), 1, 100),
            'paged'          => max(1, (int) ($input['offset'] ?? 0) + 1),
        );

        if (! empty($input['mime_type'])) {
            $args['post_mime_type'] = (string) $input['mime_type'];
        }

        if (! empty($input['search'])) {
            $args['s'] = (string) $input['search'];
        }

        $wp_query = new WP_Query($args);
        $items    = array();

        foreach ($wp_query->posts as $attachment) {
            if ($attachment instanceof WP_Post) {
                $items[] = $this->mediaItem($attachment);
            }
        }

        return array('items' => $items, 'total' => $wp_query->found_posts);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|WP_Error
     */
    public function getMedia(mixed $input = null): array|WP_Error
    {
        $input = is_array($input) ? $input : array();
        $id    = (int) ($input['id'] ?? 0);
        $post  = get_post($id);

        if (! $post instanceof WP_Post || 'attachment' !== $post->post_type) {
            return new WP_Error('wp_nerve_media_not_found', __('The requested media item does not exist.', 'wp-nerve'));
        }

        return $this->mediaItem($post);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|WP_Error
     */
    public function uploadMedia(mixed $input = null): array|WP_Error
    {
        $input = is_array($input) ? $input : array();
        $name  = trim((string) ($input['filename'] ?? ''));
        $mime  = (string) ($input['mime_type'] ?? '');
        $raw   = (string) ($input['content'] ?? '');

        if ('' === $name || '' === $raw) {
            return new WP_Error('wp_nerve_invalid_upload', __('filename and base64 content are required.', 'wp-nerve'));
        }

        if (! current_user_can('upload_files')) {
            return new WP_Error('wp_nerve_forbidden', __('You are not allowed to upload media.', 'wp-nerve'));
        }

        $bytes = $this->maxUploadBytes();

        if (strlen($raw) > $bytes) {
            return new WP_Error('wp_nerve_upload_too_large', __('The uploaded file exceeds the configured size limit.', 'wp-nerve'));
        }

        $bits = base64_decode($raw, true);

        if (false === $bits || '' === $bits) {
            return new WP_Error('wp_nerve_invalid_base64', __('The content parameter must be valid base64.', 'wp-nerve'));
        }

        $upload = wp_upload_bits($name, null, $bits);

        if (false !== $upload['error']) {
            return new WP_Error('wp_nerve_upload_failed', (string) $upload['error']);
        }

        $id = wp_insert_attachment(
            array(
                'post_title'     => (string) ($input['title'] ?? pathinfo($name, PATHINFO_FILENAME)),
                'post_content'   => (string) ($input['description'] ?? ''),
                'post_excerpt'   => (string) ($input['caption'] ?? ''),
                'post_mime_type' => $mime,
            ),
            (string) $upload['file']
        );

        if (is_wp_error($id)) {
            return $id;
        }

        $metadata = wp_generate_attachment_metadata($id, (string) $upload['file']);
        wp_update_attachment_metadata($id, $metadata);

        if (! empty($input['alt'])) {
            update_post_meta($id, '_wp_attachment_image_alt', (string) $input['alt']);
        }

        $attachment = get_post($id);

        if (! $attachment instanceof WP_Post) {
            return new WP_Error('wp_nerve_upload_failed', __('The attachment could not be registered.', 'wp-nerve'));
        }

        $item = $this->mediaItem($attachment);
        $item['recovery'] = array(
            'undo' => 'wp_nerve_delete_media',
            'note' => __('Delete the created attachment to undo this upload.', 'wp-nerve'),
        );

        return $item;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|WP_Error
     */
    public function updateMedia(mixed $input = null): array|WP_Error
    {
        $input = is_array($input) ? $input : array();
        $id    = (int) ($input['id'] ?? 0);
        $post  = get_post($id);

        if (! $post instanceof WP_Post || 'attachment' !== $post->post_type) {
            return new WP_Error('wp_nerve_media_not_found', __('The requested media item does not exist.', 'wp-nerve'));
        }

        if (! current_user_can('edit_post', $post->ID)) {
            return new WP_Error('wp_nerve_forbidden', __('You are not allowed to edit this media item.', 'wp-nerve'));
        }

        $update = array('ID' => $post->ID);

        if (array_key_exists('title', $input)) {
            $update['post_title'] = (string) $input['title'];
        }

        if (array_key_exists('caption', $input)) {
            $update['post_excerpt'] = (string) $input['caption'];
        }

        if (array_key_exists('description', $input)) {
            $update['post_content'] = (string) $input['description'];
        }

        $result = wp_update_post($update, true);

        if (is_wp_error($result)) {
            return $result;
        }

        if (array_key_exists('alt', $input)) {
            update_post_meta($post->ID, '_wp_attachment_image_alt', (string) $input['alt']);
        }

        $updated = get_post($post->ID);

        if (! $updated instanceof WP_Post) {
            return new WP_Error('wp_nerve_update_failed', __('The media item could not be updated.', 'wp-nerve'));
        }

        return $this->mediaItem($updated);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|WP_Error
     */
    public function deleteMedia(mixed $input = null): array|WP_Error
    {
        $input = is_array($input) ? $input : array();
        $id    = (int) ($input['id'] ?? 0);
        $post  = get_post($id);

        if (! $post instanceof WP_Post || 'attachment' !== $post->post_type) {
            return new WP_Error('wp_nerve_media_not_found', __('The requested media item does not exist.', 'wp-nerve'));
        }

        if (! current_user_can('delete_post', $post->ID)) {
            return new WP_Error('wp_nerve_forbidden', __('You are not allowed to delete this media item.', 'wp-nerve'));
        }

        $force = (bool) ($input['force'] ?? false);

        if ($force && ! current_user_can('delete_others_posts')) {
            return new WP_Error('wp_nerve_forbidden', __('Permanent deletion requires the delete_others_posts capability.', 'wp-nerve'));
        }

        $result = wp_delete_attachment($post->ID, $force);

        if (null === $result) {
            return new WP_Error('wp_nerve_delete_failed', __('The media item could not be deleted.', 'wp-nerve'));
        }

        return array(
            'id'     => $post->ID,
            'deleted' => true,
            'force'  => $force,
            'recovery' => $force
                ? array('note' => __('Permanent deletion cannot be undone.', 'wp-nerve'))
                : array('undo' => 'restore from trash', 'note' => __('Trashed media can be restored from the WordPress trash.', 'wp-nerve')),
        );
    }

    private function registerListMedia(): void
    {
        $this->registerReadAbility(
            'wp-nerve/list-media',
            __('List media', 'wp-nerve'),
            __('Lists media library items with optional mime and search filters.', 'wp-nerve'),
            array(
                '$schema'              => 'https://json-schema.org/draft/2020-12/schema',
                'type'                 => 'object',
                'additionalProperties' => false,
                'properties'           => array(
                    'number'    => array('type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20),
                    'offset'    => array('type' => 'integer', 'minimum' => 0, 'default' => 0),
                    'mime_type' => array('type' => 'string', 'maxLength' => 100),
                    'search'    => array('type' => 'string', 'maxLength' => 200),
                ),
            ),
            array(
                '$schema'              => 'https://json-schema.org/draft/2020-12/schema',
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array('items', 'total'),
                'properties'           => array(
                    'items' => array('type' => 'array', 'items' => $this->mediaItemSchema()),
                    'total' => array('type' => 'integer'),
                ),
            ),
            array($this, 'listMedia')
        );
    }

    private function registerGetMedia(): void
    {
        $this->registerReadAbility(
            'wp-nerve/get-media',
            __('Get media item', 'wp-nerve'),
            __('Returns a single media library item with metadata.', 'wp-nerve'),
            $this->mediaIdSchema(),
            array_merge(
                array('$schema' => 'https://json-schema.org/draft/2020-12/schema'),
                $this->mediaItemSchema()
            ),
            array($this, 'getMedia')
        );
    }

    private function registerUploadMedia(): void
    {
        $this->registerAbility(
            'wp-nerve/upload-media',
            __('Upload media', 'wp-nerve'),
            __('Uploads a base64-encoded file to the media library. Requires upload_files. Undo by deleting the attachment.', 'wp-nerve'),
            array(
                '$schema'              => 'https://json-schema.org/draft/2020-12/schema',
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array('filename', 'content'),
                'properties'           => array(
                    'filename'    => array('type' => 'string', 'minLength' => 1, 'maxLength' => 255),
                    'content'     => array('type' => 'string', 'minLength' => 1),
                    'mime_type'   => array('type' => 'string', 'maxLength' => 100),
                    'title'       => array('type' => 'string', 'maxLength' => 500),
                    'caption'     => array('type' => 'string'),
                    'description' => array('type' => 'string'),
                    'alt'         => array('type' => 'string', 'maxLength' => 500),
                ),
            ),
            array(
                '$schema'              => 'https://json-schema.org/draft/2020-12/schema',
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array('id', 'title', 'filename', 'mime', 'url'),
                'properties'           => array_merge(
                    $this->mediaItemSchema()['properties'],
                    array('recovery' => array('type' => 'object'))
                ),
            ),
            array($this, 'uploadMedia'),
            'write',
            false,
            'upload_files'
        );
    }

    private function registerUpdateMedia(): void
    {
        $this->registerAbility(
            'wp-nerve/update-media',
            __('Update media item', 'wp-nerve'),
            __('Updates the title, caption, description, or alt text of a media item.', 'wp-nerve'),
            array(
                '$schema'              => 'https://json-schema.org/draft/2020-12/schema',
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array('id'),
                'properties'           => array(
                    'id'          => array('type' => 'integer', 'minimum' => 1),
                    'title'       => array('type' => 'string', 'maxLength' => 500),
                    'caption'     => array('type' => 'string'),
                    'description' => array('type' => 'string'),
                    'alt'         => array('type' => 'string', 'maxLength' => 500),
                ),
            ),
            array_merge(
                array('$schema' => 'https://json-schema.org/draft/2020-12/schema'),
                $this->mediaItemSchema()
            ),
            array($this, 'updateMedia'),
            'write'
        );
    }

    private function registerDeleteMedia(): void
    {
        $this->registerAbility(
            'wp-nerve/delete-media',
            __('Delete media item', 'wp-nerve'),
            __('Deletes a media item. Trash by default; permanent deletion requires delete_others_posts.', 'wp-nerve'),
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
            array($this, 'deleteMedia'),
            'destructive',
            false
        );
    }

    /** @return array<string, mixed> */
    private function mediaItemSchema(): array
    {
        return array(
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => array('id', 'title', 'filename', 'mime', 'url', 'date', 'author', 'alt', 'caption', 'description', 'width', 'height'),
            'properties'           => array(
                'id'          => array('type' => 'integer'),
                'title'       => array('type' => 'string'),
                'filename'    => array('type' => 'string'),
                'mime'        => array('type' => 'string'),
                'url'         => array('type' => 'string', 'format' => 'uri'),
                'date'        => array('type' => 'string'),
                'modified'    => array('type' => 'string'),
                'author'      => array('type' => 'integer'),
                'alt'         => array('type' => 'string'),
                'caption'     => array('type' => 'string'),
                'description' => array('type' => 'string'),
                'width'       => array('type' => 'integer'),
                'height'      => array('type' => 'integer'),
                'file'        => array('type' => 'string'),
            ),
        );
    }

    /** @return array<string, mixed> */
    private function mediaIdSchema(): array
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
    private function mediaItem(WP_Post $attachment): array
    {
        $metadata = wp_get_attachment_metadata($attachment->ID);
        $metadata = is_array($metadata) ? $metadata : array();

        return array(
            'id'          => $attachment->ID,
            'title'       => $attachment->post_title,
            'filename'    => basename((string) get_attached_file($attachment->ID)),
            'mime'        => $attachment->post_mime_type,
            'url'         => (string) wp_get_attachment_url($attachment->ID),
            'date'        => $attachment->post_date,
            'modified'    => $attachment->post_modified,
            'author'      => (int) $attachment->post_author,
            'alt'         => (string) get_post_meta($attachment->ID, '_wp_attachment_image_alt', true),
            'caption'     => $attachment->post_excerpt,
            'description' => $attachment->post_content,
            'width'       => (int) ($metadata['width'] ?? 0),
            'height'      => (int) ($metadata['height'] ?? 0),
            'file'        => (string) ($metadata['file'] ?? ''),
        );
    }

    private function maxUploadBytes(): int
    {
        /**
         * Filters the maximum accepted base64 upload size in bytes.
         *
         * @param int $bytes Maximum upload size.
         */
        $bytes = apply_filters('wp_nerve_max_upload_bytes', self::MAX_UPLOAD_BYTES);

        return is_int($bytes) && $bytes > 0 ? $bytes : self::MAX_UPLOAD_BYTES;
    }
}
