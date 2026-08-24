# Changelog

All notable changes to WPNerve will be documented here.

## [0.1.0-alpha.15] - 2026-08-24

### Changed

- Completed the operator-facing Diagnostics redesign so runtime KPIs, protocol/database state, operational smoke evidence, staging controls and blocked-ability reporting use the same WPNerve product UI as Dashboard, HTTP Smoke and Documentation.
- Synchronized the installable package, repository source, version mirrors, release metadata and translation catalog around one exact alpha.15 candidate.
- Aligned the packaged admin stylesheet with the final product UI build and corrected release-documentation links/history.

### Release QA

- Regenerated the translation template from the final runtime PHP surface.
- Rebuilt the installable archive only after source/version/documentation consistency checks, PHP syntax validation and package-structure validation.

## [0.1.0-alpha.14] - 2026-08-20

### Added

- Product-grade top-level WPNerve admin navigation with Dashboard, Diagnostics, HTTP Smoke and Documentation.
- Unified Akela product UI with hero/status treatment, KPI cards, operational panels, responsive layout and dedicated admin stylesheet.
- In-product operator documentation covering onboarding, security model, risk classes, client configuration, diagnostics and product scope.

### Changed

- Connection, credentials, confirmations and risk controls are organized as an operational dashboard instead of raw WordPress tables.
- Documentation and repository copy use the exact 53-ability runtime contract and distinguish operational evidence from remaining production-readiness gates.

## [0.1.0-alpha.13] - 2026-08-20

### Added

- Authenticated public-HTTPS MCP smoke diagnostics using a temporary WPNerve Application Password that is revoked automatically after each run.
- Modern MCP `2026-07-28` discovery, 53-tool listing and `site-status` checks over the public WordPress endpoint.
- Legacy `2025-11-25` and `2025-06-18` protocol checks plus negative authentication/origin/header/version boundary tests.

## [0.1.0-alpha.12] - 2026-08-20

### Added

- One-click operational MCP smoke covering `server/discover`, `tools/list`, `site-status`, an opt-in privileged read, draft creation/update, destructive confirmation, trash and restore.
- Cleanup of temporary smoke content after the operational run.

### Validation

- Real WordPress staging passed the full operational smoke with all 53 abilities discoverable in explicit staging mode.

## [0.1.0-alpha.11] - 2026-08-20

### Added

- Live Diagnostics backed by the WordPress Abilities registry with exact registered/discoverable counts.
- Explicit per-ability opt-in plus a one-click full 53-ability staging surface for disposable test sites.

### Fixed

- Enabling every risk class no longer leaves reviewed abilities unreachable solely because their catalog default is disabled.

### Security

- Full-surface staging opt-in does not bypass WordPress capabilities, risk classes, idempotency or high-risk confirmation.

## [0.1.0-alpha.10] - 2026-08-19

### Security

- Hardened the OAuth authorization-code/PKCE lifecycle with strict S256 validation, state constraints, exact redirect rules, single-use codes, refresh rotation/replay rejection and explicit token revocation.
- Added bounded OAuth client/token cleanup, dynamic-client capacity and dedicated revocation rate limiting.
- Advanced the WPNerve database schema contract to version 6 for the hardened OAuth storage layout.

### Changed

- Hosted GitHub Actions workflows were converted to manual dispatch only; absence of hosted CI is not treated as passing evidence.

## [0.1.0-alpha.9] - 2026-08-19

### Security

- Hardened privileged user, plugin, option, transient and system-diagnostic abilities with conservative allowlists, redaction, object guards and execution-time capability checks.
- Protected WPNerve itself and network-active plugins from unsafe deactivation/deletion paths.
- Added composite capability requirements for plugin upload discovery/execution.

## [0.1.0-alpha.8] - 2026-08-19

### Security

- Added independent fail-closed fixed-window request budgets for MCP and OAuth boundaries.
- Hashes rate-limit subjects at rest and derives peer identity from the transport address rather than trusting arbitrary forwarding headers.
- Added bounded cleanup for expired rate-limit records and advanced the database schema contract to version 5.

## [0.1.0-alpha.7] - 2026-08-18

### Added

- Out-of-band WordPress admin approval for every enabled destructive or
  privileged MCP tool.
