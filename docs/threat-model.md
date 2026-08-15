# WPNerve threat model

## Assets

- WordPress content, media, users, configuration, and credentials.
- The authority represented by the authenticated WordPress user.
- MCP tool definitions and schemas consumed by an agent.
- Audit integrity and operator visibility.

## Trust boundaries

1. MCP client to the public WordPress HTTP endpoint.
2. Web server and WordPress authentication to WPNerve.
3. WPNerve policy engine to native WordPress abilities.
4. Ability callbacks to the WordPress database and filesystem.

Client identity metadata is self-reported and is not used as an authorization
signal. The authenticated WordPress user and per-object capability checks are the
authorization authority.

## Primary threats and controls

| Threat | Initial control |
|---|---|
| Stolen credential | Dedicated revocable Application Password; HTTPS required |
| Privilege escalation | Transport capability plus ability and object-level checks |
| Header/body request smuggling | Required MCP headers validated against body fields |
| DNS rebinding from browser clients | Same-origin validation whenever `Origin` is present |
| Oversized request denial of service | Configurable 1 MiB request-body ceiling |
| Excessive agent authority | Dynamic least-privilege tool discovery |
| Destructive accidental action | Destructive and privileged policies deny by default |
| Secret leakage in logs | Arguments and authorization data are not persisted |
| Audit metadata abuse | Untrusted metadata is sanitized and length-bounded |
| Tool-name confusion | Deterministic one-to-one ability-to-tool mapping |
| Untrusted third-party ability | Only the WPNerve namespace is exposed initially |
| SSRF through URL tools | URL-based abilities are outside the first release |
| Duplicate mutations | Mutation abilities must implement idempotency before release |
| Cache disclosure | Authenticated responses use private, no-store HTTP headers |

## Explicitly excluded from v1

- Editing `wp-config.php` or arbitrary files.
- Installing plugins or themes from arbitrary URLs or base64 payloads.
- Unrestricted WordPress option writes.
- User creation, role escalation, or password changes.
- Arbitrary SQL, PHP, WP-CLI, or shell execution.
- Automatic exposure of abilities registered by other plugins.

## Required before beta

- Independent security review.
- Rate limiting with tests that cover proxy and IP behavior.
- Confirmation token design for destructive actions.
- Idempotency store and replay tests for mutations.
- Audit retention controls and multisite behavior.
- Contract tests against supported MCP clients.
- Fuzzing of JSON-RPC envelopes, headers, schemas, and encoded names.
