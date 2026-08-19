#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${ROOT_DIR}"

fail() {
  echo "ERROR: $*" >&2
  exit 1
}

header_version="$(sed -n 's/^ \* Version:[[:space:]]*//p' wp-nerve.php | head -n 1)"
constant_version="$(sed -n "s/.*define('WP_NERVE_VERSION', '\([^']*\)').*/\1/p" wp-nerve.php | head -n 1)"
stable_tag="$(sed -n 's/^Stable tag:[[:space:]]*//p' readme.txt | head -n 1)"
bootstrap_version="$(sed -n "s/.*define('WP_NERVE_VERSION', '\([^']*\)').*/\1/p" tests/bootstrap.php | head -n 1)"

[[ -n "${header_version}" ]] || fail "plugin header version not found"
[[ "${header_version}" == "${constant_version}" ]] || fail "plugin header (${header_version}) != WP_NERVE_VERSION (${constant_version})"
[[ "${header_version}" == "${stable_tag}" ]] || fail "plugin header (${header_version}) != readme stable tag (${stable_tag})"
[[ "${header_version}" == "${bootstrap_version}" ]] || fail "plugin header (${header_version}) != PHPUnit bootstrap (${bootstrap_version})"

grep -Fq "## [${header_version}]" CHANGELOG.md || fail "CHANGELOG has no heading for ${header_version}"
grep -Fq "${header_version}" README.md || fail "README does not mention ${header_version}"
grep -Fq "Requires at least: 6.9" readme.txt || fail "WordPress minimum drifted in readme.txt"
grep -Fq "Requires PHP: 8.1" readme.txt || fail "PHP minimum drifted in readme.txt"
grep -Fq "Requires at least: 6.9" wp-nerve.php || fail "WordPress minimum drifted in plugin header"
grep -Fq "Requires PHP:      8.1" wp-nerve.php || fail "PHP minimum drifted in plugin header"

ability_rows="$(grep -E '^\| `[^`]+` \|' docs/abilities-v1.md | wc -l | tr -d ' ')"
[[ "${ability_rows}" == "53" ]] || fail "ability catalog contains ${ability_rows} rows; expected 53"

# Release archives must not carry development/test/reviewer tooling.
for pattern in '/.github export-ignore' '/docs export-ignore' '/tests export-ignore' '/scripts export-ignore' '/composer.json export-ignore' '/composer.lock export-ignore'; do
  grep -Fq "${pattern}" .gitattributes || fail ".gitattributes missing: ${pattern}"
done

echo "PASS: release contract ${header_version}; 53 abilities; package exclusions present"
