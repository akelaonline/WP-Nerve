# Mutation idempotency

Every `write`, `destructive`, and `privileged` tool call requires a client-generated
idempotency key. Read-only calls are not persisted and do not require a key.

## Request contract

Send the key in MCP request metadata, outside the ability arguments:

```json
{
  "jsonrpc": "2.0",
  "id": 7,
  "method": "tools/call",
  "params": {
    "name": "wp_nerve_create_draft",
    "arguments": {
      "title": "Example"
    },
    "_meta": {
      "wp-nerve/idempotencyKey": "0198c8ea-8b21-7d84-a570-58db7df48b82"
    }
  }
}
```

Keys must contain 8–128 ASCII letters, digits, dots, colons, underscores, or
hyphens. Generate a fresh unpredictable key for each intended operation and
reuse that key only when retrying the exact same tool call.

Tool descriptors advertise `wp-nerve/idempotencyRequired` in `_meta`.

## Semantics

The persistence scope is the authenticated WordPress user, authoritative
credential identity, tool, and SHA-256 hash of the client key. Application
Passwords use the UUID collected by WordPress; OAuth calls use the client ID
bound to the validated access token; cookie-authenticated calls use a one-way
digest of the WordPress session token. Self-reported MCP client names are never
used as authority. The record also binds a canonical digest of the complete
arguments.

| Situation | Behavior |
|---|---|
| First valid request | Atomically claims the key and runs the operation |
| Same key and same arguments after completion | Returns the stored outcome without executing |
| Same key with changed arguments | Rejects with `wp_nerve_idempotency_conflict` |
| Concurrent or interrupted execution | Rejects with `wp_nerve_idempotency_in_progress` |
| Persistence unavailable | Fails closed before executing |
| Result cannot be persisted | Locks the key and reports an indeterminate outcome |
| Completed outcome past retention | Requires a new key after operator verification |

Both successful results and ordinary ability errors are persisted. This prevents
a client retry from turning a partially understood failure into a second
mutation.

## Crash safety

An `in_progress` record never expires automatically. If PHP or WordPress stops
after the side effect but before the outcome is recorded, WPNerve cannot know
whether the operation committed. Repeating it would be unsafe, so subsequent
calls remain blocked. A future reconciliation workflow may resolve these records;
clients must not work around an indeterminate result automatically.

Completed outcomes remain replayable for 24 hours by default. The
`wp_nerve_idempotency_retention_ttl` filter may increase this window or reduce it
to no less than one hour.

## Storage and privacy

WPNerve stores hashes of credential identities, keys, and arguments, never their
plaintext values or tool arguments. Stored outcomes are the already validated
ability outputs or a small error envelope. The table has a unique database
constraint, so concurrent PHP workers cannot both acquire the same operation.
