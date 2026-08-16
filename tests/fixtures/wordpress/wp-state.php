<?php

/**
 * Mutable state backing the WordPress runtime stubs used by unit tests.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Tests\Fixtures;

use WP_Ability;

final class WPState
{
    /** @var array<int, WP_Ability> Abilities returned by wp_get_abilities(). */
    public static array $abilities = array();

    /** @var array<int, WP_Ability> Abilities registered through wp_register_ability(). */
    public static array $registeredAbilities = array();

    /** @var array<int, array{name: string, args: array<string, mixed>}> */
    public static array $registeredCategories = array();

    /** @var array<int, array{namespace: string, route: string, args: array<string, mixed>}> */
    public static array $restRoutes = array();

    /** @var array<string, array<int, callable>> */
    public static array $actions = array();

    /** @var array<string, array<int, callable>> */
    public static array $filters = array();

    /** @var array<string, mixed> */
    public static array $options = array();

    /** @var array<string, string> */
    public static array $bloginfo = array(
        'name'        => 'Example Test Site',
        'description' => 'A test site',
    );

    public static bool $isLoggedIn = true;

    /** @var bool|callable(string, mixed...): bool */
    public static mixed $userCan = true;

    public static bool $isSsl = true;

    public static string $environmentType = 'development';

    public static string $siteUrl = 'https://example.test';

    public static string $restUrl = 'https://example.test/wp-json';

    public static string $pluginDirPath = '';

    public static string $pluginDirUrl = 'https://example.test/wp-content/plugins/wp-nerve/';

    public static int $currentUserId = 1;

    public static string $wpVersion = '6.9';

    public static bool $multisite = false;

    /** @var array<int, string> */
    public static array $deactivatedPlugins = array();

    /** @var array<int, array<int, mixed>> */
    public static array $managementPages = array();

    /** @var array<int, array{domain: string, relPath: string}> */
    public static array $localization = array();

    /** @var array<int, string> */
    public static array $schemaCalls = array();

    /** @var array<int, array{file: string, callback: callable}> */
    public static array $activationHooks = array();

    /** @var array<int, array{message: string, title: string}> */
    public static array $wpDieCalls = array();

    public static WpDb $wpdb;

    /** @var array<string, WP_Post_Type> Post type name => object. */
    public static array $postTypes = array();

    /** @var array<int, WP_Post> Post ID => object. */
    public static array $posts = array();

    /** @var array<int, WP_Post>|callable(array<string, mixed>): array<int, WP_Post> */
    public static mixed $queryResults = array();

    /** @var array<string, mixed> */
    public static array $lastQueryArgs = array();

    public static int $queryCount = 0;

    public static function reset(): void
    {
        self::$abilities = array();
        self::$registeredAbilities = array();
        self::$registeredCategories = array();
        self::$restRoutes = array();
        self::$actions = array();
        self::$filters = array();
        self::$options = array();
        self::$bloginfo = array(
            'name'        => 'Example Test Site',
            'description' => 'A test site',
        );
        self::$isLoggedIn = true;
        self::$userCan = true;
        self::$isSsl = true;
        self::$environmentType = 'development';
        self::$siteUrl = 'https://example.test';
        self::$restUrl = 'https://example.test/wp-json';
        self::$pluginDirPath = '';
        self::$pluginDirUrl = 'https://example.test/wp-content/plugins/wp-nerve/';
        self::$currentUserId = 1;
        self::$wpVersion = '6.9';
        self::$multisite = false;
        self::$deactivatedPlugins = array();
        self::$managementPages = array();
        self::$localization = array();
        self::$schemaCalls = array();
        self::$activationHooks = array();
        self::$wpDieCalls = array();
        self::$wpdb = new WpDb();
        self::$postTypes = array();
        self::$posts = array();
        self::$queryResults = array();
        self::$lastQueryArgs = array();
        self::$queryCount = 0;

        $GLOBALS['wpdb'] = self::$wpdb;
        $GLOBALS['wp_version'] = self::$wpVersion;
    }
}
