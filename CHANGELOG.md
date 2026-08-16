# Changelog

All notable changes to WPNerve will be documented here.

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
