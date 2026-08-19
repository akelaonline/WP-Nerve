# Security policy

## Supported versions

WPNerve is pre-release software. Only the latest tagged pre-release receives
security fixes. The project is not approved for production use until the P0 gates
in `docs/roadmap/beta-readiness.md` pass.

## Reporting a vulnerability

Do not open a public issue for an undisclosed vulnerability. Use GitHub's private
security advisory flow for this repository and include:

- affected WPNerve commit/version and WordPress/PHP environment;
- reproduction steps or proof of concept;
- security impact and required WordPress capability/preconditions;
- whether Application Password, OAuth, browser, MCP, Multisite, database or
  filesystem behavior is involved;
- any proposed mitigation.

Please avoid accessing data that is not yours and do not run destructive tests on
production sites.

## Security defaults

- Authenticated MCP access only.
- HTTPS required in production.
- Minimum transport capability defaults to `edit_posts` and can only be narrowed
  or deliberately changed by the site owner.
- The v1 surface contains 53 reviewed WPNerve abilities; arbitrary third-party
  abilities are not exposed automatically.
- Read and recoverable-write abilities are selectively available according to
  per-ability policy and WordPress capabilities.
- Destructive and privileged risk classes are denied by default and high-risk
  operations require a short-lived, operation-bound WordPress-admin confirmation.
- Every mutation requires persistent, credential-bound idempotency.
- MCP and OAuth boundaries use independent fail-closed rate-limit budgets.
- Privileged user/plugin/option/transient/log surfaces have additional object and
  input guards beyond broad WordPress capabilities.
- OAuth public clients use authorization code + PKCE S256, exact redirect URIs,
  single-use authorization codes, refresh-token rotation and revocation.
- Audit records exclude credentials and tool arguments by design.
- Plugin archives are checksummed, size-bounded and preflighted before extraction;
  unsafe paths, symlinks, collisions and existing plugin roots are rejected.

## Independent review

The beta release gate requires an independent reviewer who did not implement the
reviewed controls. The review scope and finding format are defined in
`docs/security/independent-review.md`; dispositions are recorded in
`docs/security/findings-register.md`.

No Critical or High finding may remain open for the beta.
