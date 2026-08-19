# WPNerve rate limiting

WPNerve applies independent fixed-window request budgets at its public MCP and
OAuth boundaries. The purpose is abuse resistance, not user analytics.

## Default budgets

| Boundary | Bucket | Default budget |
|---|---|---:|
| MCP endpoint | `mcp` | 120 requests / 60 seconds |
| OAuth authorization | `oauth_authorize` | 60 / 60 seconds |
| OAuth token exchange | `oauth_token` | 30 / 60 seconds |
| OAuth dynamic registration | `oauth_register` | 10 / 3600 seconds |

Budgets can be adjusted with `wp_nerve_rate_limit_budget`. Invalid values fall
back to the secure defaults. Limits are constrained to 1–10,000 requests and
windows to 1–86,400 seconds.

## Network subject and proxies

The anonymous rate-limit subject is the transport peer exposed to PHP as
`REMOTE_ADDR`. WPNerve deliberately ignores `Forwarded`, `X-Forwarded-For` and
similar client-supplied headers. Trusting those headers inside the plugin would
let a caller rotate a spoofed address and bypass the budget.

A deployment behind a trusted reverse proxy must normalize the client address at
the web-server, ingress or PHP layer so `REMOTE_ADDR` has the intended meaning.
If it is left as the proxy address, clients behind that proxy intentionally share
a common budget rather than gaining an unsafe bypass.

## Storage and privacy

The `wp_nerve_rate_limits` table stores:

- a short bucket name;
- SHA-256 hashes of the bucket and network subject;
- the fixed-window start;
- the request count; and
- an expiry timestamp.

The raw network address is never persisted. Expired rows are removed in bounded
batches during normal rate-limit consumption. Explicit uninstall cleanup removes
the table only when the site owner has enabled WPNerve data deletion.

## Atomicity and failure behavior

Consumption uses a unique `(bucket_hash, subject_hash, window_start)` database
key and an atomic `INSERT ... ON DUPLICATE KEY UPDATE` that increments only while
the window remains below its limit. Concurrent requests therefore cannot each
observe spare capacity and independently oversubscribe it.

The boundary is fail closed. If the database cannot clean, consume or read the
budget record, WPNerve refuses the request instead of treating the limiter as
optional:

- MCP returns a WordPress REST error with HTTP 503.
- OAuth returns `temporarily_unavailable` with HTTP 503.

An exhausted MCP budget returns HTTP 429. Exhausted OAuth budgets return HTTP 429
with `Retry-After`, `X-RateLimit-Limit`, `X-RateLimit-Remaining`, and
`X-RateLimit-Reset` headers.

## Evidence

The unit and boundary suite covers deterministic window rollover, independent
endpoint budgets, exhaustion, database unavailability, hashed network subjects,
forwarding-header spoof resistance, MCP pre-auth rejection and OAuth response
metadata. Real reverse-proxy and WordPress runtime evidence remains part of the
runtime/protocol validation gates before beta.
