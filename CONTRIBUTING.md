# Contributing to WPNerve

WPNerve accepts focused bug fixes, security hardening, compatibility improvements and carefully scoped abilities.

## Before opening a pull request

- Keep changes inside the reviewed WPNerve surface; do not add arbitrary SQL, PHP, shell, WP-CLI or generic filesystem execution.
- Every new ability must define input/output schemas, WordPress capability requirements, a WPNerve risk class and appropriate mutation/idempotency behavior.
- Privileged or destructive operations must preserve the confirmation model.
- Add or update tests for behavior changes.
- Keep credentials and tool arguments out of normal audit persistence.
- Update user-facing documentation when behavior or setup changes.

## Security issues

Do not open public issues for vulnerabilities. Follow [SECURITY.md](SECURITY.md).

## Development

The repository includes unit, fuzz, real-WordPress, Multisite, wire-contract, abuse-resistance and release-engineering harnesses. Hosted GitHub Actions are not required to develop or test WPNerve; the workflows in the repository are manual-dispatch only.

## Style

WPNerve targets WordPress 6.9+ and PHP 8.1+. Prefer small, auditable changes and WordPress-native APIs over custom infrastructure.
