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
| `list-media` | Read | On | **Implemented** | None |
| `get-media` | Read | On | **Implemented** | None |
| `update-media` | Write | On | **Implemented** | Previous metadata |
| `upload-media` | Write | Off | **Implemented** | Delete uploaded attachment |
| `delete-media` | Destructive | Off | **Implemented** | Restore from trash |

## Comments

| Ability | Risk | Default | Status | Recovery requirement |
|---|---:|---:|---|---|
| `list-comments` | Read | On | **Implemented** | None |
| `get-comment` | Read | On | **Implemented** | None |
| `create-comment` | Write | On | **Implemented** | Trash comment |
| `reply-comment` | Write | On | **Implemented** | Trash reply |
| `moderate-comment` | Write | On | **Implemented** | Previous status |
| `delete-comment` | Destructive | Off | **Implemented** | Restore from trash |

## Menus and Widgets

| Ability | Risk | Default | Status | Recovery requirement |
|---|---:|---:|---|---|
| `list-menus` | Read | On | **Implemented** | None |
| `get-menu-items` | Read | On | **Implemented** | None |
| `create-menu` | Write | On | **Implemented** | Delete created menu |
| `add-menu-item` | Write | On | **Implemented** | Remove added item |
| `update-menu-item` | Write | On | **Implemented** | Previous item state |
| `delete-menu-item` | Write | On | **Implemented** | Restore from trash |
| `assign-menu-location` | Write | On | **Implemented** | Previous location map |
| `list-sidebars` | Read | On | **Implemented** | None |
| `get-sidebar` | Read | On | **Implemented** | None |
| `list-available-widgets` | Read | On | **Implemented** | None |

## Users

| Ability | Risk | Default | Status | Recovery requirement |
|---|---:|---:|---|---|
| `list-users` | Read | Off | **Implemented** | None |
| `get-user` | Read | Off | **Implemented** | None |
| `create-user` | Privileged | Off | **Implemented** | Delete created user |
| `update-user` | Privileged | Off | **Implemented** | Previous user state |
| `delete-user` | Destructive | Off | **Implemented** | Trash/reassign content |

## Plugins

| Ability | Risk | Default | Status | Recovery requirement |
|---|---:|---:|---|---|
| `list-plugins` | Read | Off | **Implemented** | None |
| `activate-plugin` | Privileged | Off | **Implemented** | Previous status |
| `deactivate-plugin` | Privileged | Off | **Implemented** | Previous status |
| `upload-plugin` | Destructive | Off | **Implemented** | Delete uploaded plugin |
| `delete-plugin` | Destructive | Off | **Implemented** | Plugin archive backup |

## Options and System

| Ability | Risk | Default | Status | Recovery requirement |
|---|---:|---:|---|---|
| `get-option` | Privileged | Off | **Implemented** | None |
| `update-option` | Privileged | Off | **Implemented** | Previous value |
| `list-options` | Privileged | Off | **Implemented** | None |
| `get-transient` | Privileged | Off | **Implemented** | None |
| `debug-log` | Privileged | Off | **Implemented** | None |

Plugin, theme, filesystem, SQL, and code execution abilities are outside the
product scope.
