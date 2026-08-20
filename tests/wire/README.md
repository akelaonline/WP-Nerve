# G7/G8 — MCP wire and mutation evidence

This directory tests WPNerve through its **real HTTP endpoint**, not through
PHPUnit doubles or direct PHP calls.

The clients use only Python's standard library and send credentials only in the
HTTP `Authorization` header. They do not print or persist the Application
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

`mcp_mutation_fuzz.py` adds a deterministic real-HTTP G8 corpus. It sends 60
mutated JSON-RPC/MCP requests across malformed JSON, envelope types, request IDs,
methods, params, protocol metadata, client capabilities, mirrored headers,
encoded names, nested metadata and attacker-controlled authority-like fields.
The run fails on any 5xx response, reflected Application Password/Authorization
header, malformed JSON response, oversized response, or premature rate-limit
exhaustion.

The mutation corpus intentionally stays below the default 120 requests/minute
MCP budget. Run it in a fresh rate window after the normal wire contract so the
evidence does not depend on a rate-limit override.

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
# Wait for a fresh MCP rate-limit window before the mutation corpus.
python3 tests/wire/mcp_mutation_fuzz.py
```

Expected final markers:

```text
WPNERVE_MCP_WIRE_OK
WPNERVE_MCP_MUTATION_FUZZ_OK cases=60
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

## Evidence required before G7/G8 are complete

Record the exact client output plus server WordPress/PHP/WPNerve versions for:

| Protocol / corpus | Required wire path |
|---|---|
| `2026-07-28` | discover → tools/list → tools/call |
| `2025-11-25` | initialize → tools/list → tools/call |
| `2025-06-18` | initialize → tools/list → tools/call |
| mutation corpus | 60 deterministic real-HTTP cases with no crash/secret reflection |

Then repeat the modern flow with at least one strict external MCP client. The
stdlib clients are deterministic protocol fixtures; they are not a substitute
for real-client interoperability evidence.

No automatic GitHub Actions trigger is required or added by these gates.
