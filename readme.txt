=== WPNerve ===
Contributors: akelaonline
Tags: mcp, ai, agents, abilities, automation
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.1.0-alpha.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A secure, native MCP server and agent control layer for WordPress.

== Description ==

WPNerve exposes selected native WordPress Abilities to authenticated MCP clients
over the Model Context Protocol. It runs entirely inside WordPress and does not
require an external relay, SaaS account, or additional database.

Read-only abilities ship enabled by default: site status, content type listing,
content search, and full content reads. Destructive and privileged operations
are denied by the policy engine.

This version is an early alpha for development and security review.

== Installation ==

1. Upload the WPNerve plugin directory to `/wp-content/plugins/`.
2. Activate WPNerve.
3. Open Tools > WPNerve.
4. Create a dedicated Application Password for the WordPress user the agent uses.
5. Configure the MCP client with the displayed endpoint.

== Frequently Asked Questions ==

= Does WPNerve send data to an external service? =

No. MCP requests are processed inside the WordPress installation.

= Which operations are enabled by default? =

Read-only abilities: site status, list content types, search content, and get
content. Drafts and private posts require the corresponding WordPress
capabilities. Destructive and privileged operations are denied by default.

= Which MCP protocol versions are supported? =

The modern stateless HTTP protocol `2026-07-28` plus legacy clients using
`2025-11-25` and `2025-06-18`.

== Changelog ==

= 0.1.0-alpha.2 =
* Added list-content-types, search-content, and get-content read abilities.
* Added text domain loading and the .pot catalog.
* Added the unit test suite with ~96% line coverage.

= 0.1.0-alpha.1 =
* Initial protocol, policy, audit, and native Abilities API foundation.
