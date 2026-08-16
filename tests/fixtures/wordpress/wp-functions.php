<?php

/**
 * Minimal WordPress function stubs backed by WPState.
 *
 * Every stub is guarded with function_exists() so this file can be loaded in
 * any environment without colliding with a real WordPress installation.
 *
 * @package WPNerve
 */

declare(strict_types=1);

use WPNerve\Tests\Fixtures\WPState;
use WPNerve\Tests\Fixtures\WpDb;

defined('OBJECT') || define('OBJECT', 'OBJECT');
defined('ARRAY_A') || define('ARRAY_A', 'ARRAY_A');
defined('ARRAY_N') || define('ARRAY_N', 'ARRAY_N');

if (! function_exists('current_user_can')) {
    /**
     * @param mixed ...$args
     */
    function current_user_can(string $capability, ...$args): bool
    {
        $can = WPState::$userCan;

        return is_callable($can) ? (bool) $can($capability, ...$args) : (bool) $can;
    }
}

if (! function_exists('is_user_logged_in')) {
    function is_user_logged_in(): bool
    {
        return WPState::$isLoggedIn;
    }
}

if (! function_exists('is_ssl')) {
    function is_ssl(): bool
    {
        return WPState::$isSsl;
    }
}

if (! function_exists('wp_get_environment_type')) {
    function wp_get_environment_type(): string
    {
        return WPState::$environmentType;
    }
}

if (! function_exists('site_url')) {
    function site_url(string $path = '', ?string $scheme = null): string
    {
        unset($scheme);

        return WPState::$siteUrl . $path;
    }
}

if (! function_exists('rest_url')) {
    function rest_url(string $path = '/', ?string $scheme = null): string
    {
        unset($scheme);

        return rtrim(WPState::$restUrl, '/') . '/' . ltrim($path, '/');
    }
}

if (! function_exists('get_bloginfo')) {
    function get_bloginfo(string $show = '', string $filter = 'raw'): string
    {
        unset($filter);

        $show = '' === $show ? 'name' : $show;

        return (string) (WPState::$bloginfo[$show] ?? '');
    }
}

if (! function_exists('is_multisite')) {
    function is_multisite(): bool
    {
        return WPState::$multisite;
    }
}

if (! function_exists('get_option')) {
    function get_option(string $option, mixed $default = false): mixed
    {
        return array_key_exists($option, WPState::$options) ? WPState::$options[$option] : $default;
    }
}

if (! function_exists('update_option')) {
    function update_option(string $option, mixed $value, bool $autoload = true): bool
    {
        unset($autoload);

        WPState::$options[$option] = $value;

        return true;
    }
}

if (! function_exists('delete_option')) {
    function delete_option(string $option): bool
    {
        unset(WPState::$options[$option]);

        return true;
    }
}

if (! function_exists('current_time')) {
    function current_time(string $type = 'timestamp', $gmt = 0): string|int
    {
        unset($gmt);

        return 'mysql' === $type ? '2026-08-16 12:00:00' : 1783008000;
    }
}

if (! function_exists('get_current_user_id')) {
    function get_current_user_id(): int
    {
        return WPState::$currentUserId;
    }
}

if (! function_exists('add_action')) {
    /**
     * @param callable $callback
     */
    function add_action(string $tag, $callback, int $priority = 10, int $accepted_args = 1): bool
    {
        unset($priority, $accepted_args);

        WPState::$actions[$tag][] = $callback;

        return true;
    }
}

if (! function_exists('do_action')) {
    /**
     * @param mixed ...$args
     */
    function do_action(string $tag, ...$args): void
    {
        foreach (WPState::$actions[$tag] ?? array() as $callback) {
            call_user_func_array($callback, $args);
        }
    }
}

if (! function_exists('add_filter')) {
    /**
     * @param callable $callback
     */
    function add_filter(string $tag, $callback, int $priority = 10, int $accepted_args = 1): bool
    {
        unset($priority, $accepted_args);

        WPState::$filters[$tag][] = $callback;

        return true;
    }
}

if (! function_exists('apply_filters')) {
    /**
     * @param mixed ...$args
     */
    function apply_filters(string $tag, mixed $value, ...$args): mixed
    {
        foreach (WPState::$filters[$tag] ?? array() as $callback) {
            $value = call_user_func_array($callback, array_merge(array($value), $args));
        }

        return $value;
    }
}

