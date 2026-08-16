# WPNerve v1 ability catalog

This catalog is the delivery contract. Every ability carries a risk class
(`read`, `write`, `destructive`, `privileged`) and a recovery requirement.
Mutations are implemented only when their policy, preview, idempotency, audit,
and recovery requirements are met.

## Risk classes and opt-in

| Risk class | Default | Meaning |
|---|---:|---|
| `read` | **Enabled** | Read-only, safe to expose on any site. |
| `write` | **Enabled** | Recoverable mutations (drafts, revisions, trashing semantics). |
| `destructive` | **Opt-in** | Irreversible or public-state-changing operations (delete, publish, restore revision). Denied unless the site owner enables the class. |
| `privileged` | **Opt-in** | Administrative operations (plugins, users, options, system). Denied unless explicitly enabled. |

Site owners enable risk classes with the `wp_nerve_enabled_risk_classes` option
or the `wp_nerve_enabled_risk_classes` filter:

```php
add_filter(
    'wp_nerve_enabled_risk_classes',
    static fn (array $classes): array => array_merge($classes, array('destructive', 'privileged'))
);
```

An ability is exposed only when its risk class is enabled **and** its
`enabled_by_default` flag (or the `wp_nerve_ability_is_enabled` filter) allows
it **and** the user holds the required WordPress capability. The policy engine
re-checks all three conditions before every execution.

## Content — Posts and Pages

| Ability | Risk | Default | Status | Recovery requirement |
|---|---:|---:|---|---|
| `site-status` | Read | On | **Implemented** | None |
| `list-content-types` | Read | On | **Implemented** | None |
| `search-content` | Read | On | **Implemented** | None |
| `get-content` | Read | On | **Implemented** | None |
| `list-revisions` | Read | On | **Implemented** | None |
| `get-revision` | Read | On | **Implemented** | None |
| `create-draft` | Write | On | **Implemented** | Trash draft |
| `update-content` | Write | On | **Implemented** | WordPress revision |
| `assign-terms` | Write | On | **Implemented** | Previous assignments |
| `restore-content` | Write | On | **Implemented** | Return to trash |
| `create-term` | Write | Off | **Implemented** | Delete created term |
| `list-taxonomies` | Read | On | **Implemented** | None |
| `list-terms` | Read | On | **Implemented** | None |
| `publish-content` | Destructive | Off | **Implemented** | Previous status and revision |
| `trash-content` | Destructive | Off | **Implemented** | Restore from trash |
| `restore-revision` | Destructive | Off | **Implemented** | New revision before restore |
| `preview-content-update` | Read | On | Planned | None |

## Media

| Ability | Risk | Default | Status | Recovery requirement |
|---|---:|---:|---|---|
| `list-media` | Read | On | Planned | None |
| `get-media` | Read | On | Planned | None |
| `update-media` | Write | On | Planned | Previous metadata |
| `upload-media` | Write | Off | Planned | Delete uploaded attachment |
| `delete-media` | Destructive | Off | Planned | Restore from trash |

## Comments

| Ability | Risk | Default | Status | Recovery requirement |
|---|---:|---:|---|---|
| `list-comments` | Read | On | Planned | None |
| `get-comment` | Read | On | Planned | None |
| `create-comment` | Write | On | Planned | Trash comment |
| `reply-comment` | Write | On | Planned | Trash reply |
| `moderate-comment` | Write | On | Planned | Previous status |
| `delete-comment` | Destructive | Off | Planned | Restore from trash |

## Menus and Widgets

| Ability | Risk | Default | Status | Recovery requirement |
|---|---:|---:|---|---|
| `menus/list` | Read | On | Planned | None |
| `menus/get-items` | Read | On | Planned | None |
| `menus/create` | Write | On | Planned | Delete created menu |
| `menus/add-item` | Write | On | Planned | Remove added item |
| `menus/update-item` | Write | On | Planned | Previous item state |
| `menus/delete-item` | Write | On | Planned | Restore from trash |
| `menus/assign-location` | Write | On | Planned | Previous location map |
| `widgets/list-sidebars` | Read | On | Planned | None |
| `widgets/get-sidebar` | Read | On | Planned | None |
| `widgets/list-available` | Read | On | Planned | None |

## Users

| Ability | Risk | Default | Status | Recovery requirement |
|---|---:|---:|---|---|
| `users/list` | Read | Off | Planned | None |
| `users/get` | Read | Off | Planned | None |
| `users/create` | Privileged | Off | Planned | Delete created user |
| `users/update` | Privileged | Off | Planned | Previous user state |
| `users/delete` | Destructive | Off | Planned | Trash/reassign content |

## Plugins

| Ability | Risk | Default | Status | Recovery requirement |
|---|---:|---:|---|---|
| `plugins/list` | Read | Off | Planned | None |
| `plugins/activate` | Privileged | Off | Planned | Previous status |
| `plugins/deactivate` | Privileged | Off | Planned | Previous status |
| `plugins/upload` | Destructive | Off | Planned | Delete uploaded plugin |
| `plugins/delete` | Destructive | Off | Planned | Plugin archive backup |

## Options and System

| Ability | Risk | Default | Status | Recovery requirement |
|---|---:|---:|---|---|
| `options/get` | Privileged | Off | Planned | None |
| `options/update` | Privileged | Off | Planned | Previous value |
| `options/list` | Privileged | Off | Planned | None |
| `system/get-transient` | Privileged | Off | Planned | None |
| `system/debug-log` | Privileged | Off | Planned | None |

Plugin, theme, filesystem, SQL, and code execution abilities are outside the
product scope.
