<?php

/**
 * Menu and widget ability tests.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Tests\Unit\Abilities;

use WP_Error;
use WP_Post;
use WP_Term;
use WPNerve\Abilities\AbilityRegistrar;
use WPNerve\Policy\PolicyEngine;
use WPNerve\Protocol\AbilityToolRegistry;
use WPNerve\Tests\Fixtures\WPState;
use WPNerve\Tests\Unit\TestCase;

final class MenuWidgetTest extends TestCase
{
    private AbilityToolRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $registrar = new AbilityRegistrar();
        $registrar->registerAbilities();

        $this->registry = new AbilityToolRegistry(new PolicyEngine());
    }

    public function testRegistersMenuAndWidgetAbilities(): void
    {
        $expected = array(
            'wp-nerve/list-menus'             => array('read', true),
            'wp-nerve/get-menu-items'         => array('read', true),
            'wp-nerve/create-menu'            => array('write', true),
            'wp-nerve/add-menu-item'          => array('write', true),
            'wp-nerve/update-menu-item'       => array('write', true),
            'wp-nerve/delete-menu-item'       => array('write', true),
            'wp-nerve/assign-menu-location'   => array('write', true),
            'wp-nerve/list-sidebars'          => array('read', true),
            'wp-nerve/get-sidebar'            => array('read', true),
            'wp-nerve/list-available-widgets' => array('read', true),
        );

        $actual = array();

        foreach (WPState::$registeredAbilities as $ability) {
            $name = $ability->get_name();

            if (array_key_exists($name, $expected)) {
                $meta = $ability->get_meta_item('wp_nerve', array());
                $actual[$name] = array($meta['risk'], $meta['enabled_by_default']);
            }
        }

        self::assertSame($expected, $actual);
    }

    public function testAllRegisteredAbilityNamesPassWordPressValidation(): void
    {
        $registrar = new AbilityRegistrar();
        $registrar->registerAbilities();

        self::assertNotEmpty(WPState::$registeredAbilities);

        foreach (WPState::$registeredAbilities as $ability) {
            $name = $ability->get_name();

            self::assertSame(
                1,
                preg_match('/^[a-z0-9-]+\/[a-z0-9-]+$/', $name),
                'Ability name fails WordPress 6.9 validation: ' . $name
            );
        }
    }

    public function testListMenusReturnsMenusAndLocations(): void
    {
        WPState::$navMenus[10] = $this->menu(10, 'Principal');
        WPState::$navMenus[11] = $this->menu(11, 'Footer');
        WPState::$menuLocations = array('primary' => 10);

        $result = $this->registry->execute('wp_nerve_list_menus', array());

        self::assertNotInstanceOf(WP_Error::class, $result);
        self::assertCount(2, $result['result']['menus']);
        self::assertSame('Principal', $result['result']['menus'][0]['name']);
        self::assertSame(array('primary' => 10), $result['result']['locations']);
    }

    public function testGetMenuItemsReturnsItemsInOrder(): void
    {
        WPState::$navMenus[10] = $this->menu(10, 'Principal');
        WPState::$navMenuItems[21] = $this->menuItem(21, 10, 'Home', 0);
        WPState::$navMenuItems[22] = $this->menuItem(22, 10, 'About', 1);

        $result = $this->registry->execute('wp_nerve_get_menu_items', array('menu_id' => 10));

        self::assertNotInstanceOf(WP_Error::class, $result);
        self::assertSame(2, $result['result']['total']);
        self::assertSame('Home', $result['result']['items'][0]['title']);
        self::assertSame('custom', $result['result']['items'][0]['type']);
    }

    public function testGetMenuItemsRejectsUnknownMenu(): void
    {
        $result = $this->registry->execute('wp_nerve_get_menu_items', array('menu_id' => 404));

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('wp_nerve_menu_not_found', $result->get_error_code());
    }

    public function testCreateMenuInsertsMenu(): void
    {
        $result = $this->registry->execute('wp_nerve_create_menu', array('name' => 'Nuevo menú'));

        self::assertNotInstanceOf(WP_Error::class, $result);
        self::assertSame(2000, $result['result']['id']);
        self::assertSame('Nuevo menú', $result['result']['name']);
        self::assertSame('wp_nerve_delete_menu', $result['result']['recovery']['undo'] ?? '');
    }

    public function testAddMenuItemCreatesItem(): void
    {
        WPState::$navMenus[10] = $this->menu(10, 'Principal');

        $result = $this->registry->execute('wp_nerve_add_menu_item', array(
            'menu_id' => 10,
            'title'   => 'Contacto',
            'url'     => 'https://example.test/contacto',
        ));

        self::assertNotInstanceOf(WP_Error::class, $result);
        self::assertSame(100, $result['result']['id']);
        self::assertSame('Contacto', $result['result']['title']);
        self::assertSame(10, $result['result']['menu_id']);
        self::assertSame('https://example.test/contacto', $result['result']['url']);
    }

    public function testUpdateMenuItemChangesTitle(): void
    {
        WPState::$navMenus[10] = $this->menu(10, 'Principal');
        WPState::$navMenuItems[21] = $this->menuItem(21, 10, 'Home', 0);

        $result = $this->registry->execute('wp_nerve_update_menu_item', array(
            'item_id' => 21,
            'title'   => 'Inicio',
            'url'     => 'https://example.test/inicio',
        ));

        self::assertNotInstanceOf(WP_Error::class, $result);
        self::assertSame('Inicio', $result['result']['title']);
        self::assertSame('https://example.test/inicio', $result['result']['url']);
    }

    public function testDeleteMenuItemRemovesItem(): void
    {
        WPState::$navMenus[10] = $this->menu(10, 'Principal');
        WPState::$navMenuItems[21] = $this->menuItem(21, 10, 'Home', 0);

        $result = $this->registry->execute('wp_nerve_delete_menu_item', array('item_id' => 21));

        self::assertNotInstanceOf(WP_Error::class, $result);
        self::assertSame(true, $result['result']['deleted']);
        self::assertSame(array(21), WPState::$deletedPostIds);
    }

    public function testAssignLocationReturnsPreviousMap(): void
    {
        WPState::$navMenus[10] = $this->menu(10, 'Principal');
        WPState::$menuLocations = array('primary' => 0);

        $result = $this->registry->execute('wp_nerve_assign_menu_location', array(
            'location' => 'primary',
            'menu_id'  => 10,
        ));

        self::assertNotInstanceOf(WP_Error::class, $result);
        self::assertSame(10, $result['result']['menu_id']);
        self::assertSame(array('primary' => 0), $result['result']['previous']);
        self::assertSame(array('primary' => 10), WPState::$themeMods['nav_menu_locations']);
    }

    public function testAssignLocationWithMenuIdZeroUnassigns(): void
    {
        WPState::$menuLocations = array('primary' => 10);

        $result = $this->registry->execute('wp_nerve_assign_menu_location', array(
            'location' => 'primary',
            'menu_id'  => 0,
        ));

        self::assertNotInstanceOf(WP_Error::class, $result);
        self::assertSame(0, $result['result']['menu_id']);
    }

    public function testListSidebarsReturnsRegistered(): void
    {
        WPState::$sidebars = array(
            array('id' => 'sidebar-1', 'name' => 'Sidebar principal', 'description' => 'Zona derecha'),
        );
        $GLOBALS['wp_registered_sidebars'] = WPState::$sidebars;

        $result = $this->registry->execute('wp_nerve_list_sidebars', array());

        self::assertNotInstanceOf(WP_Error::class, $result);
        self::assertSame('sidebar-1', $result['result']['sidebars'][0]['id']);
        self::assertSame('Sidebar principal', $result['result']['sidebars'][0]['name']);
    }

    public function testListSidebarsFallsBackToWidgetSidebarsForBlockThemes(): void
    {
        // Block themes do not register sidebars classically; sidebars that hold
        // widgets must still be reported.
        WPState::$sidebarWidgets = array('sidebar-1' => array('block-2'), 'footer-1' => array('block-3'));
        $GLOBALS['wp_registered_sidebars'] = array();

        $result = $this->registry->execute('wp_nerve_list_sidebars', array());

        self::assertNotInstanceOf(WP_Error::class, $result);
        self::assertSame(array('sidebar-1', 'footer-1'), array_column($result['result']['sidebars'], 'id'));
    }

    public function testGetSidebarReturnsWidgets(): void
    {
        WPState::$sidebarWidgets = array(
            'sidebar-1' => array('search-2', 'recent-posts-3'),
        );

        $result = $this->registry->execute('wp_nerve_get_sidebar', array('sidebar_id' => 'sidebar-1'));

        self::assertNotInstanceOf(WP_Error::class, $result);
        self::assertSame(array('search-2', 'recent-posts-3'), $result['result']['widgets']);
    }

    public function testGetSidebarRejectsUnknown(): void
    {
        $result = $this->registry->execute('wp_nerve_get_sidebar', array('sidebar_id' => 'nope'));

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('wp_nerve_sidebar_not_found', $result->get_error_code());
    }

    public function testListAvailableReturnsWidgetTypes(): void
    {
        WPState::$widgetFactory = (object) array(
            'widgets' => array(
                'search'        => (object) array('name' => 'Búsqueda'),
                'recent-posts'  => (object) array('name' => 'Entradas recientes'),
            ),
        );
        $GLOBALS['wp_widget_factory'] = WPState::$widgetFactory;

        $result = $this->registry->execute('wp_nerve_list_available_widgets', array());

        self::assertNotInstanceOf(WP_Error::class, $result);
        self::assertCount(2, $result['result']['widgets']);
        self::assertSame('Búsqueda', $result['result']['widgets'][0]['name']);
    }

    private function menu(int $id, string $name): WP_Term
    {
        $menu = new WP_Term($id);

        $menu->name     = $name;
        $menu->slug     = sanitize_title($name);
        $menu->taxonomy = 'nav_menu';

        return $menu;
    }

    private function menuItem(int $id, int $menuId, string $title, int $order): WP_Post
    {
        $item = new WP_Post($id);

        $item->post_title  = $title;
        $item->post_type   = 'nav_menu_item';
        $item->post_status = 'publish';
        $item->menu_order  = $order;

        WPState::$posts[$id] = $item;
        WPState::$menuItemMenu[$id] = $menuId;
        WPState::$postTerms[$id]['nav_menu'] = array($menuId);
        WPState::$postMeta[$id]['_menu_item_type']      = 'custom';
        WPState::$postMeta[$id]['_menu_item_object']    = '';
        WPState::$postMeta[$id]['_menu_item_object_id'] = 0;
        WPState::$postMeta[$id]['_menu_item_url']       = 'https://example.test/' . sanitize_title($title);
        WPState::$postMeta[$id]['_menu_item_target']    = '';

        return $item;
    }
}