if (! function_exists('wp_get_abilities')) {
    /** @return array<int, WP_Ability> */
    function wp_get_abilities(): array
    {
        return WPState::$abilities;
    }
}

if (! function_exists('wp_register_ability')) {
    /**
     * @param array<string, mixed> $args
     */
    function wp_register_ability(string $name, array $args = array()): WP_Ability
    {
        $ability = new WP_Ability($name, $args);

        WPState::$registeredAbilities[] = $ability;
        WPState::$abilities[]           = $ability;

        return $ability;
    }
}

if (! function_exists('wp_register_ability_category')) {
    /**
     * @param array<string, mixed> $args
     */
    function wp_register_ability_category(string $name, array $args = array()): bool
    {
        WPState::$registeredCategories[] = array('name' => $name, 'args' => $args);

        return true;
    }
}

if (! function_exists('register_rest_route')) {
    /**
     * @param array<string, mixed> $args
     */
    function register_rest_route(string $namespace, string $route, array $args = array()): bool
    {
        WPState::$restRoutes[] = array('namespace' => $namespace, 'route' => $route, 'args' => $args);

        return true;
    }
}

if (! function_exists('register_activation_hook')) {
    /**
     * @param callable $callback
     */
    function register_activation_hook(string $file, $callback): void
    {
        WPState::$activationHooks[] = array('file' => $file, 'callback' => $callback);
    }
}

if (! function_exists('deactivate_plugins')) {
    /**
     * @param string|array<int, string> $plugins
     */
    function deactivate_plugins($plugins, bool $silent = false, ?bool $network_wide = null): void
    {
        unset($silent, $network_wide);

        WPState::$deactivatedPlugins = array_merge(WPState::$deactivatedPlugins, (array) $plugins);
    }
}

if (! function_exists('wp_die')) {
    /**
     * @param array<string, mixed> $args
     */
    function wp_die(string $message = '', string $title = '', array $args = array()): void
    {
        unset($args);

        WPState::$wpDieCalls[] = array('message' => $message, 'title' => $title);
    }
}

if (! function_exists('add_management_page')) {
    /**
     * @param callable $callback
     */
    function add_management_page(
        string $page_title,
        string $menu_title,
        string $capability,
        string $menu_slug,
        $callback,
        string $icon_url = '',
        int|float|null $position = null
    ): string {
        WPState::$managementPages[] = func_get_args();

        return (string) $menu_slug;
    }
}

if (! function_exists('load_plugin_textdomain')) {
    function load_plugin_textdomain(string $domain, bool $deprecated = false, ?string $plugin_rel_path = null): bool
    {
        unset($deprecated);

        WPState::$localization[] = array(
            'domain'  => $domain,
            'relPath' => (string) $plugin_rel_path,
        );

        return true;
    }
}

if (! function_exists('__')) {
    function __(string $text, string $domain = 'default'): string
    {
        unset($domain);

        return $text;
    }
}

if (! function_exists('esc_html__')) {
    function esc_html__(string $text, string $domain = 'default'): string
    {
        unset($domain);

        return $text;
    }
}

if (! function_exists('esc_html')) {
    function esc_html(string $text): string
    {
        return $text;
    }
}

if (! function_exists('esc_attr')) {
    function esc_attr(string $text): string
    {
        return $text;
    }
}

if (! function_exists('sanitize_key')) {
    function sanitize_key(string $key): string
    {
        return (string) preg_replace('/[^a-z0-9_\-]/', '', strtolower($key));
    }
}

if (! function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $str): string
    {
        return trim(strip_tags(str_replace(array("\n", "\t"), ' ', $str)));
    }
}

if (! function_exists('wp_check_invalid_utf8')) {
    function wp_check_invalid_utf8(string $text, bool $strip = false): string
    {
        unset($strip);

        return $text;
    }
}

if (! function_exists('wp_json_encode')) {
    function wp_json_encode(mixed $data, int $options = 0, int $depth = 512): string|false
    {
        return json_encode($data, $options, $depth);
    }
}

if (! function_exists('wp_parse_url')) {
    function wp_parse_url(string $url, int $component = -1): array|string|int|null
    {
        return parse_url($url, $component);
    }
}

if (! function_exists('wp_parse_args')) {
    /**
     * @param array<string, mixed> $args
     * @param array<string, mixed> $defaults
     * @return array<string, mixed>
     */
    function wp_parse_args(array $args, array $defaults = array()): array
    {
        return array_merge($defaults, $args);
    }
}

