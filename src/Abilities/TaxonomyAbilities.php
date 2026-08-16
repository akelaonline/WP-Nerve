<?php

/**
 * Taxonomy and term abilities.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Abilities;

use WP_Error;
use WP_Post;
use WP_Taxonomy;
use WP_Term;

final class TaxonomyAbilities extends AbstractAbilityRegistrar
{
    public function register(): void
    {
        $this->registerListTaxonomies();
        $this->registerListTerms();
        $this->registerCreateTerm();
        $this->registerAssignTerms();
    }

    /** @return array<string, mixed> */
    public function listTaxonomies(mixed $input = null): array
    {
        unset($input);

        $taxonomies = array();

        foreach (get_taxonomies(array('public' => true), 'objects') as $taxonomy) {
            if (! $taxonomy instanceof WP_Taxonomy) {
                continue;
            }

            $taxonomies[] = array(
                'name'          => $taxonomy->name,
                'label'         => $taxonomy->label,
                'object_type'   => array_values($taxonomy->object_type),
                'hierarchical'  => $taxonomy->hierarchical,
                'rest_base'     => is_string($taxonomy->rest_base) && '' !== $taxonomy->rest_base ? $taxonomy->rest_base : $taxonomy->name,
                'show_in_rest'  => $taxonomy->show_in_rest,
            );
        }

        return array('taxonomies' => $taxonomies);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|WP_Error
     */
    public function listTerms(mixed $input = null): array|WP_Error
    {
        $input    = is_array($input) ? $input : array();
        $taxonomy = (string) ($input['taxonomy'] ?? '');

        if ('' === $taxonomy || ! taxonomy_exists($taxonomy)) {
            return new WP_Error('wp_nerve_invalid_taxonomy', __('The taxonomy does not exist.', 'wp-nerve'));
        }

        $args = array(
            'taxonomy'   => $taxonomy,
            'hide_empty' => (bool) ($input['hide_empty'] ?? false),
            'number'     => $this->clamp((int) ($input['number'] ?? 50), 1, 200),
        );

        if (! empty($input['search'])) {
            $args['search'] = (string) $input['search'];
        }

        $terms = get_terms($args);

        if (is_wp_error($terms)) {
            return $terms;
        }

        $items = array();

        foreach ($terms as $term) {
            if ($term instanceof WP_Term) {
                $items[] = $this->termItem($term);
            }
        }

        return array('items' => $items, 'total' => count($items));
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|WP_Error
     */
    public function createTerm(mixed $input = null): array|WP_Error
    {
        $input    = is_array($input) ? $input : array();
        $taxonomy = (string) ($input['taxonomy'] ?? '');
        $name     = trim((string) ($input['name'] ?? ''));

        if ('' === $name) {
            return new WP_Error('wp_nerve_invalid_name', __('The name parameter must be a non-empty string.', 'wp-nerve'));
        }

        if ('' === $taxonomy || ! taxonomy_exists($taxonomy)) {
            return new WP_Error('wp_nerve_invalid_taxonomy', __('The taxonomy does not exist.', 'wp-nerve'));
        }

        if (! current_user_can('manage_categories')) {
            return new WP_Error('wp_nerve_forbidden', __('You are not allowed to create terms.', 'wp-nerve'));
        }

        $args = array();

        if (! empty($input['slug'])) {
            $args['slug'] = sanitize_title((string) $input['slug']);
        }

        if (! empty($input['parent'])) {
            $args['parent'] = (int) $input['parent'];
        }

        $result = wp_insert_term($name, $taxonomy, $args);

        if (is_wp_error($result)) {
            return $result;
        }

        $term = get_term((int) $result['term_id'], $taxonomy);

        if (! $term instanceof WP_Term) {
            return new WP_Error('wp_nerve_create_failed', __('The term could not be created.', 'wp-nerve'));
        }

        return $this->termItem($term);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|WP_Error
     */
    public function assignTerms(mixed $input = null): array|WP_Error
    {
        $input    = is_array($input) ? $input : array();
        $postId   = (int) ($input['id'] ?? 0);
        $taxonomy = (string) ($input['taxonomy'] ?? '');

        $post = get_post($postId);

        if (! $post instanceof WP_Post) {
            return new WP_Error('wp_nerve_post_not_found', __('The requested post does not exist.', 'wp-nerve'));
        }

        if (! current_user_can('edit_post', $post->ID)) {
            return new WP_Error('wp_nerve_forbidden', __('You are not allowed to edit this content.', 'wp-nerve'));
        }

        if ('' === $taxonomy || ! taxonomy_exists($taxonomy)) {
            return new WP_Error('wp_nerve_invalid_taxonomy', __('The taxonomy does not exist.', 'wp-nerve'));
        }

        $previous = wp_get_object_terms($post->ID, $taxonomy, array('fields' => 'ids'));
        $terms    = isset($input['terms']) && is_array($input['terms'])
            ? array_values(array_map('absint', $input['terms']))
            : array();

        $result = wp_set_object_terms($post->ID, $terms, $taxonomy, (bool) ($input['append'] ?? false));

        if (is_wp_error($result)) {
            return $result;
        }

        return array(
            'id'             => $post->ID,
            'taxonomy'       => $taxonomy,
            'terms'          => array_values(array_map('intval', $result)),
            'previous_terms' => array_values(array_map('intval', is_array($previous) ? $previous : array())),
        );
    }

    private function registerListTaxonomies(): void
    {
        $this->registerReadAbility(
            'wp-nerve/list-taxonomies',
            __('List taxonomies', 'wp-nerve'),
            __('Returns the public taxonomies registered in the site.', 'wp-nerve'),
            $this->emptyInputSchema(),
            array(
                '$schema'              => 'https://json-schema.org/draft/2020-12/schema',
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array('taxonomies'),
                'properties'           => array(
                    'taxonomies' => array(
                        'type'  => 'array',
                        'items' => array(
                            'type'                 => 'object',
                            'additionalProperties' => false,
                            'required'             => array('name', 'label', 'object_type', 'hierarchical', 'rest_base', 'show_in_rest'),
                            'properties'           => array(
                                'name'         => array('type' => 'string'),
                                'label'        => array('type' => 'string'),
                                'object_type'  => array('type' => 'array', 'items' => array('type' => 'string')),
                                'hierarchical' => array('type' => 'boolean'),
                                'rest_base'    => array('type' => 'string'),
                                'show_in_rest' => array('type' => 'boolean'),
                            ),
                        ),
                    ),
                ),
            ),
            array($this, 'listTaxonomies')
        );
    }

    private function registerListTerms(): void
    {
        $this->registerReadAbility(
            'wp-nerve/list-terms',
            __('List terms', 'wp-nerve'),
            __('Lists the terms of a taxonomy with optional search.', 'wp-nerve'),
            array(
                '$schema'              => 'https://json-schema.org/draft/2020-12/schema',
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array('taxonomy'),
                'properties'           => array(
                    'taxonomy'   => array('type' => 'string', 'minLength' => 1),
                    'search'     => array('type' => 'string', 'maxLength' => 200),
                    'number'     => array('type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50),
                    'hide_empty' => array('type' => 'boolean', 'default' => false),
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
                        'items' => $this->termItemSchema(),
                    ),
                    'total' => array('type' => 'integer'),
                ),
            ),
            array($this, 'listTerms')
        );
    }

    private function registerCreateTerm(): void
    {
        $this->registerAbility(
            'wp-nerve/create-term',
            __('Create term', 'wp-nerve'),
            __('Creates a term in a taxonomy. Requires manage_categories. Undo by deleting the created term.', 'wp-nerve'),
            array(
                '$schema'              => 'https://json-schema.org/draft/2020-12/schema',
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array('taxonomy', 'name'),
                'properties'           => array(
                    'taxonomy' => array('type' => 'string', 'minLength' => 1),
                    'name'     => array('type' => 'string', 'minLength' => 1, 'maxLength' => 200),
                    'slug'     => array('type' => 'string', 'maxLength' => 200),
                    'parent'   => array('type' => 'integer', 'minimum' => 1),
                ),
            ),
            array_merge(
                array('$schema' => 'https://json-schema.org/draft/2020-12/schema'),
                $this->termItemSchema()
            ),
            array($this, 'createTerm'),
            'write',
            false,
            'manage_categories'
        );
    }

    private function registerAssignTerms(): void
    {
        $this->registerAbility(
            'wp-nerve/assign-terms',
            __('Assign terms', 'wp-nerve'),
            __('Assigns terms to a post. The previous assignments are returned so they can be restored.', 'wp-nerve'),
            array(
                '$schema'              => 'https://json-schema.org/draft/2020-12/schema',
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array('id', 'taxonomy', 'terms'),
                'properties'           => array(
                    'id'       => array('type' => 'integer', 'minimum' => 1),
                    'taxonomy' => array('type' => 'string', 'minLength' => 1),
                    'terms'    => array('type' => 'array', 'items' => array('type' => 'integer')),
                    'append'   => array('type' => 'boolean', 'default' => false),
                ),
            ),
            array(
                '$schema'              => 'https://json-schema.org/draft/2020-12/schema',
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array('id', 'taxonomy', 'terms', 'previous_terms'),
                'properties'           => array(
                    'id'             => array('type' => 'integer'),
                    'taxonomy'       => array('type' => 'string'),
                    'terms'          => array('type' => 'array', 'items' => array('type' => 'integer')),
                    'previous_terms' => array('type' => 'array', 'items' => array('type' => 'integer')),
                ),
            ),
            array($this, 'assignTerms'),
            'write'
        );
    }

    /** @return array<string, mixed> */
    private function termItem(WP_Term $term): array
    {
        return array(
            'id'       => $term->term_id,
            'name'     => $term->name,
            'slug'     => $term->slug,
            'taxonomy' => $term->taxonomy,
            'parent'   => (int) $term->parent,
            'count'    => (int) $term->count,
        );
    }

    /** @return array<string, mixed> */
    private function termItemSchema(): array
    {
        return array(
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => array('id', 'name', 'slug', 'taxonomy', 'parent', 'count'),
            'properties'           => array(
                'id'       => array('type' => 'integer'),
                'name'     => array('type' => 'string'),
                'slug'     => array('type' => 'string'),
                'taxonomy' => array('type' => 'string'),
                'parent'   => array('type' => 'integer'),
                'count'    => array('type' => 'integer'),
            ),
        );
    }
}
