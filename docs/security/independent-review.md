# G9 — Independent security review package

This document is the handoff contract for WPNerve's independent security review.
G9 is not passed by writing this package. It passes only when a reviewer who did
not implement the reviewed controls records findings and no critical/high finding
remains open.

## Review target

Review the exact commit under assessment and record its SHA before starting.
The intended baseline includes the alpha.10 OAuth hardening plus the G8 archive,
retention and MCP abuse-resistance work.

The reviewer should treat WPNerve as a privileged WordPress control plane. A
successful exploit may modify content, users, plugins or configuration within the
authority of the authenticated WordPress account, so the review must evaluate
both transport boundaries and WordPress object-level authorization.

## Security invariants to challenge

The review should try to break these invariants rather than merely confirm the
happy path:

1. Authentication/client metadata never grants authority by itself.
2. Discovery cannot reveal a tool that execution would deny to the same actor.
3. Every mutation is authorized again immediately before execution.
4. A retry cannot duplicate a completed mutation.
5. Destructive/privileged operations require a valid, actor/tool/input-bound,
   single-use confirmation after the relevant risk class/ability has been enabled.
6. Cross-user, cross-credential and cross-client confirmation/idempotency reuse
   fails closed.
7. Rate-limit persistence failure does not disable rate limiting.
8. Arbitrary forwarding headers cannot choose the anonymous rate-limit subject.
9. Protected option/transient/user/plugin boundaries cannot be bypassed by broad
   risk-class enablement or extension filters.
10. WPNerve cannot disable or delete itself through the MCP surface.
11. OAuth authorization codes and rotated refresh tokens are single-use.
12. OAuth persistence failure cannot mint credentials whose lifecycle is not
    tracked.
13. Secrets and tool arguments do not enter normal audit records or error bodies.
14. Malformed MCP/JSON-RPC/header/archive input fails closed without unbounded
    resource consumption.
15. Plugin archive extraction cannot overwrite an installed plugin root, escape
    the plugin directory, or persist symlink/traversal artifacts.
16. Cleanup/retention never recycles an unresolved in-progress mutation into a
    retryable operation.
17. Runtime-double success is never accepted as production compatibility evidence.

## Mandatory review surfaces

### Authentication and transport

- Application Password authentication identity and revocation behavior.
- OAuth authorization, PKCE S256, exact redirect matching, consent, token exchange,
  refresh rotation, revocation and client registration.
- HTTPS/Origin policy, browser behavior, cache headers, request size and HTTP
  method handling.
- Reverse-proxy assumptions, `REMOTE_ADDR`, spoofed Forwarded/X-Forwarded-For.
- MCP 2026-07-28 mirrored-header/body binding and bounded legacy compatibility.

### Mutation controls

- Canonical JSON hashing and ambiguous representations.
- Idempotency atomicity, collision handling, crash/interruption semantics and TTL.
- Confirmation challenge issuance, approval/deny race conditions, expiry,
  actor/credential/tool/arguments/idempotency binding and single-use consumption.
- Ordering between confirmation, idempotency, policy and native `WP_Ability`.

### Privileged WordPress surface

- User creation/update/delete, especially administrator and self-user protections.
- Options/transients allowlists, serialization/depth/size bounds and secret-like
  key detection.
- Debug-log redaction and path disclosure.
- Plugin activate/deactivate/upload/delete, network-active/self-protection and
  archive overwrite/traversal/symlink/collision behavior.
- Multisite capability and per-blog storage boundaries.

### Persistence and privacy

- Audit metadata injection and secret leakage.
- Bounded retention queries and cleanup failure behavior.
- OAuth client/token growth and stale-client lifecycle gap.
- Uninstall behavior and opt-in destructive cleanup.

## Adversarial scenarios expected

At minimum, attempt:

- two concurrent requests using the same idempotency key;
- same key with different canonical arguments;
- approval token copied to another user, credential, OAuth client or tool;
- approval after expiry and approval replay;
- database failure during claim/complete/confirmation/token rotation;
- OAuth redirect URI normalization tricks, encoded host/userinfo/fragment variants;
- refresh-token replay after a successful rotation;
- malicious Origin and spoofed forwarding headers;
- explicit `params: null`, scalar/deep metadata, malformed encoded `Mcp-Name`;
- plugin ZIP traversal using `/`, `\\`, drive paths, case collisions and symlinks;
- misleading ZIP filename whose internal root matches an installed plugin;
- forced extraction failure after partial filesystem writes;
- Multisite network-active plugin lifecycle and cross-blog table assumptions;
- secret-like option/transient/log payloads intended to evade redaction.

## Evidence to inspect

The reviewer should read and challenge, not assume correct:

- `docs/threat-model.md`
- `docs/architecture.md`
- `docs/security/oauth.md`
- `docs/security/idempotency.md`
- `docs/security/confirmations.md`
- `docs/security/abuse-resistance.md`
- `docs/roadmap/beta-readiness.md`
- real WordPress gates under `tests/real-wordpress/`
- real MCP wire fixtures and deterministic fuzz corpus
- all source under `src/Security/`, `src/OAuth/`, `src/Transport/`, `src/Protocol/`,
  `src/Policy/` and privileged ability implementations.

## Finding format

Each finding must include:

- stable ID (`G9-001`, `G9-002`, ...);
- severity: Critical / High / Medium / Low / Informational;
- affected commit SHA and files/functions;
- attack preconditions and required WordPress capability;
- reproducible steps or proof of concept;
- impact and security invariant violated;
- recommended remediation;
- disposition: Open / Fixed / Accepted / False positive;
- fix commit and retest result when applicable.

Use `docs/security/findings-register.md` as the canonical register.

## Exit criteria

G9 passes only when:

- the reviewer is independent from the implementation under review;
- the exact reviewed commit is recorded;
- every finding has a disposition;
- every Critical and High finding is fixed and independently retested, or the
  affected beta scope is removed;
- Medium/Low accepted risks are documented in beta known limitations;
- the final reviewer statement explicitly says whether the reviewed commit meets
  the G9 exit criterion.

A self-review, automated scanner report or absent finding is not sufficient on
its own to mark G9 passed.