if (! function_exists('absint')) {
    function absint(mixed $maybeint): int
    {
        return abs((int) $maybeint);
    }
}

if (! function_exists('plugin_dir_path')) {
    function plugin_dir_path(string $file): string
    {
        return '' !== WPState::$pluginDirPath ? WPState::$pluginDirPath : dirname($file) . '/';
    }
}

if (! function_exists('plugin_dir_url')) {
    function plugin_dir_url(string $file): string
    {
        unset($file);

        return WPState::$pluginDirUrl;
    }
}

if (! function_exists('plugin_basename')) {
    function plugin_basename(string $file): string
    {
        return basename(dirname($file)) . '/' . basename($file);
    }
}

if (! function_exists('dbDelta')) {
    /**
     * @return array<int, string>
     */
    function dbDelta(string $queries = '', bool $execute = true): array
    {
        unset($execute);

        WPState::$schemaCalls[] = $queries;

        return array();
    }
}

if (! function_exists('is_wp_error')) {
    function is_wp_error(mixed $thing): bool
    {
        return $thing instanceof WP_Error;
    }
}

if (! function_exists('wp_get_current_user')) {
    function wp_get_current_user(): object
    {
        return (object) array(
            'ID'           => WPState::$currentUserId,
            'display_name' => WPState::$currentUserDisplayName,
        );
    }
}

if (! function_exists('wp_unslash')) {
    function wp_unslash(mixed $value): mixed
    {
        return $value;
    }
}

if (! function_exists('get_post_types')) {
    /**
     * @param array<string, mixed> $args
     */
    function get_post_types(array $args = array(), string $output = 'names', string $operator = 'and'): array
    {
        unset($operator);

        $names = array();

        foreach (WPState::$postTypes as $name => $object) {
            if (isset($args['public']) && (bool) $object->public !== (bool) $args['public']) {
                continue;
            }

            $names[] = $name;
        }

        if ('objects' === $output) {
            return array_values(array_map(static fn (string $name): object => WPState::$postTypes[$name], $names));
        }

        return $names;
    }
}

if (! function_exists('get_post_type_object')) {
    function get_post_type_object(string $post_type): ?WP_Post_Type
    {
        return WPState::$postTypes[$post_type] ?? null;
    }
}

if (! function_exists('post_type_exists')) {
    function post_type_exists(string $post_type): bool
    {
        return isset(WPState::$postTypes[$post_type]);
    }
}

if (! function_exists('get_all_post_type_supports')) {
    /**
     * @return array<string, bool>
     */
    function get_all_post_type_supports(string $post_type): array
    {
        $type = get_post_type_object($post_type);

        if (null === $type) {
            return array();
        }

        return array_fill_keys($type->supports, true);
    }
}

if (! function_exists('get_post')) {
    /**
     * @param int|WP_Post|null $post
     */
    function get_post($post = null, string $output = OBJECT, string $filter = 'raw'): ?WP_Post
    {
        unset($output, $filter);

        $id = $post instanceof WP_Post ? $post->ID : (int) $post;

        return WPState::$posts[$id] ?? null;
    }
}

if (! function_exists('get_permalink')) {
    function get_permalink(WP_Post|int $post = 0): string
    {
        $id = $post instanceof WP_Post ? $post->ID : (int) $post;

        return WPState::$siteUrl . '/?p=' . $id;
    }
}

if (! function_exists('get_the_title')) {
    function get_the_title(WP_Post|int $post = 0): string
    {
        $post = get_post($post);

        return null === $post ? '' : $post->post_title;
    }
}

if (! function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags(string $text, bool $remove_breaks = false): string
    {
        $text = strip_tags($text);

        if ($remove_breaks) {
            $text = (string) preg_replace('/[\r\n\t ]+/', ' ', $text);
        }

        return $text;
    }
}

if (! function_exists('wp_trim_words')) {
    function wp_trim_words(string $text, int $num_words = 55, ?string $more = null): string
    {
        unset($more);

        $words = preg_split('/\s+/', trim(wp_strip_all_tags($text)));

        if (false === $words || count($words) <= $num_words) {
            return trim(wp_strip_all_tags($text));
        }

        return implode(' ', array_slice($words, 0, $num_words)) . '…';
    }
}

