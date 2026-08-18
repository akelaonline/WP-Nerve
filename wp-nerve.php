<?php

/**
 * Plugin Name:       WPNerve
 * Plugin URI:        https://github.com/akelaonline/WP-Nerve
 * Description:       A secure, native MCP server and agent control layer for WordPress.
 * Version:           0.1.0-alpha.7
 * Requires at least: 6.9
 * Requires PHP:      8.1
 * Author:            Akela (@akelaonline)
 * Author URI:        https://www.instagram.com/akelaonline/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-nerve
 * Update URI:        https://github.com/akelaonline/WP-Nerve
 *
 * @package WPNerve
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

if (! defined('WP_NERVE_VERSION')) {
    define('WP_NERVE_VERSION', '0.1.0-alpha.7');
}
if (! defined('WP_NERVE_FILE')) {
    define('WP_NERVE_FILE', __FILE__);
}
if (! defined('WP_NERVE_PATH')) {
    define('WP_NERVE_PATH', plugin_dir_path(__FILE__));
}
if (! defined('WP_NERVE_URL')) {
    define('WP_NERVE_URL', plugin_dir_url(__FILE__));
}

require_once WP_NERVE_PATH . 'src/Autoloader.php';

WPNerve\Autoloader::register();

register_activation_hook(WP_NERVE_FILE, array(WPNerve\Infrastructure\Activator::class, 'activate'));

// Register Abilities API hooks during plugin loading. The registry is lazy and may
// be initialized before `plugins_loaded` by another plugin.
WPNerve\Plugin::instance()->boot();
