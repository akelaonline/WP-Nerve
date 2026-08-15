# ADR 0001: Use the native WordPress Abilities API

- Status: Accepted
- Date: 2026-08-15

## Context

WordPress 6.9 introduced the Abilities API as the native typed registry for
discoverable and executable functionality. Maintaining a parallel registry or a
bundled polyfill would duplicate validation, permissions, and lifecycle behavior.

## Decision

WPNerve requires WordPress 6.9+ and registers every operation through
`wp_register_ability()`. The MCP layer adapts abilities; it does not own their
business logic.

## Consequences

- The plugin has a clear minimum WordPress version.
- Input, output, and permission validation use WordPress core.
- Other transports can reuse the same abilities.
- Supporting older WordPress releases would require a separate compatibility
  product and is intentionally out of scope.

