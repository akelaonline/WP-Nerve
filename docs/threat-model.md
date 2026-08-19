# WPNerve threat model

## Status

This document describes the alpha.8 attack surface. WPNerve is not approved for
production until the P0 gates in [the beta-readiness plan](roadmap/beta-readiness.md)
pass.

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
disabled by default. Enabling a broad risk class is not proof that each ability
is safe for production.

Arbitrary SQL, PHP, shell and WP-CLI execution are not implemented. Theme
management, arbitrary filesystem editing and wp-config editing are outside the
core product scope.

## Threats and controls

| Threat | Current control | Beta gap |
|---|---|---|
| Stolen Application Password | Dedicated revocable credential; HTTPS required | Client guidance and revocation E2E |
| Stolen OAuth token | Hashed token store, short-lived access token, refresh rotation | Cleanup, replay and lifecycle review |
| OAuth redirect or consent abuse | Registered redirect URI, PKCE S256, state and consent nonce | Full OAuth threat review and client quotas |
| Privilege escalation | Policy gate plus WordPress capability callbacks | Per-object adversarial review for every privileged ability |
| Excessive agent authority | Least-privilege discovery; destructive and privileged classes off by default | Per-ability grants instead of class-only opt-in |
| Duplicate mutation after retry | Persistent, credential-bound idempotency with atomic claim and replay | Real WordPress database and crash-path evidence |
| Accidental destructive action | Risk class off by default plus bound, expiring, single-operation admin confirmation | Real admin/browser and MCP wire E2E evidence |
| Endpoint abuse or registration flood | Independent fail-closed MCP/OAuth budgets with hashed transport-peer subjects; untrusted forwarding headers ignored | Real reverse-proxy matrix, OAuth lifecycle quotas and broader response/payload budgets |
| Header/body request smuggling | Mirrored MCP headers checked against JSON body | Fuzzing and proxy matrix |
| DNS rebinding from browser clients | Same-origin validation when Origin is present | Real proxy/browser tests |
| SSRF through uploads or URLs | Current upload paths are intended to avoid unrestricted fetching | Verify every archive/media path and redirect behavior |
| Malicious plugin archive | Capability and opt-in gates | Archive validation, path traversal, size, overwrite and rollback tests |
| Protected option disclosure or mutation | Privileged class and option-specific logic | Formal denylist/allowlist and serialized secret tests |
| User privilege escalation | WordPress capabilities and role checks | Cross-user, self-escalation and multisite tests |
| Debug log secret disclosure | Privileged and disabled by default | Redaction, bounded reads and explicit operator warning |
| Secret leakage in audit | Tool arguments and authorization data are not persisted | Retention, export, deletion and metadata injection tests |
| Tool-name confusion | Deterministic one-to-one mapping and WPNerve namespace | Encoded-name fuzzing |
| Untrusted third-party ability | Only WPNerve namespace exposed | Reviewed opt-in SDK remains post-beta |
| Cache disclosure | Authenticated and OAuth token responses use no-store headers | End-to-end intermediary tests |
| Database growth | Bounded rate-limit cleanup; no sufficient lifecycle policy yet for idempotency, OAuth and audit storage | Retention and cleanup jobs with failure tests |

## P0 requirements before beta

- Real-runtime evidence for persistent idempotency claim/complete and replay behavior.
- Real-runtime evidence for expiring confirmation tokens bound to actor,
  authoritative credential, ability, canonical arguments and idempotency key.
- Real reverse-proxy and WordPress evidence for the implemented independent MCP,
  OAuth registration, authorization and token rate limits and their trusted-peer
  behavior.
- Per-ability review of users, plugins, options and system diagnostics.
- OAuth lifecycle cleanup, registration quotas and complete negative E2E tests.
- Audit retention and privacy controls.
- Multisite authorization and storage behavior.
- Contract tests against supported MCP clients and protocol eras.
- Fuzzing of JSON-RPC envelopes, headers, schemas, archives and encoded names.
- Independent security review with no unresolved critical or high findings.

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
9. Secrets never enter audit records or normal error messages.
10. Unsupported or unknown risk states fail closed.
11. Runtime-double success is not accepted as proof of WordPress compatibility.
