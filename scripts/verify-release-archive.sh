#!/usr/bin/env bash
set -euo pipefail

ARCHIVE="${1:-}"
[[ -n "${ARCHIVE}" && -f "${ARCHIVE}" ]] || { echo "Usage: $0 /path/to/wp-nerve-version.zip" >&2; exit 2; }

command -v python3 >/dev/null 2>&1 || { echo "ERROR: python3 is required." >&2; exit 2; }

python3 - "${ARCHIVE}" <<'PY'
import pathlib
import stat
import sys
import zipfile

archive = pathlib.Path(sys.argv[1])
required = {
    "wp-nerve/wp-nerve.php",
    "wp-nerve/src/Autoloader.php",
    "wp-nerve/readme.txt",
    "wp-nerve/uninstall.php",
    "wp-nerve/LICENSE",
}
forbidden_prefixes = (
    "wp-nerve/.github/",
    "wp-nerve/docs/",
    "wp-nerve/tests/",
    "wp-nerve/scripts/",
    "wp-nerve/vendor/",
)
forbidden_exact = {
    "wp-nerve/composer.json",
    "wp-nerve/composer.lock",
    "wp-nerve/phpcs.xml.dist",
    "wp-nerve/phpstan.neon.dist",
    "wp-nerve/phpunit.xml.dist",
    "wp-nerve/.gitattributes",
    "wp-nerve/.editorconfig",
    "wp-nerve/.gitignore",
}

with zipfile.ZipFile(archive, "r") as zf:
    infos = zf.infolist()
    names = [i.filename for i in infos]
    name_set = set(names)

    missing = sorted(required - name_set)
    if missing:
        raise SystemExit(f"ERROR: missing required release files: {missing}")

    bad = [n for n in names if n in forbidden_exact or n.startswith(forbidden_prefixes)]
    if bad:
        raise SystemExit(f"ERROR: development files leaked into release ZIP: {bad[:10]}")

    seen = set()
    for info in infos:
        name = info.filename.replace("\\", "/")
        path = pathlib.PurePosixPath(name)
        if name.startswith("/") or ".." in path.parts:
            raise SystemExit(f"ERROR: unsafe archive path: {name}")
        if any(ord(ch) < 32 or ord(ch) == 127 for ch in name):
            raise SystemExit(f"ERROR: control character in archive path: {name!r}")
        if not name.startswith("wp-nerve/"):
            raise SystemExit(f"ERROR: unexpected top-level path: {name}")
        key = name.casefold()
        if key in seen:
            raise SystemExit(f"ERROR: case-colliding archive path: {name}")
        seen.add(key)

        mode = (info.external_attr >> 16) & 0xFFFF
        if stat.S_ISLNK(mode):
            raise SystemExit(f"ERROR: symlink in release archive: {name}")

    plugin = zf.read("wp-nerve/wp-nerve.php").decode("utf-8")
    readme = zf.read("wp-nerve/readme.txt").decode("utf-8")

    version = None
    for line in plugin.splitlines():
        if line.startswith(" * Version:"):
            version = line.split(":", 1)[1].strip()
            break
    if not version:
        raise SystemExit("ERROR: plugin version missing in archive")

    stable = None
    for line in readme.splitlines():
        if line.startswith("Stable tag:"):
            stable = line.split(":", 1)[1].strip()
            break
    if stable != version:
        raise SystemExit(f"ERROR: archive stable tag {stable!r} != plugin version {version!r}")

    expected_name = f"wp-nerve-{version}.zip"
    if archive.name != expected_name:
        raise SystemExit(f"ERROR: archive name {archive.name!r} != expected {expected_name!r}")

print(f"PASS: release archive {archive.name} is structurally clean; version={version}; entries={len(infos)}")
PY
