<?php

/**
 * PHPUnit bootstrap.
 *
 * Loads Composer autoloading and the minimal WordPress runtime doubles used
 * by the unit test suite.
 *
 * @package WPNerve
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

$fixtures = dirname(__DIR__) . '/tests/fixtures/wordpress';

require_once $fixtures . '/wp-state.php';
require_once $fixtures . '/class-wpdb.php';
require_once $fixtures . '/class-wp-ability.php';
require_once $fixtures . '/class-wp-error.php';
require_once $fixtures . '/class-wp-rest.php';
require_once $fixtures . '/class-wp-posts.php';
require_once $fixtures . '/wp-functions.php';
require_once $fixtures . '/wp-oauth-functions.php';

defined('ABSPATH') || define('ABSPATH', $fixtures . '/');
defined('WP_CONTENT_DIR') || define('WP_CONTENT_DIR', dirname(__DIR__) . '/wp-content');
defined('WP_PLUGIN_DIR') || define('WP_PLUGIN_DIR', WP_CONTENT_DIR . '/plugins');
defined('WP_NERVE_VERSION') || define('WP_NERVE_VERSION', '0.1.0-alpha.10');
defined('WP_NERVE_FILE') || define('WP_NERVE_FILE', dirname(__DIR__) . '/wp-nerve.php');
defined('WP_NERVE_PATH') || define('WP_NERVE_PATH', dirname(__DIR__) . '/');
defined('WP_NERVE_URL') || define('WP_NERVE_URL', 'https://example.test/wp-content/plugins/wp-nerve/');

WPNerve\Tests\Fixtures\WPState::reset();