if (! function_exists('wp_insert_post')) {
    /**
     * @param array<string, mixed> $postarr
     */
    function wp_insert_post(array $postarr, bool $wp_error = false): int|WP_Error
    {
        unset($wp_error);

        $id = WPState::$nextPostId++;

        $post = new WP_Post($id);
        $post->post_title   = (string) ($postarr['post_title'] ?? '');
        $post->post_content = (string) ($postarr['post_content'] ?? '');
        $post->post_excerpt = (string) ($postarr['post_excerpt'] ?? '');
        $post->post_status  = (string) ($postarr['post_status'] ?? 'draft');
        $post->post_type    = (string) ($postarr['post_type'] ?? 'post');
        $post->post_author  = WPState::$currentUserId;

        WPState::$posts[$id]      = $post;
        WPState::$lastInsertedPost = $postarr;

        return $id;
    }
}

if (! function_exists('wp_update_post')) {
    /**
     * @param array<string, mixed> $postarr
     */
    function wp_update_post(array $postarr, bool $wp_error = false): int|WP_Error
    {
        unset($wp_error);

        $id = (int) ($postarr['ID'] ?? 0);

        if (! isset(WPState::$posts[$id])) {
            return new WP_Error('wp_nerve_missing_post', 'Post not found.');
        }

        $post = WPState::$posts[$id];

        foreach (array('post_title', 'post_content', 'post_excerpt', 'post_status', 'post_type') as $field) {
            if (array_key_exists($field, $postarr)) {
                $post->{$field} = (string) $postarr[$field];
            }
        }

        WPState::$lastUpdatedPost = $postarr;

        return $id;
    }
}

if (! function_exists('wp_trash_post')) {
    function wp_trash_post(int $post_id = 0): ?WP_Post
    {
        $post = get_post($post_id);

        if (null === $post) {
            return null;
        }

        $post->post_status = 'trash';
        WPState::$trashedPostIds[] = $post_id;

        return $post;
    }
}

if (! function_exists('wp_untrash_post')) {
    function wp_untrash_post(int $post_id = 0): ?WP_Post
    {
        $post = get_post($post_id);

        if (null === $post) {
            return null;
        }

        $post->post_status = 'publish';
        WPState::$untrashedPostIds[] = $post_id;

        return $post;
    }
}

if (! function_exists('wp_publish_post')) {
    function wp_publish_post(int|WP_Post|null $post = null): ?WP_Post
    {
        $id = $post instanceof WP_Post ? $post->ID : (int) $post;

        $post = get_post($id);

        if (null === $post) {
            return null;
        }

        $post->post_status = 'publish';
        WPState::$publishedPostIds[] = $id;

        return $post;
    }
}

if (! function_exists('wp_get_post_revisions')) {
    /**
     * @param array<string, mixed> $args
     * @return array<int, WP_Post>
     */
    function wp_get_post_revisions(int $post_id = 0, ?array $args = null): array
    {
        unset($args);

        $items = array();

        foreach (WPState::$revisions as $revision) {
            if ((int) $revision->post_parent === $post_id) {
                $items[$revision->ID] = $revision;
            }
        }

        krsort($items);

        return $items;
    }
}

if (! function_exists('wp_get_post_revision')) {
    function wp_get_post_revision(int|WP_Post|null $post = null): ?WP_Post
    {
        $id = $post instanceof WP_Post ? $post->ID : (int) $post;

        return WPState::$revisions[$id] ?? null;
    }
}

if (! function_exists('wp_restore_post_revision')) {
    function wp_restore_post_revision(int|WP_Post $revision_id, ?array $fields = null): int|false
    {
        unset($fields);

        $id = $revision_id instanceof WP_Post ? $revision_id->ID : (int) $revision_id;
        $revision = WPState::$revisions[$id] ?? null;

        if (null === $revision) {
            return false;
        }

        WPState::$restoredRevisionIds[] = $id;

        $parent = WPState::$posts[(int) $revision->post_parent] ?? null;

        if (null === $parent) {
            return false;
        }

        $parent->post_title   = $revision->post_title;
        $parent->post_content = $revision->post_content;
        $parent->post_excerpt = $revision->post_excerpt;

        return (int) $revision->post_parent;
    }
}

