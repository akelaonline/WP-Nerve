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
use WPNerve\Admin\DiagnosticsPage;
use WPNerve\Audit\AuditRepository;
use WPNerve\Infrastructure\Activator;
use WPNerve\Maintenance\RetentionManager;
use WPNerve\OAuth\OAuthServer;
use WPNerve\OAuth\OAuthStore;
use WPNerve\Policy\PolicyEngine;
use WPNerve\Protocol\AbilityToolRegistry;
use WPNerve\Protocol\JsonRpcHandler;
use WPNerve\Protocol\RequestValidator;
use WPNerve\Security\Confirmation\ConfirmedToolRegistry;
use WPNerve\Security\Confirmation\Service as ConfirmationService;
use WPNerve\Security\Confirmation\WpdbRepository as ConfirmationRepository;
use WPNerve\Security\Idempotency\CanonicalJson;
use WPNerve\Security\Idempotency\IdempotentToolRegistry;
use WPNerve\Security\Idempotency\Service as IdempotencyService;
use WPNerve\Security\Idempotency\WpdbRepository;
use WPNerve\Security\RateLimit\ClientAddress;
use WPNerve\Security\RateLimit\RateLimiter;
use WPNerve\Security\RateLimit\WpdbRepository as RateLimitRepository;
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

        if (Activator::SCHEMA_VERSION !== get_option('wp_nerve_schema_version')) {
            Activator::installSchema();
        }

        $abilities              = new AbilityRegistrar();
        $audit                  = new AuditRepository();
        $policy                 = new PolicyEngine();
        $abilitiesRegistry      = new AbilityToolRegistry($policy);
        $canonicalJson          = new CanonicalJson();
        $confirmationRepository = new ConfirmationRepository();
        $idempotency            = new IdempotencyService(new WpdbRepository(), $canonicalJson);
        $idempotentRegistry     = new IdempotentToolRegistry($abilitiesRegistry, $idempotency);
        $confirmation           = new ConfirmationService($confirmationRepository, $canonicalJson);
        $registry               = new ConfirmedToolRegistry($idempotentRegistry, $confirmation);
        $handler                = new JsonRpcHandler($registry, $audit);
        $rateLimiter            = new RateLimiter(new RateLimitRepository());
        $clientAddress          = new ClientAddress();
        $transport              = new HttpTransport(
            new RequestValidator(),
            $handler,
            $rateLimiter,
            $clientAddress
        );
        $admin                  = new AdminPage(null, $confirmationRepository);
        $diagnostics            = new DiagnosticsPage();
        $oauth                  = new OAuthServer(new OAuthStore(), $rateLimiter, $clientAddress);
        $retention              = new RetentionManager();

        add_action('init', array($this, 'loadTextdomain'));
        add_action('wp_abilities_api_categories_init', array($abilities, 'registerCategory'));
        add_action('wp_abilities_api_init', array($abilities, 'registerAbilities'));
        add_action('rest_api_init', array($transport, 'registerRoutes'));
        add_action('rest_api_init', array($oauth, 'registerRoutes'));
        add_action('admin_init', array($admin, 'handleActions'));
        add_action('admin_init', array($diagnostics, 'handleActions'));
        add_action('admin_menu', array($admin, 'registerMenu'));
        add_action('admin_menu', array($diagnostics, 'registerMenu'));
        add_action('wp_scheduled_delete', array($retention, 'cleanup'), 20, 0);
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
