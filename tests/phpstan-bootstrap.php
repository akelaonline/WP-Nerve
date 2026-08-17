<?php

/**
 * Constants normally provided by WordPress during static analysis.
 *
 * @package WPNerve
 */

declare(strict_types=1);

defined('ABSPATH') || define('ABSPATH', __DIR__ . '/fixtures/wordpress/');
defined('WP_CONTENT_DIR') || define('WP_CONTENT_DIR', dirname(__DIR__) . '/wp-content');
defined('WP_PLUGIN_DIR') || define('WP_PLUGIN_DIR', WP_CONTENT_DIR . '/plugins');
defined('WP_NERVE_VERSION') || define('WP_NERVE_VERSION', '0.1.0-alpha.4');
defined('WP_NERVE_FILE') || define('WP_NERVE_FILE', __DIR__ . '/../wp-nerve.php');
defined('WP_NERVE_PATH') || define('WP_NERVE_PATH', dirname(__DIR__) . '/');
defined('WP_NERVE_URL') || define('WP_NERVE_URL', 'https://example.test/wp-content/plugins/wp-nerve/');
