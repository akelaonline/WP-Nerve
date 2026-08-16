<?php

/**
 * WPNerve composition root.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve;

use WPNerve\Abilities\AbilityRegistrar;
use WPNerve\Admin\AdminPage;
use WPNerve\Audit\AuditRepository;
use WPNerve\Policy\PolicyEngine;
use WPNerve\Protocol\AbilityToolRegistry;
use WPNerve\Protocol\JsonRpcHandler;
use WPNerve\Protocol\RequestValidator;
use WPNerve\Transport\HttpTransport;

final class Plugin
{
    private static ?self $instance = null;

    private bool $booted = false;

    public static function instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $this->booted = true;

        if (! $this->requirementsMet()) {
            add_action('admin_notices', array($this, 'renderRequirementsNotice'));
            return;
        }

        if ('1' !== get_option('wp_nerve_schema_version')) {
            AuditRepository::installSchema();
            update_option('wp_nerve_schema_version', '1', false);
        }

        $abilities = new AbilityRegistrar();
        $audit     = new AuditRepository();
        $policy    = new PolicyEngine();
        $registry  = new AbilityToolRegistry($policy);
        $handler   = new JsonRpcHandler($registry, $audit);
        $transport = new HttpTransport(new RequestValidator(), $handler);
        $admin     = new AdminPage();

        add_action('init', array($this, 'loadTextdomain'));
        add_action('wp_abilities_api_categories_init', array($abilities, 'registerCategory'));
        add_action('wp_abilities_api_init', array($abilities, 'registerAbilities'));
        add_action('rest_api_init', array($transport, 'registerRoutes'));
        add_action('admin_menu', array($admin, 'registerMenu'));
        add_filter('rest_allowed_cors_headers', array($transport, 'allowedCorsHeaders'), 10, 2);
    }

    public function loadTextdomain(): void
    {
        load_plugin_textdomain('wp-nerve', false, dirname(plugin_basename(WP_NERVE_FILE)) . '/languages');
    }

    public function renderRequirementsNotice(): void
    {
        if (! current_user_can('activate_plugins')) {
            return;
        }

        printf(
            '<div class="notice notice-error"><p>%s</p></div>',
            esc_html__('WPNerve requires WordPress 6.9 or newer with the native Abilities API available.', 'wp-nerve')
        );
    }

    private function requirementsMet(): bool
    {
        return version_compare(PHP_VERSION, '8.1', '>=')
            && function_exists('wp_register_ability')
            && function_exists('wp_register_ability_category')
            && function_exists('wp_get_abilities');
    }

    private function __construct()
    {
    }
}
