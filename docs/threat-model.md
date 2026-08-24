# WPNerve threat model

## Status

This document describes the alpha.10 baseline plus the implemented G8
abuse-resistance, retention, real-HTTP mutation and Multisite-isolation controls.
WPNerve is not approved for production until the P0 gates in
[the beta-readiness plan](roadmap/beta-readiness.md) pass.

## Assets

- WordPress content, media, users, configuration, plugins and credentials.
- The authority represented by the authenticated WordPress user.
- OAuth clients, authorization codes, access tokens and refresh tokens.
- MCP tool definitions and schemas consumed by an agent.
- Audit integrity and operator visibility.
- Plugin archives and files accepted by privileged abilities.

## Trust boundaries

1. MCP or OAuth client to the public WordPress HTTP endpoint.
2. Web server and WordPress authentication to WPNerve.
3. WPNerve authentication to the policy engine.
4. Policy engine to native WordPress abilities.
5. Ability callbacks to the WordPress database and filesystem.
6. Admin browser to WPNerve settings and OAuth consent.

Client identity metadata is self-reported and is never an authorization signal.
The authenticated WordPress user, enabled ability policy, risk class, and
per-object capability checks are the authorization authority.

For anonymous boundary protection, WPNerve derives its rate-limit subject only
from the transport peer exposed by PHP as `REMOTE_ADDR`. Arbitrary client-supplied
`Forwarded` and `X-Forwarded-For` headers are not trusted. Reverse proxies must
normalize the client address before the request reaches WordPress if per-client
budgets are required.

## Current administrative surface

The alpha includes content, taxonomy, media, comments, menus, widgets, users,
plugins, options and system-diagnostic abilities. User, plugin, option and
system operations are part of the current attack surface even though they are
disabled by default. Alpha.9 added object/input-specific protections on top of
the existing ability, risk, capability, idempotency and confirmation layers.
Alpha.10 hardens the OAuth lifecycle, redirect policy, registration limits,
rotation/replay behavior, revocation and storage-failure handling.

G8 adds private plugin-package staging, structural ZIP preflight, single-root
package rules, archive-root overwrite protection, special-file rejection,
malformed-request mutation coverage, a real-HTTP mutation corpus, bounded
retention cleanup, optional stale OAuth-client pruning, retention-scale evidence
and Multisite prefix-isolation evidence harnesses.

Arbitrary SQL, PHP, shell and WP-CLI execution are not implemented. Theme
management, arbitrary filesystem editing and wp-config editing are outside the
core product scope.

## Threats and controls

| Threat | Current control | Beta gap |
|---|---|---|
| Stolen Application Password | Dedicated revocable credential; HTTPS required | Client guidance and revocation E2E |
| Stolen OAuth token | Hashed token store, short-lived access token, separately bounded refresh lifetime, refresh rotation and revocation | Real-client/browser lifecycle and intermediary evidence |
| OAuth code or refresh replay | Authorization codes are single-use; refresh tokens rotate and consumed values cannot be reused | Real database concurrency/crash-path evidence |
| OAuth redirect or consent abuse | Exact redirect URI, HTTPS remote callbacks, loopback-only HTTP, PKCE S256, bounded non-empty state and consent nonce | Browser/proxy interoperability and independent review |
| OAuth registration flood | Independent registration rate limit, bounded total client capacity, max five redirects/client, and optional bounded stale-client pruning that never removes a client with unexpired token/code rows | Real deployment/load evidence |
| Privilege escalation | Policy/risk gates, confirmation, execution-time WordPress capability checks and alpha.9 object/input guards | Real WordPress/Multisite adversarial matrix and independent review |
| Excessive agent authority | Least-privilege discovery; destructive and privileged classes off by default; per-ability opt-in | Validate all supported deployment/user-role combinations |
| Duplicate mutation after retry | Persistent, credential-bound idempotency with atomic claim and replay | Real WordPress database and crash-path evidence |
| Accidental destructive action | Risk class off by default plus bound, expiring, single-operation admin confirmation | Real admin/browser and MCP wire E2E evidence |
| Endpoint abuse | Independent fail-closed MCP/OAuth authorization/token/revocation/registration budgets with hashed transport-peer subjects; untrusted forwarding headers ignored | Real reverse-proxy matrix and broader response/payload evidence |
| Header/body request smuggling | Mirrored MCP headers checked against JSON body; deterministic malformed-input corpus; in-process mutation sweep; 60-case real-HTTP mutation fixture | Execute and retain real proxy/HTTP evidence |
| DNS rebinding from browser clients | Same-origin validation when Origin is present | Real proxy/browser tests |
| SSRF through uploads or URLs | Current upload paths avoid arbitrary remote fetching; OAuth redirects are registration-bound | Verify every media path and browser redirect behavior |
| Malicious plugin archive | Capability/risk/confirmation gates; 50 MiB package limit; SHA-256; private staging; `ZipArchive` consistency preflight; traversal/absolute/drive/control-path rejection; case-collision, symlink and Unix-special-file rejection; exactly one top-level root; at least one PHP plugin file; installed/existing-root protection; 200 MiB hard expansion ceiling; best-effort rollback | Execute real filesystem/upgrader failure matrix and retained archive-edge evidence |
| Protected option disclosure or mutation | Conservative allowlists plus permanent security/credential/WPNerve/transient protections; unsafe structures refused | Real plugin option corpus and serialized-value abuse tests |
| User privilege escalation | Target capability checks; admin management separate opt-in; sensitive self-change/self-delete blocked; password/email separate opt-ins | Cross-user and Multisite/network-admin runtime tests |
| Debug log secret disclosure | Privileged/disabled by default; 64 KiB bound; relative path; common credential redaction | Real log corpus, unusual secret formats and operator-warning review |
| Transient secret disclosure | Empty default allowlist, exact per-key opt-in and credential-like key blocking | Real plugin transient corpus and serialized-value tests |
| Agent disables its own control layer | WPNerve plugin is permanently protected from MCP deactivation/deletion; network-active plugins protected | Execute real plugin lifecycle and Multisite runtime tests |
| Secret leakage in audit | Tool arguments and authorization data are not persisted; audit retention is bounded by default | Execute metadata-injection, scale and Multisite cleanup evidence |
| Tool-name confusion | Deterministic one-to-one mapping and WPNerve namespace; encoded-name corpus plus real-HTTP mutation coverage | Execute retained wire evidence |
| Untrusted third-party ability | Only WPNerve namespace exposed | Reviewed opt-in SDK remains post-beta |
| Cache disclosure | Authenticated and OAuth responses/redirects use no-store/no-cache; OAuth adds nosniff | End-to-end intermediary tests |
| Database growth | Daily bounded audit/completed-idempotency/expired-confirmation/OAuth-token cleanup; optional bounded stale-client pruning; unresolved in-progress idempotency claims are never recycled; scale and Multisite-isolation harnesses committed | Execute and retain large-table/Multisite latency evidence |

