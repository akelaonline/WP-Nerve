# WPNerve privileged surfaces

Privileged and destructive abilities are not enabled merely because a WordPress
user has a broad administrator capability. WPNerve layers several independent
controls around users, plugins, options and system diagnostics.

## Common security boundary

A privileged operation must pass all applicable layers:

1. WordPress authentication.
2. WPNerve per-ability enablement.
3. The relevant WPNerve risk-class opt-in.
4. WordPress capability checks during discovery and again at execution.
5. Persistent mutation idempotency.
6. Short-lived, operation-bound WordPress admin confirmation for privileged or
   destructive calls.
7. The input- and object-specific restrictions documented below.

Enabling a risk class is therefore never equivalent to granting unrestricted
administrator access.

## WordPress options

WPNerve does not expose arbitrary option keys. Reads and writes use conservative
allowlists intended for ordinary presentation/content settings such as site
name, description, date/time formatting, pagination and media dimensions.

The following classes remain protected even if an extension filter attempts to
add them to an allowlist:

- site/home URLs and administrator email state;
- registration/default-role settings;
- plugin activation/uninstall state;
- cron, rewrite and role data;
- WPNerve configuration and schema options;
- transient and site-transient storage;
- keys whose names look like passwords, tokens, secrets, API/private keys,
  credentials or license keys.

Extensions may tune the safe key sets with `wp_nerve_allowed_option_keys` and
`wp_nerve_writable_option_keys`. They may add further permanent protections with
`wp_nerve_protected_option_keys`.

Returned or proposed values must also be safely representable: objects and
resources are rejected, strings and collections are bounded, and excessive
nesting is refused.

## Transients

Transient disclosure has an empty default allowlist. A site must opt in an exact
key through `wp_nerve_allowed_transient_keys`. Credential-like transient names
remain blocked even if included by the filter. This is intentional because
transients frequently contain session material, third-party tokens and cached
private API responses.

## Debug log

`debug-log` reads at most 64 KiB from `wp-content/debug.log`, returns a relative
path rather than the server filesystem path, and redacts common forms of:

- Authorization Bearer/Basic credentials;
- passwords and secrets;
- API keys and access/refresh tokens;
- client secrets and generic credential assignments;
- URL userinfo such as `https://user:password@example.test/`.

Redaction is defense in depth, not a guarantee that arbitrary application text
can never contain sensitive information. The tool therefore remains privileged,
disabled by default and confirmation-gated.

## Users

WPNerve applies target-specific user checks immediately before mutation.

- Administrator creation, modification and deletion are disabled by default even
  for a caller with `promote_users`.
- A site that deliberately needs administrator-account management must also opt
  in through `wp_nerve_allow_administrator_user_management`.
- The authenticated agent user cannot change its own role, password or email and
  cannot delete itself.
- Password changes on existing users require the independent
  `wp_nerve_allow_user_password_updates` opt-in.
- Email changes on existing users require the independent
  `wp_nerve_allow_user_email_updates` opt-in.
- Role changes re-check `promote_users`; delete operations re-check the target
  `delete_user` capability.
- Reassignment to the user being deleted or to a missing user is rejected.

## Plugins

Plugin mutation callbacks re-check their WordPress capabilities at execution.
WPNerve itself is permanently protected from MCP deactivation/deletion, as are
network-active plugins. Sites can add further protected plugin files with
`wp_nerve_protected_plugins`.

Base64 plugin installation requires:

- a simple `.zip` filename with no path components;
- a bounded decoded archive size;
- a ZIP signature;
- an exact lowercase SHA-256 checksum supplied by the caller;
- no already-installed plugin whose directory matches the archive filename slug.

The install result returns a logical WordPress destination instead of the server
filesystem path.

This alpha deliberately does not claim that the archive path is fully hardened
against every malformed ZIP or WordPress upgrader edge case. Archive fuzzing,
path-traversal fixtures and real-filesystem behavior belong to G8 before beta.

## Remaining beta evidence

The alpha.9 implementation provides the code-level G4 controls and adversarial
unit coverage. The gate still requires real WordPress and Multisite execution,
filesystem/archive abuse testing, and independent review before WPNerve is
considered production-ready.
