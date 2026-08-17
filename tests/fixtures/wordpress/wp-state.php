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

    public static bool $nonceValid = true;

    /** @var bool|callable(string, mixed...): bool */
    public static mixed $userCan = true;

    public static bool $isSsl = true;

    public static string $environmentType = 'development';

    public static string $siteUrl = 'https://example.test';

    public static string $restUrl = 'https://example.test/wp-json';

    public static string $pluginDirPath = '';

    public static string $pluginDirUrl = 'https://example.test/wp-content/plugins/wp-nerve/';

    public static int $currentUserId = 1;

    public static string $currentUserDisplayName = 'Test User';

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

    public static int $nextPostId = 100;

    /** @var array<string, mixed> */
    public static array $lastInsertedPost = array();

    /** @var array<string, mixed> */
    public static array $lastUpdatedPost = array();

    /** @var array<int, int> */
    public static array $trashedPostIds = array();

    /** @var array<int, int> */
    public static array $untrashedPostIds = array();

    /** @var array<int, int> */
    public static array $publishedPostIds = array();

    /** @var array<int, int> */
    public static array $restoredRevisionIds = array();

    /** @var array<int, WP_Post> Revision ID => object. */
    public static array $revisions = array();

    /** @var array<string, WP_Taxonomy> Taxonomy name => object. */
    public static array $taxonomies = array();

    /** @var array<int, WP_Term> Term ID => object. */
    public static array $terms = array();

    /** @var array<int, array<string, array<int, int>>> Post ID => taxonomy => term IDs. */
    public static array $objectTerms = array();

    /** @var array<int, WP_Comment> Comment ID => object. */
    public static array $comments = array();

    public static int $nextCommentId = 500;

    /** @var array<string, mixed> Last wp_upload_bits result. */
    public static array $lastUpload = array();

    /** @var array<int, string> Attachment ID => file path. */
    public static array $attachedFiles = array();

    /** @var array<int, array<string, mixed>> Attachment ID => metadata. */
    public static array $attachmentMeta = array();

    /** @var array<int, array<string, mixed>> Post ID => meta key => value. */
    public static array $postMeta = array();

    /** @var array<int, int> */
    public static array $deletedAttachmentIds = array();

    /** @var array<int, array{comment_id: int, previous: string, next: string}> */
    public static array $commentStatusChanges = array();

    /** @var array<int, int> */
    public static array $deletedCommentIds = array();

    /** @var array<int, WP_Term> Nav menu ID => term object. */
    public static array $navMenus = array();

    /** @var array<int, WP_Post> Nav menu item ID => post object. */
    public static array $navMenuItems = array();

    /** @var array<int, int> Nav menu item ID => menu ID. */
    public static array $menuItemMenu = array();

    /** @var array<int, array<string, mixed>> Nav menu item ID => meta. */
    public static array $menuItemMeta = array();

    /** @var array<string, int> Location => menu ID. */
    public static array $menuLocations = array();

    /** @var array<string, mixed> */
    public static array $themeMods = array();

    /** @var array<int, array<string, string>> */
    public static array $sidebars = array();

    /** @var array<string, array<int, string>> Sidebar ID => widget IDs. */
    public static array $sidebarWidgets = array();

    /** @var object{widgets: array<string, object>} */
    public static object $widgetFactory;

    /** @var array<int, int> */
    public static array $deletedPostIds = array();

    /** @var array<int, array<int, int>> Post ID => taxonomy => term IDs (nav_menu). */
    public static array $postTerms = array();

    /** @var array<int, WP_User> User ID => object. */
    public static array $users = array();

    public static int $nextUserId = 900;

    /** @var array<string, array<string, string>> Plugin file => plugin data. */
    public static array $plugins = array();

    /** @var array<int, string> */
    public static array $activePlugins = array();

    /** @var array<int, array<string, mixed>> */
    public static array $pluginActivations = array();

    /** @var array<int, array<string, mixed>> */
    public static array $pluginDeactivations = array();

    /** @var array<int, string> */
    public static array $deletedPlugins = array();

    /** @var array<int, array{file: string, to: string}> */
    public static array $unzippedFiles = array();

    /** @var array<string, mixed> */
    public static array $transients = array();

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
        self::$nonceValid = true;
        self::$userCan = true;
        self::$isSsl = true;
        self::$environmentType = 'development';
        self::$siteUrl = 'https://example.test';
        self::$restUrl = 'https://example.test/wp-json';
        self::$pluginDirPath = '';
        self::$pluginDirUrl = 'https://example.test/wp-content/plugins/wp-nerve/';
        self::$currentUserId = 1;
        self::$currentUserDisplayName = 'Test User';
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
        self::$nextPostId = 100;
        self::$lastInsertedPost = array();
        self::$lastUpdatedPost = array();
        self::$trashedPostIds = array();
        self::$untrashedPostIds = array();
        self::$publishedPostIds = array();
        self::$restoredRevisionIds = array();
        self::$revisions = array();
        self::$taxonomies = array();
        self::$terms = array();
        self::$objectTerms = array();
        self::$comments = array();
        self::$nextCommentId = 500;
        self::$lastUpload = array();
        self::$attachedFiles = array();
        self::$attachmentMeta = array();
        self::$postMeta = array();
        self::$deletedAttachmentIds = array();
        self::$commentStatusChanges = array();
        self::$deletedCommentIds = array();
        self::$navMenus = array();
        self::$navMenuItems = array();
        self::$menuItemMenu = array();
        self::$menuItemMeta = array();
        self::$menuLocations = array();
        self::$themeMods = array();
        self::$sidebars = array();
        self::$sidebarWidgets = array();
        self::$widgetFactory = (object) array('widgets' => array());
        self::$deletedPostIds = array();
        self::$postTerms = array();
        self::$users = array();
        self::$nextUserId = 900;
        self::$plugins = array();
        self::$activePlugins = array();
        self::$pluginActivations = array();
        self::$pluginDeactivations = array();
        self::$deletedPlugins = array();
        self::$unzippedFiles = array();
        self::$transients = array();

        $GLOBALS['wpdb'] = self::$wpdb;
        $GLOBALS['wp_widget_factory'] = self::$widgetFactory;
        $GLOBALS['wp_registered_sidebars'] = self::$sidebars;
        $GLOBALS['wp_version'] = self::$wpVersion;
    }
}
