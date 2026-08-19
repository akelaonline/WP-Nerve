# WPNerve beta-readiness plan

This document is the executable release contract for the first public beta.
Feature work is frozen until the security gates below are complete.

## Objective

Ship a self-hosted WordPress MCP server that is safe for real sites, predictable
under retries, interoperable with supported MCP clients, and honest about its
administrative surface. The beta is not defined by tool count. It is defined by
verified controls around every exposed operation.

## Current baseline

- 53 implemented abilities across content, taxonomy, media, comments, menus,
  widgets, users, plugins, options, and system diagnostics.
- MCP 2026-07-28 with bounded compatibility for 2025-11-25 and 2025-06-18.
- Application Password and OAuth 2.1-style public-client authentication.
- Central policy engine with read, write, destructive, and privileged classes.
- Destructive and privileged classes disabled by default.
- Persistent mutation idempotency, out-of-band high-risk confirmation,
  independent MCP/OAuth rate-limit boundaries, privileged-surface guards, and
  hardened OAuth lifecycle controls are implemented.
- Automatic hosted GitHub Actions triggers are intentionally paused; workflows
  remain available for explicit manual dispatch. Runtime evidence must therefore
  be gathered without silently treating an absent CI run as a passing check.

The code is alpha quality. It is not approved for production until every P0 gate
in this document passes.

### Implemented controls awaiting full gate evidence

- **G1 implementation:** alpha.5 added persistent, atomic, credential-bound
  idempotency with collision, replay and fail-closed unit coverage.
- **G2 implementation:** alpha.7 added an out-of-band WordPress admin decision,
  expiring single-operation tokens, canonical argument binding and tamper,
  expiry, replay, cross-user and cross-credential unit coverage.
- **G3 implementation:** alpha.8 added independent fixed-window budgets for MCP,
  OAuth authorization, token exchange and dynamic registration. Alpha.10 adds a
  separate revocation budget. Network subjects are hashed, arbitrary forwarding
  headers are ignored, database failure fails closed, and deterministic/
  exhaustion/proxy-spoof boundary tests are included.
- **G4 implementation:** alpha.9 added conservative option allowlists, empty-by-
  default transient disclosure, debug-log redaction, administrator/self-user
  restrictions, execution-time plugin/user capability checks, protected plugin
  rules, and checksummed non-replacing plugin archive installs.
- **G5 implementation:** alpha.10 adds the advertised token-revocation route,
  exact HTTPS/loopback redirect policy, strict PKCE S256 and state validation,
  single-use authorization codes, refresh-token rotation/replay protection,
  separate access/refresh lifetimes, bounded expired-token cleanup, bounded
  dynamic-client capacity, fail-closed persistence, and no-store/no-cache/
  nosniff response handling. The OAuth schema migration is versioned so existing
  alpha installs do not keep the older token layout silently.

These controls are code-complete at the unit/adversarial level, but G1–G5 still
require real WordPress database, filesystem, Multisite, reverse-proxy/browser and
MCP wire evidence under G6–G8 before WPNerve can make a production-readiness
claim.

## Release gates

| Gate | Deliverable | Required evidence | Exit criterion |
|---|---|---|---|
| G0 Contract integrity | README, catalog, threat model, changelog and version agree | Automated or locally reproducible documentation/version checks | No stale or contradictory security claims |
| G1 Idempotency | Persistent per-user/authoritative-credential/tool idempotency store | Unit, integration and replay tests | A retry cannot duplicate a mutation |
| G2 Destructive confirmation | Short-lived, single-use confirmation bound to actor, tool and canonical arguments | Tamper, expiry, reuse and cross-user tests | No destructive or privileged mutation executes without a valid confirmation |
| G3 Rate limiting | Separate budgets for MCP and OAuth registration, authorization, token and revocation routes | Proxy/IP tests and deterministic clock tests | Limits fail closed without trusting arbitrary forwarding headers |
| G4 Privileged hardening | Per-object authorization and safe allow/deny lists for users, plugins, options and logs | Adversarial tests for privilege escalation and secret disclosure | No path to administrator creation, protected option access or unsafe plugin replacement outside explicit policy |
| G5 OAuth hardening | Complete OAuth threat review, token lifecycle cleanup, revocation and client limits | End-to-end PKCE, rotation, replay, redirect, revocation and CSRF tests | OAuth routes meet the documented profile and do not expand access beyond MCP |
| G6 Runtime compatibility | WordPress and PHP matrix plus Multisite behavior | Real WordPress integration tests | Supported matrix passes without runtime doubles masking core behavior |
| G7 MCP interoperability | Contract tests against strict clients and schema validation | Recorded client matrix and wire fixtures | Discovery and calls pass for every supported protocol era |
| G8 Abuse resistance | JSON-RPC/schema fuzzing, request-size tests, archive abuse and retention | Reproducible fuzz corpus and retention tests | No crash, secret leak or unbounded persistence in the accepted corpus |
| G9 Independent review | Security review by a reviewer who did not implement the controls | Findings register with dispositions | No open critical/high finding |
| G10 Release engineering | Reproducible ZIP, checksums, upgrade/uninstall tests and release notes | Reproducible artifact evidence | Beta artifact is reproducible and upgrade-safe |

