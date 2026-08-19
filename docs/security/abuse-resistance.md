# Abuse resistance and persistence retention

WPNerve treats malformed protocol input, privileged plugin archives, and
unbounded security persistence as hostile surfaces. This document records the
code-level G8 controls. It is not a claim that the G8 release gate is complete:
real filesystem/upgrader execution, runtime fuzzing and long-running retention
evidence still have to be recorded before beta.

## Plugin archive preflight

`wp-nerve/upload-plugin` remains destructive, disabled by default, capability-
gated, idempotent, and confirmation-gated by the existing WPNerve policy stack.
Before WordPress extracts any uploaded ZIP, WPNerve now performs a separate
fail-closed archive preflight.

### Package transport

- Decoded plugin packages are capped at **50 MiB**.
- The caller must provide the exact lowercase SHA-256 digest.
- The decoded package is staged in a private system temporary file instead of
  WordPress' public uploads directory.
- The temporary package is deleted after inspection/extraction whether the
  operation succeeds or fails.
- Secure archive inspection requires PHP's `ZipArchive`; the privileged upload
  fails closed when that inspection surface is unavailable.

### Archive structure limits

The preflight rejects an archive when any of the following is true:

- the ZIP fails structural consistency checks;
- it contains zero entries or more than **5,000** entries;
- an entry name exceeds **1,024 bytes**;
- total reported uncompressed content exceeds **200 MiB**;
- an entry contains NUL/control characters;
- a path is absolute, drive-qualified, contains a colon, or traverses via `..`;
- a path contains empty, `.` or `..` segments after normalization;
- two entries collide case-insensitively;
- an entry is a symbolic link;
- an archive top-level root matches an installed plugin root, even when the
  uploaded ZIP filename is deliberately misleading;
- an archive top-level target already exists in the plugin filesystem.

This internal-root check closes an important overwrite bypass in the earlier
alpha implementation: comparing only `filename.zip` with installed plugin slugs
was insufficient because a differently named archive could still contain
`existing-plugin/...` paths.

### Extraction failure

WordPress' filesystem transport must initialize before extraction. If
`unzip_file()` returns an error after creating archive entries, WPNerve performs
a best-effort cleanup limited to the preflighted entries/directories from that
specific package. This rollback is not yet accepted as production evidence until
it has been exercised against the supported filesystem transports and failure
modes in a real WordPress environment.

## Deterministic MCP abuse corpus

`tests/fuzz/request-validator.json` is a reproducible corpus for malformed
JSON-RPC/MCP envelopes and mirrored HTTP headers. The corresponding unit test
asserts stable, fail-closed error codes for cases including:

- missing/wrong JSON-RPC version;
- missing/empty method;
- invalid request IDs;
- scalar params;
- missing modern request metadata;
- protocol-version/header mismatch;
- unsupported protocol versions;
- missing/invalid client capabilities;
- mirrored method mismatch;
- missing/mismatched tool names;
- malformed encoded `Mcp-Name` values.

The corpus also verifies the supported encoded-header path for a UTF-8 tool name
and rejects raw non-ASCII mirrored names. G7 separately covers request-size,
authentication, Origin and protocol-era behavior over real HTTP.

The deterministic corpus is only the seed set. G8 still requires mutation-based
or generated fuzzing against the HTTP parser, JSON-RPC dispatcher, schemas and
archive parser before the gate can be marked complete.

## Persistence retention

WPNerve reuses WordPress Core's daily `wp_scheduled_delete` maintenance action
rather than creating a second cron schedule. `RetentionManager` performs bounded
batches so cleanup cannot turn into an unbounded maintenance query.

Default cleanup policy:

| Store | Default policy | Batch behavior |
|---|---|---|
| Audit log | Delete rows older than 30 days | max 200/run/table by default |
| Completed idempotency | Delete only completed rows whose replay TTL expired | max 200/run/table by default |
| Confirmations | Keep expired challenges/decisions for 7 days after expiry, then delete | max 200/run/table by default |
| OAuth tokens/codes | Delete expired token rows | max 200/run/table by default |
| OAuth clients | Not auto-deleted | bounded at registration; explicit lifecycle remains a beta gap |

### Retention bounds

The cleanup batch is configurable but clamped to **1–1,000 rows per table per
run**. Audit retention is clamped to **1 day–365 days**. Post-expiry confirmation
retention is clamped to **1 hour–30 days**. Invalid filter values fall back to
safe defaults.

Completed idempotency outcomes retain their existing replay TTL. WPNerve does
**not** automatically recycle unresolved `in_progress` idempotency claims:
an interrupted mutation is intentionally treated as indeterminate rather than
made retryable by a cleanup job.

### Filters

- `wp_nerve_audit_retention_ttl`
- `wp_nerve_confirmation_retention_ttl`
- `wp_nerve_retention_cleanup_batch`
- existing `wp_nerve_idempotency_retention_ttl`

## Remaining G8 evidence

The following remain release-gate work, even with these controls implemented:

1. Execute malicious ZIP fixtures against real WordPress filesystem transports.
2. Force mid-extraction/upgrader failures and verify the rollback boundary.
3. Expand the archive corpus with malformed central directories, high-ratio
   compression, unusual permissions and platform-specific filenames.
4. Run generated/mutation fuzzing through the real HTTP MCP endpoint.
5. Exercise retention over large real tables and verify bounded query latency.
6. Define an explicit administrative lifecycle for stale OAuth dynamic clients.
7. Confirm that audit/idempotency/confirmation cleanup behaves correctly in
   Multisite for each blog prefix.

Until those items are recorded, G8 remains **in progress**, not passed.
