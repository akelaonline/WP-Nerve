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