## Implementation order

### Phase 1 — Contract and observability

1. Synchronize README, ability catalog, threat model and changelog.
2. Add reproducible checks for ability count, version strings and forbidden stale claims.
3. Define stable security error codes and audit outcomes.
4. Add an explicit production-readiness warning to the admin screen.

**Exit:** G0 passes and every later gate has an owner, test location and evidence
path.

### Phase 2 — Mutation safety

1. Add an idempotency repository with TTL and atomic claim/complete semantics.
2. Canonicalize tool arguments before hashing.
3. Require idempotency keys for write, destructive and privileged operations.
4. Add a preview/confirmation service.
5. Bind confirmation tokens to authenticated user, client, ability, argument
   digest, expiry and one-time use.

**Exit:** G1 and G2 pass for every mutating ability.

### Phase 3 — Boundary protection

1. Rate-limit MCP and OAuth endpoints independently.
2. Define trusted-proxy behavior; ignore untrusted forwarded headers.
3. Cap OAuth dynamic registrations and clean expired codes/tokens.
4. Add payload, upload, pagination and response-size budgets.

Alpha.10 completes the code-level OAuth lifecycle and revocation portion of this
phase. Real deployment evidence remains part of G6–G8.

**Exit:** G3 and G5 pass with real boundary evidence.

### Phase 4 — Privileged surface review

Review users, plugins, options and system abilities individually. Each ability
must have an abuse-case table, object-level authorization, secret redaction,
recovery behavior and a fail-closed default. Remove or redesign any operation
that cannot meet those requirements.

Alpha.9 provides the code-level surface guards and adversarial unit coverage.
Real WordPress/Multisite execution and malformed-archive/filesystem abuse remain
part of G6/G8 evidence.

**Exit:** G4 passes. No privileged ability is enabled merely because its broad
risk class is enabled.

### Phase 5 — Real runtime and protocol proof

1. Test supported WordPress/PHP combinations in real WordPress.
2. Add Multisite single-site and network-admin cases.
3. Run MCP wire-contract fixtures for all supported protocol eras.
4. Run OAuth through a real browser/strict public client against a real database.
5. Add end-to-end clients, malformed-archive cases and fuzzing.
6. Add retention/cleanup evidence for audit, idempotency and OAuth client/token data.

**Exit:** G6, G7 and G8 pass.

### Phase 6 — Beta release

1. Independent security review.
2. Resolve all critical/high findings.
3. Produce reproducible artifacts and checksums.
4. Validate clean install, upgrade and uninstall.
5. Publish beta documentation with known limitations.

**Exit:** G9 and G10 pass.

## Scope after beta

The following are separate modules, not beta blockers:

- Gutenberg/FSE templates, template parts, patterns and global styles.
- Custom fields and ACF.
- WooCommerce.
- SEO integrations.
- Backups and managed updates.
- Public third-party ability SDK.

Themes, arbitrary filesystem access, wp-config editing, SQL, PHP, WP-CLI and
shell execution remain outside the core product unless a later threat model
proves a narrowly scoped design.

## Definition of done for every pull request

- One concern per PR and domain-aligned source/test folders.
- New behavior has unit and integration coverage.
- Security-sensitive behavior includes negative and abuse cases.
- Schemas validate serialized JSON, not only PHP arrays.
- Documentation changes in the same PR as behavior.
- PHPStan level 8, PHPCS, PHPUnit and the supported runtime matrix pass before a
  beta claim; while hosted Actions are paused, absence of a CI result is never
  treated as evidence.
- No production claim is made from runtime doubles alone.
- Recovery, audit, idempotency and confirmation impact are explicitly stated.
