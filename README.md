# WPNerve

**The native MCP gateway for WordPress.**

[![WordPress](https://img.shields.io/badge/WordPress-6.9%2B-21759b)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-8.1%2B-777bb3)](https://www.php.net/)
[![Version](https://img.shields.io/badge/version-0.1.0--alpha.14-4f46e5)](CHANGELOG.md)
[![License](https://img.shields.io/badge/license-GPL--2.0-orange)](LICENSE)

WPNerve is a self-hosted WordPress plugin that exposes a curated set of native **WordPress Abilities** as **Model Context Protocol (MCP)** tools. It runs inside the WordPress installation: no relay, no SaaS control plane, no Firebase, and no external credential store.

> **Current status:** operational alpha. The v1 catalog contains exactly **53 reviewed abilities**. Real staging has passed live registry/discovery, MCP discovery and tools/list, content create/update, destructive confirmation, trash/restore, and unauthenticated endpoint rejection. Independent security review and the full cross-version/Multisite matrix remain release gates before a production-ready claim.

## Why WPNerve

- **Native WordPress 6.9+ Abilities API** — no parallel action registry.
- **53 reviewed abilities** with live runtime catalog diagnostics.
- **Self-hosted MCP endpoint** under `/wp-json/wp-nerve/v1/mcp`.
- **Application Password + constrained OAuth** authentication over HTTPS.
- **Modern + legacy MCP compatibility**: `2026-07-28`, `2025-11-25`, `2025-06-18`.
- **Least privilege** — discovery and execution respect WordPress capabilities.
- **Risk classes** — Read, Write, Privileged, Destructive.
- **Persistent idempotency** for mutations.
- **Out-of-band WordPress approval** for privileged and destructive operations.
- **Independent rate limits** for MCP and OAuth boundaries.
- **Privacy-preserving audit** that excludes credentials and tool arguments.
- **Hardened plugin archive handling** with traversal, collision, symlink, special-file and expansion checks.
- **No arbitrary SQL, PHP, shell, WP-CLI or `wp-config.php` access.**

## Professional WordPress admin

Alpha.14 introduces a complete product-grade admin shell:

- **WPNerve → Dashboard** — connection status, credentials, confirmations, risk classes and client setup.
- **WPNerve → Diagnostics** — live 53/53 registry and policy checks plus operational MCP smoke.
- **WPNerve → HTTP Smoke** — authenticated public HTTPS MCP validation with a temporary credential that is revoked automatically.
- **WPNerve → Documentation** — in-product operator guide, security model, risk classes and client setup.

The interface follows the same restrained Akela product language used across the WordPress portfolio: clear hierarchy, compact cards, operational status at a glance, and native WordPress behavior underneath.

## Quick start

1. Install and activate the plugin on WordPress 6.9+ with PHP 8.1+.
2. Open **WPNerve → Dashboard**.
3. Choose the WordPress user the agent should act as.
4. Generate a dedicated WPNerve Application Password.
5. Copy the generated MCP configuration into your client.
6. Keep **Read** and **Write** enabled for normal operation. Enable **Privileged** or **Destructive** only when required.
7. Open **WPNerve → Diagnostics** and confirm the live registry/policy state.

### Endpoint

```text
https://example.com/wp-json/wp-nerve/v1/mcp
```

### Generic MCP client configuration

```json
{
  "mcpServers": {
    "wp-nerve": {
      "type": "http",
      "url": "https://example.com/wp-json/wp-nerve/v1/mcp",
      "headers": {
        "Authorization": "Basic BASE64_USERNAME_COLON_APPLICATION_PASSWORD"
      }
    }
  }
}
```

## Security model

WPNerve adds multiple gates around native WordPress capabilities:

1. **Transport authentication** — WordPress Application Password or OAuth bearer token.
2. **WordPress capability checks** — both transport and individual abilities.
3. **WPNerve discovery policy** — ability + risk-class exposure.
4. **Idempotency** — persistent mutation replay/conflict protection.
5. **High-risk confirmation** — privileged/destructive calls require a short-lived WordPress-admin approval bound to user, credential, tool, arguments and idempotency key.
6. **Boundary rate limiting** — fail-closed request budgets.
7. **Audit** — protocol/tool/outcome metadata without normal persistence of credentials or arguments.

### Risk classes

| Class | Purpose | Default |
| --- | --- | --- |
| Read | Safe information retrieval | Enabled |
| Write | Recoverable mutations | Enabled |
| Privileged | Users, plugins, protected options and administration | Disabled |
| Destructive | Publish, trash, restore and other high-impact operations | Disabled |

Privileged and Destructive calls still require one-time approval even after the class is enabled.

## Operational diagnostics

WPNerve does not rely on README estimates to determine what is available. The Diagnostics screen reads WordPress' **live Abilities registry** and compares it with the code contract.

Validated on real staging with alpha.12+:

- `53 / 53` abilities registered.
- `53 / 53` discoverable in full-surface staging mode.
- REST MCP route and database schema checks pass.
- `server/discover` passes.
- `tools/list` returns 53 tools.
- `site-status` and opt-in `list-plugins` pass.
- `create-draft` and `update-content` pass.
- destructive confirmation issuance + administrator approval pass.
- `trash-content` + `restore-content` pass.
- unauthenticated public endpoint request returns `401`.

## Product scope

WPNerve deliberately does **not** expose “100% of WordPress” by opening unrestricted execution surfaces. The current v1 surface is 53 reviewed, schema-defined abilities covering the WordPress operations an agent needs while keeping dangerous general-purpose primitives outside the product.

New abilities must be:

- schema-defined;
- permission-aware;
- assigned a risk class;
- compatible with idempotency/confirmation rules where required;
- auditable;
- tested against WordPress runtime behavior.

## Requirements

- WordPress **6.9+**
- PHP **8.1+**
- HTTPS for production transport
- MySQL/MariaDB through WordPress' normal database layer
- `ZipArchive` for hardened plugin ZIP inspection/upload operations

## Current release candidate

- Release notes: [`docs/releases/0.1.0-alpha.14.md`](docs/releases/0.1.0-alpha.14.md)
- SHA-256: [`docs/releases/0.1.0-alpha.14.sha256`](docs/releases/0.1.0-alpha.14.sha256)
- Package: `wp-nerve-0.1.0-alpha.14.zip`

## Documentation

- [`SECURITY.md`](SECURITY.md) — security policy and architecture notes
- [`docs/security/`](docs/security/) — threat model, OAuth and privileged-surface documentation
- [`docs/roadmap/beta-readiness.md`](docs/roadmap/beta-readiness.md) — evidence gates and beta-readiness roadmap
- **WPNerve → Documentation** — operator guide installed with the plugin

## Development and testing

The repository contains unit, fuzz, real-WordPress, Multisite, wire-contract, abuse-resistance and release-engineering harnesses. GitHub Actions workflows are present only for explicit manual dispatch; development does not depend on hosted Actions.

Local release engineering can build and verify a deterministic plugin ZIP with SHA-256 evidence using the scripts in `scripts/`.

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## Author

**Alejandro Daniel José · Akela**  
[MKT Marketing Digital](https://mktmarketingdigital.com)

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
