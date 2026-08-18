# High-risk operation confirmations

Every `destructive` or `privileged` MCP tool requires an explicit decision by a
WordPress administrator after the risk class has been enabled. The confirmation
gate sits before idempotent execution, so an unapproved request cannot create an
idempotency claim or invoke an ability.

## Two-step request contract

The first call sends the exact tool arguments and a client-generated
idempotency key, but no confirmation token:

```json
{
  "jsonrpc": "2.0",
  "id": 41,
  "method": "tools/call",
  "params": {
    "name": "wp_nerve_delete_user",
    "arguments": {"id": 7, "reassign": 2},
    "_meta": {
      "wp-nerve/idempotencyKey": "0198c8ea-8b21-7d84-a570-58db7df48b82"
    }
  }
}
```

WPNerve does not execute the tool. It returns an MCP tool error with a challenge
in result `_meta`:

```json
{
  "isError": true,
  "_meta": {
    "wp-nerve/confirmation": {
      "status": "pending",
      "displayCode": "ABCD-EF12",
      "expiresAt": "2026-08-18T12:05:00+00:00",
      "tool": "wp_nerve_delete_user",
      "risk": "destructive",
      "token": "wpc_EXAMPLE_BASE64URL_TOKEN"
    }
  }
}
```

An administrator opens **Tools → WPNerve**, matches the user, tool, risk and
display code, then approves or denies the challenge. Approval never executes an
operation from the browser; it only allows the bound MCP call to be retried.

The client then retries the exact call with the same arguments, idempotency key
and returned token:

```json
{
  "jsonrpc": "2.0",
  "id": 42,
  "method": "tools/call",
  "params": {
    "name": "wp_nerve_delete_user",
    "arguments": {"id": 7, "reassign": 2},
    "_meta": {
      "wp-nerve/idempotencyKey": "0198c8ea-8b21-7d84-a570-58db7df48b82",
      "wp-nerve/confirmationToken": "wpc_EXAMPLE_BASE64URL_TOKEN"
    }
  }
}
```

## Binding and lifetime

A challenge is bound to all of the following:

- authenticated WordPress user;
- authoritative Application Password, OAuth client or hashed WordPress session
  identity;
- MCP tool name and risk class;
- SHA-256 digest of canonical tool arguments;
- idempotency key; and
- cryptographically random confirmation token.

The default lifetime is five minutes. The
`wp_nerve_confirmation_ttl` filter may set a value from 60 through 900 seconds.
Approval and consumption both use conditional database updates. A pending,
denied, expired, mismatched or unavailable state fails closed.

The first authorized retry atomically marks the challenge consumed. The same
token may pass the gate again only for the identical logical operation and only
before the challenge expires; the idempotency guard then returns the stored
outcome instead of repeating the mutation. A later recovery attempt must request
a new challenge while keeping the original idempotency key.

## Storage and privacy

WPNerve stores hashes of the credential identity, token, idempotency key, tool
name and canonical arguments. It never stores the raw token, raw credential,
raw idempotency key or tool arguments in the confirmation table. The admin list
contains only the display code, WordPress user, tool, risk and timestamps.

Confirmation tokens and display codes are also excluded from the audit event;
the audit records only the stable error code and normal execution metadata.

## Stable error codes

| Code | Meaning |
|---|---|
| `wp_nerve_confirmation_required` | A new challenge was created; inspect response `_meta` |
| `wp_nerve_confirmation_pending` | The matching challenge has not been decided |
| `wp_nerve_confirmation_denied` | An administrator denied the challenge |
| `wp_nerve_confirmation_expired` | The challenge lifetime elapsed |
| `wp_nerve_confirmation_conflict` | Actor, credential, tool, arguments or key changed |
| `wp_nerve_confirmation_invalid` | The supplied token is malformed or unknown |
| `wp_nerve_confirmation_unavailable` | Persistence or atomic authorization failed |
