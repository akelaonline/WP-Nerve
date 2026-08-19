# G10 — Release engineering

G10 is WPNerve's artifact-integrity and lifecycle gate. It is intentionally
runnable without GitHub Actions.

G10 does not pass merely because the scripts exist. The exact beta candidate
commit must produce reproducible artifacts and pass clean-install, upgrade and
uninstall evidence on disposable real WordPress installations.

## Release contract

Run:

```bash
bash scripts/check-release-contract.sh
```

The contract requires the plugin header, `WP_NERVE_VERSION`, WordPress.org stable
tag, PHPUnit runtime version and changelog entry to agree. It also verifies the
53-ability catalog and the export-ignore rules used by the release archive.

## Reproducible ZIP and checksum

Run from a clean checkout of the exact candidate commit:

```bash
bash scripts/build-release.sh
```

The builder:

1. validates the release contract;
2. creates two ZIP archives independently from the same Git commit using
   `git archive` and the repository's `export-ignore` rules;
3. requires those two local builds to be byte-identical;
4. verifies the resulting WordPress package structure and rejects development
   directories, unsafe paths, path collisions and symlinks;
5. writes `dist/wp-nerve-<version>.zip`;
6. writes `dist/SHA256SUMS`;
7. writes `dist/RELEASE-MANIFEST.txt` containing the exact commit and checksum.

### Cross-environment reproducibility evidence

The final beta candidate must be built from the same commit in at least two clean
environments. Record:

- operating system;
- Git version;
- exact commit SHA;
- WPNerve version;
- ZIP SHA-256 from each environment.

The two final ZIP SHA-256 values must match. If they do not, G10 fails until the
cause is understood and the builder is made deterministic across the supported
build environments.

## Real WordPress lifecycle gate

The lifecycle test is deliberately destructive to the WPNerve installation and
therefore requires an explicit disposable-site opt-in:

```bash
WP_NERVE_RELEASE_TEST=1 \
WP_PATH=/absolute/path/to/wordpress \
CANDIDATE_ZIP=/absolute/path/to/dist/wp-nerve-<version>.zip \
  bash scripts/test-release-engineering.sh
```

For an upgrade test, add a previous package:

```bash
WP_NERVE_RELEASE_TEST=1 \
WP_PATH=/absolute/path/to/wordpress \
PREVIOUS_ZIP=/absolute/path/to/wp-nerve-0.1.0-alpha.10.zip \
CANDIDATE_ZIP=/absolute/path/to/dist/wp-nerve-<version>.zip \
  bash scripts/test-release-engineering.sh
```

The runner refuses production by default and refuses a site where WPNerve is
already installed, so lifecycle evidence starts from an unambiguous state.

The gate verifies:

- clean install when no previous package is supplied;
- in-place overwrite/upgrade when `PREVIOUS_ZIP` is supplied;
- candidate version is active after install/upgrade;
- schema version 6 exists;
- all six WPNerve storage tables exist;
- exactly 53 WPNerve abilities resolve in the real Core registry;
- default uninstall preserves WPNerve-owned data as documented;
- reinstall after the default retained-data uninstall succeeds;
- explicit `wp_nerve_delete_data_on_uninstall=1` uninstall removes all six tables
  and the schema marker.

Expected final marker:

```text
WPNERVE_RELEASE_ENGINEERING_OK version=<version>
```

## Required upgrade matrix

Before beta, retain successful output for at least:

| From | To | Required |
|---|---|---|
| clean WordPress | beta candidate | Yes |
| 0.1.0-alpha.9 | beta candidate | Yes |
| 0.1.0-alpha.10 | beta candidate | Yes |
| beta candidate retained-data uninstall | same candidate reinstall | Yes |
| beta candidate | explicit destructive uninstall | Yes |

Run the lifecycle matrix on the same patched WordPress/PHP baseline used for the
release runtime evidence. Multisite release lifecycle should also be recorded
before claiming network-wide beta support.

## Release notes contract

The published beta release notes must include:

- exact version, tag and commit SHA;
- SHA-256 of the downloadable ZIP;
- supported WordPress/PHP matrix actually tested;
- supported MCP protocol eras actually tested;
- authentication modes actually tested;
- security controls added since the last alpha;
- known limitations and unresolved Medium/Low G9 findings;
- explicit statement that arbitrary SQL/PHP/shell/WP-CLI/filesystem editing and
  automatic third-party ability exposure are outside the core product;
- upgrade and uninstall behavior;
- link to `SECURITY.md` for vulnerability reporting.

Use `docs/release-notes-template.md` as the publication checklist.

## G10 exit criteria

G10 passes only when:

- release contract check passes on the exact candidate commit;
- two clean builds from that commit produce the same ZIP SHA-256;
- archive verifier passes;
- clean install passes on real WordPress;
- required upgrade paths pass;
- default and explicit uninstall semantics pass;
- release notes contain the exact checksum and tested matrix;
- the artifact tested is byte-for-byte the artifact published.

Hosted GitHub Actions are not required for any of these steps and an absent Action
run is not evidence either way.
