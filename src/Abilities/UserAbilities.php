<?php

/**
 * User management abilities (privileged, opt-in).
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Abilities;

use WP_Error;
use WP_User;

final class UserAbilities extends AbstractAbilityRegistrar
{
    public function register(): void
    {
        $this->registerListUsers();
        $this->registerGetUser();
        $this->registerCreateUser();
        $this->registerUpdateUser();
        $this->registerDeleteUser();
    }

    /** @return array<string, mixed> */
    public function listUsers(mixed $input = null): array
    {
        $input = is_array($input) ? $input : array();

        $args = array(
            'number' => $this->clamp((int) ($input['number'] ?? 20), 1, 100),
        );

        if (! empty($input['role'])) {
            $args['role'] = (string) $input['role'];
        }

        if (! empty($input['search'])) {
            $args['search'] = (string) $input['search'];
        }

        $items = array();

        foreach (get_users($args) as $user) {
            if ($user instanceof WP_User) {
                $items[] = $this->userItem($user, false);
            }
        }

        return array('items' => $items, 'total' => count($items));
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|WP_Error
     */
    public function getUser(mixed $input = null): array|WP_Error
    {
        $input = is_array($input) ? $input : array();
        $user  = get_userdata((int) ($input['id'] ?? 0));

        if (! $user instanceof WP_User) {
            return new WP_Error('wp_nerve_user_not_found', __('The requested user does not exist.', 'wp-nerve'));
        }

        return $this->userItem($user, true);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|WP_Error
     */
    public function createUser(mixed $input = null): array|WP_Error
    {
        $input = is_array($input) ? $input : array();

        if (! current_user_can('create_users')) {
            return new WP_Error('wp_nerve_forbidden', __('You are not allowed to create users.', 'wp-nerve'));
        }

        $role = (string) ($input['role'] ?? 'subscriber');

        if (! in_array($role, array('subscriber', 'contributor', 'author', 'editor', 'administrator'), true)) {
            return new WP_Error('wp_nerve_invalid_role', __('The requested role is not allowed.', 'wp-nerve'));
        }

        if ('administrator' === $role && ! current_user_can('promote_users')) {
            return new WP_Error('wp_nerve_forbidden', __('Creating administrators requires the promote_users capability.', 'wp-nerve'));
        }

        $userdata = array(
            'user_login'   => (string) ($input['username'] ?? ''),
            'user_email'   => (string) ($input['email'] ?? ''),
            'display_name' => (string) ($input['display_name'] ?? ''),
            'role'         => $role,
        );

        if (! empty($input['password'])) {
            $userdata['user_pass'] = (string) $input['password'];
        }

        $id = wp_insert_user($userdata);

        if (is_wp_error($id)) {
            return $id;
        }

        $user = get_userdata($id);

        if (! $user instanceof WP_User) {
            return new WP_Error('wp_nerve_create_failed', __('The user could not be created.', 'wp-nerve'));
        }

        $item = $this->userItem($user, true);
        $item['recovery'] = array(
            'undo' => 'wp_nerve_delete_user',
            'note' => __('Delete the created user to undo.', 'wp-nerve'),
        );

        return $item;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|WP_Error
     */
    public function updateUser(mixed $input = null): array|WP_Error
    {
        $input = is_array($input) ? $input : array();
        $id    = (int) ($input['id'] ?? 0);
        $user  = get_userdata($id);

        if (! $user instanceof WP_User) {
            return new WP_Error('wp_nerve_user_not_found', __('The requested user does not exist.', 'wp-nerve'));
        }

        if (! current_user_can('edit_user', $user->ID)) {
            return new WP_Error('wp_nerve_forbidden', __('You are not allowed to edit this user.', 'wp-nerve'));
        }

        $userdata = array('ID' => $user->ID);

        foreach (array('display_name', 'user_email', 'first_name', 'last_name') as $field) {
            if (array_key_exists($field, $input)) {
                $userdata[$field] = (string) $input[$field];
            }
        }

        if (array_key_exists('role', $input)) {
            $role = (string) $input['role'];

            if (! in_array($role, array('subscriber', 'contributor', 'author', 'editor', 'administrator'), true)) {
                return new WP_Error('wp_nerve_invalid_role', __('The requested role is not allowed.', 'wp-nerve'));
            }

            if (! current_user_can('promote_users')) {
                return new WP_Error('wp_nerve_forbidden', __('Changing roles requires the promote_users capability.', 'wp-nerve'));
            }

            $userdata['role'] = $role;
        }

        if (array_key_exists('password', $input) && '' !== (string) $input['password']) {
            $userdata['user_pass'] = (string) $input['password'];
        }

        $result = wp_update_user($userdata);

        if (is_wp_error($result)) {
            return $result;
        }

        $updated = get_userdata($user->ID);

        return $updated instanceof WP_User ? $this->userItem($updated, true) : $this->userItem($user, true);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|WP_Error
     */
    public function deleteUser(mixed $input = null): array|WP_Error
    {
        $input = is_array($input) ? $input : array();
        $id    = (int) ($input['id'] ?? 0);
        $user  = get_userdata($id);

        if (! $user instanceof WP_User) {
            return new WP_Error('wp_nerve_user_not_found', __('The requested user does not exist.', 'wp-nerve'));
        }

        if (! current_user_can('delete_user', $user->ID)) {
            return new WP_Error('wp_nerve_forbidden', __('You are not allowed to delete this user.', 'wp-nerve'));
        }

        $reassign = (int) ($input['reassign'] ?? 0);

        if (0 !== $reassign && ! get_userdata($reassign) instanceof WP_User) {
            return new WP_Error('wp_nerve_invalid_reassign', __('The reassign target user does not exist.', 'wp-nerve'));
        }

        $result = wp_delete_user($user->ID, 0 === $reassign ? null : $reassign);

        if (! $result) {
            return new WP_Error('wp_nerve_delete_failed', __('The user could not be deleted.', 'wp-nerve'));
        }

        return array(
            'id'      => $user->ID,
            'deleted' => true,
            'recovery' => array(
                'note' => __('Deleting a user cannot be undone; recreate the user if needed.', 'wp-nerve'),
            ),
        );
    }

    private function registerListUsers(): void
    {
        $this->registerAbility(
            'wp-nerve/list-users',
            __('List users', 'wp-nerve'),
            __('Lists users with optional role and search filters. Requires list_users.', 'wp-nerve'),
            array(
                '$schema'              => 'https://json-schema.org/draft/2020-12/schema',
                'type'                 => 'object',
                'additionalProperties' => false,
                'properties'           => array(
                    'number' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20),
                    'role'   => array('type' => 'string'),
                    'search' => array('type' => 'string', 'maxLength' => 200),
                ),
            ),
            array(
                '$schema'              => 'https://json-schema.org/draft/2020-12/schema',
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array('items', 'total'),
                'properties'           => array(
                    'items' => array('type' => 'array', 'items' => $this->userItemSchema(false)),
                    'total' => array('type' => 'integer'),
                ),
            ),
            array($this, 'listUsers'),
            'read',
            false,
            'list_users'
        );
    }

    private function registerGetUser(): void
    {
        $this->registerAbility(
            'wp-nerve/get-user',
            __('Get user', 'wp-nerve'),
            __('Returns a single user including email. Requires list_users.', 'wp-nerve'),
            $this->userIdSchema(),
            array_merge(
                array('$schema' => 'https://json-schema.org/draft/2020-12/schema'),
                $this->userItemSchema(true)
            ),
            array($this, 'getUser'),
            'read',
            false,
            'list_users'
        );
    }

    private function registerCreateUser(): void
    {
        $this->registerAbility(
            'wp-nerve/create-user',
            __('Create user', 'wp-nerve'),
            __('Creates a user. Administrator creation requires promote_users. Undo by deleting the user.', 'wp-nerve'),
            array(
                '$schema'              => 'https://json-schema.org/draft/2020-12/schema',
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array('username', 'email'),
                'properties'           => array(
                    'username'     => array('type' => 'string', 'minLength' => 1, 'maxLength' => 60),
                    'email'        => array('type' => 'string', 'format' => 'email'),
                    'display_name' => array('type' => 'string', 'maxLength' => 250),
                    'password'     => array('type' => 'string', 'minLength' => 8, 'maxLength' => 100),
                    'role'         => array(
                        'type'    => 'string',
                        'enum'    => array('subscriber', 'contributor', 'author', 'editor', 'administrator'),
                        'default' => 'subscriber',
                    ),
                ),
            ),
            $this->userResultSchema(),
            array($this, 'createUser'),
            'privileged',
            false,
            'create_users'
        );
    }

    private function registerUpdateUser(): void
    {
        $this->registerAbility(
            'wp-nerve/update-user',
            __('Update user', 'wp-nerve'),
            __('Updates a user profile or role. Role changes require promote_users.', 'wp-nerve'),
            array(
                '$schema'              => 'https://json-schema.org/draft/2020-12/schema',
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array('id'),
                'properties'           => array(
                    'id'           => array('type' => 'integer', 'minimum' => 1),
                    'display_name' => array('type' => 'string', 'maxLength' => 250),
                    'email'        => array('type' => 'string', 'format' => 'email'),
                    'first_name'   => array('type' => 'string', 'maxLength' => 100),
                    'last_name'    => array('type' => 'string', 'maxLength' => 100),
                    'role'         => array(
                        'type' => 'string',
                        'enum' => array('subscriber', 'contributor', 'author', 'editor', 'administrator'),
                    ),
                    'password' => array('type' => 'string', 'minLength' => 8, 'maxLength' => 100),
                ),
            ),
            $this->userResultSchema(),
            array($this, 'updateUser'),
            'privileged',
            false,
            'edit_users'
        );
    }

    private function registerDeleteUser(): void
    {
        $this->registerAbility(
            'wp-nerve/delete-user',
            __('Delete user', 'wp-nerve'),
            __('Deletes a user, optionally reassigning their content. Cannot be undone.', 'wp-nerve'),
            array(
                '$schema'              => 'https://json-schema.org/draft/2020-12/schema',
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array('id'),
                'properties'           => array(
                    'id'       => array('type' => 'integer', 'minimum' => 1),
                    'reassign' => array('type' => 'integer', 'minimum' => 1),
                ),
            ),
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
            array($this, 'deleteUser'),
            'destructive',
            false,
            'delete_users'
        );
    }

    /** @return array<string, mixed> */
    private function userItem(WP_User $user, bool $withEmail): array
    {
        $item = array(
            'id'       => $user->ID,
            'username' => $user->user_login,
            'name'     => $user->display_name,
            'roles'    => array_values($user->roles),
            'registered' => $user->user_registered,
        );

        if ($withEmail) {
            $item['email'] = $user->user_email;
        }

        return $item;
    }

    /** @return array<string, mixed> */
    private function userItemSchema(bool $withEmail): array
    {
        $required = array('id', 'username', 'name', 'roles', 'registered');
        $props = array(
            'id'         => array('type' => 'integer'),
            'username'   => array('type' => 'string'),
            'name'       => array('type' => 'string'),
            'roles'      => array('type' => 'array', 'items' => array('type' => 'string')),
            'registered' => array('type' => 'string'),
        );

        if ($withEmail) {
            $required[] = 'email';
            $props['email'] = array('type' => 'string', 'format' => 'email');
        }

        return array(
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => $required,
            'properties'           => $props,
        );
    }

    /** @return array<string, mixed> */
    private function userResultSchema(): array
    {
        $schema = $this->userItemSchema(true);

        $schema['properties']['recovery'] = array('type' => 'object');

        return array_merge(
            array('$schema' => 'https://json-schema.org/draft/2020-12/schema'),
            $schema
        );
    }

    /** @return array<string, mixed> */
    private function userIdSchema(): array
    {
        return array(
            '$schema'              => 'https://json-schema.org/draft/2020-12/schema',
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => array('id'),
            'properties'           => array(
                'id' => array('type' => 'integer', 'minimum' => 1),
            ),
        );
    }
}
