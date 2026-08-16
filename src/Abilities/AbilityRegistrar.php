<?php

/**
 * Registers WPNerve's native WordPress abilities.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Abilities;

use WP_Error;
use WP_Post;
use WP_Post_Type;
use WP_Query;

final class AbilityRegistrar
{
    public function registerCategory(): void
    {
        wp_register_ability_category(
            'wp-nerve-site',
            array(
                'label'       => __('WPNerve: Site', 'wp-nerve'),
                'description' => __('Safe site information abilities exposed by WPNerve.', 'wp-nerve'),
            )
        );
    }

    public function registerAbilities(): void
    {
        $this->registerSiteStatus();
        $this->registerListContentTypes();
        $this->registerSearchContent();
        $this->registerGetContent();
    }

    public function canReadSiteStatus(mixed $input = null): bool
    {
        unset($input);

        return current_user_can($this->transportCapability());
    }

    /** @return array<string, mixed> */
    public function getSiteStatus(mixed $input = null): array
    {
        global $wp_version;

        unset($input);

        return array(
            'site_name'         => (string) get_bloginfo('name'),
            'site_url'          => site_url('/'),
            'wordpress_version' => (string) $wp_version,
            'php_version'       => PHP_VERSION,
            'multisite'         => is_multisite(),
            'mcp_endpoint'      => rest_url('wp-nerve/v1/mcp'),
            'protocol_versions' => array('2026-07-28', '2025-11-25', '2025-06-18'),
            'wpnerve_version'   => WP_NERVE_VERSION,
        );
    }

    /** @return array<string, mixed> */
    public function listContentTypes(mixed $input = null): array
    {
        unset($input);

        $types = array();

        foreach (get_post_types(array('public' => true), 'objects') as $type) {
            if (! $type instanceof WP_Post_Type) {
                continue;
            }

            $types[] = array(
                'name'         => $type->name,
                'label'        => $type->label,
                'rest_base'    => is_string($type->rest_base) && '' !== $type->rest_base ? $type->rest_base : $type->name,
                'hierarchical' => $type->hierarchical,
                'show_in_rest' => $type->show_in_rest,
                'supports'     => is_array($type->supports) ? array_values($type->supports) : array(),
            );
        }

        return array('types' => $types);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|WP_Error
     */
    public function searchContent(mixed $input = null): array|WP_Error
    {
        $input = is_array($input) ? $input : array();
        $query = trim((string) ($input['query'] ?? ''));

        if ('' === $query) {
            return new WP_Error('wp_nerve_invalid_query', __('The query parameter must be a non-empty string.', 'wp-nerve'));
        }

        $status = (string) ($input['status'] ?? 'publish');

        if ('publish' !== $status && ! current_user_can('read_private_posts')) {
            return new WP_Error(
                'wp_nerve_forbidden_status',
                __('Searching non-public content requires the read_private_posts capability.', 'wp-nerve')
            );
        }

        $args = array(
            's'              => $query,
            'posts_per_page' => $this->clamp((int) ($input['number'] ?? 10), 1, 50),
            'post_status'    => 'any' === $status ? array('publish', 'private') : $status,
            'orderby'        => $this->searchOrderBy((string) ($input['orderby'] ?? 'relevance')),
            'order'          => 'ASC' === strtoupper((string) ($input['order'] ?? 'DESC')) ? 'ASC' : 'DESC',
        );

        if (! empty($input['post_type'])) {
            $args['post_type'] = is_array($input['post_type'])
                ? array_values(array_map('strval', $input['post_type']))
                : array((string) $input['post_type']);
        }

        $wp_query = new WP_Query($args);
        $items    = array();

        foreach ($wp_query->posts as $post) {
            if ($post instanceof WP_Post) {
                $items[] = $this->contentItem($post, false);
            }
        }

        return array(
            'items' => $items,
            'total' => $wp_query->found_posts,
            'query' => $query,
        );
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|WP_Error
     */
    public function getContent(mixed $input = null): array|WP_Error
    {
        $input = is_array($input) ? $input : array();
        $id    = (int) ($input['id'] ?? 0);

        if ($id < 1) {
            return new WP_Error('wp_nerve_invalid_id', __('The id parameter must be a positive integer.', 'wp-nerve'));
        }

        $post = get_post($id);

        if (! $post instanceof WP_Post) {
            return new WP_Error('wp_nerve_post_not_found', __('The requested post does not exist.', 'wp-nerve'));
        }

        if (! $this->canReadPost($post)) {
            return new WP_Error('wp_nerve_forbidden', __('You are not allowed to read this content.', 'wp-nerve'));
        }

        return $this->contentItem($post, true);
    }

    private function registerSiteStatus(): void
    {
        $this->registerReadAbility(
            'wp-nerve/site-status',
            __('Get site status', 'wp-nerve'),
            __(
                'Returns non-sensitive WordPress and WPNerve runtime information for connection diagnostics.',
                'wp-nerve'
            ),
            $this->emptyInputSchema(),
            array(
                '$schema'              => 'https://json-schema.org/draft/2020-12/schema',
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array(
                    'site_name',
                    'site_url',
                    'wordpress_version',
                    'php_version',
                    'multisite',
                    'mcp_endpoint',
                    'protocol_versions',
                    'wpnerve_version',
                ),
                'properties'           => array(
                    'site_name'         => array('type' => 'string'),
                    'site_url'          => array('type' => 'string', 'format' => 'uri'),
                    'wordpress_version' => array('type' => 'string'),
                    'php_version'       => array('type' => 'string'),
                    'multisite'         => array('type' => 'boolean'),
                    'mcp_endpoint'      => array('type' => 'string', 'format' => 'uri'),
                    'protocol_versions' => array(
                        'type'  => 'array',
                        'items' => array('type' => 'string'),
                    ),
                    'wpnerve_version'   => array('type' => 'string'),
                ),
            ),
            array($this, 'getSiteStatus')
        );
    }

    private function registerListContentTypes(): void
    {
        $this->registerReadAbility(
            'wp-nerve/list-content-types',
            __('List content types', 'wp-nerve'),
            __('Returns the public content types registered in the site.', 'wp-nerve'),
            $this->emptyInputSchema(),
            array(
                '$schema'              => 'https://json-schema.org/draft/2020-12/schema',
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array('types'),
                'properties'           => array(
                    'types' => array(
                        'type'  => 'array',
                        'items' => array(
                            'type'                 => 'object',
                            'additionalProperties' => false,
                            'required'             => array(
                                'name',
                                'label',
                                'rest_base',
                                'hierarchical',
                                'show_in_rest',
                                'supports',
                            ),
                            'properties'           => array(
                                'name'         => array('type' => 'string'),
                                'label'        => array('type' => 'string'),
                                'rest_base'    => array('type' => 'string'),
                                'hierarchical' => array('type' => 'boolean'),
                                'show_in_rest' => array('type' => 'boolean'),
                                'supports'     => array(
                                    'type'  => 'array',
                                    'items' => array('type' => 'string'),
                                ),
                            ),
                        ),
                    ),
                ),
            ),
            array($this, 'listContentTypes')
        );
    }

    private function registerSearchContent(): void
    {
        $this->registerReadAbility(
            'wp-nerve/search-content',
            __('Search content', 'wp-nerve'),
            __('Searches published content and returns matching posts with metadata.', 'wp-nerve'),
            array(
                '$schema'              => 'https://json-schema.org/draft/2020-12/schema',
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array('query'),
                'properties'           => array(
                    'query'     => array(
                        'type'      => 'string',
                        'minLength' => 1,
                        'maxLength' => 200,
                    ),
                    'post_type' => array(
                        'oneOf' => array(
                            array('type' => 'string'),
                            array('type' => 'array', 'items' => array('type' => 'string')),
                        ),
                    ),
                    'number'    => array(
                        'type'    => 'integer',
                        'minimum' => 1,
                        'maximum' => 50,
                        'default' => 10,
                    ),
                    'orderby'   => array(
                        'type'    => 'string',
                        'enum'    => array('relevance', 'date', 'modified', 'title'),
                        'default' => 'relevance',
                    ),
                    'order'     => array(
                        'type'    => 'string',
                        'enum'    => array('ASC', 'DESC'),
                        'default' => 'DESC',
                    ),
                    'status'    => array(
                        'type'    => 'string',
                        'enum'    => array('publish', 'private', 'any'),
                        'default' => 'publish',
                    ),
                ),
            ),
            array(
                '$schema'              => 'https://json-schema.org/draft/2020-12/schema',
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array('items', 'total', 'query'),
                'properties'           => array(
                    'items' => array(
                        'type'  => 'array',
                        'items' => $this->contentItemSchema(false),
                    ),
                    'total' => array('type' => 'integer'),
                    'query' => array('type' => 'string'),
                ),
            ),
            array($this, 'searchContent')
        );
    }

    private function registerGetContent(): void
    {
        $this->registerReadAbility(
            'wp-nerve/get-content',
            __('Get content', 'wp-nerve'),
            __('Returns a single post with full content when the user may read it.', 'wp-nerve'),
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
                'required'             => array('id', 'title', 'type', 'status', 'date', 'modified', 'author', 'link', 'excerpt', 'content'),
                'properties'           => $this->contentItemSchema(true),
            ),
            array($this, 'getContent')
        );
    }

    /**
     * @param lowercase-string&non-falsy-string $name
     * @param array<string, mixed>              $inputSchema
     * @param array<string, mixed>              $outputSchema
     * @param callable                          $execute
     */
    private function registerReadAbility(
        string $name,
        string $label,
        string $description,
        array $inputSchema,
        array $outputSchema,
        callable $execute
    ): void {
        wp_register_ability(
            $name,
            array(
                'label'               => $label,
                'description'         => $description,
                'category'            => 'wp-nerve-site',
                'input_schema'        => $inputSchema,
                'output_schema'       => $outputSchema,
                'execute_callback'    => $execute,
                'permission_callback' => array($this, 'canReadSiteStatus'),
                'meta'                => array(
                    'public'       => true,
                    'show_in_rest' => false,
                    'annotations'  => array(
                        'readonly'    => true,
                        'destructive' => false,
                        'idempotent'  => true,
                    ),
                    'wp_nerve'     => array(
                        'risk'               => 'read',
                        'capability'         => $this->transportCapability(),
                        'enabled_by_default' => true,
                    ),
                ),
            )
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyInputSchema(): array
    {
        return array(
            '$schema'              => 'https://json-schema.org/draft/2020-12/schema',
            'type'                 => 'object',
            'properties'           => array(),
            'additionalProperties' => false,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function contentItemSchema(bool $withContent): array
    {
        $schema = array(
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => array(
                'id',
                'title',
                'type',
                'status',
                'date',
                'modified',
                'author',
                'link',
                'excerpt',
            ),
            'properties'           => array(
                'id'       => array('type' => 'integer'),
                'title'    => array('type' => 'string'),
                'type'     => array('type' => 'string'),
                'status'   => array('type' => 'string'),
                'date'     => array('type' => 'string'),
                'modified' => array('type' => 'string'),
                'author'   => array('type' => 'integer'),
                'link'     => array('type' => 'string', 'format' => 'uri'),
                'excerpt'  => array('type' => 'string'),
            ),
        );

        if ($withContent) {
            $schema['required'][]   = 'content';
            $schema['properties']['content'] = array('type' => 'string');
        }

        return $schema;
    }

    /** @return array<string, mixed> */
    private function contentItem(WP_Post $post, bool $withContent): array
    {
        $item = array(
            'id'       => $post->ID,
            'title'    => $post->post_title,
            'type'     => $post->post_type,
            'status'   => $post->post_status,
            'date'     => $post->post_date,
            'modified' => $post->post_modified,
            'author'   => (int) $post->post_author,
            'link'     => (string) get_permalink($post),
            'excerpt'  => '' !== $post->post_excerpt
                ? $post->post_excerpt
                : wp_trim_words($post->post_content, 30),
        );

        if ($withContent) {
            $item['content'] = $post->post_content;
        }

        return $item;
    }

    private function canReadPost(WP_Post $post): bool
    {
        if ('publish' === $post->post_status) {
            return true;
        }

        if ('private' === $post->post_status) {
            return current_user_can('read_private_posts');
        }

        return current_user_can('edit_post', $post->ID);
    }

    private function searchOrderBy(string $orderby): string
    {
        return in_array($orderby, array('relevance', 'date', 'modified', 'title'), true) ? $orderby : 'relevance';
    }

    private function clamp(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }

    private function transportCapability(): string
    {
        /**
         * Filters the minimum capability required to access WPNerve.
         *
         * @param string $capability WordPress capability name.
         */
        $capability = apply_filters('wp_nerve_transport_capability', 'edit_posts');

        return is_string($capability) && '' !== $capability ? $capability : 'do_not_allow';
    }
}
