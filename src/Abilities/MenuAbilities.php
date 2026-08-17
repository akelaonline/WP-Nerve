<?php

/**
 * Navigation menu abilities.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Abilities;

use WP_Error;
use WP_Post;
use WP_Term;

final class MenuAbilities extends AbstractAbilityRegistrar
{
    private const CAPABILITY = 'edit_theme_options';

    public function register(): void
    {
        $this->registerListMenus();
        $this->registerGetMenuItems();
        $this->registerCreateMenu();
        $this->registerAddMenuItem();
        $this->registerUpdateMenuItem();
        $this->registerDeleteMenuItem();
        $this->registerAssignLocation();
    }

    /** @return array<string, mixed> */
    public function listMenus(mixed $input = null): array
    {
        unset($input);

        $menus = array();

        foreach (wp_get_nav_menus() as $menu) {
            if (! $menu instanceof WP_Term) {
                continue;
            }

            $menus[] = $this->menuItemSummary($menu);
        }

        return array(
            'menus'     => $menus,
            'locations' => $this->locations(),
        );
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|WP_Error
     */
    public function getMenuItems(mixed $input = null): array|WP_Error
    {
        $input  = is_array($input) ? $input : array();
        $menuId = (int) ($input['menu_id'] ?? 0);

        $menu = $this->menuOrError($menuId);

        if ($menu instanceof WP_Error) {
            return $menu;
        }

        $items = wp_get_nav_menu_items($menu->term_id);

        $result = array();

        if (is_array($items)) {
            foreach ($items as $item) {
                if ($item instanceof WP_Post) {
                    $result[] = $this->menuItem($item);
                }
            }
        }

        return array('items' => $result, 'total' => count($result));
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|WP_Error
     */
    public function createMenu(mixed $input = null): array|WP_Error
    {
        $input = is_array($input) ? $input : array();
        $name  = trim((string) ($input['name'] ?? ''));

        if ('' === $name) {
            return new WP_Error('wp_nerve_invalid_name', __('The name parameter must be a non-empty string.', 'wp-nerve'));
        }

        $id = wp_create_nav_menu($name);

        if (is_wp_error($id)) {
            return $id;
        }

        return array(
            'id'   => $id,
            'name' => $name,
            'recovery' => array(
                'undo' => 'wp_nerve_delete_menu',
                'note' => __('Delete the created menu to undo.', 'wp-nerve'),
            ),
        );
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|WP_Error
     */
    public function addMenuItem(mixed $input = null): array|WP_Error
    {
        $input  = is_array($input) ? $input : array();
        $menuId = (int) ($input['menu_id'] ?? 0);

        $menu = $this->menuOrError($menuId);

        if ($menu instanceof WP_Error) {
            return $menu;
        }

        $data = $this->menuItemData($input);

        $id = wp_update_nav_menu_item($menu->term_id, 0, $data);

        if (is_wp_error($id)) {
            return $id;
        }

        return $this->menuItemResult($id);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|WP_Error
     */
    public function updateMenuItem(mixed $input = null): array|WP_Error
    {
        $input  = is_array($input) ? $input : array();
        $itemId = (int) ($input['item_id'] ?? 0);
        $post   = get_post($itemId);

        if (! $post instanceof WP_Post || 'nav_menu_item' !== $post->post_type) {
            return new WP_Error('wp_nerve_item_not_found', __('The requested menu item does not exist.', 'wp-nerve'));
        }

        $menuId = $this->menuForItem($post->ID);

        if (null === $menuId) {
            return new WP_Error('wp_nerve_item_not_found', __('The menu item is not attached to a menu.', 'wp-nerve'));
        }

        $data = array();

        if (array_key_exists('title', $input)) {
            $data['menu-item-title'] = (string) $input['title'];
        }

        if (array_key_exists('url', $input)) {
            $data['menu-item-url'] = (string) $input['url'];
        }

        if (array_key_exists('target', $input)) {
            $data['menu-item-target'] = (string) $input['target'];
        }

        if (array_key_exists('parent', $input)) {
            $data['menu-item-parent-id'] = (int) $input['parent'];
        }

        $id = wp_update_nav_menu_item($menuId, $post->ID, $data);

        if (is_wp_error($id)) {
            return $id;
        }

        return $this->menuItemResult($id);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|WP_Error
     */
    public function deleteMenuItem(mixed $input = null): array|WP_Error
    {
        $input  = is_array($input) ? $input : array();
        $itemId = (int) ($input['item_id'] ?? 0);
        $post   = get_post($itemId);

        if (! $post instanceof WP_Post || 'nav_menu_item' !== $post->post_type) {
            return new WP_Error('wp_nerve_item_not_found', __('The requested menu item does not exist.', 'wp-nerve'));
        }

        $menuId = $this->menuForItem($post->ID);

        if (null === $menuId) {
            return new WP_Error('wp_nerve_item_not_found', __('The menu item is not attached to a menu.', 'wp-nerve'));
        }

        $result = wp_delete_post($post->ID, true);

        if (null === $result) {
            return new WP_Error('wp_nerve_delete_failed', __('The menu item could not be deleted.', 'wp-nerve'));
        }

        return array(
            'id'      => $post->ID,
            'deleted' => true,
            'recovery' => array(
                'undo' => 'wp_nerve_add_menu_item',
                'note' => __('Re-add the menu item to undo.', 'wp-nerve'),
            ),
        );
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|WP_Error
     */
    public function assignLocation(mixed $input = null): array|WP_Error
    {
        $input    = is_array($input) ? $input : array();
        $location = (string) ($input['location'] ?? '');
        $menuId   = (int) ($input['menu_id'] ?? 0);

        if ('' === $location) {
            return new WP_Error('wp_nerve_invalid_location', __('The location parameter is required.', 'wp-nerve'));
        }

        $previous = get_nav_menu_locations();

        if (0 !== $menuId) {
            $menu = $this->menuOrError($menuId);

            if ($menu instanceof WP_Error) {
                return $menu;
            }
        }

        $locations = $previous;
        $locations[$location] = 0 === $menuId ? 0 : $menuId;

        set_theme_mod('nav_menu_locations', $locations);

        return array(
            'location' => $location,
            'menu_id'  => $locations[$location],
            'previous' => $previous,
            'recovery' => array(
                'note' => __('Restore the previous location map to undo.', 'wp-nerve'),
                'previous' => $previous,
            ),
        );
    }

    private function registerListMenus(): void
    {
        $this->registerReadAbility(
            'wp-nerve/list-menus',
            __('List menus', 'wp-nerve'),
            __('Lists navigation menus and their assigned theme locations.', 'wp-nerve'),
            $this->emptyInputSchema(),
            array(
                '$schema'              => 'https://json-schema.org/draft/2020-12/schema',
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array('menus', 'locations'),
                'properties'           => array(
                    'menus'     => array(
                        'type'  => 'array',
                        'items' => array(
                            'type'                 => 'object',
                            'additionalProperties' => false,
                            'required'             => array('id', 'name', 'slug', 'count'),
                            'properties'           => array(
                                'id'    => array('type' => 'integer'),
                                'name'  => array('type' => 'string'),
                                'slug'  => array('type' => 'string'),
                                'count' => array('type' => 'integer'),
                            ),
                        ),
                    ),
                    'locations' => array('type' => 'object'),
                ),
            ),
            array($this, 'listMenus')
        );
    }

    private function registerGetMenuItems(): void
    {
        $this->registerReadAbility(
            'wp-nerve/get-menu-items',
            __('Get menu items', 'wp-nerve'),
            __('Returns the items of a navigation menu.', 'wp-nerve'),
            $this->menuIdSchema(),
            array(
                '$schema'              => 'https://json-schema.org/draft/2020-12/schema',
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array('items', 'total'),
                'properties'           => array(
                    'items' => array('type' => 'array', 'items' => $this->menuItemSchema()),
                    'total' => array('type' => 'integer'),
                ),
            ),
            array($this, 'getMenuItems')
        );
    }

    private function registerCreateMenu(): void
    {
        $this->registerAbility(
            'wp-nerve/create-menu',
            __('Create menu', 'wp-nerve'),
            __('Creates a navigation menu. Undo by deleting the created menu.', 'wp-nerve'),
            array(
                '$schema'              => 'https://json-schema.org/draft/2020-12/schema',
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array('name'),
                'properties'           => array(
                    'name' => array('type' => 'string', 'minLength' => 1, 'maxLength' => 200),
                ),
            ),
            array(
                '$schema'              => 'https://json-schema.org/draft/2020-12/schema',
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array('id', 'name'),
                'properties'           => array(
                    'id'       => array('type' => 'integer'),
                    'name'     => array('type' => 'string'),
                    'recovery' => array('type' => 'object'),
                ),
            ),
            array($this, 'createMenu'),
            'write',
            true,
            self::CAPABILITY
        );
    }

    private function registerAddMenuItem(): void
    {
        $this->registerAbility(
            'wp-nerve/add-menu-item',
            __('Add menu item', 'wp-nerve'),
            __('Adds an item to a navigation menu. Undo by deleting the item.', 'wp-nerve'),
            $this->menuItemInputSchema(true),
            $this->menuItemOutputSchema(),
            array($this, 'addMenuItem'),
            'write',
            true,
            self::CAPABILITY
        );
    }

    private function registerUpdateMenuItem(): void
    {
        $this->registerAbility(
            'wp-nerve/update-menu-item',
            __('Update menu item', 'wp-nerve'),
            __('Updates the title, URL, target, or parent of a menu item.', 'wp-nerve'),
            array(
                '$schema'              => 'https://json-schema.org/draft/2020-12/schema',
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array('item_id'),
                'properties'           => array(
                    'item_id' => array('type' => 'integer', 'minimum' => 1),
                    'title'   => array('type' => 'string', 'maxLength' => 500),
                    'url'     => array('type' => 'string', 'maxLength' => 1000),
                    'target'  => array('type' => 'string', 'enum' => array('', '_blank', '_self', '_parent', '_top')),
                    'parent'  => array('type' => 'integer', 'minimum' => 0),
                ),
            ),
            $this->menuItemOutputSchema(),
            array($this, 'updateMenuItem'),
            'write',
            true,
            self::CAPABILITY
        );
    }

    private function registerDeleteMenuItem(): void
    {
        $this->registerAbility(
            'wp-nerve/delete-menu-item',
            __('Delete menu item', 'wp-nerve'),
            __('Deletes a menu item. Undo by re-adding the item.', 'wp-nerve'),
            $this->menuItemIdSchema(),
            array(
                '$schema'              => 'https://json-schema.org/draft/2020-12/schema',
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array('id', 'deleted'),
                'properties'           => array(
                    'id'       => array('type' => 'integer'),
                    'deleted'  => array('type' => 'boolean'),
                    'recovery' => array('type' => 'object'),
                ),
            ),
            array($this, 'deleteMenuItem'),
            'write',
            true,
            self::CAPABILITY
        );
    }

    private function registerAssignLocation(): void
    {
        $this->registerAbility(
            'wp-nerve/assign-menu-location',
            __('Assign menu location', 'wp-nerve'),
            __('Assigns a menu to a theme location, or removes it with menu_id 0. The previous map is returned for recovery.', 'wp-nerve'),
            array(
                '$schema'              => 'https://json-schema.org/draft/2020-12/schema',
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array('location'),
                'properties'           => array(
                    'location' => array('type' => 'string', 'minLength' => 1, 'maxLength' => 100),
                    'menu_id'  => array('type' => 'integer', 'minimum' => 0, 'default' => 0),
                ),
            ),
            array(
                '$schema'              => 'https://json-schema.org/draft/2020-12/schema',
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array('location', 'menu_id', 'previous'),
                'properties'           => array(
                    'location' => array('type' => 'string'),
                    'menu_id'  => array('type' => 'integer'),
                    'previous' => array('type' => 'object'),
                    'recovery' => array('type' => 'object'),
                ),
            ),
            array($this, 'assignLocation'),
            'write',
            true,
            self::CAPABILITY
        );
    }

    /** @return array<string, mixed> */
    private function menuItemSummary(WP_Term $menu): array
    {
        return array(
            'id'    => $menu->term_id,
            'name'  => $menu->name,
            'slug'  => $menu->slug,
            'count' => count($this->menuItemsOf($menu->term_id)),
        );
    }

    /** @return array<string, mixed> */
    private function menuItem(WP_Post $item): array
    {
        return array(
            'id'        => $item->ID,
            'menu_id'   => (int) $this->menuForItem($item->ID),
            'title'     => $item->post_title,
            'type'      => (string) $this->itemMeta($item->ID, 'type', 'custom'),
            'object'    => (string) $this->itemMeta($item->ID, 'object', ''),
            'object_id' => (int) $this->itemMeta($item->ID, 'object_id', 0),
            'url'       => (string) $this->itemMeta($item->ID, 'url', ''),
            'target'    => (string) $this->itemMeta($item->ID, 'target', ''),
            'parent'    => (int) $item->post_parent,
            'order'     => (int) $item->menu_order,
        );
    }

    /**
     * Output schema for mutation results, which include the item plus the
     * optional recovery guidance.
     *
     * @return array<string, mixed>
     */
    private function menuItemOutputSchema(): array
    {
        $schema = $this->menuItemSchema();

        $schema['properties']['recovery'] = array('type' => 'object');

        return array_merge(
            array('$schema' => 'https://json-schema.org/draft/2020-12/schema'),
            $schema
        );
    }

    /** @return array<string, mixed> */
    private function menuItemSchema(): array
    {
        return array(
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => array('id', 'menu_id', 'title', 'type', 'object', 'object_id', 'url', 'target', 'parent', 'order'),
            'properties'           => array(
                'id'        => array('type' => 'integer'),
                'menu_id'   => array('type' => 'integer'),
                'title'     => array('type' => 'string'),
                'type'      => array('type' => 'string'),
                'object'    => array('type' => 'string'),
                'object_id' => array('type' => 'integer'),
                'url'       => array('type' => 'string'),
                'target'    => array('type' => 'string'),
                'parent'    => array('type' => 'integer'),
                'order'     => array('type' => 'integer'),
            ),
        );
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function menuItemData(array $input): array
    {
        return array(
            'menu-item-title'     => (string) ($input['title'] ?? ''),
            'menu-item-type'      => (string) ($input['type'] ?? 'custom'),
            'menu-item-object'    => (string) ($input['object'] ?? ''),
            'menu-item-object-id' => (int) ($input['object_id'] ?? 0),
            'menu-item-url'       => (string) ($input['url'] ?? ''),
            'menu-item-target'    => (string) ($input['target'] ?? ''),
            'menu-item-parent-id' => (int) ($input['parent'] ?? 0),
            'menu-item-position'  => (int) ($input['position'] ?? 0),
        );
    }

    /** @return array<string, mixed>|WP_Error */
    private function menuItemResult(int $id): array|WP_Error
    {
        $item = get_post($id);

        if (! $item instanceof WP_Post) {
            return new WP_Error('wp_nerve_item_failed', __('The menu item could not be saved.', 'wp-nerve'));
        }

        $result = $this->menuItem($item);
        $result['recovery'] = array(
            'undo' => 'wp_nerve_menus_delete_item',
            'note' => __('Delete the item to undo.', 'wp-nerve'),
        );

        return $result;
    }

    /** @return WP_Term|WP_Error */
    private function menuOrError(int $menuId): WP_Term|WP_Error
    {
        foreach (wp_get_nav_menus() as $menu) {
            if ($menu instanceof WP_Term && $menuId === $menu->term_id) {
                return $menu;
            }
        }

        return new WP_Error('wp_nerve_menu_not_found', __('The requested menu does not exist.', 'wp-nerve'));
    }

    /** @return array<int, WP_Post> */
    private function menuItemsOf(int $menuId): array
    {
        $items = wp_get_nav_menu_items($menuId);

        return is_array($items) ? $items : array();
    }

    private function menuForItem(int $itemId): ?int
    {
        $menu = wp_get_post_terms($itemId, 'nav_menu');

        if (! is_array($menu) || array() === $menu || ! isset($menu[0])) {
            return null;
        }

        return (int) $menu[0]->term_id;
    }

    private function itemMeta(int $itemId, string $key, mixed $default): mixed
    {
        $meta = get_post_meta($itemId, '_menu_item_' . $key, true);

        return '' === $meta ? $default : $meta;
    }

    /** @return array<string, mixed> */
    private function menuIdSchema(): array
    {
        return array(
            '$schema'              => 'https://json-schema.org/draft/2020-12/schema',
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => array('menu_id'),
            'properties'           => array(
                'menu_id' => array('type' => 'integer', 'minimum' => 1),
            ),
        );
    }

    /** @return array<string, mixed> */
    private function menuItemInputSchema(bool $requireTarget = true): array
    {
        unset($requireTarget);

        return array(
            '$schema'              => 'https://json-schema.org/draft/2020-12/schema',
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => array('menu_id', 'title'),
            'properties'           => array(
                'menu_id'   => array('type' => 'integer', 'minimum' => 1),
                'title'     => array('type' => 'string', 'minLength' => 1, 'maxLength' => 500),
                'type'      => array('type' => 'string', 'enum' => array('custom', 'post_type', 'taxonomy'), 'default' => 'custom'),
                'object'    => array('type' => 'string', 'maxLength' => 100),
                'object_id' => array('type' => 'integer', 'minimum' => 1),
                'url'       => array('type' => 'string', 'maxLength' => 1000),
                'target'    => array('type' => 'string', 'enum' => array('', '_blank', '_self', '_parent', '_top'), 'default' => ''),
                'parent'    => array('type' => 'integer', 'minimum' => 0, 'default' => 0),
                'position'  => array('type' => 'integer', 'minimum' => 0, 'default' => 0),
            ),
        );
    }

    /** @return array<string, mixed> */
    private function menuItemIdSchema(): array
    {
        return array(
            '$schema'              => 'https://json-schema.org/draft/2020-12/schema',
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => array('item_id'),
            'properties'           => array(
                'item_id' => array('type' => 'integer', 'minimum' => 1),
            ),
        );
    }

    /** @return array<string, int> */
    private function locations(): array
    {
        return get_nav_menu_locations();
    }
}
