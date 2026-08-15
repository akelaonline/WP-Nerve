# Contributing to WPNerve

WPNerve is security-sensitive infrastructure. Open an issue before adding a new
ability or protocol feature.

## Local checks

```bash
composer install
composer check
```

Every ability contribution must include:

- A narrow purpose and deterministic name.
- JSON Schema input and output contracts.
- General and object-level capability checks.
- Risk classification and semantic annotations.
- Unit and integration tests.
- Recovery behavior for mutations.
- Documentation updates in `docs/abilities-v1.md`.

Never log credentials, authorization headers, secrets, or raw tool arguments.