## P0 requirements before beta

- Execute real-runtime idempotency claim/complete/replay evidence against the
  supported WordPress/PHP matrix.
- Execute real-runtime expiring confirmation evidence bound to actor,
  authoritative credential, ability, canonical arguments and idempotency key.
- Execute reverse-proxy evidence for the independent MCP/OAuth rate limits and
  their trusted-peer behavior.
- Execute WordPress and Multisite validation of privileged-surface guards,
  including user-role boundaries, protected options/transients, log redaction and
  plugin lifecycle behavior.
- Execute malformed archive, path-traversal, overwrite, symlink, special-file,
  expansion and rollback evidence against supported WordPress filesystem
  transports. The deterministic and real-runtime harnesses are committed.
- Execute real-browser/strict-client OAuth PKCE, redirect, consent, rotation,
  replay, revocation, expiry and database-failure evidence.
- Execute retention-scale and Multisite prefix-isolation harnesses for audit,
  idempotency, confirmation, OAuth token and optional stale-client cleanup.
- Execute contract tests against supported MCP clients and protocol eras.
- Execute and retain the real-HTTP mutation corpus with no crash, secret
  reflection or unbounded response.
- Complete an independent security review with no unresolved critical/high
  findings.

## Explicitly outside the core product

- Editing wp-config.php.
- Arbitrary file editing.
- Arbitrary SQL, PHP, WP-CLI or shell execution.
- Automatic exposure of abilities registered by other plugins.
- Theme editing or installation until a separate threat model exists.
- Remote plugin/theme installation from arbitrary URLs.
- Unrestricted option reads or writes.

## Security invariants

1. Discovery never exposes an ability that execution would deny.
2. Authentication metadata supplied by a client never grants authority.
3. Every mutation is authorized again immediately before execution.
4. Retries cannot duplicate a completed mutation.
5. Destructive and privileged operations require explicit, narrow authorization.
6. Confirmation tokens cannot authorize a second logical operation, be
   transferred, outlive their expiry or be applied to changed input.
7. Rate-limit storage failure never turns rate limiting into an optional control.
8. Arbitrary forwarding headers never select the rate-limit subject.
9. Protected option/transient and administrator-account boundaries cannot be
   bypassed merely by enabling a broad WPNerve risk class.
10. WPNerve cannot deactivate or delete its own MCP control plugin.
11. OAuth authorization codes and rotated refresh tokens cannot authorize a
   second exchange after successful consumption.
12. OAuth persistence failure cannot produce credentials whose lifecycle WPNerve
   cannot track.
13. Secrets never enter audit records or normal error messages.
14. Unsupported or unknown risk states fail closed.
15. Runtime-double success is not accepted as proof of WordPress compatibility.
16. A plugin archive cannot escape the plugin directory, overwrite an existing
   plugin root, install multiple top-level packages, or persist a symbolic-link,
   special-file or traversal path through accepted input.
17. Retention cleanup cannot make an unresolved `in_progress` mutation silently
   retryable.
18. Retention cleanup for one Multisite blog prefix cannot delete another site's
   WPNerve-owned rows.
19. Automatic OAuth-client pruning is disabled by default and, when explicitly
   enabled, cannot delete a client with unexpired token/code rows.
