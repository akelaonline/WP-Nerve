# G7 — MCP wire-contract evidence

This directory tests WPNerve through its **real HTTP endpoint**, not through
PHPUnit doubles or direct PHP calls.

The client uses only Python's standard library and sends credentials only in the
HTTP `Authorization` header. It does not print or persist the Application
Password.

## Covered contract

`mcp_contract.py` verifies:

- unauthenticated requests are rejected;
- a hostile browser `Origin` is rejected;
- modern MCP `2026-07-28` `server/discover` succeeds;
- modern `tools/list` exposes `wp_nerve_site_status`;
- modern `tools/call` executes `wp_nerve_site_status` over the wire;
- legacy `2025-11-25` initializes, lists tools and calls a tool;
- legacy `2025-06-18` initializes, lists tools and calls a tool;
- modern `Mcp-Method` mismatches are rejected;
- modern `Mcp-Name` mismatches are rejected;
- unsupported modern protocol versions are rejected;
- request bodies over 1 MiB are rejected;
- authenticated GET is rejected with HTTP 405 and advertises POST;
- MCP responses use private/no-store caching and `nosniff`.

The modern mirrored-header checks are intentionally strict: the MCP protocol
version and method in the request metadata/body must agree with the corresponding
HTTP headers, and named calls must agree with `Mcp-Name`.

## Prerequisites

- a real WordPress site with this WPNerve branch installed and active;
- HTTPS for normal testing;
- a dedicated WordPress user with at least the WPNerve transport capability
  (default: `edit_posts`);
- a WordPress Application Password for that user;
- Python 3.10+.

Use a disposable/staging site. Do not use a personal administrator credential.

## Run

```bash
export WP_NERVE_BASE_URL='https://example.test'
export WP_NERVE_USER='wpnerve-agent'
export WP_NERVE_APPLICATION_PASSWORD='xxxx xxxx xxxx xxxx xxxx xxxx'

python3 tests/wire/mcp_contract.py
```

Expected final marker:

```text
WPNERVE_MCP_WIRE_OK
```

For a disposable local HTTP environment only:

```bash
export WP_NERVE_ALLOW_HTTP=1
```

For a disposable HTTPS environment with a self-signed certificate only:

```bash
export WP_NERVE_INSECURE_TLS=1
```

Neither override should be used for production-readiness evidence.

## Evidence required before G7 is complete

Record the exact client output plus server WordPress/PHP/WPNerve versions for:

| Protocol | Required wire path |
|---|---|
| `2026-07-28` | discover → tools/list → tools/call |
| `2025-11-25` | initialize → tools/list → tools/call |
| `2025-06-18` | initialize → tools/list → tools/call |

Then repeat the modern flow with at least one strict external MCP client. The
stdlib client is the deterministic protocol fixture; it is not a substitute for
real-client interoperability evidence.

No automatic GitHub Actions trigger is required or added by this gate.
