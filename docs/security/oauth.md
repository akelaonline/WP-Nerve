# WPNerve OAuth security profile

WPNerve implements a deliberately narrow OAuth 2.1-style public-client profile for MCP clients that cannot send WordPress Application Passwords.

## Supported profile

- Authorization code grant.
- PKCE with `S256` only.
- Public clients (`token_endpoint_auth_method = none`).
- Exact pre-registered redirect URIs.
- Refresh-token rotation.
- Token revocation.
- Hashed token and authorization-code storage.

Dynamic registration accepts at most five unique redirect URIs per client. Remote redirects must use HTTPS. Plain HTTP is accepted only for loopback IP hosts (`127.0.0.1` and `::1`) for local/native clients. URI fragments, userinfo and embedded credentials are rejected.

## Authorization requests

Authorization requires:

- a registered `client_id`;
- an exact registered `redirect_uri`;
- `response_type=code`;
- a non-empty bounded `state` value;
- a 43-character base64url SHA-256 PKCE challenge;
- `code_challenge_method=S256`.

Anonymous users are sent through WordPress login with the authorization parameters preserved. Consent POSTs use a WordPress nonce. Authorization codes are short lived, stored only as SHA-256 hashes and are single use.

## Token lifecycle

Access tokens and refresh tokens have separate lifetimes. Defaults are:

- authorization code: 5 minutes;
- access token: 1 hour;
- refresh token: 30 days.

The bounds can be tuned with:

- `wp_nerve_oauth_authorization_code_ttl`;
- `wp_nerve_oauth_access_token_ttl`;
- `wp_nerve_oauth_refresh_token_ttl`.

Refresh tokens rotate on use. Replaying a consumed refresh token fails. Token persistence failures fail closed rather than issuing credentials that cannot be tracked.

Expired token rows are removed in bounded batches at OAuth boundaries. The cleanup path also fails closed if storage is unavailable.

## Revocation

`POST /wp-json/wp-nerve/v1/oauth/revoke` revokes access or refresh tokens for the supplied registered public client. The response deliberately does not disclose whether the token existed. A client cannot use the endpoint to delete another registered client's token.

Revocation has its own request budget independent from authorization, token exchange and dynamic registration.

## Dynamic registration limits

Dynamic client registration is rate limited and also has a bounded total client capacity. The default is 100 clients and can be tuned with `wp_nerve_oauth_client_limit` within the allowed range.

Only the WPNerve public-client profile is accepted. Unsupported confidential-client authentication methods, response types or grant combinations are rejected.

## Response handling

OAuth responses and redirects apply:

- `Cache-Control: no-store`;
- `Pragma: no-cache`;
- `X-Content-Type-Options: nosniff`.

Rate-limited responses include retry metadata where applicable.

## Remaining beta evidence

The alpha implementation is not a production-readiness claim. Before beta, G5 still requires real-browser and strict-client end-to-end evidence, redirect/proxy interoperability, Multisite behavior, token lifecycle failure tests against a real WordPress database, and independent review. OAuth abuse cases also feed into G8 fuzzing and retention work.