- Five-minute confirmation challenges bound to the authenticated WordPress
  user, authoritative Application Password, OAuth client or hashed WordPress
  session identity, tool, canonical arguments and idempotency key.
- Atomic approve/deny/consume transitions with changed-input, cross-user,
  cross-credential, expiry and replay protection.
- MCP tool metadata and error response metadata describing the confirmation
  requirement, display code, expiry and opaque retry token.
- Privacy-preserving confirmation storage and a pending-decision table in
  Tools → WPNerve.

### Fixed

- Admin form handling is now registered on `admin_init` during plugin boot,
  before WordPress fires the hook. Credential generation, revocation, risk
  settings and confirmation decisions therefore execute from the real panel.

### Changed

- Database schema version increased to 4 and explicit uninstall cleanup now
  includes the confirmation table.

## [0.1.0-alpha.6] - 2026-08-18

### Added

- Least-privilege WordPress user selection for MCP credentials.
- WPNerve-owned Application Password inventory and scoped revocation controls.
- Copy-ready Basic authentication configuration shown only after generation.
- Immediate authenticated loopback test against the MCP discovery endpoint.

### Fixed

- Parse the numeric tuple returned by WordPress core when creating an
  Application Password; the previous associative lookup could display an empty
  secret after successful creation.
- Newly generated secrets remain only in the current admin request instead of
  being persisted in a WordPress transient.
- The placeholder Claude configuration no longer base64-encodes literal
  placeholder text.

## [0.1.0-alpha.5] - 2026-08-17

### Added

- Persistent idempotency for every write, destructive, and privileged MCP tool.
- Atomic claims scoped to the WordPress user, authoritative Application Password
  or OAuth client identity, tool, key, and canonical argument digest.
- Safe replay of completed results, conflict detection, and fail-closed handling
  for concurrent or indeterminate executions.
- Tool metadata advertising when an idempotency key is required.
- Dedicated schema v3 idempotency table and security documentation.

### Changed

- Mutating tool calls now require `wp-nerve/idempotencyKey` in request `_meta`.
- Explicit uninstall cleanup removes audit, idempotency, and OAuth tables.

## [0.1.0-alpha.4] - 2026-08-17

### Added

- User abilities (opt-in): `list-users`, `get-user`, `create-user`,
  `update-user`, `delete-user`. Administrator creation requires
  `promote_users`.
- Plugin abilities (opt-in): `list-plugins`, `activate-plugin`,
  `deactivate-plugin`, `upload-plugin` (base64 zip), `delete-plugin`.
- Option abilities (opt-in): `get-option`, `update-option` (previous value
  returned for recovery), and `list-options` (keys only, never values).
- System abilities (opt-in): `get-transient` and `debug-log` (tail read).
- `preview-content-update` dry-run ability, completing the v1 catalog.
- Admin dashboard: generate an Application Password in one click, toggle the
  enabled risk classes, and copy client configuration snippets for Claude Code
  and curl.
- OAuth 2.1 authorization server for clients that cannot send Application
  Passwords (Claude web and mobile connectors): dynamic client registration,
  authorization code grant with PKCE S256, refresh token rotation, bearer
  token authentication on the MCP endpoint, and the authorization server
  metadata document. Tokens are stored as SHA-256 hashes in dedicated tables.

### Fixed

- PKCE verification now uses unpadded base64url per RFC 7636, so
  spec-compliant clients can exchange codes.
- OAuth token responses send `Cache-Control: no-store` per OAuth 2.1.
- Consent submission is protected by a nonce (CSRF).
- Anonymous authorize requests preserve their query parameters through the
  wp-login redirect.

## [0.1.0-alpha.3] - 2026-08-16

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
- Menu abilities: `list-menus`, `get-menu-items`, `create-menu`,
  `add-menu-item`, `update-menu-item`, `delete-menu-item`, and
  `assign-menu-location` (previous location map returned for recovery).
- Widget abilities (read-only): `list-sidebars`, `get-sidebar`,
  and `list-available-widgets`.
- Ability registration refactored into per-domain registrars sharing a common
  base (`AbstractAbilityRegistrar`).

### Fixed

- Menu and widget ability names renamed to single-slash form so they pass the
  WordPress 6.9 ability name validation.
- Menu item output schemas declare the `recovery` field; `trash-content`
  uses the full content item schema. The runtime double now validates ability
  output against the output schema.

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
