# WPNerve Security Policy

WPNerve is a self-hosted MCP gateway that intentionally sits on a privileged WordPress boundary. Security issues are treated as product defects, not documentation problems.

## Supported versions

WPNerve is currently pre-release software. Security fixes are provided for the latest published pre-release/candidate only. Older alpha builds should be upgraded before reporting behavior that may already have been corrected.

## Reporting a vulnerability

Please **do not open a public issue** for a suspected vulnerability. Use GitHub's private security-advisory flow for this repository and include, when possible:

- affected WPNerve version;
- WordPress and PHP versions;
- single-site or Multisite;
- authentication method used;
- required WordPress role/capability;
- reproduction steps or proof of concept;
- expected vs. observed behavior;
- impact and any proposed mitigation.

Do not access data you do not own and do not run destructive tests against production sites.

## Security architecture

WPNerve layers controls instead of relying on one permission check:

- authenticated MCP transport using WordPress Application Passwords or constrained OAuth;
- HTTPS enforcement in production;
- WordPress transport and object-level capability checks;
- a WPNerve discovery policy with explicit ability/risk-class exposure;
- persistent idempotency for mutations;
- short-lived, out-of-band WordPress-admin confirmation for privileged and destructive operations;
- independent fail-closed request budgets around MCP and OAuth boundaries;
- privacy-preserving audit records that exclude normal persistence of credentials and tool arguments;
- hardened plugin archive inspection before extraction;
- self-protection around WPNerve plugin administration;
- bounded retention for operational tables.

## Deliberately excluded surfaces

The core product does not expose arbitrary SQL, arbitrary PHP execution, shell access, unrestricted WP-CLI, direct `wp-config.php` editing, or generic filesystem access. Adding a new ability requires a schema, explicit capability model, risk classification, audit behavior and tests.

## Runtime security diagnostics

Administrators can use **WPNerve → Diagnostics** to compare the code catalog with WordPress' live Abilities registry and current discovery policy. **WPNerve → HTTP Smoke** exercises the public HTTPS MCP boundary using a temporary Application Password that is revoked after the test.

## Production guidance

- Keep WordPress, PHP and WPNerve patched.
- Use HTTPS only.
- Prefer a dedicated WordPress user with only the capabilities the agent needs.
- Leave Privileged and Destructive classes disabled unless required.
- Revoke unused Application Passwords and OAuth clients.
- Do not use staging/full-surface diagnostic mode on a production site.
- Review pending high-risk confirmations before approving them.
