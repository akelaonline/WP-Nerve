=== WPNerve ===
Contributors: akelaonline
Tags: mcp, ai, agents, abilities, wordpress
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.1.0-alpha.14
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Native, self-hosted MCP gateway for WordPress agents built on the WordPress Abilities API.

== Description ==

WPNerve exposes a curated catalog of 53 native WordPress Abilities as authenticated MCP tools.

Highlights:

* Native WordPress 6.9+ Abilities API
* 53 reviewed abilities with live runtime diagnostics
* Application Password and constrained OAuth authentication over HTTPS
* Read, Write, Privileged and Destructive risk classes
* Persistent idempotency for mutations
* WordPress-admin approval for high-risk operations
* Independent MCP/OAuth rate limits
* Privacy-preserving audit records
* Hardened plugin ZIP inspection
* In-product Dashboard, Diagnostics, HTTP Smoke and Documentation
* No arbitrary SQL, PHP, shell, WP-CLI or wp-config.php access

WPNerve runs inside WordPress. There is no relay, SaaS control plane, Firebase dependency or external credential store.

== Installation ==

1. Upload the WPNerve ZIP from Plugins > Add New > Upload Plugin.
2. Activate WPNerve.
3. Open WPNerve > Dashboard.
4. Generate a dedicated WPNerve Application Password for the WordPress user the agent should act as.
5. Copy the generated MCP client configuration.
6. Review enabled risk classes.
7. Run WPNerve > Diagnostics before connecting a new client.

== Frequently Asked Questions ==

= Does WPNerve expose unrestricted access to WordPress? =

No. WPNerve exposes only reviewed, schema-defined abilities. It does not expose arbitrary SQL, PHP, shell, WP-CLI, filesystem editing or wp-config.php access.

= How many abilities are included? =

The v1 catalog contains exactly 53 registered abilities. The Diagnostics screen compares that contract to WordPress' live registry and current policy.

= What authentication is supported? =

WordPress Application Passwords are the simplest option. A constrained OAuth public-client flow is also implemented for clients that cannot send Basic authentication.

= Are destructive operations automatic? =

No. Privileged and destructive operations are disabled by default and require a one-time WordPress administrator approval when enabled.

== Changelog ==

= 0.1.0-alpha.14 =
* Product-grade WPNerve admin dashboard and navigation.
* Unified visual system across Dashboard, Diagnostics, HTTP Smoke and Documentation.
* New in-product operator documentation.
* GitHub/readme documentation refresh.
* Preserves the 53-ability runtime contract and security gates validated on staging.

= 0.1.0-alpha.13 =
* Added authenticated real-HTTP MCP smoke diagnostics using a temporary Application Password that is revoked automatically.

= 0.1.0-alpha.12 =
* Added one-click operational MCP smoke covering discovery, tools/list, reads, writes and destructive confirmation.

= 0.1.0-alpha.11 =
* Added live 53-ability runtime diagnostics and explicit full-surface staging controls.
