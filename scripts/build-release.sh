#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${ROOT_DIR}"

command -v git >/dev/null 2>&1 || { echo "ERROR: git is required." >&2; exit 2; }
command -v cmp >/dev/null 2>&1 || { echo "ERROR: cmp is required." >&2; exit 2; }

# The package is built from HEAD, so release validation must never read a
# different uncommitted working tree and accidentally certify another artifact.
if [[ -n "$(git status --porcelain --untracked-files=normal)" ]]; then
  echo "ERROR: release builds require a clean worktree; commit or discard local changes first." >&2
  exit 1
fi

bash scripts/check-release-contract.sh

version="$(sed -n 's/^ \* Version:[[:space:]]*//p' wp-nerve.php | head -n 1)"
sha="$(git rev-parse HEAD)"
short_sha="$(git rev-parse --short=12 HEAD)"

[[ -n "${version}" ]] || { echo "ERROR: version not found." >&2; exit 1; }

mkdir -p dist
archive="dist/wp-nerve-${version}.zip"
checksum_file="dist/SHA256SUMS"
manifest="dist/RELEASE-MANIFEST.txt"

tmp1="$(mktemp "${TMPDIR:-/tmp}/wp-nerve-release.XXXXXX.zip")"
tmp2="$(mktemp "${TMPDIR:-/tmp}/wp-nerve-release.XXXXXX.zip")"
trap 'rm -f "${tmp1}" "${tmp2}"' EXIT

git archive --format=zip --prefix=wp-nerve/ --output="${tmp1}" HEAD
git archive --format=zip --prefix=wp-nerve/ --output="${tmp2}" HEAD

if ! cmp -s "${tmp1}" "${tmp2}"; then
  echo "ERROR: two release builds from the same commit are not byte-identical." >&2
  exit 1
fi

mv "${tmp1}" "${archive}"
rm -f "${tmp2}"
trap - EXIT

bash scripts/verify-release-archive.sh "${archive}"

if command -v sha256sum >/dev/null 2>&1; then
  checksum="$(sha256sum "${archive}" | awk '{print $1}')"
elif command -v shasum >/dev/null 2>&1; then
  checksum="$(shasum -a 256 "${archive}" | awk '{print $1}')"
else
  echo "ERROR: sha256sum or shasum is required." >&2
  exit 2
fi

printf '%s  %s\n' "${checksum}" "$(basename "${archive}")" > "${checksum_file}"
cat > "${manifest}" <<EOF
WPNerve release manifest
version=${version}
commit=${sha}
commit_short=${short_sha}
archive=$(basename "${archive}")
sha256=${checksum}
builder=git-archive
prefix=wp-nerve/
reproducibility=two same-commit local builds byte-identical
EOF

printf 'PASS: built %s\n' "${archive}"
printf 'SHA-256: %s\n' "${checksum}"
printf 'Manifest: %s\n' "${manifest}"
