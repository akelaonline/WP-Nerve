# WPNerve beta-readiness plan

This document is the executable release contract for the first public beta.
Feature work is frozen until the security and release gates below are complete.

## Objective

Ship a self-hosted WordPress MCP server that is safe for real sites, predictable
under retries, interoperable with supported MCP clients, and honest about its
administrative surface. The beta is not defined by tool count. It is defined by
verified controls around every exposed operation.

## Current baseline

- 53 implemented abilities across content, taxonomy, media, comments, menus,
  widgets, users, plugins, options, and system diagnostics.
- MCP `2026-07-28` with bounded compatibility for `2025-11-25` and
  `2025-06-18`.
- Application Password and OAuth 2.1-style public-client authentication.
- Central policy engine with read, write, destructive, and privileged classes.
- Destructive and privileged classes disabled by default.
- Persistent mutation idempotency and out-of-band high-risk confirmation.
- Independent MCP/OAuth rate limits with fail-closed storage behavior.
- Privileged user/plugin/option/log/transient hardening.
- Hardened OAuth code/token/refresh/revocation lifecycle.
- Fail-closed plugin ZIP preflight, private staging and bounded expansion.
- Bounded persistence retention plus optional stale OAuth-client pruning.
- Real WordPress, Multisite, HTTP wire, mutation, archive, retention and release
  evidence harnesses committed on the consolidated beta-candidate branch.
- Independent-review handoff and canonical findings register committed.
- Reproducible local release builder/checksum/manifest and lifecycle gate
  committed.

Automatic hosted GitHub Actions triggers remain intentionally paused. Absence of
a hosted CI result is never evidence of a passing gate.

The code remains alpha quality until every P0 exit criterion below has actual
recorded evidence. A committed harness is **implemented**, not **passed**.

## Gate status

| Gate | Implementation | Evidence required before PASS | Current state |
|---|---|---|---|
| G0 Contract integrity | Version/docs/catalog/release checks | Run release contract on final clean candidate; no stale claims | Implemented / pending execution |
| G1 Idempotency | Persistent credential-bound atomic claim/complete/replay | Real DB replay, conflict and crash-path evidence | Implemented / pending runtime evidence |
| G2 Confirmation | Short-lived actor/tool/arguments/key-bound approval | Real admin + MCP tamper/expiry/replay evidence | Implemented / pending runtime evidence |
| G3 Rate limiting | Independent MCP/OAuth budgets, hashed peer subject, fail closed | Real proxy/IP exhaustion evidence | Implemented / pending runtime evidence |
| G4 Privileged hardening | Object guards, protected options/users/plugins/logs | Real WordPress/Multisite adversarial matrix | Implemented / pending runtime evidence |
| G5 OAuth hardening | PKCE S256, state, exact redirects, rotation/replay, revocation, quotas | Real browser/public-client lifecycle and proxy evidence | Implemented / pending runtime evidence |
| G6 Runtime compatibility | Real WP/MySQL runner plus Multisite | Supported patched WordPress/PHP matrix output | Harness complete / not executed |
| G7 MCP interoperability | Real HTTP deterministic client for all supported eras | Recorded wire output + at least one strict external client | Harness complete / not executed |
| G8 Abuse resistance | ZIP preflight, mutation corpus, retention/scale/prefix-isolation harnesses | Recorded real archive/HTTP/DB/Multisite evidence; forced extraction-failure evidence | Harness substantially complete / not executed |
| G9 Independent review | Review contract + findings register | Independent reviewer assesses exact final commit; no open Critical/High | Package complete / reviewer pending |
| G10 Release engineering | Reproducible builder + install/upgrade/uninstall runner | Two clean builds match; lifecycle matrix passes | Harness complete / not executed |

## Implemented security controls

### G1 — Idempotency

Alpha.5 added persistent atomic idempotency for every mutation. Claims are scoped
to WordPress user, authoritative credential, tool and key, with canonical
argument hashing. Completed retries replay the stored result; collisions,
concurrency, ambiguous state and unavailable storage fail closed. Unresolved
`in_progress` claims are deliberately not recycled by retention.

### G2 — High-risk confirmation

Alpha.7 added five-minute out-of-band WordPress-admin approval for destructive
and privileged tools. Challenges are bound to authenticated user, authoritative
credential/session, tool, canonical arguments and idempotency key, then consumed
atomically on the first authorized retry.

### G3 — Boundary budgets

Alpha.8 added independent fixed-window budgets for MCP, OAuth authorization,
token exchange and registration; alpha.10 added revocation. Network subjects are
hashed and derived from the transport peer rather than arbitrary forwarded
headers. Rate-limit persistence failure fails closed.

### G4 — Privileged surfaces

Alpha.9 replaced arbitrary option access with conservative allowlists, made
transient disclosure empty-by-default, redacted/capped debug logs, protected
administrator and self-user operations, rechecked WordPress capabilities at
execution time, protected WPNerve/network-active plugins, and required
checksummed non-replacing plugin uploads.

The consolidated candidate further hardens plugin packages: private temp staging,
`ZipArchive` consistency checks, traversal/absolute/drive/control-path rejection,
case-collision rejection, symlink and Unix-special-file rejection, exactly one
top-level root, at least one PHP plugin file, installed/existing-root protection,
and a hard 200 MiB total uncompressed ceiling. The ceiling can only be reduced by
filter for deterministic evidence; it cannot be raised above the hard limit.

### G5 — OAuth

Alpha.10 adds the advertised revocation endpoint, exact HTTPS/loopback redirect
policy, strict PKCE S256 and state validation, single-use authorization codes,
refresh-token rotation/replay rejection, separate access/refresh lifetimes,
bounded expired-token cleanup, bounded dynamic-client capacity, fail-closed
persistence, and `no-store`/`no-cache`/`nosniff` response handling.

