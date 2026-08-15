# ADR 0002: Modern-first, dual-era MCP transport

- Status: Accepted
- Date: 2026-08-15

## Context

MCP `2026-07-28` is stateless and carries version and capabilities on each
request. Existing clients may still use initialization-based `2025-11-25` or
`2025-06-18` behavior.

## Decision

WPNerve implements the modern stateless protocol as the primary path and a bounded
compatibility path for the two earlier Streamable HTTP revisions. Legacy protocol
state is not allowed to leak into abilities or policy decisions.

## Consequences

- Modern clients receive strict mirrored-header validation and `server/discover`.
- Existing clients can initialize and use tools while they migrate.
- Protocol code has explicit era branches and contract tests.
- SSE subscriptions and server-initiated requests are not part of the initial
  compatibility surface.

