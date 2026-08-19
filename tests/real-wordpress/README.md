# G6 — Real WordPress runtime evidence

This directory is the manual, reproducible runtime gate for WPNerve. It is
intentionally separate from the PHPUnit runtime doubles.

The Abilities API is a WordPress Core API available in WordPress 6.9+, and a
registered `WP_Ability` exposes public permission, schema and `execute()` behavior.
The G6 harness uses those real Core objects plus the real WordPress database.

## Security baseline

G6 evidence must be collected only on patched WordPress Core releases. The
runner executes `platform-security.php` before every stateful probe and refuses
the known-vulnerable July 2026 ranges:

- WordPress 6.9.0 through 6.9.4;
- WordPress 7.0.0 through 7.0.1.

Use **6.9.5 or newer on the 6.9 line**, or **7.0.2 or newer on the 7.0 line**.
A unit/runtime result from an affected Core build is not accepted as release
evidence.

## What this gate proves

`single-site.php` runs inside a real WordPress request through WP-CLI and checks:

- a patched WordPress 6.9+ runtime and PHP 8.1+ are actually running;
- WPNerve alpha.10 is loaded;
- the global schema contract is current;
- audit, idempotency, confirmation, rate-limit and OAuth tables exist with the
  active site's real `$wpdb->prefix`;
- exactly 53 WPNerve abilities are present in the native Abilities registry;
- `wp-nerve/site-status` resolves as a real `WP_Ability` and executes through
  Core validation/permission handling;
- idempotency claim → complete → replay → conflict semantics work against MySQL;
- fixed-window rate limiting exhausts atomically against MySQL;
- high-risk confirmation persists, approves, consumes once and rejects replay;
- OAuth client/code/token rows work against MySQL, including code replay
  rejection, refresh rotation/replay rejection and access-token revocation.

`multisite.php` adds network-specific checks:

- the runtime is actually Multisite;
- the actor is a real super admin;
- the current blog has its own migrated WPNerve storage;
- WPNerve cannot deactivate or delete its own plugin through the privileged MCP
  surface, including a network-active installation.

The shell runner executes the Core-security baseline and `single-site.php` in a
**fresh WP-CLI process per Multisite URL**. This avoids masking per-blog
boot/schema behavior behind the plugin's request-local singleton.

## Safety

These probes write short-lived rows to WPNerve's idempotency, confirmation,
rate-limit and OAuth tables and remove their own rows afterwards. Run them on a
disposable or staging site.

The runner refuses WordPress environments whose `wp_get_environment_type()` is
`production` unless you explicitly set:

```bash
WP_NERVE_ALLOW_PRODUCTION_RUNTIME_TEST=1
```

That override should not be needed for normal G6 evidence.

## Prerequisites

- a real WordPress installation on a patched Core release (6.9.5+ or 7.0.2+ for
  the currently supported branches);
- PHP 8.1 or newer;
- MySQL/MariaDB used by that WordPress installation;
- WP-CLI available as `wp`;
- this WPNerve branch mounted/installed and active in the site.

No GitHub Actions runner is required.

## Single-site run

```bash
WP_PATH=/absolute/path/to/wordpress \
  bash scripts/test-real-wordpress.sh single
```

Expected final markers include:

```text
WPNERVE_PLATFORM_SECURITY_BASELINE_OK
WPNERVE_REAL_WORDPRESS_SINGLE_SITE_OK
WPNERVE_REAL_WORDPRESS_RUNTIME_OK
```

## Multisite run

```bash
WP_PATH=/absolute/path/to/multisite \
  bash scripts/test-real-wordpress.sh multisite
```

The runner tests up to the first ten site URLs in separate WP-CLI processes and
then executes the network-specific gate.

Expected markers include:

```text
WPNERVE_PLATFORM_SECURITY_BASELINE_OK
WPNERVE_REAL_WORDPRESS_SINGLE_SITE_OK
WPNERVE_REAL_WORDPRESS_MULTISITE_OK
WPNERVE_REAL_WORDPRESS_RUNTIME_OK
```

## Required G6 evidence matrix

Before G6 can be marked complete, record successful runs for at least:

| WordPress | PHP | Mode |
|---|---:|---|
| 6.9.5+ (6.9 line) | 8.1 | Single site |
| 6.9.5+ (6.9 line) | 8.3 | Single site |
| 7.0.2+ / current supported 7.x | 8.3 | Single site |
| 7.0.2+ / current supported 7.x | 8.5 | Single site |
| 7.0.2+ / current supported 7.x | 8.3+ | Multisite |

Do not mark a matrix cell green merely because a unit test or runtime double
passes. Keep the exact WordPress/PHP versions and command output with the release
evidence.