Dynamic OAuth-client cleanup is intentionally opt-in: when configured, stale
clients are removed in bounded batches only if they are older than the configured
retention window and have no unexpired token/code rows.

## Evidence phase

### G6 — Real WordPress/PHP/Multisite

Committed gates:

- `scripts/test-real-wordpress.sh single`
- `scripts/test-real-wordpress.sh multisite`
- `tests/real-wordpress/platform-security.php`
- `tests/real-wordpress/single-site.php`
- `tests/real-wordpress/multisite.php`
- `tests/real-wordpress/multisite-retention.php`

The Multisite path migrates/test-boots each selected blog prefix in a fresh
WP-CLI process before checking network behavior and cross-prefix retention
isolation.

### G7 — MCP wire proof

`tests/wire/mcp_contract.py` exercises the real HTTP endpoint with Application
Password authentication for modern MCP `2026-07-28` and both bounded legacy
protocol eras. It also covers unauthenticated access, hostile Origin, mirrored
header mismatches, unsupported protocol version, >1 MiB requests, wrong HTTP
method and cache headers.

A strict external MCP client is still required in addition to this deterministic
fixture.

### G8 — Abuse resistance

Committed evidence layers include:

- deterministic malformed request corpus;
- in-process mutation sweep;
- 60-case real-HTTP mutation corpus;
- real archive traversal/collision/symlink/root-overwrite tests;
- malformed-central-directory, package-shape, Unix-special-file, Unicode and
  reduced-ceiling expansion tests;
- rollback cleanup against WordPress' filesystem transport;
- retention scale with more rows than two cleanup batches;
- Multisite per-blog-prefix retention isolation.

The remaining code/evidence gap is deliberately narrow: force a real
mid-extraction/upgrader failure on each supported filesystem transport and retain
the output. All other G8 harnesses still need to be executed on the release
matrix before the gate can pass.

### G9 — Independent review

`docs/security/independent-review.md` and
`docs/security/findings-register.md` define the reviewer handoff, required attack
surfaces, severity model, dispositions and retest contract.

The implementation workflow cannot satisfy its own independent-review gate. The
reviewer must assess an exact candidate commit and every finding must be recorded.
No Critical or High finding may remain open at beta release.

### G10 — Release engineering

Committed release tooling:

- `scripts/check-release-contract.sh`
- `scripts/build-release.sh`
- `scripts/verify-release-archive.sh`
- `scripts/test-release-engineering.sh`
- `scripts/run-beta-gates.sh`

The builder creates the same commit twice and requires byte-identical ZIPs,
verifies package structure, then emits the release ZIP, `SHA256SUMS` and a
manifest containing the exact commit SHA. The lifecycle gate tests clean install,
optional previous-version upgrade, schema/ability registration, conservative
uninstall, reinstall and explicit destructive uninstall.

G10 also fixed a real uninstall defect: WordPress persists a true option as the
string `"1"`, while the old uninstall guard required strict boolean `true`. The
cleanup opt-in now accepts only explicit persisted truth representations and
remains fail-safe for absent/ambiguous values.

## One-command off-host runner

On a clean checkout with Composer dependencies installed:

```bash
bash scripts/run-beta-gates.sh quality
```

Runtime evidence uses **separate disposable sites** because the G10 lifecycle
ends by uninstalling WPNerve:

```bash
WP_PATH=/path/to/single-site \
WP_MULTISITE_PATH=/path/to/multisite \
WP_RELEASE_PATH=/path/to/fresh-release-site \
WP_NERVE_BASE_URL=https://staging.example \
WP_NERVE_USER=wpnerve-agent \
WP_NERVE_APPLICATION_PASSWORD='...' \
PREVIOUS_ZIP_ALPHA9=/path/to/alpha9.zip \
PREVIOUS_ZIP_ALPHA10=/path/to/alpha10.zip \
  bash scripts/run-beta-gates.sh all
```

Missing optional environments are reported as **PENDING**, never as PASS.
Credentials must be dedicated staging credentials and must not be committed.

## Beta exit sequence

1. Freeze the exact candidate commit.
2. Run local quality + release-contract + reproducible-build checks.
3. Execute G6 single-site and Multisite matrices on patched supported Core/PHP.
4. Execute G7 deterministic wire contract and one strict external MCP client.
5. Execute G8 archive, mutation, retention-scale, prefix-isolation and forced
   extraction-failure evidence.
6. Run G9 independent review on that exact candidate SHA and resolve/retest every
   Critical/High finding.
7. Build the reviewed commit independently twice and compare SHA-256.
8. Execute G10 clean/upgrade/uninstall matrix.
9. Publish beta notes with known limitations and exact artifact checksum.

## Scope after beta

Separate modules, not beta blockers:

- Gutenberg/FSE templates, template parts, patterns and global styles.
- Custom fields and ACF.
- WooCommerce.
- SEO integrations.
- Backups and managed updates.
- Public third-party ability SDK.

Themes, arbitrary filesystem access, `wp-config.php` editing, SQL, PHP, WP-CLI
and shell execution remain outside core unless a later threat model proves a
narrowly scoped design.

## Definition of done for every release change

- One concern per change and domain-aligned source/test folders.
- New behavior has deterministic coverage.
- Security-sensitive behavior includes negative/abuse cases.
- Schemas validate serialized JSON, not merely PHP arrays.
- Documentation changes with behavior.
- PHPStan level 8, PHPCS, PHPUnit and supported runtime evidence pass before a
  beta claim.
- Hosted Actions being paused cannot be interpreted as a passing CI result.
- No production claim is made from runtime doubles alone.
- Recovery, audit, idempotency, confirmation and retention impact are explicit.
