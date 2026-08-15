# Security policy

## Supported versions

WPNerve is currently pre-release software. Only the latest tagged version receives
security fixes.

## Reporting a vulnerability

Do not open a public issue. Use GitHub's private security advisory flow for this
repository and include:

- Affected version and WordPress/PHP environment.
- Reproduction steps or proof of concept.
- Security impact and required user capability.
- Any proposed mitigation.

Please avoid accessing data that is not yours and do not run destructive tests on
production sites.

## Security defaults

- Authenticated access only.
- HTTPS required in production.
- Minimum transport capability: `edit_posts`.
- Read-only diagnostic tool only in the first alpha.
- Destructive and privileged risk classes denied by the policy engine.
- Audit records exclude credentials and tool arguments.