if (! function_exists('get_taxonomies')) {
    /**
     * @param array<string, mixed> $args
     * @return array<int, string>|array<int, WP_Taxonomy>
     */
    function get_taxonomies(array $args = array(), string $output = 'names', string $operator = 'and'): array
    {
        unset($operator);

        $names = array();

        foreach (WPState::$taxonomies as $name => $taxonomy) {
            if (isset($args['public']) && (bool) $taxonomy->public !== (bool) $args['public']) {
                continue;
            }

            $names[] = $name;
        }

        if ('objects' === $output) {
            return array_values(array_map(static fn (string $name): object => WPState::$taxonomies[$name], $names));
        }

        return $names;
    }
}

if (! function_exists('taxonomy_exists')) {
    function taxonomy_exists(string $taxonomy): bool
    {
        return isset(WPState::$taxonomies[$taxonomy]);
    }
}

if (! function_exists('get_taxonomy')) {
    function get_taxonomy(string $taxonomy): ?WP_Taxonomy
    {
        return WPState::$taxonomies[$taxonomy] ?? null;
    }
}

if (! function_exists('get_terms')) {
    /**
     * @param array<string, mixed> $args
     * @return array<int, WP_Term>|WP_Error
     */
    function get_terms(array $args = array()): array|WP_Error
    {
        $taxonomy = (string) ($args['taxonomy'] ?? '');

        if ('' === $taxonomy || ! taxonomy_exists($taxonomy)) {
            return new WP_Error('invalid_taxonomy', 'Invalid taxonomy.');
        }

        $items = array();

        foreach (WPState::$terms as $term) {
            if ($term->taxonomy !== $taxonomy) {
                continue;
            }

            if (! empty($args['hide_empty']) && 0 === $term->count) {
                continue;
            }

            if (! empty($args['search']) && false === strpos($term->name, (string) $args['search'])) {
                continue;
            }

            $items[] = $term;
        }

        if (isset($args['number']) && count($items) > (int) $args['number']) {
            $items = array_slice($items, 0, (int) $args['number']);
        }

        return $items;
    }
}

if (! function_exists('get_term')) {
    function get_term(int|object $term, string $taxonomy = '', string $output = OBJECT, string $filter = 'raw'): WP_Term|WP_Error|null
    {
        unset($taxonomy, $output, $filter);

        $id = $term instanceof WP_Term ? $term->term_id : (int) $term;

        return WPState::$terms[$id] ?? null;
    }
}

if (! function_exists('wp_insert_term')) {
    /**
     * @param array<string, mixed> $args
     * @return array{term_id: int, term_taxonomy_id: int}|WP_Error
     */
    function wp_insert_term(string $term, string $taxonomy, array $args = array()): array|WP_Error
    {
        if (! taxonomy_exists($taxonomy)) {
            return new WP_Error('invalid_taxonomy', 'Invalid taxonomy.');
        }

        $id = count(WPState::$terms) + 1000;

        $object = new WP_Term($id);
        $object->name     = $term;
        $object->slug     = (string) ($args['slug'] ?? sanitize_title($term));
        $object->taxonomy = $taxonomy;
        $object->parent   = (int) ($args['parent'] ?? 0);

        WPState::$terms[$id] = $object;

        return array('term_id' => $id, 'term_taxonomy_id' => $id);
    }
}

if (! function_exists('wp_delete_term')) {
    function wp_delete_term(int $term_id, string $taxonomy): bool
    {
        unset($taxonomy);

        unset(WPState::$terms[$term_id]);

        return true;
    }
}

if (! function_exists('wp_set_object_terms')) {
    /**
     * @param array<int, int|string> $terms
     * @return array<int, int>|WP_Error
     */
    function wp_set_object_terms(int $object_id, array $terms, string $taxonomy, bool $append = false): array|WP_Error
    {
        if (! taxonomy_exists($taxonomy)) {
            return new WP_Error('invalid_taxonomy', 'Invalid taxonomy.');
        }

        $ids = array_values(array_map('intval', $terms));

        if (! $append) {
            WPState::$objectTerms[$object_id][$taxonomy] = array();
        }

        WPState::$objectTerms[$object_id][$taxonomy] = array_values(array_unique(array_merge(
            WPState::$objectTerms[$object_id][$taxonomy] ?? array(),
            $ids
        )));

        return WPState::$objectTerms[$object_id][$taxonomy];
    }
}

