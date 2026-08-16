# Changelog

All notable changes to WPNerve will be documented here.

## [Unreleased]

### Added

- Risk class opt-in mechanism: `read` and `write` classes enabled by default,
  `destructive` and `privileged` denied unless the site opts in via the
  `wp_nerve_enabled_risk_classes` option or filter. Per-ability enablement via
  the `wp_nerve_ability_is_enabled` filter.
- Content lifecycle abilities: `create-draft`, `update-content`,
  `list-revisions`, `get-revision`, `trash-content`, `restore-content`,
  `publish-content`, and `restore-revision`, each with recovery semantics and
  capability gates. Destructive operations are opt-in.
- Taxonomy abilities: `list-taxonomies`, `list-terms`, `create-term` (opt-in),
  and `assign-terms` with previous-assignment recovery data.
- Media abilities: `list-media`, `get-media`, `upload-media` (base64, opt-in),
  `update-media`, and `delete-media` (opt-in), with upload size limits and
  attachment metadata handling.
- Comment abilities: `list-comments`, `get-comment`, `create-comment`,
  `reply-comment`, `moderate-comment` (previous status returned for recovery),
  and `delete-comment` (opt-in). Non-approved comment access requires
  `moderate_comments`.
- Menu abilities: `menus/list`, `menus/get-items`, `menus/create`,
  `menus/add-item`, `menus/update-item`, `menus/delete-item`, and
  `menus/assign-location` (previous location map returned for recovery).
- Widget abilities (read-only): `widgets/list-sidebars`, `widgets/get-sidebar`,
  and `widgets/list-available`.
- Ability registration refactored into per-domain registrars sharing a common
  base (`AbstractAbilityRegistrar`).

## [0.1.0-alpha.2] - 2026-08-16

### Added

- `wp_nerve_list_content_types` read ability.
- `wp_nerve_search_content` read ability with post type, status, and pagination
  controls. Non-public statuses require the `read_private_posts` capability.
- `wp_nerve_get_content` read ability. Drafts and private posts are gated behind
  the `edit_post` and `read_private_posts` capabilities respectively.
- Text domain loading via `load_plugin_textdomain()` and a bundled
  `languages/wp-nerve.pot` catalog.
- Unit test suite covering the protocol, policy, transport, audit, abilities,
  plugin composition root, and entry point (114 tests, ~96% line coverage).
- `ToolRegistry` and `AuditRecorder` contracts to keep the protocol layer
  decoupled from concrete implementations.
- CI consistency job (header/constant/stable-tag version match, conflict
  marker detection) and CI coverage report job.

### Changed

- Version bumped to `0.1.0-alpha.2` (header, constant, and readme stable tag).
- Runtime constants in `wp-nerve.php` are now defined defensively so a double
  include cannot redeclare them.

## [0.1.0-alpha.1] - 2026-08-15

### Added

- Native WordPress Abilities API composition root.
- MCP `2026-07-28` stateless discovery and tool transport.
- Compatibility surface for MCP `2025-11-25` and `2025-06-18`.
- Mirrored-header, Origin, and request-size validation with secure HTTP defaults.
- Central risk policy engine.
- Privacy-preserving execution audit schema.
- Read-only `wp_nerve_site_status` vertical slice.
- Admin connection screen, automated quality checks, and architecture records.
