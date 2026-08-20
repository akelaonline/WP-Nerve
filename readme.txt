=== WPNerve ===
Contributors: akelaonline
Tags: mcp, ai, agents, abilities, automation
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.1.0-alpha.11
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A secure, native MCP server and agent control layer for WordPress.

== Description ==

WPNerve exposes selected native WordPress Abilities to authenticated MCP clients
over the Model Context Protocol. It runs entirely inside WordPress and does not
require an external relay, SaaS account, or additional database.

The v1 catalog contains exactly 53 reviewed abilities. Tools > WPNerve
Diagnostics shows the live WordPress registry count and the number currently
discoverable for the logged-in administrator, so documentation counts are never
confused with runtime exposure.

Read-only abilities ship enabled by default: site status, content type listing,
content search, and full content reads. Recoverable writes are available across
the selected v1 surface. Destructive and privileged tools are hidden until the
site owner opts in and then require a matching, short-lived approval in Tools >
WPNerve before each logical operation.

Public MCP and OAuth boundaries have independent, fail-closed request budgets.
Client-supplied forwarding headers are never trusted when deriving the network
subject used for rate limiting.

Privileged surfaces have additional input- and object-level protections:
security-sensitive options are not exposed, transient reads require explicit
key allowlisting, debug logs are redacted, administrator account management has
a separate opt-in, and WPNerve protects its own plugin from agent deactivation
or deletion.

OAuth public clients use authorization code + PKCE S256, exact redirect URIs,
single-use authorization codes, refresh-token rotation, bounded dynamic client
registration and token revocation. OAuth responses are explicitly non-cacheable.

This version is an early alpha for development and security review.

== Installation ==

1. Upload the WPNerve plugin directory to `/wp-content/plugins/`.
2. Activate WPNerve.
3. Open Tools > WPNerve.
4. Select a dedicated, least-privilege agent user and generate its WPNerve
   credential. The plugin verifies the connection without persisting the secret.
5. Open Tools > WPNerve Diagnostics to verify the live 53-ability registry and
   current discoverable count. On disposable staging only, the full reviewed
   surface can be enabled in one click.
6. Copy the generated client configuration and revoke the credential from the
   same screen when it is no longer needed.

== Frequently Asked Questions ==

= Does WPNerve send data to an external service? =

No. MCP requests are processed inside the WordPress installation.

= How many abilities are implemented? =

The v1 catalog contains exactly 53 registered WPNerve abilities. The number a
specific MCP client can discover can be lower because ability flags, risk
classes and WordPress capabilities are independent policy gates. Tools > WPNerve
Diagnostics shows both numbers from the live registry.

= Which operations are enabled by default? =

Read-only abilities: site status, list content types, search content, and get
content. Drafts and private posts require the corresponding WordPress
capabilities. Destructive and privileged operations are denied by default.

= Which MCP protocol versions are supported? =

The modern stateless HTTP protocol `2026-07-28` plus legacy clients using
`2025-11-25` and `2025-06-18`.

= Do mutating tools require an idempotency key? =

Yes. Every write, destructive, and privileged call must send a unique
`wp-nerve/idempotencyKey` in request `_meta`. Reuse the same key only when
retrying the exact same call. This prevents network retries from duplicating
changes.

= Do destructive or privileged tools require confirmation? =

Yes. Their risk class must first be enabled by an administrator. The first exact
call returns a short-lived token and display code without executing. Match and
approve that code in Tools > WPNerve, then retry with the same arguments,
idempotency key, credential and confirmation token. Changed or expired requests
fail closed.

= How does rate limiting work behind a reverse proxy? =

WPNerve uses the transport peer exposed as `REMOTE_ADDR` and deliberately ignores
arbitrary `Forwarded` and `X-Forwarded-For` headers. If your deployment sits
behind a trusted reverse proxy, normalize the client address at the web-server
or PHP layer instead of trusting a header directly in WordPress.

= Can an enabled privileged tool access every option, transient, user or plugin? =

No. Privileged abilities remain disabled by default and, when explicitly
enabled, still apply narrow safety boundaries. Sensitive WordPress/WPNerve and
credential-like options are always protected. Transients require an exact
per-key allowlist. Administrator account management, existing-user password
changes and existing-user email changes each require separate opt-ins. WPNerve
itself cannot be deactivated or deleted through its MCP plugin tools.

= How is OAuth constrained? =

OAuth is a public-client profile for MCP clients that cannot send a WordPress
Application Password. It requires PKCE S256, exact pre-registered redirect URIs,
a non-empty state value and explicit WordPress consent. Remote redirects require
HTTPS; plain HTTP is accepted only for loopback IP clients. Authorization codes
and refresh tokens are single-use at their rotation boundary, and clients can
revoke their own access or refresh tokens.

== Changelog ==

= 0.1.0-alpha.11 =
* Added Tools > WPNerve Diagnostics with live registered/discoverable ability
  counts, REST-route and schema checks.