if (! function_exists('wp_get_object_terms')) {
    /**
     * @param array<string, mixed> $args
     * @return array<int, int>|array<int, WP_Term>|WP_Error
     */
    function wp_get_object_terms(int $object_id, string|array $taxonomies, array $args = array()): array|WP_Error
    {
        $names = is_array($taxonomies) ? $taxonomies : array($taxonomies);
        $fields = $args['fields'] ?? 'all';

        $items = array();

        foreach ($names as $taxonomy) {
            $items = array_merge($items, WPState::$objectTerms[$object_id][$taxonomy] ?? array());
        }

        $items = array_values(array_unique($items));

        if ('ids' === $fields) {
            return $items;
        }

        return array_values(array_map(
            static fn (int $id): WP_Term => WPState::$terms[$id],
            $items
        ));
    }
}

if (! function_exists('wp_upload_bits')) {
    /**
     * @return array{file: string, url: string, error: string|false}
     */
    function wp_upload_bits(string $name, ?string $deprecated = null, string $bits = '', ?string $time = null): array
    {
        unset($deprecated, $time);

        $result = array(
            'file'  => '/var/www/wp-content/uploads/' . $name,
            'url'   => WPState::$siteUrl . '/wp-content/uploads/' . $name,
            'error' => false,
        );

        WPState::$lastUpload = $result;

        return $result;
    }
}

if (! function_exists('wp_insert_attachment')) {
    /**
     * @param array<string, mixed> $args
     */
    function wp_insert_attachment(array $args, string $file = '', int $parent_post_id = 0, bool $wp_error = false): int|WP_Error
    {
        unset($parent_post_id);

        $id = WPState::$nextPostId++;

        $post = new WP_Post($id);
        $post->post_title      = (string) ($args['post_title'] ?? '');
        $post->post_content    = (string) ($args['post_content'] ?? '');
        $post->post_excerpt    = (string) ($args['post_excerpt'] ?? '');
        $post->post_status     = 'inherit';
        $post->post_type       = 'attachment';
        $post->post_mime_type  = (string) ($args['post_mime_type'] ?? '');
        $post->post_author     = WPState::$currentUserId;

        WPState::$posts[$id] = $post;

        if ('' !== $file) {
            WPState::$attachedFiles[$id] = $file;
        }

        unset($wp_error);

        return $id;
    }
}

if (! function_exists('wp_generate_attachment_metadata')) {
    /**
     * @return array<string, mixed>
     */
    function wp_generate_attachment_metadata(int $attachment_id, string $file): array
    {
        $metadata = array(
            'file'   => $file,
            'width'  => 800,
            'height' => 600,
            'sizes'  => array(),
        );

        WPState::$attachmentMeta[$attachment_id] = $metadata;

        return $metadata;
    }
}

if (! function_exists('wp_update_attachment_metadata')) {
    /**
     * @param array<string, mixed> $metadata
     * @return bool
     */
    function wp_update_attachment_metadata(int $attachment_id, array $metadata): bool
    {
        WPState::$attachmentMeta[$attachment_id] = $metadata;

        return true;
    }
}

if (! function_exists('wp_get_attachment_metadata')) {
    /**
     * @return array<string, mixed>
     */
    function wp_get_attachment_metadata(int $attachment_id, bool $unfiltered = false): array
    {
        unset($unfiltered);

        return WPState::$attachmentMeta[$attachment_id] ?? array();
    }
}

if (! function_exists('wp_get_attachment_url')) {
    function wp_get_attachment_url(int $attachment_id): string|false
    {
        $file = WPState::$attachedFiles[$attachment_id] ?? '';

        return '' === $file ? false : WPState::$siteUrl . '/wp-content/uploads/' . basename($file);
    }
}

if (! function_exists('get_attached_file')) {
    function get_attached_file(int $attachment_id, bool $unfiltered = false): string|false
    {
        unset($unfiltered);

        return WPState::$attachedFiles[$attachment_id] ?? false;
    }
}

if (! function_exists('update_post_meta')) {
    function update_post_meta(int $post_id, string $meta_key, mixed $meta_value, mixed $prev_value = ''): bool
    {
        unset($prev_value);

        WPState::$postMeta[$post_id][$meta_key] = $meta_value;

        return true;
    }
}

if (! function_exists('get_post_meta')) {
    function get_post_meta(int $post_id, string $key = '', bool $single = false): mixed
    {
        if ('' === $key) {
            return WPState::$postMeta[$post_id] ?? array();
        }

        $value = WPState::$postMeta[$post_id][$key] ?? '';

        return $single ? $value : array($value);
    }
}

