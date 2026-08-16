# WPNerve v1 ability catalog

This catalog is a delivery contract, not a promise that every ability is enabled
by default. Mutations remain disabled until their policy, preview, idempotency,
audit, and recovery requirements are implemented.

| Ability | Risk | Default | Status | Recovery requirement |
|---|---:|---:|---|---|
| `site-status` | Read | On | **Implemented** | None |
| `list-content-types` | Read | On | **Implemented** | None |
| `search-content` | Read | On | **Implemented** | None |
| `get-content` | Read | On | **Implemented** | None |
| `list-revisions` | Read | On | Planned | None |
| `create-draft` | Write | On | Planned | Trash draft |
| `preview-content-update` | Read | On | Planned | None |
| `update-content` | Write | On | Planned | WordPress revision |
| `publish-content` | Destructive | Off | Planned | Previous status and revision |
| `trash-content` | Destructive | Off | Planned | Restore from trash |
| `restore-content` | Write | On | Planned | Return to trash |
| `restore-revision` | Destructive | Off | Planned | New revision before restore |
| `list-taxonomies` | Read | On | Planned | None |
| `list-terms` | Read | On | Planned | None |
| `create-term` | Write | Off | Planned | Delete created term |
| `assign-terms` | Write | On | Planned | Previous assignments |
| `list-media` | Read | On | Planned | None |
| `get-media` | Read | On | Planned | None |
| `upload-media` | Write | Off | Planned | Delete uploaded attachment |
| `update-media` | Write | On | Planned | Previous metadata |
| `list-comments` | Read | On | Planned | None |
| `moderate-comment` | Write | Off | Planned | Previous status |

Plugin, theme, user, filesystem, debug, arbitrary options, SQL, and code execution
abilities are outside the initial product scope.
