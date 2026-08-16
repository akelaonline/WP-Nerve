<?php

/**
 * Minimal WP_Post, WP_Post_Type, and WP_Query runtime doubles for unit tests.
 *
 * @package WPNerve
 */

declare(strict_types=1);

if (! class_exists('WP_Post')) {
    class WP_Post
    {
        public string $post_title = '';

        public string $post_content = '';

        public string $post_excerpt = '';

        public string $post_status = 'publish';

        public string $post_type = 'post';

        public string $post_name = '';

        public string $post_date = '2026-01-01 00:00:00';

        public string $post_modified = '2026-01-01 00:00:00';

        public string $post_password = '';

        public string $post_mime_type = '';

        public int $post_author = 0;

        public int $menu_order = 0;

        public int $post_parent = 0;

        public function __construct(public readonly int $ID)
        {
        }
    }
}

if (! class_exists('WP_Post_Type')) {
    class WP_Post_Type
    {
        public bool $public = true;

        public string $label = '';

        public ?string $rest_base = null;

        public bool $hierarchical = false;

        public bool $show_in_rest = false;

        /** @var array<int, string> */
        public array $supports = array();

        /** @param array<string, mixed> $args */
        public function __construct(public readonly string $name, array $args = array())
        {
            $this->public       = (bool) ($args['public'] ?? true);
            $this->label        = (string) ($args['label'] ?? $name);
            $this->rest_base    = isset($args['rest_base']) ? (string) $args['rest_base'] : null;
            $this->hierarchical = (bool) ($args['hierarchical'] ?? false);
            $this->show_in_rest = (bool) ($args['show_in_rest'] ?? false);
            $this->supports     = (array) ($args['supports'] ?? array());
        }
    }
}

if (! class_exists('WP_Term')) {
    class WP_Term
    {
        public string $name = '';

        public string $slug = '';

        public string $taxonomy = '';

        public int $parent = 0;

        public int $count = 0;

        public function __construct(public readonly int $term_id)
        {
        }
    }
}

if (! class_exists('WP_Taxonomy')) {
    class WP_Taxonomy
    {
        public string $label = '';

        /** @var array<int, string> */
        public array $object_type = array();

        public bool $hierarchical = false;

        public bool $show_in_rest = false;

        public ?string $rest_base = null;

        public bool $public = true;

        /** @param array<string, mixed> $args */
        public function __construct(public readonly string $name, array $args = array())
        {
            $this->label        = (string) ($args['label'] ?? $name);
            $this->object_type  = array_map('strval', (array) ($args['object_type'] ?? array()));
            $this->hierarchical = (bool) ($args['hierarchical'] ?? false);
            $this->show_in_rest = (bool) ($args['show_in_rest'] ?? false);
            $this->rest_base    = isset($args['rest_base']) ? (string) $args['rest_base'] : null;
            $this->public       = (bool) ($args['public'] ?? true);
        }
    }
}

if (! class_exists('WP_Comment')) {
    class WP_Comment
    {
        public int $comment_post_ID = 0;

        public string $comment_author = '';

        public string $comment_author_email = '';

        public string $comment_content = '';

        public string $comment_date = '2026-01-01 00:00:00';

        public string $comment_approved = '1';

        public int $comment_parent = 0;

        public int $user_id = 0;

        public string $comment_type = 'comment';

        public function __construct(public readonly int $comment_ID)
        {
        }
    }
}

if (! class_exists('WP_Query')) {
    class WP_Query
    {
        /** @var array<int, WP_Post> */
        public array $posts = array();

        public int $found_posts = 0;

        /** @var array<string, mixed> */
        public array $query_vars = array();

        public ?WP_Post $post = null;

        /** @param array<string, mixed> $query */
        public function __construct(array $query = array())
        {
            $this->query($query);
        }

        /** @param array<string, mixed> $query */
        public function query(array $query = array()): void
        {
            $this->query_vars = $query;

            \WPNerve\Tests\Fixtures\WPState::$lastQueryArgs = $query;
            \WPNerve\Tests\Fixtures\WPState::$queryCount++;

            $results = \WPNerve\Tests\Fixtures\WPState::$queryResults;

            if (is_callable($results)) {
                $results = $results($query);
            }

            $this->posts       = array_values((array) $results);
            $this->found_posts = count($this->posts);
        }

        /** @return array<int, WP_Post> */
        public function get_posts(): array
        {
            return $this->posts;
        }

        public function have_posts(): bool
        {
            return array() !== $this->posts;
        }

        public function the_post(): void
        {
            $this->post = array_shift($this->posts);
        }
    }
}