if (! function_exists('wp_delete_attachment')) {
    function wp_delete_attachment(int $post_id, bool $force_delete = false): ?WP_Post
    {
        $post = get_post($post_id);

        if (null === $post) {
            return null;
        }

        unset(WPState::$posts[$post_id]);
        WPState::$deletedAttachmentIds[] = $post_id;

        return $post;
    }
}

if (! function_exists('get_comments')) {
    /**
     * @param array<string, mixed> $args
     * @return array<int, WP_Comment>
     */
    function get_comments(array $args = array()): array
    {
        $status = (string) ($args['status'] ?? 'approve');

        $map = array('approve' => '1', 'hold' => '0', 'spam' => 'spam', 'trash' => 'trash');

        if ('all' === $status) {
            $statuses = array('1', '0', 'spam', 'trash');
        } elseif (isset($map[$status])) {
            $statuses = array($map[$status]);
        } else {
            $statuses = array($status);
        }

        $items = array();

        foreach (WPState::$comments as $comment) {
            if (! in_array($comment->comment_approved, $statuses, true)) {
                continue;
            }

            if (! empty($args['post_id']) && (int) $args['post_id'] !== $comment->comment_post_ID) {
                continue;
            }

            $items[] = $comment;
        }

        if (isset($args['number']) && count($items) > (int) $args['number']) {
            $items = array_slice($items, 0, (int) $args['number']);
        }

        return $items;
    }
}

if (! function_exists('get_comment')) {
    function get_comment(int|WP_Comment|null $comment = null, string $output = OBJECT): WP_Comment|null
    {
        unset($output);

        $id = $comment instanceof WP_Comment ? $comment->comment_ID : (int) $comment;

        return WPState::$comments[$id] ?? null;
    }
}

if (! function_exists('wp_insert_comment')) {
    /**
     * @param array<string, mixed> $commentdata
     */
    function wp_insert_comment(array $commentdata): int
    {
        $id = WPState::$nextCommentId++;

        $comment = new WP_Comment($id);
        $comment->comment_post_ID     = (int) ($commentdata['comment_post_ID'] ?? 0);
        $comment->comment_author      = (string) ($commentdata['comment_author'] ?? '');
        $comment->comment_author_email = (string) ($commentdata['comment_author_email'] ?? '');
        $comment->comment_content     = (string) ($commentdata['comment_content'] ?? '');
        $comment->comment_parent      = (int) ($commentdata['comment_parent'] ?? 0);
        $comment->user_id             = (int) ($commentdata['user_id'] ?? WPState::$currentUserId);
        $comment->comment_approved    = (string) ($commentdata['comment_approved'] ?? '1');

        WPState::$comments[$id] = $comment;

        return $id;
    }
}

if (! function_exists('wp_set_comment_status')) {
    function wp_set_comment_status(int $comment_id, string $comment_status, bool $wp_error = false): bool|WP_Error
    {
        unset($wp_error);

        $comment = get_comment($comment_id);

        if (null === $comment) {
            return false;
        }

        $map = array('approve' => '1', 'hold' => '0', 'spam' => 'spam', 'trash' => 'trash');

        $next = $map[$comment_status] ?? $comment_status;

        WPState::$commentStatusChanges[] = array(
            'comment_id' => $comment_id,
            'previous'   => $comment->comment_approved,
            'next'       => $next,
        );

        $comment->comment_approved = $next;

        return true;
    }
}

if (! function_exists('wp_delete_comment')) {
    function wp_delete_comment(int $comment_id, bool $force_delete = false): bool
    {
        unset($force_delete);

        $comment = get_comment($comment_id);

        if (null === $comment) {
            return false;
        }

        unset(WPState::$comments[$comment_id]);
        WPState::$deletedCommentIds[] = $comment_id;

        return true;
    }
}

if (! function_exists('sanitize_title')) {
    function sanitize_title(string $title): string
    {
        $title = strtolower(trim($title));
        $title = (string) preg_replace('/[^a-z0-9\-_]+/', '-', $title);

        return trim($title, '-');
    }
}

if (! function_exists('wp_reset_postdata')) {
    function wp_reset_postdata(): void
    {
    }
}

if (! function_exists('current_user_can_for_blog')) {
    function current_user_can_for_blog(int $blog_id, string $capability, mixed ...$args): bool
    {
        unset($blog_id);

        return current_user_can($capability, ...$args);
    }
}