* Established 53 as the explicit v1 catalog contract in code and documentation.
* Added an explicit site-owner ability opt-in that never bypasses risk classes or
  WordPress capability checks.
* Added a one-click full 53-ability staging mode for disposable test sites while
  preserving idempotency and high-risk confirmations.
* Protected the new ability-exposure option from MCP option mutation and clean it
  during explicit destructive uninstall.

= 0.1.0-alpha.10 =
* Hardened the OAuth lifecycle with strict PKCE/state validation, exact HTTPS or
  loopback redirect rules and bounded public-client dynamic registration.
* Added a real OAuth revocation endpoint with an independent request budget.
* Authorization codes are single-use, refresh tokens rotate on use, access and
  refresh lifetimes are separate, and expired token rows are cleaned in bounded
  batches.
* OAuth storage failures fail closed; access, refresh and authorization-code
  values remain hashed at rest.
* OAuth responses and redirects send no-store/no-cache/nosniff headers.
* Database schema advanced to v6 so existing alpha installs migrate the hardened
  OAuth token/client schema during normal plugin boot.
* Automatic GitHub Actions triggers are paused; repository workflows remain
  available for explicit manual runs only.

= 0.1.0-alpha.9 =
* Hardened user, plugin, option and system diagnostic abilities with additional
  object- and input-level authorization controls.
* Added conservative WordPress option allowlists and permanent protection for
  security-sensitive, credential-like, transient and WPNerve configuration keys.
* Transient disclosure now defaults to an empty per-key allowlist.
* Debug-log reads are capped at 64 KiB, redact common credential forms and no
  longer disclose the absolute server filesystem path.
* Administrator account management, existing-user password changes and email
  changes require separate opt-ins; sensitive self-user changes and self-delete
  are blocked.
* WPNerve itself and network-active plugins are protected from deactivation and
  deletion.
* Plugin archive installs now require ZIP validation, a SHA-256 checksum,
  decoded-size limits and refuse replacement of a matching installed slug.

= 0.1.0-alpha.8 =
* Added independent fixed-window rate limits for MCP, OAuth authorization,
  token exchange and dynamic client registration.
* Added atomic database-backed request accounting with hashed network subjects.
* Rate-limit storage failures fail closed and exhausted OAuth budgets return
  Retry-After plus rate-limit response headers.
* Untrusted forwarding headers are ignored when selecting the rate-limit subject.
* Database schema advanced to v5 and uninstall cleanup includes rate-limit data.

= 0.1.0-alpha.7 =
* Added out-of-band admin confirmation for destructive and privileged MCP tools.
* Challenges are short-lived and bound to the WordPress user, authoritative
  credential, tool, canonical arguments and idempotency key.
* Added atomic approval/consumption, safe idempotent replay, tamper and expiry
  protection, and privacy-preserving confirmation storage.
* Fixed admin action wiring so credential and confirmation forms run on the
  correct WordPress hook.

= 0.1.0-alpha.6 =
* Added least-privilege user selection, copy-ready client configuration,
  automatic MCP connection testing, and WPNerve credential revocation.
* Fixed Application Password parsing to use WordPress core's tuple response.
* Newly generated secrets exist only in the current admin response and are no
  longer stored in a transient.

= 0.1.0-alpha.5 =
* Added persistent, atomic idempotency for every mutating MCP tool.
* Claims are scoped to the authenticated user, authoritative credential, tool,
  key, and canonical argument digest.
* Added safe outcome replay, collision detection, and fail-closed handling of
  concurrent or indeterminate executions.

= 0.1.0-alpha.4 =
* Added content lifecycle abilities: create-draft, update-content,
  list/get-revisions, trash/restore/publish-content, and restore-revision.
* Added taxonomy abilities: list-taxonomies, list-terms, create-term, assign-terms.
* Added media abilities: list/get/upload/update/delete-media.
* Added comment abilities: list/get/create/reply/moderate/delete-comment.
* Added menu abilities: list, get-items, create, add/update/delete-item,
  assign-location. Widget reads: list-sidebars, get-sidebar, list-available.
* Added user, plugin, option, and system abilities (opt-in by default):
  list/get/create/update/delete-user, plugin lifecycle, options, transients,
  and debug log.
* Added preview-content-update dry-run and an admin dashboard with one-click
  Application Password generation, risk class toggles, and client snippets.
* Added an OAuth 2.1 authorization server (PKCE S256, dynamic client
  registration, refresh tokens) for Claude web and mobile connectors.
* Added risk class opt-in: destructive and privileged operations are denied
  until the site owner enables them.

= 0.1.0-alpha.2 =
* Added list-content-types, search-content, and get-content read abilities.
* Added text domain loading and the .pot catalog.
* Added the unit test suite with ~96% line coverage.

= 0.1.0-alpha.1 =
* Initial protocol, policy, audit, and native Abilities API foundation.
