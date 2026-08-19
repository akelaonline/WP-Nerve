# Abuse resistance and persistence retention

WPNerve treats malformed protocol input, privileged plugin archives, and
unbounded security persistence as hostile surfaces. This document records the
implemented G8 controls and the reproducible runtime evidence harnesses. It is
not a production-readiness claim: the real-runtime evidence still has to be
executed and retained against the supported matrix before G8 can pass.

## Plugin archive preflight

`wp-nerve/upload-plugin` remains destructive, disabled by default, capability-
gated, idempotent, and confirmation-gated by the existing WPNerve policy stack.
Before WordPress extracts any uploaded ZIP, WPNerve performs a separate
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
- a Unix entry is a device, socket, FIFO, or another unsupported special file;
- the package contains more than one top-level plugin root;
- the package contains no PHP plugin file;
- an archive top-level root matches an installed plugin root, even when the
  uploaded ZIP filename is deliberately misleading;
- an archive top-level target already exists in the plugin filesystem.

A single-file plugin ZIP remains valid, as does one directory root containing the
plugin. Unicode filenames inside that root are accepted when the normalized path
is otherwise safe.

The internal-root check closes an overwrite bypass in the earlier alpha
implementation: comparing only `filename.zip` with installed plugin slugs was
insufficient because a differently named archive could still contain
`existing-plugin/...` paths. Requiring exactly one root also prevents one
approved upload from installing several unrelated top-level packages.

### Extraction failure

WordPress' filesystem transport must initialize before extraction. If
`unzip_file()` returns an error after creating archive entries, WPNerve performs
a best-effort cleanup limited to the preflighted entries/directories from that
specific package. This rollback remains an evidence item until it has been
forced through real filesystem/upgrader failure modes.

## MCP abuse corpus and real-HTTP mutation sweep

`tests/fuzz/request-validator.json` is a reproducible corpus for malformed
JSON-RPC/MCP envelopes and mirrored HTTP headers. Unit coverage asserts stable,
fail-closed behavior for missing/wrong JSON-RPC versions, invalid IDs, scalar or
explicit-null params, malformed modern metadata, protocol/header mismatches,
unsupported protocol versions, invalid capability shapes, method/name
mismatches and malformed encoded `Mcp-Name` values.

The explicit `params: null` corpus case exposed a validator gap in the prior
implementation: `isset()` treated an explicit JSON null like an absent member.
The validator now uses presence-aware validation and rejects null/scalar params
with `-32602`.

`MutationFuzzTest` adds a deterministic in-process mutation sweep. G8 also ships
`tests/wire/mcp_mutation_fuzz.py`, which sends **60 deterministic mutations
through the real WordPress HTTP/REST/JSON stack**. The wire corpus covers
malformed JSON, envelope types, IDs, methods, params, protocol metadata,
capability shapes, mirrored headers, encoded names, deep metadata and
attacker-controlled authority-like fields. It fails on any 5xx response, secret
reflection, malformed JSON response, unbounded response, or unexpected
rate-limit exhaustion.

The real-HTTP mutation run intentionally stays below the default MCP request
budget and must be executed in a fresh rate window. The normal G7 wire contract
continues to cover authentication, Origin, protocol-era and request-size
boundaries.

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
| OAuth clients | Automatic deletion disabled by default | optional age policy, only with no unexpired token/code rows |

The cleanup batch is configurable but clamped to **1–1,000 rows per table per
run**. Audit retention is clamped to **1 day–365 days**. Post-expiry confirmation
retention is clamped to **1 hour–30 days**. Optional OAuth dynamic-client
retention is disabled at `0`; when enabled, it is clamped to **1 day–1 year** and
only stale clients with no unexpired OAuth token/code rows are eligible. This
avoids silently breaking legitimate long-lived public-client registrations while
providing an explicit lifecycle for sites that require automatic pruning.

Completed idempotency outcomes retain their existing replay TTL. WPNerve does
**not** automatically recycle unresolved `in_progress` idempotency claims: an
interrupted mutation remains indeterminate rather than becoming retryable merely
because a cleanup job ran.

### Retention filters

- `wp_nerve_audit_retention_ttl`
- `wp_nerve_confirmation_retention_ttl`
- `wp_nerve_oauth_client_retention_ttl`
- `wp_nerve_retention_cleanup_batch`
- existing `wp_nerve_idempotency_retention_ttl`

## Real-WordPress G8 evidence harnesses

The G8 runners refuse `production` by default and refuse known-vulnerable
WordPress 6.9.0–6.9.4 / 7.0.0–7.0.1 baselines.

### Archive and persistence boundary

`tests/real-wordpress/abuse-resistance.php` exercises the real PHP Zip extension,
filesystem and WordPress database for valid packages, traversal, case
collisions, installed-root overwrite protection, symlink rejection and bounded
retention while preserving unresolved idempotency claims.

`tests/real-wordpress/retention-scale.php` seeds more rows than two cleanup
batches across audit, idempotency, confirmations, OAuth tokens and OAuth clients.
It proves each cleanup remains capped at 200 rows per store/run, remains bounded
on the second run, and preserves unresolved claims. The default scale is 600 rows
per store and can be raised to 5,000 on a disposable environment.

Run both with:

```bash
WP_PATH=/absolute/path/to/wordpress \
  bash scripts/test-abuse-resistance.sh all
```

Expected markers include:

```text
WPNERVE_REAL_WORDPRESS_G8_OK
WPNERVE_RETENTION_SCALE_OK
WPNERVE_REAL_WORDPRESS_G8_RUNTIME_OK mode=all
```

### Multisite prefix isolation

`tests/real-wordpress/multisite-retention.php` requires at least two network
sites. It seeds identical classes of old WPNerve-owned rows into two different
blog prefixes, runs retention in only the current site, and proves the comparison
site's rows remain untouched. The normal Multisite runtime runner migrates each
tested site in a fresh WP-CLI process before executing the isolation gate.

```bash
WP_PATH=/absolute/path/to/multisite \
  bash scripts/test-real-wordpress.sh multisite
```

Expected markers include:

```text
WPNERVE_REAL_WORDPRESS_MULTISITE_OK
WPNERVE_MULTISITE_RETENTION_OK
WPNERVE_REAL_WORDPRESS_RUNTIME_OK
```

### Real HTTP mutation corpus

With the same dedicated staging credential used for G7:

```bash
python3 tests/wire/mcp_contract.py
# wait for a fresh MCP rate-limit window
python3 tests/wire/mcp_mutation_fuzz.py
```

Expected marker:

```text
WPNERVE_MCP_MUTATION_FUZZ_OK cases=60
```

## Remaining G8 evidence

Code/harness coverage is now present for the original protocol mutation,
retention-scale, stale OAuth-client lifecycle, and Multisite-prefix gaps. The
remaining release-gate work is deliberately narrower:

1. **Execute and retain** the committed G8 runtime, retention-scale, Multisite and
   real-HTTP mutation outputs against the supported WordPress/PHP matrix.
2. Force real mid-extraction/upgrader failures and verify the rollback boundary
   on supported filesystem transports.
3. Add/execute malformed-central-directory and extreme-compression ZIP fixtures
   against the real archive parser. Unusual Unix file types, Unicode filenames,
   multi-root packages and path-platform edge cases already have deterministic
   coverage.
4. Record bounded cleanup latency on the actual release environments rather than
   inferring performance from unit/runtime doubles.

Until that evidence is recorded, G8 remains **implemented but not passed**.
