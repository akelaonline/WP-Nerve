# WPNerve <version> — beta release notes

> Replace every `<placeholder>` before publication. Do not publish this template
> as evidence of a completed release gate.

## Artifact identity

- **Version:** `<version>`
- **Git tag:** `<tag>`
- **Commit:** `<full SHA>`
- **ZIP:** `wp-nerve-<version>.zip`
- **SHA-256:** `<sha256>`

## Tested runtime matrix

| WordPress | PHP | Mode | Result |
|---|---:|---|---|
| `<version>` | `<version>` | Single site | `<pass/fail>` |
| `<version>` | `<version>` | Multisite | `<pass/fail>` |

## MCP interoperability

| Protocol era | Client | Result |
|---|---|---|
| 2026-07-28 | `<strict client>` | `<pass/fail>` |
| 2025-11-25 | `<client>` | `<pass/fail>` |
| 2025-06-18 | `<client>` | `<pass/fail>` |

## Authentication tested

- [ ] WordPress Application Password over HTTPS
- [ ] OAuth authorization code + PKCE S256
- [ ] OAuth refresh rotation/replay rejection
- [ ] OAuth revocation
- [ ] Browser consent/Origin behavior

## Security summary

Describe the controls actually present in this candidate, including:

- persistent credential-bound mutation idempotency;
- out-of-band, actor/tool/input-bound high-risk confirmation;
- independent fail-closed MCP/OAuth rate limits;
- privileged user/plugin/option/transient/log guards;
- OAuth code/token lifecycle hardening and revocation;
- plugin ZIP checksum/preflight/path/symlink/collision protections;
- bounded audit/idempotency/confirmation/OAuth retention;
- deterministic MCP malformed-input corpus and real runtime gates.

## Independent security review

- **Reviewed commit:** `<SHA>`
- **Reviewer:** `<reviewer>`
- **Critical open:** `0`
- **High open:** `0`
- **Accepted Medium/Low findings:** `<IDs or none>`

List accepted residual risks below and keep them synchronized with
`docs/security/findings-register.md`.

## Upgrade evidence

- [ ] Clean install → candidate
- [ ] 0.1.0-alpha.9 → candidate
- [ ] 0.1.0-alpha.10 → candidate
- [ ] Retained-data uninstall → candidate reinstall
- [ ] Explicit destructive uninstall

## Known limitations

Record only limitations that are true for the published candidate. Include any
accepted G9 Medium/Low risks that affect operators.

Core product intentionally does not expose arbitrary SQL, PHP, shell, WP-CLI,
`wp-config.php` editing, arbitrary filesystem editing, theme installation/editing,
or every third-party WordPress ability automatically.

## Upgrade and uninstall behavior

Describe any schema migration from the previous release. State that WPNerve data
is preserved on normal uninstall by default and is removed only when the operator
explicitly enables the destructive uninstall-data option before uninstalling.

## Security reporting

Vulnerabilities should be reported through the private process documented in
`SECURITY.md`, not through a public issue before disclosure is coordinated.
