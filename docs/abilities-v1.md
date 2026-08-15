# WPNerve v1 ability catalog

This catalog is a delivery contract, not a promise that every ability is enabled
by default. Mutations remain disabled until their policy, preview, idempotency,
audit, and recovery requirements are implemented.

| Ability | Risk | Default | Recovery requirement |
|---|---:|---:|---|
| `site-status` | Read | On | None |
| `list-content-types` | Read | On | None |
| `search-content` | Read | On | None |
| `get-content` | Read | On | None |
| `list-revisions` | Read | On | None |
| `create-draft` | Write | On | Trash draft |
| `preview-content-update` | Read | On | None |
| `update-content` | Write | On | WordPress revision |
| `publish-content` | Destructive | Off | Previous status and revision |
| `trash-content` | Destructive | Off | Restore from trash |
| `restore-content` | Write | On | Return to trash |
| `restore-revision` | Destructive | Off | New revision before restore |
| `list-taxonomies` | Read | On | None |
| `list-terms` | Read | On | None |
| `create-term` | Write | Off | Delete created term |
| `assign-terms` | Write | On | Previous assignments |
| `list-media` | Read | On | None |
| `get-media` | Read | On | None |
| `upload-media` | Write | Off | Delete uploaded attachment |
| `update-media` | Write | On | Previous metadata |
| `list-comments` | Read | On | None |
| `moderate-comment` | Write | Off | Previous status |

Plugin, theme, user, filesystem, debug, arbitrary options, SQL, and code execution
abilities are outside the initial product scope.

